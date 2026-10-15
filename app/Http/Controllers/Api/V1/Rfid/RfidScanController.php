<?php

namespace App\Http\Controllers\Api\V1\Rfid;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rfid\ManualScanRequest;
use App\Http\Requests\Rfid\ReasonRequest;
use App\Models\Batch;
use App\Models\Checkpoint;
use App\Models\RfidReader;
use App\Services\Hardware\DeviceContext;
use App\Services\Hardware\RfidScanService;
use App\Support\RfidApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Umpan scan, scan manual (USB/ketik/simulasi), pembatalan scan, dan ringkasan.
 * Semua query dibatasi company_id pengguna (kolom denormalisasi checkpoints.company_id, berindeks).
 */
class RfidScanController extends Controller
{
    private const FEED_MAX = 100;

    public function __construct(private readonly RfidScanService $scans) {}

    /**
     * GET scans — umpan scan (kursor).
     *   since_id : hanya yang LEBIH BARU dari id ini (polling langsung)
     *   before_id: halaman lebih lama
     *   filter   : stage, reader_id, origin (real|simulation|manual), batch_id, voided (0|1)
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('rfid.view');

        $limit = min(max($request->integer('limit', 30), 1), self::FEED_MAX);

        $query = Checkpoint::query()
            ->where('company_id', $request->user()->company_id)
            ->whereNotNull('rfid_scan_code')
            ->with(['batch:id,batch_code,product_code_id', 'batch.productCode:id,code', 'reader:id,code,name'])
            ->when($request->filled('stage'),    fn ($q) => $q->where('scan_stage', $request->string('stage')))
            ->when($request->filled('reader_id'), fn ($q) => $q->where('reader_id', $request->integer('reader_id')))
            ->when($request->filled('batch_id'),  fn ($q) => $q->where('batch_id', $request->integer('batch_id')))
            ->when($request->filled('origin'), function ($q) use ($request) {
                match ($request->string('origin')->toString()) {
                    'simulation' => $q->where('rfid_origin', 'simulation'),
                    'manual'     => $q->where('rfid_origin', 'manual'),
                    'real'       => $q->whereIn('rfid_origin', ['real_esp32', 'real_uhf', 'api']),
                    default      => null,
                };
            })
            ->when($request->has('voided'), fn ($q) => $request->boolean('voided')
                ? $q->whereNotNull('voided_at') : $q->whereNull('voided_at'));

        if ($request->filled('since_id')) {
            $rows = $query->where('id', '>', $request->integer('since_id'))->orderByDesc('id')->limit($limit)->get();
        } else {
            $rows = $query->when($request->filled('before_id'), fn ($q) => $q->where('id', '<', $request->integer('before_id')))
                ->orderByDesc('id')->limit($limit)->get();
        }

        return RfidApi::ok('ok', $rows->map(fn (Checkpoint $c) => $this->present($c))->all(), 200, [
            'next_before_id' => $rows->count() === $limit ? $rows->last()?->id : null,
            'server_time'    => now()->toIso8601String(),
        ]);
    }

    /** GET overview — angka ringkas untuk kartu di halaman RFID. */
    public function overview(Request $request): JsonResponse
    {
        Gate::authorize('rfid.view');
        $companyId = $request->user()->company_id;
        $dayStart  = now('Asia/Jakarta')->startOfDay()->utc();

        $scansToday = Checkpoint::where('company_id', $companyId)->active()
            ->whereNotNull('rfid_scan_code')->where('recorded_at', '>=', $dayStart);

        $readers = RfidReader::where('company_id', $companyId)->get();

        return RfidApi::ok('ok', [
            'scans_today'      => (clone $scansToday)->count(),
            'scans_today_sim'  => (clone $scansToday)->where('rfid_origin', 'simulation')->count(),
            'batches_tagged'   => Batch::where('company_id', $companyId)->whereNotNull('rfid_tag')->count(),
            'batches_untagged' => Batch::where('company_id', $companyId)->whereNull('rfid_tag')
                ->whereNotIn('status', ['exported', 'terminated'])->count(),
            'readers_total'    => $readers->count(),
            'readers_online'   => $readers->filter(fn ($r) => $r->connectionState() === 'online')->count(),
            'readers_offline'  => $readers->filter(fn ($r) => $r->connectionState() === 'offline')->count(),
            'simulation_enabled' => (bool) config('rfid.simulation_enabled'),
        ]);
    }

