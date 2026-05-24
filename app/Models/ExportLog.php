<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

/**
 * Model ExportLog — pencatatan ekspor dan penjualan domestik.
 */
class ExportLog extends Model
{
    use SoftDeletes, LogsActivity;

    protected $fillable = [
        'company_id', 'batch_ids', 'total_weight_kg', 'export_type',
        'destination_country', 'destination_company', 'destination_address',
        'manifest_number', 'invoice_number', 'coa_number',
        'container_number', 'vessel_name',
        'port_of_loading', 'port_of_discharge',
        'estimated_departure', 'actual_departure',
        'value_usd', 'currency', 'status',
        'generated_documents', 'warnings_acknowledged',
        'notes', 'created_by', 'exported_at',
    ];

    protected $casts = [
        'batch_ids'              => 'array',
        'generated_documents'    => 'array',
        'warnings_acknowledged'  => 'array',
        'total_weight_kg'        => 'float',
        'value_usd'              => 'float',
        'estimated_departure'    => 'datetime',
        'actual_departure'       => 'datetime',
        'exported_at'            => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'export_type', 'total_weight_kg'])
            ->logOnlyDirty();
    }

    // ─── Relationships ─────────────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ─── Helper Methods ───────────────────────────────────────────────────

    public function getBatches()
    {
        return Batch::whereIn('id', $this->batch_ids ?? [])->get();
    }

    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            'draft'     => 'Draft',
            'confirmed' => 'Dikonfirmasi',
            'shipped'   => 'Dikirim',
            'delivered' => 'Diterima',
            default     => $this->status,
        };
    }

    public function getTypeLabelAttribute(): string
    {
        return match($this->export_type) {
            'international' => 'Ekspor Internasional',
            'domestic'      => 'Penjualan Domestik',
            default         => $this->export_type,
        };
    }

    // ─── Scopes ───────────────────────────────────────────────────────────

    public function scopeOfCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeInternational($query)
    {
        return $query->where('export_type', 'international');
    }

    public function scopeDomestic($query)
    {
        return $query->where('export_type', 'domestic');
    }
}