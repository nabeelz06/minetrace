<?php

namespace App\Services\Hardware;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * RfidService — Business logic untuk integrasi hardware RFID.
 *
 * ARSITEKTUR:
 *   ESP32 (Arduino) membaca kartu RFID → kirim UID ke endpoint MINETRA
 *   MINETRA melakukan lookup user by UID → return response ke ESP32
 *   ESP32 tampilkan hasil di LCD I2C
 *
 * FLOW REGISTER:
 *   1. Admin di web MINETRA klik "Daftarkan Kartu" → pilih user
 *   2. Sistem simpan pending_user_id ke Cache (TTL 60 detik)
 *   3. Operator tempelkan kartu ke ESP32 yang dalam mode REGISTER
 *   4. ESP32 kirim UID ke /api/rfid/register
 *   5. Service ambil pending_user_id dari cache, hubungkan UID ke user
 *
 * FLOW LOGIN:
 *   1. ESP32 dalam mode LOGIN, operator tempelkan kartu
 *   2. ESP32 kirim UID ke /api/rfid/login
 *   3. Service cari user by rfid_uid, update rfid_is_present = true
 *   4. Return nama user ke ESP32 → tampil di LCD
 *
 * FLOW LOGOUT:
 *   1. ESP32 dalam mode LOGOUT, operator tempelkan kartu
 *   2. ESP32 kirim UID ke /api/rfid/logout
 *   3. Service cari user by rfid_uid, update rfid_is_present = false
 */
class RfidService
{
    private const CACHE_PENDING_PREFIX = 'rfid_pending_user_';
    private const CACHE_PENDING_TTL    = 90;  // detik — waktu window untuk tempel kartu

    // ═══════════════════════════════════════════════════════════════
    // REGISTER — Daftarkan UID kartu ke user yang pending
    // ═══════════════════════════════════════════════════════════════

