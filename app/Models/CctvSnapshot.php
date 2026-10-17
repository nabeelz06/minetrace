<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Model CctvSnapshot — entitas first-class untuk riwayat capture CCTV.
 *
 * Sumber kebenaran kanonik sejak migrasi dari checkpoints.photos (JSON blob).
 * Data evidentiary untuk audit BIM/ESDM — hanya soft-delete, tidak pernah
 * hard-delete (lihat CctvSnapshotController::destroy()).
 */
class CctvSnapshot extends Model
{
    use SoftDeletes, LogsActivity;

    protected $fillable = [
        'company_id', 'batch_id', 'checkpoint_id', 'read_log_id', 'camera_id',
        'file_path', 'capture_mode', 'capture_status', 'failure_reason',
        'scan_type', 'rfid_uid', 'stage', 'filesize_kb', 'captured_at',
        'is_verified', 'verified_by', 'verified_at', 'review_notes',
    ];

    protected $casts = [
        'captured_at'  => 'datetime',
        'is_verified'  => 'boolean',
        'verified_at'  => 'datetime',
        'filesize_kb'  => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['capture_status', 'is_verified', 'review_notes', 'deleted_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // ─── Relationships ─────────────────────────────────────────────────────

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function checkpoint(): BelongsTo
    {
        return $this->belongsTo(Checkpoint::class);
    }

    public function camera(): BelongsTo
    {
        return $this->belongsTo(CctvCamera::class, 'camera_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    // ─── Accessors ────────────────────────────────────────────────────────

    public function getUrlAttribute(): ?string
    {
        return $this->file_path ? asset('storage/' . $this->file_path) : null;
    }

    // ─── Scopes ───────────────────────────────────────────────────────────

    public function scopeForBatch(Builder $query, int $batchId): Builder
    {
        return $query->where('batch_id', $batchId);
    }

    public function scopeVerified(Builder $query, bool $verified = true): Builder
    {
        return $query->where('is_verified', $verified);
    }

    public function scopeByMode(Builder $query, string $mode): Builder
    {
        return $query->where('capture_mode', $mode);
    }
}
