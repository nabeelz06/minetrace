<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model BatchTransformation — audit trail transformasi batch.
 * Contoh: Dry Process — 1 MIK input → [MON, ZIR, ILM] output.
 */
class BatchTransformation extends Model
{
    protected $fillable = [
        'input_batch_id', 'output_batch_ids', 'transformation_type',
        'location_id', 'transformation_details', 'notes',
        'performed_by', 'transformed_at',
    ];

    protected $casts = [
        'output_batch_ids'        => 'array',
        'transformation_details'  => 'array',
        'transformed_at'          => 'datetime',
    ];

    // ─── Relationships ─────────────────────────────────────────────────────

    public function inputBatch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'input_batch_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    // ─── Helper Methods ───────────────────────────────────────────────────

    /** Ambil semua batch output dari transformasi ini. */
    public function getOutputBatches()
    {
        return Batch::whereIn('id', $this->output_batch_ids)->get();
    }
}