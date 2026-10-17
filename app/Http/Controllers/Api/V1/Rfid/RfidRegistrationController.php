<?php

namespace App\Http\Controllers\Api\V1\Rfid;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rfid\StartRegistrationRequest;
use App\Models\Batch;
use App\Models\RfidReader;
use App\Services\Hardware\RfidReadLogService;
use App\Services\Hardware\RfidRegistrationService;
use App\Services\Hardware\RfidService;
use App\Support\RfidApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Registrasi tag (ikat UID ke batch) dari UI:
 *  - store : mulai sesi menunggu reader (paralel antar stasiun), ATAU commit langsung bila `uid` diisi
 *            (input manual / reader USB tipe keyboard-wedge / reader UHF desktop R2000 via Web Serial).
 *  - status: polling hasil per batch (waiting | completed | expired).
 *  - destroy: batalkan sesi.
 */
class RfidRegistrationController extends Controller
{
    public function __construct(
        private readonly RfidRegistrationService $registration,
        private readonly RfidService $rfid,
        private readonly RfidReadLogService $readLogs,
    ) {}

    public function store(StartRegistrationRequest $request): JsonResponse
    {
        Gate::authorize('rfid.operate');
        $user = $request->user();

        $batch = Batch::with('productCode')->where('company_id', $user->company_id)->find($request->integer('batch_id'));
        if (! $batch) {
            return RfidApi::fail('batch_missing', 404);
        }

        $reader = null;
        if ($request->filled('reader_id')) {
            $reader = RfidReader::where('company_id', $user->company_id)->find($request->integer('reader_id'));
            if (! $reader) {
                return RfidApi::fail('reader_invalid');
            }
        }

        // Input manual / USB: ikat langsung tanpa menunggu reader.
        if ($request->filled('uid')) {
            return $this->commitManual($request, $batch, $reader);
        }

        try {
            $session = $this->registration->start($user, $batch, $reader);
        } catch (\DomainException $e) {
            return RfidApi::fail($e->getMessage());
        }

        return RfidApi::ok('registration_started', $this->present($session), 201);
    }

    private function commitManual(Request $request, Batch $batch, ?RfidReader $reader): JsonResponse
    {
        $uid = $this->rfid->sanitizeUid((string) $request->input('uid'));

        // Gunakan sesi aktif batch bila ada; jika tidak, sesi sementara (tidak disimpan).
        $session = collect($this->registration->activeForCompany($batch->company_id))->firstWhere('batch_id', $batch->id);
        if (! $session) {
            try {
                $session = $this->registration->start($request->user(), $batch, $reader);
            } catch (\DomainException $e) {
                return RfidApi::fail($e->getMessage());
            }
        }

        // Reader UHF desktop (Receiving): EPC dibaca browser langsung dari port serial → asal real_uhf.
        $origin = $request->input('source') === 'uhf_desktop' ? 'real_uhf' : 'manual';
        $result = $this->registration->commit($session, $uid, $origin, $reader, $request->user()->id);

        $this->readLogs->registration($batch->company_id, $reader, $uid, $result + ['batch_id' => $batch->id], [
            'origin'  => $origin,
            'user_id' => $request->user()->id,
            'rssi'    => $request->input('rssi'),
            'antenna' => $request->input('antenna'),
            'tid'     => $request->input('tid'),
            'meta'    => ['ip' => $request->ip(), 'source' => $request->input('source', 'manual')],
        ]);

        if ($result['status'] !== 'success') {
            $this->registration->cancel($session['id']);
            return RfidApi::fail($result['_code'] ?? 'tag_in_use', 422, ['detail' => $result['detail'] ?? null]);
        }

        return RfidApi::ok('tag_registered', [
            'batch_id' => $result['batch_id'], 'batch_code' => $result['batch_code'], 'uid' => $uid,
        ]);
    }

    /** GET batches/{batch}/registration — status untuk polling UI. */
    public function status(Request $request, int $batchId): JsonResponse
    {
        Gate::authorize('rfid.view');

        $batch = Batch::where('company_id', $request->user()->company_id)->findOrFail($batchId);
        $session = collect($this->registration->activeForCompany($batch->company_id))->firstWhere('batch_id', $batch->id);

        if ($session) {
            return RfidApi::ok('ok', ['state' => 'waiting'] + $this->present($session));
        }

        return RfidApi::ok('ok', [
            'state' => $batch->rfid_tag ? 'completed' : 'expired',
            'uid'   => $batch->rfid_tag,
            'batch_id' => $batch->id,
            'batch_code' => $batch->batch_code,
        ]);
    }

    /** GET registration — semua sesi aktif perusahaan (stasiun paralel). */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('rfid.view');

        return RfidApi::ok('ok', array_map(
            fn ($s) => $this->present($s),
            $this->registration->activeForCompany($request->user()->company_id)
        ));
    }

    public function destroy(Request $request, string $sessionId): JsonResponse
    {
        Gate::authorize('rfid.operate');

        if ($this->registration->get($sessionId, $request->user()->company_id)) {
            $this->registration->cancel($sessionId);
        }

        return RfidApi::ok('registration_cancelled');
    }

    private function present(array $s): array
    {
        return [
            'session_id'   => $s['id'],
            'batch_id'     => $s['batch_id'],
            'batch_code'   => $s['batch_code'],
            'product_code' => $s['product_code'],
            'reader_id'    => $s['reader_id'],
            'reader_code'  => $s['reader_code'],
            'started_at'   => $s['started_at'],
            'expires_at'   => $s['expires_at'],
            'ttl_seconds'  => (int) config('rfid.registration_ttl'),
        ];
    }
}
