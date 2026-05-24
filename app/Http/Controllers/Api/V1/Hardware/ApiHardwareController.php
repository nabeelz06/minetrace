<?php

namespace App\Http\Controllers\Api\V1\Hardware;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Location;
use App\Services\Hardware\RfidService;
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
     * POST /api/v1/hardware/rfid-scan
     * Endpoint untuk RFID reader mengirim scan event.
     *
     * Body: { rfid_tag, location_id, signal_strength?, antenna_port? }
     */
    public function rfidScan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'rfid_tag'        => 'required|string|max:100',
            'location_id'     => 'required|integer|exists:locations,id',
            'signal_strength' => 'nullable|numeric',
            'antenna_port'    => 'nullable|integer',
            'reader_id'       => 'nullable|string|max:50',
        ]);

        $result = $this->rfidService->processScan(
            $validated['rfid_tag'],
            $validated['location_id'],
            [
                'signal_strength' => $validated['signal_strength'] ?? null,
                'antenna_port'    => $validated['antenna_port'] ?? null,
                'reader_id'       => $validated['reader_id'] ?? null,
            ]
        );

        // ─── Dispatch event ke antrian untuk frontend polling ──────────────
        if ($result['status'] === 'success' && isset($result['batch'])) {
            $batch = Batch::find($result['batch']['id']);
            if ($batch) {
                $this->hardwareEventService->dispatchRfidScan($result, $batch->company_id);
            }
        }

        $statusCode = match($result['status']) {
            'success'   => 200,
            'duplicate' => 200,
            default     => 422,
        };

        return response()->json([
            'success' => $result['status'] === 'success',
            'data'    => $result,
            'message' => $result['message'],
        ], $statusCode);
    }

    /**
     * POST /api/v1/hardware/rfid-assign
     * Assign RFID tag ke batch (saat tag baru dipasang ke kantung).
     *
     * Body: { batch_id, rfid_tag }
     */
    public function rfidAssign(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'batch_id' => 'required|integer|exists:batches,id',
            'rfid_tag' => 'required|string|max:100',
        ]);

        $batch = Batch::findOrFail($validated['batch_id']);

        // Pastikan batch milik company user
        abort_unless($batch->company_id === Auth::user()->company_id, 403);

        $success = $this->rfidService->assignTagToBatch($batch, $validated['rfid_tag']);

        if (!$success) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => "Tag [{$validated['rfid_tag']}] sudah digunakan batch lain.",
            ], 409);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'batch_code' => $batch->batch_code,
                'rfid_tag'   => $validated['rfid_tag'],
                'assigned_at'=> now()->toIso8601String(),
            ],
            'message' => "Tag RFID berhasil di-assign ke batch {$batch->batch_code}.",
        ]);
    }

    /**
     * POST /api/v1/hardware/simulate-scan
     * Simulasi RFID scan untuk testing & demo (tanpa hardware fisik).
     *
     * Body: { batch_id, location_id }
     */
    public function simulateScan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'batch_id'    => 'required|integer|exists:batches,id',
            'location_id' => 'required|integer|exists:locations,id',
        ]);

        $batch = Batch::findOrFail($validated['batch_id']);
        abort_unless($batch->company_id === Auth::user()->company_id, 403);

        $result = $this->rfidService->simulateScan(
            $validated['batch_id'],
            $validated['location_id']
        );

        if ($result['status'] === 'success') {
            $this->hardwareEventService->dispatchRfidScan($result, $batch->company_id);
        }

        return response()->json([
            'success' => $result['status'] === 'success',
            'data'    => $result,
            'message' => $result['message'],
        ]);
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