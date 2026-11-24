<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu sisi silsilah: batch induk menyumbang ke batch anak (lewat himpunan). Baris yang di-void tidak dihapus. */
class BatchLineage extends Model
{
    protected $table = 'batch_lineage';

    protected $fillable = [
        'parent_batch_id', 'child_batch_id', 'pool_id', 'contributed_weight', 'weight_is_estimate',
        'voided_at', 'voided_by', 'void_reason',
    ];

    protected $casts = [
        'contributed_weight' => 'float',
        'weight_is_estimate' => 'boolean',
        'voided_at'          => 'datetime',
    ];

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('voided_at');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'parent_batch_id')->withTrashed();
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'child_batch_id')->withTrashed();
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(BatchPool::class, 'pool_id');
    }
}
