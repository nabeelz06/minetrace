<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Himpunan batch di Wet/Dry. Batch yang diterima di tahap itu masuk himpunan `collecting`.
 * Drum output pertama mengubahnya menjadi `outputting` (Dry) atau langsung `closed` (Wet).
 */
class BatchPool extends Model
{
    public const STATUS_COLLECTING = 'collecting';
    public const STATUS_OUTPUTTING = 'outputting';
    public const STATUS_CLOSED     = 'closed';

    public const STAGES = ['wet_process', 'dry_process'];

    /** Dry: paling banyak satu drum per produk ini. */
    public const MAX_DRY_DRUMS = 3;

    protected $fillable = [
        'company_id', 'stage', 'status', 'opened_at', 'closed_at', 'closed_by',
        'total_input_weight', 'total_output_weight', 'loss_pct', 'has_discrepancy', 'transformation_id',
    ];

    protected $casts = [
        'opened_at'           => 'datetime',
        'closed_at'           => 'datetime',
        'total_input_weight'  => 'float',
        'total_output_weight' => 'float',
        'loss_pct'            => 'float',
        'has_discrepancy'     => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function inputs(): HasMany
    {
        return $this->hasMany(BatchPoolInput::class, 'pool_id');
    }

    /** Drum output (batch yang dihasilkan himpunan ini). Drum yang dibatalkan ter-soft-delete. */
    public function outputs(): HasMany
    {
        return $this->hasMany(Batch::class, 'pool_id');
    }

    public function transformation(): BelongsTo
    {
        return $this->belongsTo(BatchTransformation::class, 'transformation_id');
    }

    public function isOpen(): bool
    {
        return $this->status !== self::STATUS_CLOSED;
    }
}
