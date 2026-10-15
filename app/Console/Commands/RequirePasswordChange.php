<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * users:require-password-change — tandai pengguna agar WAJIB mengganti password pada login berikutnya.
 * Aman dijalankan berulang. Gunakan --dry-run untuk melihat jumlah yang terdampak.
 *
 *   php artisan users:require-password-change --all --dry-run
 *   php artisan users:require-password-change --role=operator_wet --role=operator_dry
 *   php artisan users:require-password-change --email=admin@contoh.co.id
 */
class RequirePasswordChange extends Command
{
    protected $signature = 'users:require-password-change
        {--all : semua pengguna aktif}
        {--role=* : hanya peran tertentu}
        {--email=* : hanya email tertentu}
        {--dry-run : hanya tampilkan jumlah, tanpa mengubah}';

    protected $description = 'Wajibkan pengguna mengganti password pada login berikutnya';

    public function handle(): int
    {
        if (! $this->option('all') && ! $this->option('role') && ! $this->option('email')) {
            $this->error('Tentukan --all, --role, atau --email.');
            return self::FAILURE;
        }

        $query = User::query()->where('is_active', true)->where('force_password_change', false);

        if (! $this->option('all')) {
            $query->where(function ($q) {
                if ($roles = $this->option('role')) {
                    $q->orWhereHas('roles', fn ($r) => $r->whereIn('name', $roles));
                }
                if ($emails = $this->option('email')) {
                    $q->orWhereIn('email', $emails);
                }
            });
        }

        $count = $query->count();

        if ($this->option('dry-run')) {
            $this->info("[dry-run] {$count} pengguna akan diwajibkan mengganti password.");
            return self::SUCCESS;
        }

        $query->update(['force_password_change' => true]);
        $this->info("{$count} pengguna diwajibkan mengganti password pada login berikutnya.");

        return self::SUCCESS;
    }
}
