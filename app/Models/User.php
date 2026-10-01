<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Spatie\Activitylog\Traits\CausesActivity;

/**
 * Model User — semua pengguna sistem MINETRA.
 *
 * Menggunakan Spatie HasRoles dengan teams mode (scope per company).
 * Mendukung 8 roles: super_admin, bim_admin, bim_viewer,
 * company_admin, operator_lab, operator_process,
 * company_auditor, government_auditor.
 *
 * @property int     $id
 * @property int     $company_id
 * @property string  $name
 * @property string  $email
 * @property string  $language      'id' atau 'en'
 * @property bool    $dark_mode
 * @property bool    $is_active
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes, HasRoles, CausesActivity, HasApiTokens;

    protected $fillable = [
        'company_id',
        'name',
        'email',
        'password',
        'avatar_path',
        'phone',
        'employee_id',
        'department',
        'language',
        'dark_mode',
        'timezone',
        'is_active',
        'force_password_change',
        'last_login_at',
        'last_login_ip',
        'password_changed_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at'     => 'datetime',
        'password'              => 'hashed',
        'dark_mode'             => 'boolean',
        'is_active'             => 'boolean',
        'force_password_change' => 'boolean',
        'last_login_at'         => 'datetime',
        'password_changed_at'   => 'datetime',
    ];

    // ─── Spatie Permission: nama guard & team key ─────────────────────────

    protected $guard_name = 'web';

    /**
     * Nama kolom team untuk Spatie Permission teams mode.
     * Setiap role di-scope ke company_id.
     */
    public function guardName(): string
    {
        return 'web';
    }

    // ─── Relationships ─────────────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function locationRoles(): HasMany
    {
        return $this->hasMany(UserLocationRole::class);
    }

    // ─── Role Helper Methods ───────────────────────────────────────────────

    /**
     * Cek apakah user punya role, dengan team context yang benar.
     * Selalu gunakan ini daripada hasRole() langsung.
     */
    public function hasRoleInCompany(string|array $role): bool
    {
        if ($this->company_id) {
            setPermissionsTeamId($this->company_id);
        }
        return $this->hasRole($role);
    }

    /**
     * Apakah user adalah Super Admin (bypass semua tenant scope)?
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    /**
     * Apakah user dari BIM (regulator)?
     */
    public function isBIM(): bool
    {
        return $this->company?->is_regulator === true;
    }

    /**
     * Apakah user adalah company operator (bukan BIM, bukan super admin)?
     */
    public function isCompanyUser(): bool
    {
        return !$this->isSuperAdmin() && !$this->isBIM();
    }

    /**
     * Lokasi primary user (untuk pre-fill form input).
     */
    public function primaryLocationId(): ?int
    {
        return $this->locationRoles()
            ->where('is_primary', true)
            ->where(function ($q) {
                $q->whereNull('valid_until')
                  ->orWhere('valid_until', '>=', now()->toDateString());
            })
            ->value('location_id');
    }

    // ─── Accessors ────────────────────────────────────────────────────────

    public function getAvatarUrlAttribute(): string
    {
        if ($this->avatar_path) {
            return asset('storage/' . $this->avatar_path);
        }
        // Fallback: avatar inisial via DiceBear
        $name = urlencode($this->name);
        return "https://api.dicebear.com/7.x/initials/svg?seed={$name}&backgroundColor=3e5c74&textColor=ffffff";
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

    /**
     * Kirim notifikasi reset password bermerek MINETRA (mengganti default
     * Laravel yang mengarah ke route Blade, diarahkan ke halaman Vue kita).
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}