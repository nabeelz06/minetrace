<?php

namespace App\Http\Controllers\Api\V1\Hardware;

use App\Exceptions\DeviceAuthException;
use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Services\Hardware\DeviceContext;
use App\Support\ProcessFlow;
use App\Services\Hardware\RfidAuthorizationService;
use App\Services\Hardware\RfidDeviceService;
use App\Services\Hardware\RfidRegistrationService;
use App\Services\Hardware\RfidScanService;
use App\Services\Hardware\RfidService;
use App\Services\Hardware\RfidTagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * ApiRfidController — endpoint perangkat (ESP32 legacy) + endpoint UI RFID lama.
 *
 * ════════════════════════════════════════════════════════════════════
 * KONTRAK PERANGKAT (TERKUNCI — firmware ESP32 fisik tidak ada di repo; hanya perubahan ADITIF;
 * dijaga tests/Feature/Rfid/LegacyDeviceContractTest):
 *   POST /api/rfid/register   → ikat UID ke batch pada sesi registrasi
 *   POST /api/rfid/login      → Scan IN
 *   POST /api/rfid/logout     → Scan OUT
 *   GET  /api/v1/hardware/rfid/esp-config → konfigurasi boot
 * Header: X-Rfid-Token (token global opsional), X-Company-Code (tenant),
 *         X-Device-Key (reader terdaftar; opsional, menggantikan token), X-Event-Id (idempotensi; opsional).
 * Respons gaya LCD 16×2: name (baris 1), message (baris 2), status success|failed, detail.
 *
 * Perilaku scan bergantung pada MODE OTORISASI per tahap (audit_only | scan_to_receive |
 * scan_to_dispatch) — lihat RfidAuthorizationService. Orkestrasi: RfidScanService.
 *
 * ENDPOINT UI LAMA (sesi): /api/v1/company/rfid/* — dipertahankan untuk kompatibilitas;
 * UI baru memakai Api\V1\Rfid\* (reader, sesi registrasi, umpan scan, siklus hidup tag).
 * ════════════════════════════════════════════════════════════════════
 */
class ApiRfidController extends Controller
{
    private const CORS_HEADERS = [
        'Access-Control-Allow-Origin'  => '*',
        'Access-Control-Allow-Methods' => 'GET, POST, DELETE, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type, X-Rfid-Token, X-Company-Code, X-Device-Key, X-Event-Id, Authorization',
    ];

    public function __construct(
        private RfidService              $rfid,
        private RfidAuthorizationService $authorization,
        private RfidDeviceService        $devices,
        private RfidRegistrationService  $registration,
        private RfidScanService          $scans,
        private RfidTagService           $tags,
    ) {}

    // ═══════════════════════════════════════════════════════════════════
    // HARDWARE ENDPOINTS — Dipanggil ESP32 Arduino
    // ═══════════════════════════════════════════════════════════════════

    /** REGISTER: tempel tag ke karung → ikat ke batch pada sesi registrasi. POST /api/rfid/register */
    public function registerCard(Request $request): JsonResponse
    {
        if ($error = $this->guardDevice($request, $ctx)) {
            return $error;
        }
        if ($error = $this->validateUid($request, 'register')) {
            return $error;
        }

        $uid    = $this->rfid->sanitizeUid($request->uid);
        $origin = $this->resolveOrigin($request, $ctx);

        // Urutan lama dipertahankan: "tag sudah dipakai" dicek sebelum "tidak ada sesi".
        if ($taken = $this->registration->findTagOwner($uid)) {
            $result = $this->registration->tagTakenResult($taken, $ctx->companyId);
        } elseif (! ($session = $this->registration->findForDevice($ctx))) {
            $result = $this->registration->noSessionResult();
        } else {
            $result = $this->registration->commit($session, $uid, $origin, $ctx->reader);
            if ($ctx->reader) {
                $this->devices->touch($ctx->reader, $request->ip());
            }
        }

        Log::info("RFID /register → uid:{$uid} status:{$result['status']} identity:{$ctx->identity} ip:{$request->ip()}");

        return $this->hardwareResponse($result);
    }

