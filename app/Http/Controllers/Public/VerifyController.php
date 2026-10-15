<?php

namespace App\Http\Controllers\Public;

use App\Enums\BatchStage;
use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Services\Batch\QrCodeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * VerifyController (publik) — halaman tujuan QR yang tercetak di karung.
 *
 * Payload QR: {APP_URL}/verify/batch/{id}?code=…&cs=… (lihat QrCodeService).
 * Kebijakan minimal-disclosure: HANYA kode batch, produk, perusahaan, tahap, status,
 * lokasi umum, dan linimasa tahap (tanggal saja). Tidak ada berat, hasil lab, atau operator.
 */
class VerifyController extends Controller
{
    /** Peta checkpoint_stage → tahap proses (untuk linimasa ringkas). */
    private const CHECKPOINT_TO_STAGE = [
        'lab_kimia_create'     => 'lab_kimia',
        'lab_sample_taken'     => 'lab_kimia',
        'in_transit'           => 'receiving',
        'receiving_verify'     => 'receiving',
        'wet_process_input'    => 'wet_process',
        'wet_process_output'   => 'wet_process',
        'dry_process_input'    => 'dry_process',
        'dry_process_output'   => 'dry_process',
        'project_plant_input'  => 'project_plant',
        'project_plant_output' => 'project_plant',
        'warehouse_inbound'    => 'warehouse',
        'warehouse_outbound'   => 'warehouse',
        'warehouse_transfer'   => 'warehouse',
        'smelter_input'        => 'smelter',
        'export_release'       => 'exported',
    ];

    public function __construct(private readonly QrCodeService $qrCodeService) {}

    public function show(int $id, Request $request): Response
    {
        $code = (string) $request->query('code', '');
        $cs   = (string) $request->query('cs', '');

        // Parameter hilang/tidak wajar → 422 (bukan 404, agar pesan bisa ramah & spesifik).
        if ($code === '' || $cs === '' || strlen($code) > 100 || strlen($cs) > 64) {
            return $this->render(['state' => 'malformed'], 422);
        }

        $result = $this->qrCodeService->verify($id, $code, $cs);

        if (! $result['valid']) {
            Log::info('verify.qr.invalid', ['batch_id' => $id, 'reason' => $result['reason'] ?? null, 'ip' => $request->ip()]);
            return $this->render(['state' => 'invalid'], 404);
        }

        /** @var Batch $batch */
        $batch = $result['batch'];

        return $this->render([
            'state' => 'valid',
            'batch' => [
                'batch_code' => $batch->batch_code,
                'product'    => $batch->productCode?->name,
                'company'    => $batch->company?->name,
                'location'   => $batch->currentLocation?->name,
                'status'     => $batch->status,
                'stage'      => $this->stageLabels($batch->process_stage),
            ],
            'timeline'    => $this->timeline($batch),
            'verified_at' => now()->toIso8601String(),
        ], 200);
    }

    /** Linimasa: satu entri per tahap (kejadian pertama), tanpa data sensitif; scan simulasi dikecualikan. */
    private function timeline(Batch $batch): array
    {
        $seen = [];
        $rows = $batch->checkpoints()
            ->where(fn ($q) => $q->whereNull('rfid_origin')->orWhere('rfid_origin', '!=', 'simulation'))
            ->orderBy('recorded_at')
            ->limit(200)
            ->get(['checkpoint_stage', 'recorded_at']);

        foreach ($rows as $cp) {
            $stage = self::CHECKPOINT_TO_STAGE[$cp->checkpoint_stage] ?? null;
            if ($stage === null || isset($seen[$stage])) {
                continue;
            }
            $seen[$stage] = [
                'stage' => $this->stageLabels($stage),
                'date'  => $cp->recorded_at?->setTimezone('Asia/Jakarta')->toDateString(),
            ];
        }

        return array_values($seen);
    }

    private function stageLabels(string $stage): array
    {
        $enum = BatchStage::tryFrom($stage);

        return [
            'key' => $stage,
            'id'  => $enum?->label('id') ?? $stage,
            'en'  => $enum?->label('en') ?? $stage,
        ];
    }

    /** Respons Inertia + header: tidak diindeks mesin pencari dan tidak di-cache. */
    private function render(array $props, int $status): Response
    {
        $response = Inertia::render('Public/VerifyBatch', $props)->toResponse(request());
        $response->setStatusCode($status);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
