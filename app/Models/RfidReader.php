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
    public const DEVICE_TYPES = ['esp32_rc522', 'uhf_epc_gen2', 'keyboard_wedge', 'other'];

    protected $fillable = [
        'company_id', 'code', 'name', 'stage', 'direction', 'device_type',
        'key_prefix', 'key_hash', 'is_active', 'last_seen_at', 'last_ip',
        'firmware_version', 'last_status', 'key_rotated_at', 'created_by',
    ];

    protected $hidden = ['key_hash'];

    protected $casts = [
        'is_active'      => 'boolean',
        'last_seen_at'   => 'datetime',
        'key_rotated_at' => 'datetime',
        'last_status'    => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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
