<?php

namespace App\Http\Controllers\Api\V1\Hardware;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Hardware\RfidService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * ApiRfidController — Endpoint API untuk integrasi hardware RFID ESP32.
 *
 * ════════════════════════════════════════════════════════════════════
 * HARDWARE ENDPOINTS (dipanggil oleh ESP32 Arduino):
 *   POST /api/rfid/register  → registerCard()
 *   POST /api/rfid/login     → loginCard()
 *   POST /api/rfid/logout    → logoutCard()
 *
 * WEB ENDPOINTS (dipanggil oleh MINETRA web UI / Vue):
 *   GET  /api/rfid/status              → getStatus()
 *   GET  /api/rfid/users               → getUsers()
 *   POST /api/rfid/pending             → setPending()
 *   DELETE /api/rfid/pending           → cancelPending()
 *   DELETE /api/rfid/users/{id}/card   → unregisterCard()
 *
 * AUTENTIKASI:
 *   Hardware endpoints: Token via header X-Rfid-Token atau body api_token
 *   Web endpoints: Sanctum session (user harus login di MINETRA web)
 *
 * ESP32 REQUEST FORMAT:
 *   Header: Content-Type: application/json
 *   Header: X-Rfid-Token: {token} (optional)
 *   Body: { "uid": "A3B2C1D0" }
 *
 * ESP32 RESPONSE FORMAT (untuk LCD 16 char):
 *   { "status": "success|failed", "name": "NamaUser", "message": "...", "detail": "..." }
 * ════════════════════════════════════════════════════════════════════
 */
class ApiRfidController extends Controller
{
    private const DEFAULT_COMPANY_ID = 1;

    public function __construct(
        private RfidService $rfid
    ) {}

    // ═══════════════════════════════════════════════════════════════
    // HARDWARE ENDPOINTS — Dipanggil langsung oleh ESP32 Arduino
    // ═══════════════════════════════════════════════════════════════

