<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

/**
 * Model LabAnalysis — hasil analisis kimia LTJ.
 *
 * 5 parameter aktif: La, Ce, Pr, Nd, Y
 * Total REO dihitung otomatis saat create/update.
 */
class LabAnalysis extends Model
{
    use SoftDeletes, LogsActivity;

    protected $fillable = [
        'batch_id', 'company_id', 'location_id',
        'la_pct', 'ce_pct', 'pr_pct', 'nd_pct', 'y_pct',
        'total_reo_pct',
        'tho2_pct', 'u3o8_pct',
        'la_kg', 'ce_kg', 'pr_kg', 'nd_kg', 'y_kg',
        'lab_method', 'equipment_used', 'sample_weight_g',
        'is_validated', 'validated_at', 'validated_by',
        'report_file_path', 'notes', 'analyzed_by', 'analyzed_at',
    ];

    protected $casts = [
        'la_pct'  => 'float', 'ce_pct'  => 'float',
        'pr_pct'  => 'float', 'nd_pct'  => 'float',
        'y_pct'   => 'float', 'total_reo_pct' => 'float',
        'tho2_pct'=> 'float', 'u3o8_pct' => 'float',
        'la_kg'   => 'float', 'ce_kg'   => 'float',
        'pr_kg'   => 'float', 'nd_kg'   => 'float',
        'y_kg'    => 'float', 'sample_weight_g' => 'float',
        'is_validated' => 'boolean',
        'validated_at' => 'datetime',
        'analyzed_at'  => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['la_pct','ce_pct','pr_pct','nd_pct','y_pct','is_validated'])
            ->logOnlyDirty();
    }

    // ─── Relationships ─────────────────────────────────────────────────────

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function analyzedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'analyzed_by');
    }

    public function validatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    // ─── Helper Methods ───────────────────────────────────────────────────

    /**
     * Hitung total REO dari 5 parameter aktif.
     */
    public function calculateTotalReo(): float
    {
        return round(
            ($this->la_pct ?? 0) +
            ($this->ce_pct ?? 0) +
            ($this->pr_pct ?? 0) +
            ($this->nd_pct ?? 0) +
            ($this->y_pct  ?? 0),
            3
        );
    }

    /**
     * Hitung berat per elemen berdasarkan persentase dan berat batch.
     */
    public function calculateElementWeights(float $batchWeightKg): array
    {
        return [
            'la_kg' => round($batchWeightKg * ($this->la_pct ?? 0) / 100, 3),
            'ce_kg' => round($batchWeightKg * ($this->ce_pct ?? 0) / 100, 3),
            'pr_kg' => round($batchWeightKg * ($this->pr_pct ?? 0) / 100, 3),
            'nd_kg' => round($batchWeightKg * ($this->nd_pct ?? 0) / 100, 3),
            'y_kg'  => round($batchWeightKg * ($this->y_pct  ?? 0) / 100, 3),
        ];
    }

    // ─── Accessors ────────────────────────────────────────────────────────

    public function getParameterSummaryAttribute(): array
    {
        return [
            ['symbol' => 'La', 'name' => 'Lantanum',     'pct' => $this->la_pct, 'kg' => $this->la_kg],
            ['symbol' => 'Ce', 'name' => 'Serium',       'pct' => $this->ce_pct, 'kg' => $this->ce_kg],
            ['symbol' => 'Pr', 'name' => 'Praseodimium', 'pct' => $this->pr_pct, 'kg' => $this->pr_kg],
            ['symbol' => 'Nd', 'name' => 'Neodimium',    'pct' => $this->nd_pct, 'kg' => $this->nd_kg],
            ['symbol' => 'Y',  'name' => 'Itrium',       'pct' => $this->y_pct,  'kg' => $this->y_kg ],
        ];
    }
}