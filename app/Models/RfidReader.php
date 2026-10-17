<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RfidReader — perangkat pembaca terdaftar (read point tetap: tahap + arah).
 * Kunci perangkat hanya disimpan sebagai hash; kunci asli tampil sekali saat dibuat/dirotasi.
 */
class RfidReader extends Model
{
    public const DIRECTIONS   = ['in', 'out', 'any'];
    public const DEVICE_TYPES = ['esp32_rc522', 'uhf_epc_gen2', 'uhf_desktop_r2000', 'uhf_chainway_ur4', 'keyboard_wedge', 'other'];

    /** Reader jaringan yang dibaca daemon `rfid:uhf-gate` (butuh connection.host). */
    public const NETWORK_TYPES = ['uhf_chainway_ur4'];

    /** Reader UHF (EPC Gen2) — asal scan dicatat sebagai real_uhf. */
    public const UHF_TYPES = ['uhf_epc_gen2', 'uhf_desktop_r2000', 'uhf_chainway_ur4'];

    protected $fillable = [
        'company_id', 'code', 'name', 'stage', 'direction', 'device_type', 'connection',
        'key_prefix', 'key_hash', 'is_active', 'last_seen_at', 'last_ip',
        'firmware_version', 'last_status', 'key_rotated_at', 'created_by',
    ];

    protected $hidden = ['key_hash'];

    protected $casts = [
        'is_active'      => 'boolean',
        'last_seen_at'   => 'datetime',
        'key_rotated_at' => 'datetime',
        'last_status'    => 'array',
        'connection'     => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isUhf(): bool
    {
        return in_array($this->device_type, self::UHF_TYPES, true);
    }

    /**
     * Arah untuk satu pembacaan reader gate: pemetaan antena (connection.antennas) menang,
     * lalu arah tetap reader; reader 'any' tanpa pemetaan dianggap Scan IN.
     */
    public function directionForAntenna(?int $antenna): string
    {
        $map = $this->connection['antennas'] ?? [];
        if ($antenna !== null && in_array($map[(string) $antenna] ?? null, ['in', 'out'], true)) {
            return $map[(string) $antenna];
        }

        return $this->direction === 'out' ? 'out' : 'in';
    }

    /** online | offline | never | disabled — berdasar heartbeat/scan terakhir. */
    public function connectionState(): string
    {
        if (! $this->is_active) {
            return 'disabled';
        }
        if (! $this->last_seen_at) {
            return 'never';
        }

        return $this->last_seen_at->gt(now()->subSeconds(config('rfid.offline_after_seconds')))
            ? 'online'
            : 'offline';
    }
}
