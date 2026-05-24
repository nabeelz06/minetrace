<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model MassBalanceThreshold — konfigurasi toleransi susut material.
 * Warning (kuning) dan Critical (merah) per tahap proses.
 */
class MassBalanceThreshold extends Model
{
    protected $fillable = [
        'company_id', 'process_stage', 'stage_label',
        'warning_threshold_pct', 'critical_threshold_pct',
        'notes', 'updated_by',
    ];

    protected $casts = [
        'warning_threshold_pct'  => 'float',
        'critical_threshold_pct' => 'float',
    ];

    // ─── Relationships ─────────────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // ─── Helper Methods ───────────────────────────────────────────────────

    /**
     * Tentukan level alert berdasarkan persentase loss aktual.
     * Return: 'normal' | 'warning' | 'critical'
     */
    public function getAlertLevel(float $lossPct): string
    {
        if ($lossPct >= $this->critical_threshold_pct) {
            return 'critical';
        }
        if ($lossPct >= $this->warning_threshold_pct) {
            return 'warning';
        }
        return 'normal';
    }

    // ─── Scopes ───────────────────────────────────────────────────────────

    public function scopeOfCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}