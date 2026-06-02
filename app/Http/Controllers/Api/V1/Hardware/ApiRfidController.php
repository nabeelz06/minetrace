<?php

namespace App\Http\Controllers\Api\V1\Hardware;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Services\Hardware\RfidService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * ApiRfidController — API endpoint untuk hardware RFID ESP32.
 *
 * ════════════════════════════════════════════════════════════════════
 * ENDPOINT ESP32 (hardware langsung, tanpa session auth):
 *   POST /api/rfid/register   → Link UID tag ke batch (di Lab Kimia)
 *   POST /api/rfid/login      → Checkpoint IN (batch masuk gudang)
 *   POST /api/rfid/logout     → Checkpoint OUT (batch keluar gudang)
 *
 * ENDPOINT WEB UI (Sanctum session auth dari Vue):
 *   GET  /api/v1/rfid/status          → Status sistem + batch ter-tag + aktivitas
 *   GET  /api/v1/rfid/batches         → Daftar batch belum di-tag (untuk dropdown)
 *   POST /api/v1/rfid/pending-batch   → Set batch mana yang akan menerima tag
 *   DELETE /api/v1/rfid/pending-batch → Batalkan sesi pending
 *   POST /api/v1/rfid/device          → Simpan IP ESP32 ke sistem
 *   GET  /api/v1/hardware/rfid/esp-config → Konfigurasi untuk ESP32 saat boot
 *
 * FORMAT REQUEST ESP32:
 *   Header: Content-Type: application/json
 *   Header: X-Rfid-Token: {token} (opsional)
 *   Body:   { "uid": "A3B2C1D0" }
 *
 * FORMAT RESPONSE untuk LCD 16×2 ESP32:
 *   { "status": "success|failed", "name": "BatchCode", "message": "Keterangan" }
 *   └─ "name"    → ditampilkan di LCD baris 1 (max 16 char)
 *   └─ "message" → ditampilkan di LCD baris 2 (max 16 char)
 * ════════════════════════════════════════════════════════════════════
 */
class ApiRfidController extends Controller
{
    private const DEFAULT_COMPANY_ID = 1;

    // ─── CORS Headers standar untuk ESP32 compatibility ───────────────
    private const CORS_HEADERS = [
        'Access-Control-Allow-Origin'  => '*',
        'Access-Control-Allow-Methods' => 'GET, POST, DELETE, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type, X-Rfid-Token, Authorization',
    ];

    public function __construct(
        private RfidService $rfid
    ) {}

    // ═══════════════════════════════════════════════════════════════════
    // HARDWARE ENDPOINTS — Dipanggil langsung oleh ESP32
    // ═══════════════════════════════════════════════════════════════════

    /**
     * REGISTER: Tempelkan RFID tag ke karung → link ke batch pending.
     *
     * Skenario: Operator Lab tempel tag ke karung mineral baru.
     * Sebelumnya: Web MINETRA sudah set pending batch via /pending-batch
     *
     * POST /api/rfid/register
     * Body: { "uid": "A3B2C1D0", "api_token": "..." }
     *
     * Response LCD: name=BatchCode, message="Tag terdaftar!"
     */
    public function registerCard(Request $request): JsonResponse
    {
        if (!$this->rfid->validateToken(
            $request->header('X-Rfid-Token') ?? $request->input('api_token', '')
        )) {
            return $this->errorResponse('Token tidak valid', 401);
        }

        $request->validate(['uid' => 'required|string|min:4|max:50']);

        $companyId = $this->resolveCompanyId($request);
        $result    = $this->rfid->registerBatchTag($request->uid, $companyId);

        Log::info("RFID /register → uid:{$request->uid} status:{$result['status']} ip:{$request->ip()}");

        return $this->hardwareResponse($result);
    }

