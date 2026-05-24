<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model CompanySetting — konfigurasi dinamis per company (key-value).
 */
class CompanySetting extends Model
{
    protected $fillable = [
        'company_id', 'key', 'value', 'type',
        'group', 'label', 'description', 'is_public',
    ];

    protected $casts = [
        'is_public' => 'boolean',
    ];

    // ─── Relationships ─────────────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    // ─── Helper Methods ───────────────────────────────────────────────────

    /**
     * Ambil nilai setting dengan type casting yang benar.
     */
    public function getCastedValue(): mixed
    {
        return match($this->type) {
            'integer' => (int) $this->value,
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'json'    => json_decode($this->value, true),
            default   => $this->value,
        };
    }

    /**
     * Helper static: ambil nilai setting per company dan key.
     */
    public static function get(int $companyId, string $key, mixed $default = null): mixed
    {
        $setting = static::where('company_id', $companyId)
            ->where('key', $key)
            ->first();

        return $setting ? $setting->getCastedValue() : $default;
    }

    // ─── Scopes ───────────────────────────────────────────────────────────

    public function scopeOfCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeGroup($query, string $group)
    {
        return $query->where('group', $group);
    }
}