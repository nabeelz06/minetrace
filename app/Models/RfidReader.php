<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * RfidReader — Device Registry untuk RFID Reader fisik (Fase 7).
 *
 * ════════════════════════════════════════════════════════════════════════
 * KENAPA DEVICE REGISTRY, BUKAN FORM SPESIFIKASI HARDWARE
 *
 * Tabel ini TIDAK dibuat untuk menyimpan spesifikasi teknis reader
 * (frekuensi, power dBm, protokol UHF) sebagai data yang diketik admin —
 * itu urusan firmware/bridge, percuma disalin manual ke form web dan akan
 * basi begitu firmware diperbarui.
 *
 * Tujuan sebenarnya, mengikuti konvensi device registry IoT (AWS IoT Core
 * "Thing", Azure IoT Hub "Device Identity"):
 *   1. IDENTITAS  — setiap unit fisik punya credential UNIK (device_key)
 *      yang bisa dicabut satu-per-satu tanpa mematikan reader lain.
 *   2. VISIBILITAS — admin bisa melihat reader apa saja yang terdaftar,
 *      di lokasi/stage mana, dan apakah sedang online.
 *   3. KONTROL RINGAN — rename, pindah lokasi, nonaktifkan/cabut, generate
 *      ulang key — semua dari web, tanpa SSH ke server atau edit .env.
 *
 * RFID TAG sengaja TIDAK memiliki tabel/registry serupa — lihat catatan
 * di ApiRfidController::resolveDeviceContext() dan dokumentasi integrasi:
 * UID tag tidak bisa "diketik" di form (itu meniadakan gunanya RFID), jadi
 * alur yang benar tetap hybrid: web UI menyatakan INTENT (batch mana yang
 * menunggu tag), hardware yang memasok UID saat tag ditempel.
 *
 * KEAMANAN device_key: hanya device_key_hash (SHA-256) yang disimpan.
 * Plaintext key HANYA ada sesaat di response generateCredentials() —
 * ditampilkan sekali ke admin lalu dibuang. Hilang/perlu diganti → generate
 * ulang (bukan "lihat lagi"), persis seperti personal access token Sanctum.
 * ════════════════════════════════════════════════════════════════════════
 */
class RfidReader extends Model
{
    protected $fillable = [
        'company_id', 'name', 'device_code', 'device_key_hash',
        'location_key', 'hardware_type', 'is_active',
        'last_seen_at', 'last_seen_ip',
        'created_by', 'revoked_by', 'revoked_at',
    ];

    protected $casts = [
        'is_active'    => 'boolean',
        'last_seen_at' => 'datetime',
        'revoked_at'   => 'datetime',
    ];

    protected $appends = ['status'];

    /** Reader dianggap "online" jika last_seen_at dalam N detik terakhir.
     *  90s = ~3x interval heartbeat yang disarankan (30s) → toleran
     *  terhadap satu-dua heartbeat yang terlewat (jitter jaringan) tanpa
     *  flapping online/offline di UI. */
    private const ONLINE_THRESHOLD_SECONDS = 90;

    /** Prefix plaintext key — memudahkan pengenalan "ini RFID device key"
     *  secara visual di config file/log, TIDAK membocorkan apa pun karena
     *  entropi penuh ada di 40 karakter random setelahnya. */
    private const KEY_PREFIX = 'rfk';

    // ─── Relationships ──────────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function revokedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    // ─── Device key lifecycle ───────────────────────────────────────────

    /**
     * Generate device_code + device_key BARU. plain_key HANYA ada di
     * return value ini — caller WAJIB mengembalikannya ke admin sekali
     * (response JSON), lalu TIDAK PERNAH menyimpannya ke DB atau log.
     */
    public static function generateCredentials(int $companyId): array
    {
        $plainKey = self::KEY_PREFIX . '_' . Str::random(40);

        return [
            'device_code'     => self::nextDeviceCode($companyId),
            'device_key_hash' => hash('sha256', $plainKey),
            'plain_key'       => $plainKey,
        ];
    }

