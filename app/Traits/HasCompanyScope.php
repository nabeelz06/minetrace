<?php

namespace App\Traits;

use Illuminate\Support\Facades\Auth;

/**
 * HasCompanyScope — trait untuk controller yang membutuhkan
 * scope company dari user yang sedang login.
 *
 * Gunakan di semua Company controller untuk DRY.
 */
trait HasCompanyScope
{
    /**
     * Ambil company_id dari user yang sedang login.
     * Throw 403 jika user tidak terikat ke company.
     */
    protected function getCompanyId(): int
    {
        $companyId = Auth::user()?->company_id;

        abort_unless($companyId, 403, 'User tidak terikat ke perusahaan manapun.');

        return $companyId;
    }

    /**
     * Pastikan model yang diakses adalah milik company user.
     */
    protected function authorizeCompanyAccess(mixed $model): void
    {
        abort_unless(
            $model->company_id === $this->getCompanyId(),
            403,
            'Akses ditolak — bukan milik perusahaan Anda.'
        );
    }
}