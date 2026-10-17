<?php

namespace App\Http\Controllers\Api\V1\Hardware;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Location;
use App\Exceptions\DeviceAuthException;
use App\Services\Hardware\RfidDeviceService;
use App\Services\Hardware\RfidScanService;
use App\Services\Hardware\RfidService;
use App\Support\RfidApi;
use App\Services\Hardware\HardwareEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * ApiHardwareController — endpoint untuk integrasi hardware RFID & sensors.
 *
 * Digunakan oleh:
 * - RFID reader (POST /api/v1/hardware/rfid-scan)
 * - Weight sensor / timbangan digital
 * - Simulasi hardware (untuk testing)
 * - Frontend polling events (GET /api/v1/hardware/events)
 */
class ApiHardwareController extends Controller
{
    public function __construct(
        private RfidService          $rfidService,
        private HardwareEventService $hardwareEventService
    ) {}

    /**
     * POST /api/v1/hardware/rfid-scan — endpoint scan terpadu untuk reader baru (mis. UHF EPC Gen2).
     *
     * Header: X-Device-Key (reader terdaftar; wajib) · X-Event-Id (idempotensi; opsional).
     * Body  : { uid, direction: "in"|"out", stage?, event_id?, rssi?, antenna_port? }
     * Respons: { success, code, message, message_en, data: {...hasil scan} } (format UI; bukan format LCD).
     * Reader terdaftar membawa read point tetap (tahap + arah) — body `stage` hanya bila reader fleksibel.
     */
    public function rfidScan(Request $request): JsonResponse
    {
        try {
            $ctx = app(RfidDeviceService::class)->resolveContext($request);
        } catch (DeviceAuthException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->httpStatus);
        }
        if (! $ctx->reader) {
            return response()->json(['success' => false, 'message' => 'X-Device-Key wajib untuk endpoint ini.'], 401);
        }

        $v = $request->validate([
            'uid'          => 'required|string|min:4|max:100',
            'direction'    => 'required|in:in,out',
            'stage'        => 'nullable|string|in:' . implode(',', \App\Support\ProcessFlow::rfidStages()),
            'event_id'     => ['nullable', 'string', 'regex:/^[A-Za-z0-9._:\-]{8,64}$/'],
            'rssi'         => 'nullable|integer',
            'antenna_port' => 'nullable|integer',
        ]);

        [$result, $duplicate] = app(RfidScanService::class)->scan(
            $ctx, $v['direction'], $v['uid'], $v['stage'] ?? null,
            [
                'origin'   => $ctx->reader->isUhf() ? 'real_uhf' : 'api',
                'event_id' => $v['event_id'] ?? $request->header('X-Event-Id'),
                'ip'       => $request->ip(),
                'rssi'     => $v['rssi'] ?? null,
                'antenna'  => $v['antenna_port'] ?? null,
            ]
        );

        if ($result['status'] !== 'success') {
            return RfidApi::fail($result['_code'] ?? 'validation_failed', 422, ['detail' => $result['detail'] ?? null]);
        }

        $data = array_filter($result, fn ($k) => ! str_starts_with((string) $k, '_'), ARRAY_FILTER_USE_KEY);
        if ($batch = Batch::find($result['batch_id'] ?? 0)) {
            $this->hardwareEventService->dispatchRfidScan($data, $batch->company_id);
        }

        return RfidApi::ok($duplicate ? 'scan_duplicate' : 'scan_recorded', $data + ['duplicate' => $duplicate]);
    }

    /**
     * POST /api/v1/hardware/weight
     * Input dari timbangan digital.
     *
     * Body: { batch_id, weight_kg, location_id, unit? }
     */
    public function weightInput(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'batch_id'    => 'required|integer|exists:batches,id',
            'weight_kg'   => 'required|numeric|min:0',
            'location_id' => 'required|integer|exists:locations,id',
            'unit'        => 'nullable|in:kg,ton,lb',
        ]);

        $batch    = Batch::findOrFail($validated['batch_id']);
        abort_unless($batch->company_id === Auth::user()->company_id, 403);

        $weightKg = $validated['weight_kg'];
        if (($validated['unit'] ?? 'kg') === 'ton') {
            $weightKg *= 1000;
        }

        $location = Location::findOrFail($validated['location_id']);

        $this->hardwareEventService->dispatchWeightMeasurement(
            $batch->id,
            $weightKg,
            $location->code,
            $batch->company_id
        );

        return response()->json([
            'success' => true,
            'data'    => [
                'batch_id'    => $batch->id,
                'batch_code'  => $batch->batch_code,
                'weight_kg'   => $weightKg,
                'location'    => $location->name,
                'received_at' => now()->toIso8601String(),
            ],
            'message' => "Data berat {$weightKg} kg diterima untuk batch {$batch->batch_code}.",
        ]);
    }

    /**
     * GET /api/v1/hardware/events?since_id={last_event_id}
     * Frontend polling untuk mendapatkan events hardware terbaru.
     * Frontend call ini setiap 3 detik.
     */
    public function pollEvents(Request $request): JsonResponse
    {
        $companyId = Auth::user()->company_id;
        abort_unless($companyId, 403);

        $sinceId = $request->string('since_id')->toString() ?: null;
        $events  = $this->hardwareEventService->pollEvents($companyId, $sinceId);

        return response()->json([
            'success' => true,
            'data'    => $events,
            'meta'    => [
                'count'       => count($events),
                'polled_at'   => now()->toIso8601String(),
            ],
            'message' => 'OK',
        ]);
    }

    /**
     * GET /api/v1/hardware/reader-config
     * Konfigurasi reader RFID untuk suatu lokasi.
     * Digunakan oleh hardware device saat boot untuk mendapatkan config.
     */
    public function readerConfig(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_code' => 'required|string|max:10',
            'company_code'  => 'required|string|max:20',
        ]);

        $location = Location::whereHas('company', fn($q) =>
            $q->where('code', $validated['company_code'])
        )->where('code', $validated['location_code'])->first();

        abort_unless($location, 404, 'Lokasi tidak ditemukan.');

        return response()->json([
            'success' => true,
            'data'    => [
                'location_id'   => $location->id,
                'location_code' => $location->code,
                'location_name' => $location->name,
                'location_type' => $location->location_type,
                'company_id'    => $location->company_id,
                'api_endpoint'  => url('/api/v1/hardware/rfid-scan'),
                'poll_interval' => 3,  // seconds
                'config'        => [
                    'read_power'    => 30,  // dBm
                    'session'       => 'S1',
                    'target'        => 'AB',
                    'antenna_ports' => [1, 2],
                ],
            ],
            'message' => 'Reader config retrieved.',
        ]);
    }
}