<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model WorkflowTemplate — definisi alur proses per company.
 * Menyimpan stages (JSON) dan transformation_rules (JSON).
 */
class WorkflowTemplate extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id', 'name', 'slug', 'is_default',
        'is_active', 'stages', 'transformation_rules',
        'description', 'created_by',
    ];

    protected $casts = [
        'is_default'           => 'boolean',
        'is_active'            => 'boolean',
        'stages'               => 'array',
        'transformation_rules' => 'array',
    ];

    // ─── Relationships ─────────────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ─── Helper Methods ───────────────────────────────────────────────────

    /**
     * Ambil definisi stage berdasarkan code.
     */
    public function getStage(string $code): ?array
    {
        return collect($this->stages)
            ->firstWhere('code', $code);
    }

    /**
     * Ambil stages yang bisa dijadikan next stage dari stage tertentu.
     */
    public function getNextStages(string $currentStageCode): array
    {
        $stage = $this->getStage($currentStageCode);
        return $stage['next_stages'] ?? [];
    }

    // ─── Scopes ───────────────────────────────────────────────────────────

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function scopeOfCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}