<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model Location — titik proses fisik operasional.
 *
 * @property int    $id
 * @property int    $company_id
 * @property string $code           Kode lokasi 3 huruf (SGT, MTK, KND, TJU)
 * @property string $location_type  Tipe: lab_kimia, receiving, wet_process, dll.
 * @property float  $max_capacity_kg Kapasitas maksimum (khusus warehouse)
 */
class Location extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id', 'code', 'name', 'full_name', 'location_type',
        'max_capacity_kg', 'capacity_warning_pct',
        'city', 'province', 'latitude', 'longitude',
        'is_active', 'notes',
    ];

    protected $casts = [
        'is_active'           => 'boolean',
        'max_capacity_kg'     => 'float',
        'capacity_warning_pct'=> 'float',
        'latitude'            => 'float',
        'longitude'           => 'float',
    ];

    // ─── Relationships ─────────────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    // ─── Accessors ────────────────────────────────────────────────────────

    /**
     * Label tipe lokasi yang ramah ditampilkan di UI.
     */
    public function getTypeLabel(): string
    {
        return match($this->location_type) {
            'lab_kimia'     => 'Lab Kimia',
            'receiving'     => 'Receiving Area',
            'wet_process'   => 'Wet Process',
            'dry_process'   => 'Dry Process',
            'project_plant' => 'Project Plant',
            'warehouse'     => 'LTJ Warehouse',
            'smelter'       => 'Smelter',
            default         => ucfirst($this->location_type),
        };
    }

    /**
     * Apakah lokasi ini adalah warehouse (punya kapasitas)?
     */
    public function isWarehouse(): bool
    {
        return $this->location_type === 'warehouse';
    }

    // ─── Scopes ───────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOfCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('location_type', $type);
    }
}