    /** SCAN IN. POST /api/rfid/login  Body: { uid, stage?, event_id? } */
    public function loginCard(Request $request): JsonResponse
    {
        return $this->handleScan($request, 'in');
    }

    /** SCAN OUT. POST /api/rfid/logout  Body: { uid, stage?, event_id? } */
    public function logoutCard(Request $request): JsonResponse
    {
        return $this->handleScan($request, 'out');
    }

    private function handleScan(Request $request, string $direction): JsonResponse
    {
        if ($error = $this->guardDevice($request, $ctx)) {
            return $error;
        }
        if ($error = $this->validateUid($request, $direction === 'in' ? 'login' : 'logout')) {
            return $error;
        }

        [$result, $duplicate] = $this->scans->scan(
            $ctx, $direction, $request->uid, $request->input('stage'),
            [
                'origin'   => $this->resolveOrigin($request, $ctx),
                'event_id' => $this->eventId($request),
                'ip'       => $request->ip(),
            ]
        );

        $response = $this->hardwareResponse($result);
        if ($duplicate) {
            // Header (bukan field JSON) agar muatan yang dibaca firmware identik dengan respons pertama.
            $response->headers->set('X-Rfid-Duplicate', '1');
        }

        return $response;
    }

    /** ESP32 Boot Config */
    public function espBootConfig(Request $request): JsonResponse
    {
        try {
            $ctx = $this->devices->resolveContext($request);
        } catch (DeviceAuthException $e) {
            return $this->errorResponse($e->getMessage(), $e->httpStatus);
        }

        return response()->json([
            'success' => true,
            'data'    => $this->rfid->getEspBootConfig($ctx->companyId),
        ])->withHeaders(self::CORS_HEADERS);
    }

    /**
     * HEARTBEAT reader terdaftar. POST /api/rfid/heartbeat  (wajib X-Device-Key)
     * Body (semua opsional): { firmware, uptime_s, rssi, free_heap, queued, device_time }
     * Respons membawa waktu server (UTC) agar perangkat dapat menyinkronkan jam.
     */
    public function heartbeat(Request $request): JsonResponse
    {
        try {
            $ctx = $this->devices->resolveContext($request);
        } catch (DeviceAuthException $e) {
            return $this->errorResponse($e->getMessage(), $e->httpStatus);
        }
        if (! $ctx->reader) {
            return $this->errorResponse('Heartbeat butuh X-Device-Key', 401);
        }

        $payload = $request->validate([
            'firmware'    => 'nullable|string|max:40',
            'uptime_s'    => 'nullable|integer|min:0',
            'rssi'        => 'nullable|integer|between:-130,0',
            'free_heap'   => 'nullable|integer|min:0',
            'queued'      => 'nullable|integer|min:0',
            'device_time' => 'nullable|integer|min:0',
        ]);

        return response()->json($this->devices->heartbeat($ctx->reader, $payload, $request->ip()))
            ->withHeaders(self::CORS_HEADERS);
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
        Gate::authorize('rfid.view');
        $companyId = $this->companyId();

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
        Gate::authorize('rfid.view');
        $companyId = $this->companyId();
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
        Gate::authorize('rfid.operate');

        $validated = $request->validate([
            // Accept BOTH old names (login/logout) dan new names (scan_in/scan_out)
            // untuk backward compatibility dengan ESP32 firmware dan kode lama
            'mode'  => 'required|string|in:scan_in,scan_out,login,logout,register,idle',
            'stage' => 'required_unless:mode,idle|nullable|string|in:' . implode(',', ProcessFlow::rfidStages()),
        ]);

        // ─── Normalize mode name ────────────────────────────────────────────────
        // login → scan_in, logout → scan_out (internal naming canonical)
        $mode = match($validated['mode']) {
            'login'  => 'scan_in',
            'logout' => 'scan_out',
            default  => $validated['mode'],
        };

        $companyId = $this->companyId();

        if ($mode === 'idle') {
            $clearStage = $validated['stage'] ?? null;
            $this->rfid->clearActiveMode($companyId, $clearStage);
            return response()->json([
                'success' => true,
                'message' => $clearStage
                    ? "Mode RFID untuk stage {$clearStage} direset ke idle."
                    : 'Semua mode RFID direset ke idle.',
                'data'    => ['mode' => 'idle', 'stage' => $clearStage],
            ]);
        }

        $stage = $validated['stage'];
        $this->rfid->setActiveMode($mode, $stage, $companyId);

        $stageLabels = array_map(fn ($f) => $f['label'], ProcessFlow::rfidFlow());
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
        Gate::authorize('rfid.view');
        $companyId  = $this->companyId();
        $stage      = $request->query('stage');
        $activeMode = $this->rfid->getActiveMode($companyId, $stage ?: null);
        $allModes   = $this->rfid->getAllActiveModes($companyId);

        return response()->json([
            'success'   => true,
            'data'      => $activeMode,
            'all_modes' => $allModes,
            'rfid_mode' => 'manual',
        ]);
    }

