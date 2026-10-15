<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Model Company — entitas perusahaan dalam sistem multi-tenant MINETRACE.
 *
 * Arsitektur fleksibel:
 * - is_regulator: memisahkan BIM dari company operasional
 * - primary_color: branding per-company
 * - settings JSONB: konfigurasi yang dapat diperluas tanpa migrasi baru
 * - schema_name: siap untuk full schema isolation (future)
 *
 * Untuk onboard company baru (PT Vale, MIND ID, dll.):
 * 1. INSERT ke tabel companies
 * 2. Jalankan seeder company tersebut
 * 3. Buat users dengan company_id baru
 * 4. Semua analytics & dashboard langsung berjalan
 */
class Company extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code', 'name', 'legal_name', 'logo_path',
        'industry', 'schema_name',
        'primary_color', 'secondary_color',
        'address', 'city', 'province', 'postal_code',
        'phone', 'email', 'website',
        'is_active', 'is_regulator',
        'onboarded_at', 'settings',
    ];

    protected $casts = [
        'is_active'     => 'boolean',
        'is_regulator'  => 'boolean',
        'onboarded_at'  => 'datetime',
        'settings'      => 'array',
    ];

    // ─── Relationships ─────────────────────────────────────────────────────

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function productCodes(): HasMany
    {
        return $this->hasMany(ProductCode::class);
    }

    public function workflowTemplates(): HasMany
    {
        return $this->hasMany(WorkflowTemplate::class);
    }

    public function massBalanceThresholds(): HasMany
    {
        return $this->hasMany(MassBalanceThreshold::class);
    }

    public function companySettings(): HasMany
    {
        return $this->hasMany(CompanySetting::class);
    }

    public function exportLogs(): HasMany
    {
        return $this->hasMany(ExportLog::class);
    }

    public function labAnalyses(): HasMany
    {
        return $this->hasMany(LabAnalysis::class);
    }

    // ─── Helper Methods ───────────────────────────────────────────────────

    /**
     * Ambil setting company berdasarkan key.
     * Fleksibel — setting baru bisa ditambah tanpa migrasi.
     */
    public function getSetting(string $key, mixed $default = null): mixed
    {
        return CompanySetting::get($this->id, $key, $default);
    }

    /**
     * Ambil workflow template default company ini.
     */
    public function getDefaultWorkflow(): ?WorkflowTemplate
    {
        return $this->workflowTemplates()->where('is_default', true)->first();
    }

    /**
     * Apakah company ini sudah fully onboarded?
     * (punya locations + product codes + workflow)
     */
    public function isFullyOnboarded(): bool
    {
        return $this->locations()->exists()
            && $this->productCodes()->exists()
            && $this->workflowTemplates()->exists();
    }

    // ─── Scopes ───────────────────────────────────────────────────────────

    public function scopeOperational($query)
    {
        return $query->where('is_regulator', false)->where('is_active', true);
    }

    public function scopeRegulator($query)
    {
        return $query->where('is_regulator', true);
    }
}