<?php

namespace App\Http\Controllers\Api\V1\Hardware;

use App\Http\Controllers\Controller;
use App\Jobs\CaptureCctvSnapshotJob;
use App\Models\Batch;
use App\Models\Checkpoint;
use App\Services\Hardware\RfidService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * ApiRfidController — API endpoint RFID ESP32 + Web UI.
 *
 * ════════════════════════════════════════════════════════════════════
 * MODE MANUAL — RFID sebagai audit trail saja.
 *
 * HARDWARE ENDPOINTS (POST body: { uid, stage?, api_token? }):
 *   POST /api/rfid/register   → Link UID ke batch pending (Lab Kimia)
 *   POST /api/rfid/login      → Catat Scan IN (audit trail, NO stage move)
 *   POST /api/rfid/logout     → Catat Scan OUT (audit trail, NO status change)
 *
 * WEB UI ENDPOINTS (session auth):
 *   GET    /api/v1/company/rfid/status         → Dashboard status
 *   GET    /api/v1/company/rfid/batches        → Batch belum ter-tag
 *   POST   /api/v1/company/rfid/pending-batch  → Set pending register
 *   DELETE /api/v1/company/rfid/pending-batch  → Cancel pending
 *   POST   /api/v1/company/rfid/set-mode       → Set scan mode + stage
 *   GET    /api/v1/company/rfid/active-mode    → Cek mode aktif
 *   GET    /api/v1/company/rfid/stages         → Daftar stage tersedia
 *   DELETE /api/v1/company/rfid/batches/{id}/tag → Hapus RFID tag
 *   POST   /api/v1/company/rfid/device         → Simpan IP ESP32
 *
 * FORMAT RESPONSE untuk LCD 16×2 ESP32:
 *   name    → LCD baris 1 (max 16 char)
 *   message → LCD baris 2 (max 16 char): "Scan IN OK" / "Scan OUT OK"
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
     * POST /api/rfid/register
     */
    public function registerCard(Request $request): JsonResponse
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
        $result    = $this->rfid->registerBatchTag($request->uid, $companyId);

        Log::info("RFID /register → uid:{$request->uid} status:{$result['status']} ip:{$request->ip()}");

        return $this->hardwareResponse($result);
    }

    /**
     * SCAN IN: Rekam scan masuk area — AUDIT TRAIL ONLY.
     *
     * ✅ MANUAL MODE: Tidak ada perpindahan stage otomatis.
     * Hanya mencatat Checkpoint dengan rfid_scan_code.
     * Operator konfirmasi penerimaan via web panel.
     *
     * POST /api/rfid/login
     * Body: { "uid": "A3B2C1D0", "stage": "wet_process" }
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
        $scanStage = $request->input('stage');

        $result = $this->rfid->checkpointIn($request->uid, $companyId, $scanStage);

        Log::info("RFID /login (SCAN IN, audit only) → uid:{$request->uid} stage:{$scanStage} status:{$result['status']} ip:{$request->ip()}");

        if ($result['status'] === 'success' && !empty($result['batch_id'])) {
            $checkpoint = Checkpoint::where('batch_id', $result['batch_id'])
                ->latest('id')->value('id');
            if ($checkpoint) {
                CaptureCctvSnapshotJob::dispatch($result['batch_id'], $checkpoint, 'rfid_scan_in')
                    ->delay(now()->addSeconds(config('cctv.snapshot_delay', 2)));
            }
        }

        return $this->hardwareResponse($result);
    }

    /**
     * SCAN OUT: Rekam scan keluar area — AUDIT TRAIL ONLY.
     *
     * ✅ MANUAL MODE: Tidak ada perubahan status batch.
     * Hanya mencatat Checkpoint dengan rfid_scan_code.
     * Operator kirim batch ke stage berikutnya via web panel.
     *
     * POST /api/rfid/logout
     * Body: { "uid": "A3B2C1D0", "stage": "wet_process" }
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

        Log::info("RFID /logout (SCAN OUT, audit only) → uid:{$request->uid} stage:{$scanStage} status:{$result['status']} ip:{$request->ip()}");

        if ($result['status'] === 'success' && !empty($result['batch_id'])) {
            $checkpoint = Checkpoint::where('batch_id', $result['batch_id'])
                ->latest('id')->value('id');
            if ($checkpoint) {
                CaptureCctvSnapshotJob::dispatch($result['batch_id'], $checkpoint, 'rfid_scan_out')
                    ->delay(now()->addSeconds(config('cctv.snapshot_delay', 2)));
            }
        }

        return $this->hardwareResponse($result);
    }

    /** ESP32 Boot Config */
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
    // WEB UI ENDPOINTS
    // ═══════════════════════════════════════════════════════════════════

    /** Status dashboard RFID */
    public function getStatus(Request $request): JsonResponse
    {
        $companyId = Auth::user()?->company_id ?? self::DEFAULT_COMPANY_ID;

        return response()->json([
            'success' => true,
            'data'    => [
                'esp32_ip'        => $this->rfid->getEsp32Ip($companyId),
                'active_mode'     => $this->rfid->getActiveMode($companyId),
                'pending_batch'   => $this->rfid->getPendingBatch($companyId),
                'tagged_batches'  => $this->rfid->getTaggedBatches($companyId),
                'recent_activity' => $this->rfid->getRecentRfidActivity($companyId),
                'rfid_mode'       => 'manual',
                'timestamp_wib'   => now()->timezone('Asia/Jakarta')->format('d/m/Y H:i:s') . ' WIB',
            ],
        ]);
    }

    /** Daftar batch belum ter-tag */
    public function getUntaggedBatches(Request $request): JsonResponse
    {
        $companyId = Auth::user()?->company_id ?? self::DEFAULT_COMPANY_ID;
        return response()->json([
            'success' => true,
            'data'    => $this->rfid->getUntaggedBatches($companyId),
        ]);
    }

    /**
     * SET SCAN MODE — Operator set mode + stage di web panel.
     *
     * Mode digunakan untuk:
     * - Menampilkan label yang benar di LCD ESP32
     * - Menentukan checkpoint_stage ENUM untuk audit log
     *
     * Mode TIDAK menentukan perpindahan stage batch.
     *
     * POST /api/v1/company/rfid/set-mode
     * Body: { "mode": "scan_in", "stage": "wet_process" }
     */
    public function setActiveMode(Request $request): JsonResponse
    {
        abort_unless(Auth::user()->hasAnyRole([
            'company_admin', 'operator_lab_station', 'operator_wet',
            'operator_dry', 'operator_warehouse', 'operator_lab_ltj',
        ]), 403, 'Tidak memiliki akses.');

        $validated = $request->validate([
            // Accept BOTH old names (login/logout) dan new names (scan_in/scan_out)
            // untuk backward compatibility dengan ESP32 firmware dan kode lama
            'mode'  => 'required|string|in:scan_in,scan_out,login,logout,register,idle',
            'stage' => 'required_unless:mode,idle|nullable|string|in:receiving,wet_process,dry_process,warehouse,project_plant',
        ]);

        // ─── Normalize mode name ────────────────────────────────────────────────
        // login → scan_in, logout → scan_out (internal naming canonical)
        $mode = match($validated['mode']) {
            'login'  => 'scan_in',
            'logout' => 'scan_out',
            default  => $validated['mode'],
        };

        $companyId = Auth::user()->company_id;

        if ($mode === 'idle') {
            $this->rfid->clearActiveMode($companyId);
            return response()->json([
                'success' => true,
                'message' => 'Mode RFID direset ke idle.',
                'data'    => ['mode' => 'idle', 'stage' => null],
            ]);
        }

        $stage = $validated['stage'];
        $this->rfid->setActiveMode($mode, $stage, $companyId);

        $stageLabels = [
            'receiving'     => 'Receiving Area',
            'wet_process'   => 'Wet Process',
            'dry_process'   => 'Dry Process',
            'warehouse'     => 'LTJ Warehouse',
            'project_plant' => 'Lab Analisis LTJ',
        ];
        $stageLabel = $stageLabels[$stage] ?? $stage;
        $modeLabel  = match($mode) {
            'scan_in'  => 'SCAN IN (Karung Masuk)',
            'scan_out' => 'SCAN OUT (Karung Keluar)',
            default    => strtoupper($mode),
        };

        return response()->json([
            'success' => true,
            'message' => "Mode {$modeLabel} aktif untuk {$stageLabel}. ESP32 siap merekam scan.",
            'data'    => [
                'mode'        => $mode,
                'stage'       => $stage,
                'label'       => $stageLabel,
                'rfid_mode'   => 'manual',
                'description' => 'Scan RFID hanya merekam audit trail. Perpindahan stage dilakukan manual via web panel.',
            ],
        ]);
    }

    /** GET active mode untuk polling dari Vue */
    public function getActiveModeStatus(Request $request): JsonResponse
    {
        $companyId  = Auth::user()?->company_id ?? self::DEFAULT_COMPANY_ID;
        $activeMode = $this->rfid->getActiveMode($companyId);

        return response()->json([
            'success'   => true,
            'data'      => $activeMode,
            'rfid_mode' => 'manual',
        ]);
    }

    /** Daftar stage tersedia */
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
            'company_admin', 'operator_lab_station', 'operator_lab_ltj',
            'operator_wet', 'operator_dry', 'operator_warehouse',
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
        abort_unless(Auth::user()->hasAnyRole([
            'company_admin', 'operator_lab_station', 'operator_wet',
        ]), 403);

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