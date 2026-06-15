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
 * ApiRfidController — API endpoint RFID ESP32 + Web UI.
 *
 * ════════════════════════════════════════════════════════════════════
 * HARDWARE ENDPOINTS (POST body: { uid, stage?, api_token? }):
 *   POST /api/rfid/register   → Link UID ke batch pending (Lab Kimia)
 *   POST /api/rfid/login      → Auto-terima batch di stage (MASUK)
 *   POST /api/rfid/logout     → Catat batch keluar stage (KELUAR)
 *
 * WEB UI ENDPOINTS (session auth Inertia):
 *   GET    /api/v1/company/rfid/status         → Dashboard status
 *   GET    /api/v1/company/rfid/batches        → Batch belum ter-tag
 *   POST   /api/v1/company/rfid/pending-batch  → Set pending register
 *   DELETE /api/v1/company/rfid/pending-batch  → Cancel pending
 *   POST   /api/v1/company/rfid/set-mode       → Set active mode+stage
 *   GET    /api/v1/company/rfid/active-mode    → Cek mode aktif
 *   GET    /api/v1/company/rfid/stages         → Daftar stage tersedia
 *   DELETE /api/v1/company/rfid/batches/{id}/tag → Hapus RFID tag
 *   POST   /api/v1/company/rfid/device         → Simpan IP ESP32
 *
 * FORMAT RESPONSE untuk LCD 16×2 ESP32:
 *   name    → LCD baris 1 (max 16 char)
 *   message → LCD baris 2 (max 16 char)
 *   status  → "success" | "failed"
 * ════════════════════════════════════════════════════════════════════
 */
class ApiRfidController extends Controller
{
    private const DEFAULT_COMPANY_ID = 1;

    private const CORS_HEADERS = [
        'Access-Control-Allow-Origin'  => '*',
        'Access-Control-Allow-Methods' => 'GET, POST, DELETE, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type, X-Rfid-Token, Authorization',
    ];

    public function __construct(
        private RfidService $rfid
    ) {}

    // ═══════════════════════════════════════════════════════════════════
    // HARDWARE ENDPOINTS — Dipanggil ESP32 Arduino
    // ═══════════════════════════════════════════════════════════════════

    /**
     * REGISTER: Tempel tag RFID ke karung → link ke batch pending.
     *
     * POST /api/rfid/register
     * Body: { "uid": "A3B2C1D0", "api_token": "" }
     */
    public function registerCard(Request $request): JsonResponse
    {
        if (!$this->rfid->validateToken(
            $request->header('X-Rfid-Token') ?? $request->input('api_token', '')
        )) {
            return $this->errorResponse('Token tidak valid', 401);
        }

        // Sanitasi UID (handle spasi dari RC522)
        $request->merge([
            'uid' => strtoupper(str_replace([' ', ':', '-'], '', trim($request->input('uid', ''))))
        ]);
        $request->validate(['uid' => 'required|string|min:4|max:50']);

        $companyId = $this->resolveCompanyId($request);
        $result    = $this->rfid->registerBatchTag($request->uid, $companyId);

        Log::info("RFID /register → uid:{$request->uid} status:{$result['status']} ip:{$request->ip()}");

        return $this->hardwareResponse($result);
    }

    /**
     * LOGIN / CHECKPOINT IN: Scan karung saat MASUK stage.
     *
     * PERUBAHAN UTAMA: Batch sekarang AUTO-DIPINDAH ke stage tujuan.
     * Stage ditentukan dari: body.stage > cache active_mode > batch stage saat ini
     *
     * POST /api/rfid/login
     * Body: { "uid": "A3B2C1D0", "stage": "wet_process", "api_token": "" }
     *       stage opsional — jika tidak ada, dibaca dari cache
     */
    public function loginCard(Request $request): JsonResponse
    {
        if (!$this->rfid->validateToken(
            $request->header('X-Rfid-Token') ?? $request->input('api_token', '')
        )) {
            return $this->errorResponse('Token tidak valid', 401);
        }

        $request->merge([
            'uid' => strtoupper(str_replace([' ', ':', '-'], '', trim($request->input('uid', ''))))
        ]);
        $request->validate(['uid' => 'required|string|min:4|max:50']);

        $companyId = $this->resolveCompanyId($request);

        // Stage dari request body (ESP32 firmware baru)
        // Jika tidak ada → RfidService akan baca dari cache active_mode
        $scanStage = $request->input('stage');

        $result = $this->rfid->checkpointIn($request->uid, $companyId, $scanStage);

        Log::info("RFID /login (IN) → uid:{$request->uid} stage:{$scanStage} status:{$result['status']} ip:{$request->ip()}");

        return $this->hardwareResponse($result);
    }