    /**
     * Daftarkan UID kartu RFID ke user yang sedang pending registration.
     *
     * Dipanggil oleh ESP32 via POST /api/rfid/register
     *
     * @param  string   $uid        UID fisik kartu (hex string)
     * @param  int      $companyId  Company ID (dari token atau default)
     * @return array    Response untuk ESP32
     */
    public function registerCard(string $uid, int $companyId = 1): array
    {
        $uid = strtoupper(trim($uid));

        // ─── Cek apakah UID sudah terdaftar di user lain ──────────
        $existingUser = User::where('rfid_uid', $uid)->first();
        if ($existingUser) {
            Log::warning("RFID Register: UID {$uid} sudah terdaftar untuk user {$existingUser->name}");
            return [
                'status'  => 'failed',
                'message' => 'Kartu sudah terdaftar',
                'detail'  => "UID ini sudah dipakai oleh {$existingUser->name}",
            ];
        }

        // ─── Ambil pending user dari cache ─────────────────────────
        $cacheKey  = self::CACHE_PENDING_PREFIX . $companyId;
        $pendingData = Cache::get($cacheKey);

        if (!$pendingData) {
            Log::info("RFID Register: Tidak ada pending registration untuk company {$companyId}");
            return [
                'status'  => 'failed',
                'message' => 'Tidak ada sesi registrasi aktif',
                'detail'  => 'Mulai registrasi dari panel web MINETRA terlebih dahulu',
            ];
        }

        $userId = $pendingData['user_id'];
        $user   = User::where('company_id', $companyId)->find($userId);

        if (!$user) {
            Cache::forget($cacheKey);
            return [
                'status'  => 'failed',
                'message' => 'User tidak ditemukan',
            ];
        }

        // ─── Daftarkan UID ke user ─────────────────────────────────
        $user->update([
            'rfid_uid'           => $uid,
            'rfid_last_scan_at'  => now(),
        ]);

        // Hapus pending dari cache setelah berhasil
        Cache::forget($cacheKey);

        Log::info("RFID Register: Berhasil. UID {$uid} → User {$user->name} (ID:{$user->id})");

        return [
            'status'  => 'success',
            'name'    => $this->truncateName($user->name),
            'message' => 'Kartu terdaftar',
            'detail'  => "{$user->name} ({$user->rfid_uid})",
            'user_id' => $user->id,
            'role'    => $user->getRoleNames()->first() ?? 'user',
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // LOGIN — Autentikasi operator via kartu RFID
    // ═══════════════════════════════════════════════════════════════

    /**
     * Login operator via kartu RFID.
     *
     * Dipanggil oleh ESP32 via POST /api/rfid/login
     *
     * @param  string $uid  UID fisik kartu
     * @return array        Response untuk ESP32 (ditampilkan di LCD)
     */
    public function loginByCard(string $uid): array
    {
        $uid  = strtoupper(trim($uid));
        $user = User::where('rfid_uid', $uid)->where('is_active', true)->first();

        if (!$user) {
            Log::warning("RFID Login: UID {$uid} tidak dikenal");
            return [
                'status'  => 'failed',
                'message' => 'Kartu tidak dikenal',
                'detail'  => "UID {$uid} tidak terdaftar. Hubungi admin.",
            ];
        }

        // ─── Update status kehadiran ───────────────────────────────
        $user->update([
            'rfid_is_present'   => true,
            'rfid_last_scan_at' => now(),
        ]);

        $roleLabel = $this->getRoleLabel($user->getRoleNames()->first());

        Log::info("RFID Login: {$user->name} (UID:{$uid}) berhasil masuk pukul " . now()->timezone('Asia/Jakarta')->format('H:i:s'));

        return [
            'status'     => 'success',
            'name'       => $this->truncateName($user->name),
            'message'    => 'Login berhasil',
            'detail'     => "{$user->name} \u2014 {$roleLabel}",
            'user_id'    => $user->id,
            'role'       => $user->getRoleNames()->first() ?? 'user',
            'role_label' => $roleLabel,
            'timestamp'  => now()->timezone('Asia/Jakarta')->format('d/m/Y H:i:s') . ' WIB',
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // LOGOUT — Operator scan keluar
    // ═══════════════════════════════════════════════════════════════

    /**
     * Logout operator via kartu RFID.
     *
     * Dipanggil oleh ESP32 via POST /api/rfid/logout
     *
     * @param  string $uid  UID fisik kartu
     * @return array        Response untuk ESP32
     */
    public function logoutByCard(string $uid): array
    {
        $uid  = strtoupper(trim($uid));
        $user = User::where('rfid_uid', $uid)->where('is_active', true)->first();

        if (!$user) {
            Log::warning("RFID Logout: UID {$uid} tidak dikenal");
            return [
                'status'  => 'failed',
                'message' => 'Kartu tidak dikenal',
                'detail'  => "UID {$uid} tidak terdaftar.",
            ];
        }

        // ─── Update status keluar ──────────────────────────────────
        $user->update([
            'rfid_is_present'   => false,
            'rfid_last_scan_at' => now(),
        ]);

        Log::info("RFID Logout: {$user->name} (UID:{$uid}) keluar pukul " . now()->timezone('Asia/Jakarta')->format('H:i:s'));

        return [
            'status'    => 'success',
            'name'      => $this->truncateName($user->name),
            'message'   => 'Logout berhasil',
            'detail'    => "{$user->name} telah keluar",
            'user_id'   => $user->id,
            'timestamp' => now()->timezone('Asia/Jakarta')->format('d/m/Y H:i:s') . ' WIB',
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // PENDING REGISTRATION — Manajemen antrian registrasi kartu
    // ═══════════════════════════════════════════════════════════════

    /**
     * Set pending registration untuk user tertentu.
     * Dipanggil dari web MINETRA sebelum operator tempel kartu.
     *
     * @param int    $userId     User yang akan menerima kartu
     * @param int    $companyId  Company ID
     * @param string $initiatedBy Nama admin yang memulai registrasi
     */
    public function setPendingRegistration(int $userId, int $companyId, string $initiatedBy = 'System'): void
    {
        $cacheKey = self::CACHE_PENDING_PREFIX . $companyId;
        Cache::put($cacheKey, [
            'user_id'      => $userId,
            'initiated_by' => $initiatedBy,
            'started_at'   => now()->toISOString(),
        ], self::CACHE_PENDING_TTL);

        Log::info("RFID Pending: Registrasi dimulai untuk user_id={$userId} oleh {$initiatedBy}");
    }

    /**
     * Ambil status pending registration saat ini.
     *
     * @param  int   $companyId
     * @return array|null  Data pending atau null jika tidak ada
     */
    public function getPendingRegistration(int $companyId): ?array
    {
        $cacheKey = self::CACHE_PENDING_PREFIX . $companyId;
        $data     = Cache::get($cacheKey);

        if (!$data) return null;

        $user = User::find($data['user_id']);
        if (!$user) {
            Cache::forget($cacheKey);
            return null;
        }

        return array_merge($data, [
            'user_name'     => $user->name,
            'user_email'    => $user->email,
            'user_role'     => $user->getRoleNames()->first(),
            'expires_in_sec'=> self::CACHE_PENDING_TTL,
            'ttl'           => Cache::getStore()->getPrefix(),
        ]);
    }

    /**
     * Batalkan pending registration.
     */
    public function cancelPendingRegistration(int $companyId): void
    {
        Cache::forget(self::CACHE_PENDING_PREFIX . $companyId);
    }

    /**
     * Hapus kartu RFID dari user (unregister).
     */
    public function unregisterCard(int $userId, int $companyId): array
    {
        $user = User::where('company_id', $companyId)->find($userId);

        if (!$user) {
            return ['status' => 'failed', 'message' => 'User tidak ditemukan'];
        }

        $oldUid = $user->rfid_uid;
        $user->update([
            'rfid_uid'        => null,
            'rfid_is_present' => false,
        ]);

        Log::info("RFID Unregister: UID {$oldUid} dihapus dari user {$user->name}");

        return [
            'status'  => 'success',
            'message' => "Kartu RFID berhasil dihapus dari {$user->name}",
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // TOKEN VALIDATION
    // ═══════════════════════════════════════════════════════════════

    /**
     * Validasi API token dari ESP32.
     * Token dikonfigurasi di .env sebagai RFID_API_TOKEN.
     * Jika token kosong (tidak dikonfigurasi), semua request diterima.
     */
    public function validateToken(?string $token): bool
    {
        $configuredToken = config('app.rfid_api_token', env('RFID_API_TOKEN', ''));

        // Jika token tidak dikonfigurasi, lewati validasi (development mode)
        if (empty($configuredToken)) {
            return true;
        }

        return $token === $configuredToken;
    }

    // ═══════════════════════════════════════════════════════════════
    // PRESENCE REPORT — Laporan kehadiran operator
    // ═══════════════════════════════════════════════════════════════

    /**
     * Daftar operator yang saat ini hadir (rfid_is_present = true).
     */
    public function getPresentOperators(int $companyId): array
    {
        return User::where('company_id', $companyId)
            ->where('rfid_is_present', true)
            ->whereNotNull('rfid_uid')
            ->get(['id', 'name', 'rfid_uid', 'rfid_last_scan_at'])
            ->map(fn($u) => [
                'id'            => $u->id,
                'name'          => $u->name,
                'rfid_uid'      => $u->rfid_uid,
                'role'          => $u->getRoleNames()->first(),
                'last_scan_wib' => $u->rfid_last_scan_at?->timezone('Asia/Jakarta')->format('H:i:s') . ' WIB',
            ])
            ->toArray();
    }

    /**
     * Daftar semua user dengan status kartu RFID untuk panel manajemen.
     */
    public function getUsersWithRfidStatus(int $companyId): array
    {
        return User::where('company_id', $companyId)
            ->whereNotIn('name', ['Super Admin'])
            ->orderBy('name')
            ->get()
            ->map(fn($u) => [
                'id'              => $u->id,
                'name'            => $u->name,
                'email'           => $u->email,
                'role'            => $u->getRoleNames()->first() ?? 'user',
                'role_label'      => $this->getRoleLabel($u->getRoleNames()->first()),
                'rfid_uid'        => $u->rfid_uid,
                'has_card'        => !is_null($u->rfid_uid),
                'is_present'      => $u->rfid_is_present,
                'last_scan_at'    => $u->rfid_last_scan_at?->timezone('Asia/Jakarta')->format('d/m/Y H:i:s') . ' WIB',
                'last_scan_diff'  => $u->rfid_last_scan_at?->diffForHumans(),
            ])
            ->toArray();
    }

    // ═══════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════

    /** Potong nama agar muat di LCD 16 karakter */
    private function truncateName(string $name): string
    {
        // Ambil nama depan saja jika terlalu panjang
        $parts = explode(' ', $name);
        if (mb_strlen($name) <= 16) return $name;
        return mb_substr($parts[0], 0, 16);
    }

    /** Label role dalam Bahasa Indonesia */
    private function getRoleLabel(?string $role): string
    {
        return match($role) {
            'company_admin'       => 'Company Admin',
            'operator_lab_station'=> 'Operator Lab Kimia',
            'operator_lab_ltj'    => 'Operator Lab LTJ',
            'operator_wet'        => 'Operator Wet Process',
            'operator_dry'        => 'Operator Dry Process',
            'operator_warehouse'  => 'Operator Gudang',
            'company_auditor'     => 'Auditor',
            'government_auditor'  => 'Auditor Pemerintah',
            'bim_admin'           => 'BIM Admin',
            'bim_viewer'          => 'BIM Viewer',
            'super_admin'         => 'Super Admin',
            default               => $role ?? 'User',
        };
    }
}