    /** POST scan — Scan Masuk/Keluar dari UI (input manual/USB, atau simulasi bertanda). */
    public function store(ManualScanRequest $request): JsonResponse
    {
        Gate::authorize('rfid.operate');
        $user     = $request->user();
        $simulate = $request->boolean('simulate');

        if ($simulate && ! config('rfid.simulation_enabled')) {
            return RfidApi::fail('simulation_disabled', 403);
        }

        $reader = null;
        if ($request->filled('reader_id')) {
            $reader = RfidReader::where('company_id', $user->company_id)->where('is_active', true)->find($request->integer('reader_id'));
            if (! $reader) {
                return RfidApi::fail('reader_invalid');
            }
        }

        $ctx = new DeviceContext($user->company_id, $reader, $reader ? 'device_key' : 'company_code');

        [$result, $duplicate] = $this->scans->scan($ctx, $request->string('direction')->toString(), $request->string('uid')->toString(), $request->input('stage'), [
            'origin'   => $simulate ? 'simulation' : 'manual',
            'event_id' => $request->input('event_id'),
            'ip'       => $request->ip(),
            'user_id'  => $user->id,
        ]);

        if ($result['status'] !== 'success') {
            return RfidApi::fail($result['_code'] ?? 'validation_failed', 422, ['detail' => $result['detail'] ?? null]);
        }

        $data = array_filter($result, fn ($k) => ! str_starts_with((string) $k, '_'), ARRAY_FILTER_USE_KEY);

        return RfidApi::ok($duplicate ? 'scan_duplicate' : 'scan_recorded', $data + ['duplicate' => $duplicate]);
    }

    /** POST scans/{id}/void — koreksi: tandai dibatalkan beralasan (riwayat tetap). */
    public function void(ReasonRequest $request, int $id): JsonResponse
    {
        Gate::authorize('rfid.operate');

        $checkpoint = Checkpoint::where('company_id', $request->user()->company_id)->findOrFail($id);

        try {
            $voided = $this->scans->void($checkpoint, $request->user(), $request->string('reason')->toString());
        } catch (\DomainException $e) {
            return RfidApi::fail($e->getMessage() === 'already_voided' ? 'void_already' : 'void_not_scan');
        }

        return RfidApi::ok('scan_voided', $this->present($voided->load(['batch.productCode', 'reader'])));
    }

    private function present(Checkpoint $c): array
    {
        return [
            'id'              => $c->id,
            'batch_id'        => $c->batch_id,
            'batch_code'      => $c->batch?->batch_code,
            'product_code'    => $c->batch?->productCode?->code,
            'uid'             => $c->rfid_scan_code,
            'direction'       => $c->scan_direction,                // in | out | null (registrasi)
            'stage'           => $c->scan_stage,
            'checkpoint_stage'=> $c->checkpoint_stage,
            'is_registration' => ($c->checkpoint_data['action'] ?? null) === 'rfid_register',
            'origin'          => $c->rfid_origin,
            'reader'          => $c->reader ? ['id' => $c->reader->id, 'code' => $c->reader->code, 'name' => $c->reader->name] : null,
            'snapshot_status' => $c->snapshot_status,                // pending | captured | failed | null
            'recorded_at'     => $c->recorded_at?->toIso8601String(),
            'is_voided'       => $c->voided_at !== null,
            'void_reason'     => $c->void_reason,
        ];
    }
}
