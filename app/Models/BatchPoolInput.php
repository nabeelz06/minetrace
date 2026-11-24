<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BatchPoolInput extends Model
{
    protected $fillable = ['pool_id', 'batch_id', 'weight_at_join', 'released_rfid_tag', 'joined_at'];

    protected $casts = [
        'weight_at_join' => 'float',
        'joined_at'      => 'datetime',
    ];

    public function pool(): BelongsTo
    {
        return $this->belongsTo(BatchPool::class, 'pool_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class)->withTrashed();
    }
}
