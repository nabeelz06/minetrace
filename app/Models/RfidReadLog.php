<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Log setiap pembacaan RFID (append-only). Lihat migrasi create_rfid_read_logs_table. */
class RfidReadLog extends Model
{
    public const UPDATED_AT = null;
    public const CREATED_AT = null;

    public const RESULTS = ['accepted', 'rejected', 'duplicate'];
    public const ACTIONS = ['in', 'out', 'register'];

    protected $fillable = [
        'company_id', 'reader_id', 'epc', 'tid', 'action', 'stage', 'antenna', 'rssi',
        'origin', 'result', 'result_code', 'message', 'batch_id', 'checkpoint_id', 'user_id',
        'snapshot_status', 'snapshot_id', 'meta', 'read_at',
    ];

    protected $casts = [
        'meta'    => 'array',
        'read_at' => 'datetime',
        'antenna' => 'integer',
        'rssi'    => 'integer',
    ];

    public function reader(): BelongsTo
    {
        return $this->belongsTo(RfidReader::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(CctvSnapshot::class, 'snapshot_id');
    }
}