    /**
     * LOGIN / CHECKPOINT IN: Scan karung saat masuk gudang/area.
     *
     * Skenario: Karung tiba di depan gudang, scan untuk catat kedatangan.
     *
     * POST /api/rfid/login
     * Body: { "uid": "A3B2C1D0", "api_token": "..." }
     *
     * Response LCD: name=BatchCode, message="Batch masuk OK"
     */
    public function loginCard(Request $request): JsonResponse
    {
        if (!$this->rfid->validateToken(
            $request->header('X-Rfid-Token') ?? $request->input('api_token', '')
        )) {
            return $this->errorResponse('Token tidak valid', 401);
        }

        $request->validate(['uid' => 'required|string|min:4|max:50']);

        $companyId = $this->resolveCompanyId($request);
        $result    = $this->rfid->checkpointIn($request->uid, $companyId);

        Log::info("RFID /login (CP-IN) → uid:{$request->uid} status:{$result['status']} ip:{$request->ip()}");

        return $this->hardwareResponse($result);
    }

    /**
     * LOGOUT / CHECKPOINT OUT: Scan karung saat keluar gudang.
     *
     * Skenario: Karung dipindahkan keluar gudang, scan di pintu keluar.
     *
     * POST /api/rfid/logout
     * Body: { "uid": "A3B2C1D0", "api_token": "..." }
     *
     * Response LCD: name=BatchCode, message="Batch keluar OK"
     */
    public function logoutCard(Request $request): JsonResponse
    {
        if (!$this->rfid->validateToken(
            $request->header('X-Rfid-Token') ?? $request->input('api_token', '')
        )) {
            return $this->errorResponse('Token tidak valid', 401);
        }

        $request->validate(['uid' => 'required|string|min:4|max:50']);

        $companyId = $this->resolveCompanyId($request);
        $result    = $this->rfid->checkpointOut($request->uid, $companyId);

        Log::info("RFID /logout (CP-OUT) → uid:{$request->uid} status:{$result['status']} ip:{$request->ip()}");

        return $this->hardwareResponse($result);
    }