    /**
     * Register kartu RFID baru ke user yang sedang pending.
     *
     * ESP32 memanggil ini setelah membaca kartu dalam mode REGISTER.
     *
     * Request: POST /api/rfid/register
     * Body: { "uid": "A3B2C1D0", "api_token": "..." }
     */
    public function registerCard(Request $request): JsonResponse
    {
        // ─── Validasi token ESP32 ──────────────────────────────────
        $token = $request->header('X-Rfid-Token') ?? $request->input('api_token', '');
        if (!$this->rfid->validateToken($token)) {
            Log::warning('RFID API: Token tidak valid dari ' . $request->ip());
            return response()->json([
                'status'  => 'failed',
                'message' => 'Token tidak valid',
            ], 401);
        }

        // ─── Validasi UID wajib ada ────────────────────────────────
        $request->validate(['uid' => 'required|string|min:4|max:50']);

        $companyId = $this->resolveCompanyId($request);
        $result    = $this->rfid->registerCard($request->uid, $companyId);

        $httpStatus = $result['status'] === 'success' ? 200 : 422;

        Log::info("RFID /register → status:{$result['status']} uid:{$request->uid} ip:{$request->ip()}");

        return response()->json($result, $httpStatus)
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'POST, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, X-Rfid-Token');
    }

    /**
     * Login operator via kartu RFID.
     *
     * ESP32 memanggil ini setelah membaca kartu dalam mode LOGIN.
     *
     * Request: POST /api/rfid/login
     * Body: { "uid": "A3B2C1D0", "api_token": "..." }
     */
    public function loginCard(Request $request): JsonResponse
    {
        $token = $request->header('X-Rfid-Token') ?? $request->input('api_token', '');
        if (!$this->rfid->validateToken($token)) {
            return response()->json(['status' => 'failed', 'message' => 'Token tidak valid'], 401);
        }

        $request->validate(['uid' => 'required|string|min:4|max:50']);

        $result = $this->rfid->loginByCard($request->uid);

        Log::info("RFID /login → status:{$result['status']} uid:{$request->uid} ip:{$request->ip()}");

        return response()->json($result, $result['status'] === 'success' ? 200 : 422)
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'POST, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, X-Rfid-Token');
    }

    /**
     * Logout operator via kartu RFID.
     *
     * ESP32 memanggil ini setelah membaca kartu dalam mode LOGOUT.
     *
     * Request: POST /api/rfid/logout
     * Body: { "uid": "A3B2C1D0", "api_token": "..." }
     */
    public function logoutCard(Request $request): JsonResponse
    {
        $token = $request->header('X-Rfid-Token') ?? $request->input('api_token', '');
        if (!$this->rfid->validateToken($token)) {
            return response()->json(['status' => 'failed', 'message' => 'Token tidak valid'], 401);
        }

        $request->validate(['uid' => 'required|string|min:4|max:50']);

        $result = $this->rfid->logoutByCard($request->uid);

        Log::info("RFID /logout → status:{$result['status']} uid:{$request->uid} ip:{$request->ip()}");

        return response()->json($result, $result['status'] === 'success' ? 200 : 422)
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'POST, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, X-Rfid-Token');
    }

    /**
     * Handle preflight OPTIONS request dari ESP32 / browser.
     * Diperlukan untuk CORS.
     */
    public function handleOptions(): JsonResponse
    {
        return response()->json(['ok' => true])
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'POST, GET, OPTIONS, DELETE')
            ->header('Access-Control-Allow-Headers', 'Content-Type, X-Rfid-Token, Authorization');
    }

    // ═══════════════════════════════════════════════════════════════
    // WEB UI ENDPOINTS — Dipanggil oleh MINETRA Vue (Sanctum auth)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Status sistem RFID untuk panel manajemen.
     * Menampilkan: daftar user + status kartu + operator hadir saat ini.
     *
     * GET /api/rfid/status
     */
    public function getStatus(Request $request): JsonResponse
    {
        $companyId = Auth::user()?->company_id ?? self::DEFAULT_COMPANY_ID;

        $users     = $this->rfid->getUsersWithRfidStatus($companyId);
        $present   = $this->rfid->getPresentOperators($companyId);
        $pending   = $this->rfid->getPendingRegistration($companyId);

        return response()->json([
            'users'            => $users,
            'present_count'    => count($present),
            'present_operators'=> $present,
            'pending'          => $pending,
            'total_users'      => count($users),
            'registered_count' => collect($users)->where('has_card', true)->count(),
            'timestamp_wib'    => now()->timezone('Asia/Jakarta')->format('d/m/Y H:i:s') . ' WIB',
        ]);
    }

    /**
     * Daftar users company untuk dropdown registrasi kartu.
     *
     * GET /api/rfid/users
     */
    public function getUsers(Request $request): JsonResponse
    {
        $companyId = Auth::user()?->company_id ?? self::DEFAULT_COMPANY_ID;

        $users = User::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn($u) => [
                'id'        => $u->id,
                'name'      => $u->name,
                'email'     => $u->email,
                'role'      => $u->getRoleNames()->first(),
                'rfid_uid'  => $u->rfid_uid,
                'has_card'  => !is_null($u->rfid_uid),
                'is_present'=> $u->rfid_is_present,
            ]);

        return response()->json(['users' => $users]);
    }

    /**
     * Set pending registration — mulai proses daftarkan kartu ke user tertentu.
     * Setelah ini, operator tempel kartu ke ESP32 dalam mode REGISTER.
     *
     * POST /api/rfid/pending
     * Body: { "user_id": 5 }
     */
    public function setPending(Request $request): JsonResponse
    {
        abort_unless(
            Auth::user()->hasAnyRole(['company_admin', 'operator_lab_station']),
            403, 'Hanya Company Admin atau Operator Lab yang dapat mendaftarkan kartu RFID.'
        );

        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
        ]);

        $companyId = Auth::user()->company_id;
        $targetUser = User::where('company_id', $companyId)->findOrFail($validated['user_id']);

        // Cek apakah sudah punya kartu
        if ($targetUser->rfid_uid) {
            return response()->json([
                'status'  => 'warning',
                'message' => "{$targetUser->name} sudah memiliki kartu terdaftar (UID: {$targetUser->rfid_uid}). Hapus kartu lama terlebih dahulu.",
            ], 422);
        }

        $this->rfid->setPendingRegistration(
            $validated['user_id'],
            $companyId,
            Auth::user()->name
        );

        return response()->json([
            'status'      => 'success',
            'message'     => "Sesi registrasi dimulai untuk {$targetUser->name}. Tempelkan kartu ke ESP32 dalam {$this->getTimeoutLabel()} detik.",
            'user_name'   => $targetUser->name,
            'expires_in'  => 90,
        ]);
    }

    /**
     * Batalkan pending registration.
     *
     * DELETE /api/rfid/pending
     */
    public function cancelPending(Request $request): JsonResponse
    {
        $companyId = Auth::user()?->company_id ?? self::DEFAULT_COMPANY_ID;
        $this->rfid->cancelPendingRegistration($companyId);

        return response()->json([
            'status'  => 'success',
            'message' => 'Sesi registrasi dibatalkan.',
        ]);
    }

    /**
     * Hapus kartu RFID dari user (unregister).
     *
     * DELETE /api/rfid/users/{userId}/card
     */
    public function unregisterCard(Request $request, int $userId): JsonResponse
    {
        abort_unless(Auth::user()->hasAnyRole(['company_admin']), 403);

        $companyId = Auth::user()->company_id;
        $result    = $this->rfid->unregisterCard($userId, $companyId);

        return response()->json($result, $result['status'] === 'success' ? 200 : 422);
    }

    // ═══════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════

    /** Resolve company_id dari request (token atau default) */
    private function resolveCompanyId(Request $request): int
    {
        // Dari header jika ESP32 mengirimkan company identifier
        $companyCode = $request->header('X-Company-Code');
        if ($companyCode) {
            $company = \App\Models\Company::where('code', $companyCode)->first();
            if ($company) return $company->id;
        }

        // Default ke company pertama (PT Timah)
        return self::DEFAULT_COMPANY_ID;
    }

    private function getTimeoutLabel(): string
    {
        return '90';
    }
}