    /** Daftar stage tersedia */
    public function getAvailableStages(Request $request): JsonResponse
    {
        Gate::authorize('rfid.view');
        return response()->json([
            'success' => true,
            'data'    => $this->rfid->getAvailableStages(),
        ]);
    }

    /** Set pending batch untuk register */
    public function setPendingBatch(Request $request): JsonResponse
    {
        Gate::authorize('rfid.operate');

        $validated = $request->validate([
            'batch_id' => 'required|integer|exists:batches,id',
        ]);

        $companyId = $this->companyId();
        $batch     = Batch::with('productCode')->where('company_id', $companyId)->findOrFail($validated['batch_id']);

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
        Gate::authorize('rfid.operate');
        $companyId = $this->companyId();
        $this->rfid->cancelPendingBatch($companyId);
        return response()->json(['success' => true, 'message' => 'Sesi registrasi dibatalkan.']);
    }

    /** Hapus RFID tag dari batch (jalur lama) — kini tercatat di rfid_tag_events (alasan: other). */
    public function removeTag(Request $request, int $batchId): JsonResponse
    {
        Gate::authorize('rfid.operate');

        $batch = Batch::where('company_id', $this->companyId())->findOrFail($batchId);

        try {
            $oldUid = $this->tags->release($batch, Auth::user(), 'other', 'Dilepas via panel lama');
        } catch (\DomainException) {
            return response()->json(['success' => false, 'message' => 'Batch ini tidak memiliki RFID tag.'], 422);
        }

        return response()->json([
            'success' => true,
            'message' => "RFID tag (UID: {$oldUid}) berhasil dihapus dari batch {$batch->batch_code}.",
        ]);
    }

