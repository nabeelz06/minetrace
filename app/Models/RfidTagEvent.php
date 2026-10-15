<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Riwayat siklus hidup tag (append-only): tidak ada UPDATE/DELETE dari aplikasi. */
class RfidTagEvent extends Model
{
    public const UPDATED_AT = null;

    public const REASONS = ['replaced', 'lost', 'damaged', 'other'];

    protected $fillable = [
        'company_id', 'batch_id', 'uid', 'event', 'reason', 'note', 'reader_id', 'performed_by',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