    /**
     * LOGOUT / CHECKPOINT OUT: Scan karung saat KELUAR stage.
     *
     * POST /api/rfid/logout
     * Body: { "uid": "A3B2C1D0", "stage": "wet_process", "api_token": "" }
     */
    public function logoutCard(Request $request): JsonResponse
    {
        if (!$this->rfid->validateToken(
            $request->header('X-Rfid-Token') ?? $request->input('api_token', '')
        )) {
            return $this->errorResponse('Token tidak valid', 401);
        }

        $request->merge([
            'uid' => strtoupper(str_replace([' ', ':', '-'], '', trim($request->input('uid', ''))))
        ]);
        $request->validate(['uid' => 'required|string|min:4|max:50']);

        $companyId = $this->resolveCompanyId($request);
        $scanStage = $request->input('stage');

        $result = $this->rfid->checkpointOut($request->uid, $companyId, $scanStage);

        Log::info("RFID /logout (OUT) → uid:{$request->uid} stage:{$scanStage} status:{$result['status']} ip:{$request->ip()}");

        return $this->hardwareResponse($result);
    }

    /** ESP32 Boot Config — dipanggil saat ESP32 startup */
    public function espBootConfig(Request $request): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);
        return response()->json([
            'success' => true,
            'data'    => $this->rfid->getEspBootConfig($companyId),
        ])->withHeaders(self::CORS_HEADERS);
    }

    /** CORS preflight */
    public function handleOptions(): JsonResponse
    {
        return response()->json(['ok' => true])->withHeaders(self::CORS_HEADERS);
    }

    // ═══════════════════════════════════════════════════════════════════
    // WEB UI ENDPOINTS — Session auth (Inertia.js)
    // ═══════════════════════════════════════════════════════════════════

    /** Status dashboard RFID */
    public function getStatus(Request $request): JsonResponse
    {
        $companyId = Auth::user()?->company_id ?? self::DEFAULT_COMPANY_ID;

        return response()->json([
            'success' => true,
            'data'    => [
                'esp32_ip'       => $this->rfid->getEsp32Ip($companyId),
                'active_mode'    => $this->rfid->getActiveMode($companyId),
                'pending_batch'  => $this->rfid->getPendingBatch($companyId),
                'tagged_batches' => $this->rfid->getTaggedBatches($companyId),
                'recent_activity'=> $this->rfid->getRecentRfidActivity($companyId),
                'timestamp_wib'  => now()->timezone('Asia/Jakarta')->format('d/m/Y H:i:s') . ' WIB',
            ],
        ]);
    }

    /** Daftar batch belum ter-tag (dropdown register) */
    public function getUntaggedBatches(Request $request): JsonResponse
    {
        $companyId = Auth::user()?->company_id ?? self::DEFAULT_COMPANY_ID;
        return response()->json([
            'success' => true,
            'data'    => $this->rfid->getUntaggedBatches($companyId),
        ]);
    }

    /**
     * SET ACTIVE MODE — operator klik Login/Logout mode di web panel.
     *
     * FIX: Mode + stage di-cache sehingga ESP32 tahu di stage mana scan terjadi.
     *
     * POST /api/v1/company/rfid/set-mode
     * Body: { "mode": "login", "stage": "wet_process" }
     */
    public function setActiveMode(Request $request): JsonResponse
    {
        abort_unless(Auth::user()->hasAnyRole([
            'company_admin', 'operator_lab_station', 'operator_wet',
            'operator_dry', 'operator_warehouse', 'operator_lab_ltj',
        ]), 403, 'Tidak memiliki akses.');

        $validated = $request->validate([
            'mode'  => 'required|string|in:login,logout,register,idle',
            'stage' => 'required_unless:mode,idle|nullable|string|in:receiving,wet_process,dry_process,warehouse,project_plant',
        ]);

        $companyId = Auth::user()->company_id;

        if ($validated['mode'] === 'idle') {
            $this->rfid->clearActiveMode($companyId);
            return response()->json([
                'success' => true,
                'message' => 'Mode direset ke idle.',
                'data'    => ['mode' => 'idle', 'stage' => null],
            ]);
        }

        $stage = $validated['stage'];
        $this->rfid->setActiveMode($validated['mode'], $stage, $companyId);

        $stageLabels = [
            'receiving'     => 'Receiving Area',
            'wet_process'   => 'Wet Process',
            'dry_process'   => 'Dry Process',
            'warehouse'     => 'LTJ Warehouse',
            'project_plant' => 'Lab Analisis LTJ',
        ];
        $stageLabel = $stageLabels[$stage] ?? $stage;
        $modeLabel  = match($validated['mode']) {
            'login'  => 'LOGIN (Masuk)',
            'logout' => 'LOGOUT (Keluar)',
            default  => strtoupper($validated['mode']),
        };

        return response()->json([
            'success' => true,
            'message' => "Mode {$modeLabel} aktif untuk {$stageLabel}. ESP32 siap scan.",
            'data'    => [
                'mode'   => $validated['mode'],
                'stage'  => $stage,
                'label'  => $stageLabel,
            ],
        ]);
    }

    /** GET active mode untuk polling dari Vue */
    public function getActiveModeStatus(Request $request): JsonResponse
    {
        $companyId  = Auth::user()?->company_id ?? self::DEFAULT_COMPANY_ID;
        $activeMode = $this->rfid->getActiveMode($companyId);

        return response()->json([
            'success' => true,
            'data'    => $activeMode,
        ]);
    }

    /** Daftar stage yang tersedia untuk dropdown di Vue */
    public function getAvailableStages(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->rfid->getAvailableStages(),
        ]);
    }

    /** Set pending batch untuk register */
    public function setPendingBatch(Request $request): JsonResponse
    {
        abort_unless(Auth::user()->hasAnyRole([
            'company_admin', 'operator_lab_station', 'operator_lab_ltj', 'operator_wet',
        ]), 403, 'Tidak memiliki akses untuk mendaftarkan RFID tag.');

        $validated = $request->validate([
            'batch_id' => 'required|integer|exists:batches,id',
        ]);

        $companyId = Auth::user()->company_id;
        $batch     = Batch::where('company_id', $companyId)->findOrFail($validated['batch_id']);

        if ($batch->rfid_tag) {
            return response()->json([
                'success' => false,
                'message' => "Batch {$batch->batch_code} sudah memiliki RFID tag (UID: {$batch->rfid_tag}). Hapus tag lama terlebih dahulu.",
            ], 422);
        }

        $this->rfid->setPendingBatch($validated['batch_id'], $companyId, Auth::user()->name);

        return response()->json([
            'success' => true,
            'message' => "Sesi registrasi aktif untuk batch {$batch->batch_code} ({$batch->productCode?->code}). Tempelkan RFID tag dalam 90 detik.",
            'data'    => [
                'batch_id'   => $batch->id,
                'batch_code' => $batch->batch_code,
                'product'    => $batch->productCode?->code,
                'expires_in' => 90,
            ],
        ]);
    }

    /** Cancel pending batch */
    public function cancelPendingBatch(Request $request): JsonResponse
    {
        $companyId = Auth::user()?->company_id ?? self::DEFAULT_COMPANY_ID;
        $this->rfid->cancelPendingBatch($companyId);
        return response()->json(['success' => true, 'message' => 'Sesi registrasi dibatalkan.']);
    }

    /** Hapus RFID tag dari batch */
    public function removeTag(Request $request, int $batchId): JsonResponse
    {
        abort_unless(Auth::user()->hasAnyRole(['company_admin']), 403);

        $batch  = Batch::where('company_id', Auth::user()->company_id)->findOrFail($batchId);
        $oldUid = $batch->rfid_tag;
        $batch->update(['rfid_tag' => null]);

        Log::info("RFID Tag dihapus: UID {$oldUid} dari batch {$batch->batch_code}");

        return response()->json([
            'success' => true,
            'message' => "RFID tag (UID: {$oldUid}) berhasil dihapus dari batch {$batch->batch_code}.",
        ]);
    }

    /** Simpan IP ESP32 */
    public function saveDeviceIp(Request $request): JsonResponse
    {
        abort_unless(Auth::user()->hasAnyRole(['company_admin', 'operator_lab_station', 'operator_wet']), 403);

        $validated = $request->validate(['esp32_ip' => 'required|string|max:100']);

        $companyId = Auth::user()->company_id;
        $this->rfid->saveEsp32Ip($companyId, $validated['esp32_ip']);
        $savedIp   = $this->rfid->getEsp32Ip($companyId);

        return response()->json([
            'success' => true,
            'message' => "IP ESP32 disimpan: {$savedIp}",
            'data'    => ['esp32_ip' => $savedIp],
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════

    private function hardwareResponse(array $result): JsonResponse
    {
        $httpStatus = $result['status'] === 'success' ? 200 : 422;
        return response()->json($result, $httpStatus)->withHeaders(self::CORS_HEADERS);
    }

    private function errorResponse(string $message, int $status = 422): JsonResponse
    {
        return response()->json([
            'status'  => 'failed',
            'name'    => 'Error',
            'message' => $message,
        ], $status)->withHeaders(self::CORS_HEADERS);
    }

    private function resolveCompanyId(Request $request): int
    {
        if ($code = $request->header('X-Company-Code')) {
            $company = \App\Models\Company::where('code', $code)->first();
            if ($company) return $company->id;
        }
        return self::DEFAULT_COMPANY_ID;
    }
}