    /**
     * Konfigurasi untuk ESP32 saat boot pertama kali.
     * ESP32 memanggil ini setelah connect WiFi untuk mendapatkan endpoint URLs.
     *
     * GET /api/v1/hardware/rfid/esp-config
     */
    public function espBootConfig(Request $request): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);
        $config    = $this->rfid->getEspBootConfig($companyId);

        return response()->json([
            'success' => true,
            'data'    => $config,
            'message' => 'Config loaded.',
        ])->withHeaders(self::CORS_HEADERS);
    }

    /**
     * Handle CORS preflight OPTIONS.
     */
    public function handleOptions(): JsonResponse
    {
        return response()->json(['ok' => true])->withHeaders(self::CORS_HEADERS);
    }

    // ═══════════════════════════════════════════════════════════════════
    // WEB UI ENDPOINTS — Dipanggil oleh RfidPanel.vue (Sanctum auth)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Dashboard status RFID — dipakai untuk polling di RfidPanel.
     *
     * GET /api/v1/rfid/status
     */
    public function getStatus(Request $request): JsonResponse
    {
        $companyId = Auth::user()?->company_id ?? self::DEFAULT_COMPANY_ID;

        return response()->json([
            'success' => true,
            'data'    => [
                'esp32_ip'       => $this->rfid->getEsp32Ip($companyId),
                'pending_batch'  => $this->rfid->getPendingBatch($companyId),
                'tagged_batches' => $this->rfid->getTaggedBatches($companyId),
                'recent_activity'=> $this->rfid->getRecentRfidActivity($companyId),
                'timestamp_wib'  => now()->timezone('Asia/Jakarta')->format('d/m/Y H:i:s') . ' WIB',
            ],
            'message' => 'OK',
        ]);
    }

    /**
     * Daftar batch yang BELUM punya RFID tag — untuk dropdown registrasi.
     *
     * GET /api/v1/rfid/batches
     */
    public function getUntaggedBatches(Request $request): JsonResponse
    {
        $companyId = Auth::user()?->company_id ?? self::DEFAULT_COMPANY_ID;

        return response()->json([
            'success' => true,
            'data'    => $this->rfid->getUntaggedBatches($companyId),
            'message' => 'OK',
        ]);
    }

    /**
     * Set batch yang akan menerima RFID tag berikutnya.
     * Dipanggil sebelum operator menempel tag ke karung.
     *
     * POST /api/v1/rfid/pending-batch
     * Body: { "batch_id": 42 }
     */
    public function setPendingBatch(Request $request): JsonResponse
    {
        abort_unless(
            Auth::user()->hasAnyRole(['company_admin', 'operator_lab_station', 'operator_wet']),
            403, 'Tidak memiliki akses untuk mendaftarkan RFID tag.'
        );

        $validated = $request->validate([
            'batch_id' => 'required|integer|exists:batches,id',
        ]);

        $companyId = Auth::user()->company_id;
        $batch     = Batch::where('company_id', $companyId)->findOrFail($validated['batch_id']);

        // Guard: jika sudah punya tag
        if ($batch->rfid_tag) {
            return response()->json([
                'success' => false,
                'message' => "Batch {$batch->batch_code} sudah memiliki RFID tag (UID: {$batch->rfid_tag}). Hapus tag lama terlebih dahulu.",
            ], 422);
        }

        $this->rfid->setPendingBatch($validated['batch_id'], $companyId, Auth::user()->name);

        return response()->json([
            'success'    => true,
            'message'    => "Sesi registrasi aktif untuk batch {$batch->batch_code} ({$batch->productCode?->code}). Tempelkan RFID tag dalam 90 detik.",
            'data'       => [
                'batch_id'   => $batch->id,
                'batch_code' => $batch->batch_code,
                'product'    => $batch->productCode?->code,
                'expires_in' => 90,
            ],
        ]);
    }

    /**
     * Batalkan sesi pending registrasi.
     *
     * DELETE /api/v1/rfid/pending-batch
     */
    public function cancelPendingBatch(Request $request): JsonResponse
    {
        $companyId = Auth::user()?->company_id ?? self::DEFAULT_COMPANY_ID;
        $this->rfid->cancelPendingBatch($companyId);

        return response()->json(['success' => true, 'message' => 'Sesi registrasi dibatalkan.']);
    }

    /**
     * Hapus RFID tag dari batch (unregister).
     *
     * DELETE /api/v1/rfid/batches/{batchId}/tag
     */
    public function removeTag(Request $request, int $batchId): JsonResponse
    {
        abort_unless(Auth::user()->hasAnyRole(['company_admin']), 403);

        $batch = Batch::where('company_id', Auth::user()->company_id)->findOrFail($batchId);
        $oldUid = $batch->rfid_tag;
        $batch->update(['rfid_tag' => null]);

        Log::info("RFID Tag dihapus: UID {$oldUid} dari batch {$batch->batch_code}");

        return response()->json([
            'success' => true,
            'message' => "RFID tag (UID: {$oldUid}) berhasil dihapus dari batch {$batch->batch_code}.",
        ]);
    }

    /**
     * Simpan IP address ESP32 ke CompanySetting.
     *
     * POST /api/v1/rfid/device
     * Body: { "esp32_ip": "http://192.168.1.50" }
     */
    public function saveDeviceIp(Request $request): JsonResponse
    {
        abort_unless(
            Auth::user()->hasAnyRole(['company_admin', 'operator_lab_station']),
            403
        );

        $validated = $request->validate([
            'esp32_ip' => 'required|string|max:100',
        ]);

        $companyId = Auth::user()->company_id;
        $this->rfid->saveEsp32Ip($companyId, $validated['esp32_ip']);

        $savedIp = $this->rfid->getEsp32Ip($companyId);

        return response()->json([
            'success' => true,
            'message' => "IP ESP32 disimpan: {$savedIp}",
            'data'    => ['esp32_ip' => $savedIp],
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════

    /** Response standar untuk ESP32 (selalu ada CORS headers) */
    private function hardwareResponse(array $result): JsonResponse
    {
        $httpStatus = $result['status'] === 'success' ? 200 : 422;
        return response()->json($result, $httpStatus)->withHeaders(self::CORS_HEADERS);
    }

    /** Response error untuk hardware */
    private function errorResponse(string $message, int $status = 422): JsonResponse
    {
        return response()->json([
            'status'  => 'failed',
            'name'    => 'Error',
            'message' => $message,
        ], $status)->withHeaders(self::CORS_HEADERS);
    }

    /** Resolve company_id dari header atau default */
    private function resolveCompanyId(Request $request): int
    {
        if ($code = $request->header('X-Company-Code')) {
            $company = \App\Models\Company::where('code', $code)->first();
            if ($company) return $company->id;
        }
        return self::DEFAULT_COMPANY_ID;
    }
}