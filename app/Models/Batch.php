<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

/**
 * Model Batch — entitas utama sistem traceability.
 *
 * @property string $batch_code      Kode unik batch
 * @property string $process_stage   Stage saat ini
 * @property string $status          Status saat ini
 * @property float  $current_weight  Berat terkini (kg)
 * @property bool   $has_discrepancy Alert mass balance
 */
class Batch extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $fillable = [
        'company_id', 'batch_code', 'rfid_tag', 'lot_number',
        'product_code_id', 'parent_batch_id',
        'initial_weight', 'current_weight', 'weight_unit',
        'process_stage', 'status', 'current_location_id',
        'is_split', 'split_count', 'split_suffix', 'split_reason',
        'mass_loss_pct', 'has_discrepancy', 'export_status',
        'qr_code_path', 'metadata',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'initial_weight'  => 'float',
        'current_weight'  => 'float',
        'mass_loss_pct'   => 'float',
        'is_split'        => 'boolean',
        'has_discrepancy' => 'boolean',
        'metadata'        => 'array',
        'split_count'     => 'integer',
    ];

    // ─── Activity Log Configuration ────────────────────────────────────────
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'status', 'process_stage', 'current_weight',
                'current_location_id', 'export_status', 'has_discrepancy',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // ─── Relationships ─────────────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function productCode(): BelongsTo
    {
        return $this->belongsTo(ProductCode::class);
    }

    public function parentBatch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'parent_batch_id');
    }

    public function childBatches(): HasMany
    {
        return $this->hasMany(Batch::class, 'parent_batch_id');
    }

    public function currentLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'current_location_id');
    }

    public function checkpoints(): HasMany
    {
        return $this->hasMany(Checkpoint::class)->orderBy('recorded_at');
    }

    public function labAnalyses(): HasMany
    {
        return $this->hasMany(LabAnalysis::class);
    }

    public function transformations(): HasMany
    {
        return $this->hasMany(BatchTransformation::class, 'input_batch_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ─── Accessors ────────────────────────────────────────────────────────

    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            'draft'        => 'Draft',
            'active'       => 'Aktif',
            'in_transit'   => 'Dalam Perjalanan',
            'in_process'   => 'Sedang Diproses',
            'in_warehouse' => 'Di Gudang',
            'completed'    => 'Selesai',
            'split'        => 'Di-split',
            'transformed'  => 'Ditransformasi',
            'exported'     => 'Diekspor',
            'terminated'   => 'Terminal',
            default        => $this->status,
        };
    }

    public function getStageLabelAttribute(): string
    {
        return match($this->process_stage) {
            'lab_kimia'     => 'Lab Kimia',
            'receiving'     => 'Receiving Area',
            'wet_process'   => 'Wet Process',
            'dry_process'   => 'Dry Process',
            'project_plant' => 'Project Plant',
            'warehouse'     => 'LTJ Warehouse',
            'smelter'       => 'Smelter',
            'exported'      => 'Diekspor',
            'terminated'    => 'Terminal',
            default         => $this->process_stage,
        };
    }

    // ─── Business Logic Helpers ───────────────────────────────────────────

    /** Apakah batch bisa diekspor? */
    public function isExportable(): bool
    {
        return $this->status === 'in_warehouse'
            && $this->productCode->isExportable();
    }

    /** Apakah batch bisa di-split? */
    public function isSplittable(): bool
    {
        return in_array($this->status, ['in_warehouse', 'active'])
            && !in_array($this->status, ['transformed', 'terminated', 'exported']);
    }

    /** Apakah batch bisa ditransformasi (Dry Process)? */
    public function isTransformable(): bool
    {
        return $this->status === 'in_process'
            && $this->process_stage === 'dry_process';
    }

    // ─── Scopes ───────────────────────────────────────────────────────────

    public function scopeOfCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeActive($query)
    {   
        return $query->whereIn('status', ['active', 'in_process', 'in_transit']);
    }

    public function scopeInWarehouse($query)
    {
        return $query->where('status', 'in_warehouse');
    }

    public function scopeHasAlert($query)
    {
        return $query->where('has_discrepancy', true);
    }

    public function scopeByStage($query, string $stage)
    {
        return $query->where('process_stage', $stage);
    }
}