    /**
     * Device code berurutan per company, mis. "RDR-TIMAH-001".
     *
     * CATATAN: penghitungan berbasis count() punya race condition teoritis
     * jika dua admin membuat reader di detik yang sama persis — diterima
     * sebagai risiko sangat rendah karena ini aksi admin manual, bukan
     * hot-path frekuensi tinggi. unique() constraint di migration tetap
     * mencegah collision ganda menimpa data (insert kedua akan gagal, bukan
     * korup), jadi failure mode-nya aman meski jarang terjadi.
     */
    private static function nextDeviceCode(int $companyId): string
    {
        $company = Company::find($companyId);
        $prefix  = 'RDR-' . strtoupper($company?->code ?? 'CO') . '-';

        $sequence = static::where('company_id', $companyId)
            ->where('device_code', 'like', $prefix . '%')
            ->count();

        return $prefix . str_pad((string) ($sequence + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * Resolve reader AKTIF dari plaintext key yang dikirim device.
     * Return null untuk key kosong, salah, milik reader nonaktif, ATAU
     * yang sudah dicabut — caller (ApiRfidController) WAJIB menolak request
     * (401) saat ini null, BUKAN fallback diam-diam ke alur token lama
     * (lihat penjelasan panjang di ApiRfidController::resolveDeviceContext).
     */
    public static function findActiveByPlainKey(string $plainKey): ?self
    {
        $plainKey = trim($plainKey);
        if ($plainKey === '') {
            return null;
        }

        return static::where('device_key_hash', hash('sha256', $plainKey))
            ->where('is_active', true)
            ->whereNull('revoked_at')
            ->first();
    }

    /** Catat heartbeat/aktivitas reader — dipanggil setiap request hardware
     *  yang berhasil diautentikasi via device_key (scan ATAU heartbeat). */
    public function markSeen(?string $ip = null): void
    {
        $this->update([
            'last_seen_at' => now(),
            'last_seen_ip' => $ip,
        ]);
    }

    public function revoke(int $byUserId): void
    {
        $this->update([
            'is_active'  => false,
            'revoked_by' => $byUserId,
            'revoked_at' => now(),
        ]);
    }

    public function reactivate(): void
    {
        $this->update([
            'is_active'  => true,
            'revoked_by' => null,
            'revoked_at' => null,
        ]);
    }

    // ─── Computed ────────────────────────────────────────────────────────

    /**
     * 'revoked' | 'never_connected' | 'online' | 'offline'.
     * Dipisah dari is_active (boolean mentah) karena UI butuh 4 state
     * visual yang berbeda maknanya bagi admin, bukan cuma 2.
     */
    public function getStatusAttribute(): string
    {
        if (!$this->is_active || $this->revoked_at) {
            return 'revoked';
        }

        if (!$this->last_seen_at) {
            return 'never_connected';
        }

        return $this->last_seen_at->greaterThan(now()->subSeconds(self::ONLINE_THRESHOLD_SECONDS))
            ? 'online'
            : 'offline';
    }

    /** Bentuk array siap-kirim untuk Inertia props maupun JSON API —
     *  dipakai BERSAMA oleh HardwareController::rfidPanel() dan
     *  ApiRfidDeviceController agar bentuk data tidak pernah divergen
     *  antara dua tempat (DRY — satu sumber kebenaran untuk "apa yang
     *  frontend lihat tentang satu reader"). TIDAK PERNAH menyertakan
     *  device_key_hash maupun plain_key. */
    public function toDisplayArray(): array
    {
        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'device_code'   => $this->device_code,
            'location_key'  => $this->location_key,
            'hardware_type' => $this->hardware_type,
            'is_active'     => $this->is_active,
            'status'        => $this->status,
            'last_seen_at'  => $this->last_seen_at?->timezone('Asia/Jakarta')->format('d/m/Y H:i:s'),
            'last_seen_ip'  => $this->last_seen_ip,
            'created_at'    => $this->created_at?->timezone('Asia/Jakarta')->format('d/m/Y H:i:s'),
        ];
    }

    // ─── Scopes ──────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
