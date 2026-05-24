<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model ProductCode — master kode material/produk LTJ.
 *
 * @property string $code              Kode 3-5 karakter (MIK, MON, ZIR, NEO)
 * @property bool   $is_export_blocked Apakah ekspor diblokir (MON = true)
 * @property bool   $is_hazardous      Apakah material berbahaya/radioaktif
 */
class ProductCode extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id', 'code', 'name', 'name_en', 'category',
        'chemical_symbol', 'unit', 'is_hazardous', 'radioactive_level',
        'hazard_types', 'regulatory_notes', 'is_export_blocked',
        'export_block_reason', 'origin_stage', 'description',
        'specifications', 'is_active',
    ];

    protected $casts = [
        'is_hazardous'       => 'boolean',
        'is_export_blocked'  => 'boolean',
        'is_active'          => 'boolean',
        'hazard_types'       => 'array',
        'specifications'     => 'array',
    ];

    // ─── Relationships ─────────────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    // ─── Accessors ────────────────────────────────────────────────────────

    /**
     * Label kategori yang ramah ditampilkan.
     */
    public function getCategoryLabel(): string
    {
        return match($this->category) {
            'raw_material' => 'Bahan Baku',
            'concentrate'  => 'Konsentrat',
            'ree_element'  => 'Unsur LTJ Murni',
            'mixed_ree'    => 'Campuran LTJ',
            default        => $this->category,
        };
    }

    /**
     * Label level radioaktif.
     */
    public function getRadioactiveLabelAttribute(): string
    {
        return match($this->radioactive_level) {
            'none'   => 'Tidak Ada',
            'low'    => 'Rendah',
            'medium' => 'Sedang',
            'high'   => 'Tinggi',
            default  => $this->radioactive_level,
        };
    }

    /**
     * Apakah material ini aman untuk diekspor?
     */
    public function isExportable(): bool
    {
        return $this->is_active && !$this->is_export_blocked;
    }

    // ─── Scopes ───────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeExportable($query)
    {
        return $query->where('is_active', true)->where('is_export_blocked', false);
    }

    public function scopeHazardous($query)
    {
        return $query->where('is_hazardous', true);
    }

    public function scopeOfCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}