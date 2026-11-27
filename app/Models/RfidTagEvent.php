<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Riwayat siklus hidup tag (append-only): tidak ada UPDATE/DELETE dari aplikasi. */
class RfidTagEvent extends Model
{
    public const UPDATED_AT = null;

    /** Alasan yang boleh dipilih operator saat melepas tag. 'test' dipakai otomatis oleh reset uji. */
    public const REASONS = ['replaced', 'lost', 'damaged', 'other'];

    /** Alasan sistem (tidak bisa dipilih operator): tag dilepas otomatis saat batch masuk himpunan Wet/Dry. */
    public const REASON_POOLED = 'pooled';

    /** Alasan sistem: tag drum output dilepas karena drum dibatalkan. */
    public const REASON_DRUM_CANCELLED = 'drum_cancelled';

    /** Alasan sistem: operator membebaskan tag agar fisik yang sama dipakai ulang (lihat RfidTagService::freeForReuse). */
    public const REASON_REUSE = 'reuse';

    /** Peristiwa 'cleared': UID kembali dianggap BARU (riwayat lama tidak dihapus, hanya diberi batas). */
    public const EVENT_CLEARED = 'cleared';

    /** Jenis peristiwa: registered (terpasang) | released (dilepas) | reset (reset uji, tag tetap terpasang) | cleared (dibebaskan). */
    public const EVENTS = ['registered', 'released', 'reset', 'cleared'];

    protected $fillable = [
        'company_id', 'batch_id', 'uid', 'event', 'reason', 'note', 'reader_id', 'performed_by',
        'barrier_checkpoint_id', 'barrier_pool_input_id',
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