    /** Simpan IP ESP32 */
    public function saveDeviceIp(Request $request): JsonResponse
    {
        Gate::authorize('rfid.manage');

        $validated = $request->validate(['esp32_ip' => 'required|string|max:100']);

        $companyId = $this->companyId();
        $this->rfid->saveEsp32Ip($companyId, $validated['esp32_ip']);
        $savedIp   = $this->rfid->getEsp32Ip($companyId);

        return response()->json([
            'success' => true,
            'message' => "IP ESP32 disimpan: {$savedIp}",
            'data'    => ['esp32_ip' => $savedIp],
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // PROGRESSIVE AUTHORIZATION MODE
    // ═══════════════════════════════════════════════════════════════════

    /**
     * GET /api/v1/company/rfid/authorization-modes
     * Semua mode authorization per stage untuk company ini.
     */
    public function getAuthorizationModes(Request $request): JsonResponse
    {
        Gate::authorize('rfid.view');
        $companyId = $this->companyId();

        return response()->json([
            'success'      => true,
            'data'         => $this->authorization->getAllModes($companyId),
            'valid_modes'  => $this->authorization->getValidModes(),
        ]);
    }

    /**
     * POST /api/v1/company/rfid/authorization-modes
     * Set authorization mode untuk stage tertentu. company_admin only.
     */
    public function setAuthorizationMode(Request $request): JsonResponse
    {
        Gate::authorize('rfid.manage');

        $validated = $request->validate([
            'stage' => 'required|string|in:' . implode(',', ProcessFlow::rfidStages()),
            'mode'  => 'required|string|in:audit_only,scan_to_receive,scan_to_dispatch',
        ]);

        $companyId = $this->companyId();

        $this->authorization->setMode(
            $companyId,
            $validated['stage'],
            $validated['mode'],
            Auth::id()
        );

        return response()->json([
            'success' => true,
            'message' => "Mode RFID untuk stage {$validated['stage']} diubah ke {$validated['mode']}.",
            'data'    => $this->authorization->getAllModes($companyId),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════

    private function hardwareResponse(array $result): JsonResponse
    {
        $httpStatus = $result['status'] === 'success' ? 200 : 422;
        // Kunci internal (awalan '_', mis. _code untuk UI) tidak pernah dikirim ke perangkat.
        $result = array_filter($result, fn ($key) => ! str_starts_with((string) $key, '_'), ARRAY_FILTER_USE_KEY);
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

    /** Tenant pengguna sesi. Tanpa company (super_admin/BIM) = tidak berhak (tidak ada fallback ke id=1). */
    private function companyId(): int
    {
        $companyId = Auth::user()?->company_id;
        abort_if(! $companyId, 403, 'Akun ini tidak terikat perusahaan.');

        return (int) $companyId;
    }

    /**
     * Autentikasi perangkat + token global. Mengisi $ctx; mengembalikan JsonResponse bila gagal.
     * Reader ber-kunci tidak memerlukan RFID_API_TOKEN (kunci perangkat menggantikannya).
     */
    private function guardDevice(Request $request, ?DeviceContext &$ctx): ?JsonResponse
    {
        try {
            $ctx = $this->devices->resolveContext($request);
        } catch (DeviceAuthException $e) {
            return $this->errorResponse($e->getMessage(), $e->httpStatus);
        }

        if (! $ctx->reader && ! $this->rfid->validateToken($request->header('X-Rfid-Token') ?? $request->input('api_token', ''))) {
            return $this->errorResponse('Token tidak valid', 401);
        }

        return null;
    }

    /** Normalisasi + validasi UID (pesan & status 422 identik perilaku lama). */
    private function validateUid(Request $request, string $endpoint): ?JsonResponse
    {
        $request->merge(['uid' => strtoupper(str_replace([' ', ':', '-'], '', trim((string) $request->input('uid', ''))))]);

        try {
            $request->validate(['uid' => 'required|string|min:4|max:50']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning("RFID /{$endpoint} → validasi gagal ip:{$request->ip()}", ['errors' => $e->errors()]);
            return $this->errorResponse('UID tidak valid atau kosong', 422);
        }

        return null;
    }

    /** event_id idempotensi dari body atau header X-Event-Id; format tidak valid diabaikan. */
    private function eventId(Request $request): ?string
    {
        $id = (string) ($request->input('event_id') ?: $request->header('X-Event-Id', ''));

        return preg_match('/^[A-Za-z0-9._:\-]{8,64}$/', $id) ? $id : null;
    }

    /**
     * Asal scan. Reader terdaftar: dari jenis perangkat. Perangkat legacy: perilaku lama
     * (browser mengirim X-Requested-With → simulasi; firmware ESP32 tidak).
     */
    private function resolveOrigin(Request $request, DeviceContext $ctx): string
    {
        if ($ctx->reader) {
            return match (true) {
                str_starts_with($ctx->reader->device_type, 'esp32') => 'real_esp32',
                $ctx->reader->device_type === 'uhf_epc_gen2'         => 'real_uhf',
                $ctx->reader->device_type === 'keyboard_wedge'       => 'manual',
                default                                              => 'api',
            };
        }

        return ($request->hasHeader('X-Requested-With') || $request->hasHeader('X-CSRF-TOKEN'))
            ? 'simulation'
            : 'real_esp32';
    }
}
