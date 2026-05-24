<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model UserLocationRole — permission scope berdasarkan lokasi.
 *
 * Contoh: User Ahmad hanya bisa input data di Wet Process Muntok,
 * bukan di Wet Process Kundur.
 */
class UserLocationRole extends Model
{
    protected $fillable = [
        'user_id',
        'company_schema',
        'location_id',
        'role_name',
        'is_primary',
        'valid_from',
        'valid_until',
        'assigned_by',
        'assignment_notes',
    ];

    protected $casts = [
        'is_primary'  => 'boolean',
        'valid_from'  => 'date',
        'valid_until' => 'date',
    ];

    // ─── Relationships ─────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    // ─── Scopes ───────────────────────────────────────────────────────────

    /** Filter hanya assignment yang masih aktif (belum expired) */
    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('valid_until')
              ->orWhere('valid_until', '>=', now()->toDateString());
        });
    }

    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }
}