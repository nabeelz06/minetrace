<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

/**
 * HandleInertiaRequests — share data global ke semua halaman Inertia.
 *
 * `can` object mencakup semua permission flags yang dibutuhkan frontend
 * agar komponen Vue bisa menampilkan/menyembunyikan UI berdasarkan role.
 */
class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user    = Auth::user();
        $company = $user?->company;

        return array_merge(parent::share($request), [

            // ─── Auth & User ──────────────────────────────────────────────
            'auth' => [
                'user' => $user ? [
                    'id'          => $user->id,
                    'name'        => $user->name,
                    'email'       => $user->email,
                    'role'        => $user->getRoleNames()->first() ?? '',
                    'avatar_url'  => $user->avatar_url,
                    'language'    => $user->language ?? 'id',
                    'dark_mode'   => $user->dark_mode ?? false,
                    'company_id'  => $user->company_id,
                    'employee_id' => $user->employee_id,
                    'department'  => $user->department,
                    'phone'       => $user->phone,
                ] : null,
                'company' => $company ? [
                    'id'            => $company->id,
                    'name'          => $company->name,
                    'code'          => $company->code,
                    'primary_color' => $company->primary_color ?? '#3E5C74',
                    'is_regulator'  => $company->is_regulator,
                    'industry'      => $company->industry,
                    'city'          => $company->city,
                ] : null,
            ],

            // ─── Permissions — semua flags yang dibutuhkan frontend ────────
            'can' => $user ? [
                // ── System ──────────────────────────────────────────────────
                'manage_system'          => $user->hasRole('super_admin'),
                'manage_users'           => $user->hasRole('super_admin'),
                'manage_companies'       => $user->hasRole('super_admin'),

                // ── BIM ─────────────────────────────────────────────────────
                'view_bim'               => $user->hasAnyRole(['bim_admin','bim_viewer','government_auditor']),
                'bim_export_data'        => $user->hasRole('bim_admin'),
                'is_bim'                 => $user->hasAnyRole(['bim_admin','bim_viewer','government_auditor']),

                // ── Company Admin ────────────────────────────────────────────
                'is_company_admin'       => $user->hasRole('company_admin'),
                'manage_settings'        => $user->hasRole('company_admin'),
                'view_all_company'       => $user->hasAnyRole(['company_admin','company_auditor']),

                // ── Batch (Lab Kimia Station) ────────────────────────────────
                'create_batch'           => $user->hasAnyRole(['company_admin','operator_lab_station']),
                'is_operator_lab_station'=> $user->hasRole('operator_lab_station'),

                // ── Wet Process ──────────────────────────────────────────────
                'is_operator_wet'        => $user->hasRole('operator_wet'),
                'can_receive_batch'      => $user->hasAnyRole(['company_admin','operator_wet']),
                'can_wet_process'        => $user->hasAnyRole(['company_admin','operator_wet']),

                // ── Dry Process ──────────────────────────────────────────────
                'is_operator_dry'        => $user->hasRole('operator_dry'),
                'can_dry_process'        => $user->hasAnyRole(['company_admin','operator_dry']),
                'can_input_concentrates' => $user->hasAnyRole(['company_admin','operator_dry']),

                // ── Lab LTJ ──────────────────────────────────────────────────
                'is_operator_lab_ltj'    => $user->hasRole('operator_lab_ltj'),
                'input_lab'              => $user->hasAnyRole(['company_admin','operator_lab_ltj']),
                'validate_lab'           => $user->hasAnyRole(['company_admin','operator_lab_ltj']),
                'can_send_to_warehouse'  => $user->hasAnyRole(['company_admin','operator_lab_ltj']),

                // ── Warehouse ─────────────────────────────────────────────────
                'is_operator_warehouse'  => $user->hasRole('operator_warehouse'),
                'manage_warehouse'       => $user->hasAnyRole(['company_admin','operator_warehouse']),
                'can_send_to_ltj_lab'    => $user->hasAnyRole(['company_admin','operator_warehouse']),
                'prepare_export'         => $user->hasAnyRole(['company_admin','operator_warehouse']),

                // ── Export ────────────────────────────────────────────────────
                'create_export'          => $user->hasAnyRole(['company_admin','operator_warehouse']),
                'view_export'            => $user->hasAnyRole([
                    'company_admin','operator_warehouse','company_auditor',
                    'bim_admin','bim_viewer','government_auditor',
                ]),

                // ── Reports ───────────────────────────────────────────────────
                'view_reports'           => $user->hasAnyRole(['company_admin','company_auditor']),
                'download_reports'       => $user->hasAnyRole(['company_admin','company_auditor']),

                // ── Checkpoint (semua operator + admin) ───────────────────────
                'record_checkpoint'      => $user->hasAnyRole([
                    'company_admin',
                    'operator_lab_station','operator_lab_ltj',
                    'operator_wet','operator_dry','operator_warehouse',
                ]),

                // ── Read-only flag ────────────────────────────────────────────
                'read_only'              => $user->hasAnyRole([
                    'company_auditor','bim_viewer','government_auditor',
                ]),

                // ── Role identity checks (untuk conditional UI) ───────────────
                'is_super_admin'         => $user->hasRole('super_admin'),
                'is_auditor'             => $user->hasRole('company_auditor'),
                'is_company_user'        => $user->hasAnyRole([
                    'company_admin',
                    'operator_lab_station','operator_lab_ltj',
                    'operator_wet','operator_dry','operator_warehouse',
                    'company_auditor',
                ]),

                // ── Allowed stages (untuk process navigation) ─────────────────
                'allowed_stages'         => $this->getAllowedStages($user),

            ] : [],

            // ─── Flash messages ───────────────────────────────────────────
            'flash' => [
                'success' => $request->session()->get('success'),
                'error'   => $request->session()->get('error'),
                'warning' => $request->session()->get('warning'),
                'info'    => $request->session()->get('info'),
            ],

            // ─── Ziggy routes ─────────────────────────────────────────────
            'ziggy' => fn() => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],
        ]);
    }

    /**
     * Tentukan stage proses yang boleh diakses user.
     */
    private function getAllowedStages($user): array
    {
        if ($user->hasRole('super_admin') || $user->hasRole('company_admin')) {
            return ['lab_kimia','receiving','wet_process','dry_process','project_plant','warehouse','smelter'];
        }
        if ($user->hasRole('operator_lab_station')) return ['lab_kimia'];
        if ($user->hasRole('operator_wet'))         return ['receiving', 'wet_process'];
        if ($user->hasRole('operator_dry'))         return ['dry_process'];
        if ($user->hasRole('operator_lab_ltj'))     return ['project_plant'];
        if ($user->hasRole('operator_warehouse'))   return ['warehouse'];
        if ($user->hasRole('company_auditor'))      return ['lab_kimia','receiving','wet_process','dry_process','project_plant','warehouse'];

        // BIM & auditors — lihat semua tapi tidak bisa aksi
        if ($user->hasAnyRole(['bim_admin','bim_viewer','government_auditor'])) {
            return ['lab_kimia','receiving','wet_process','dry_process','project_plant','warehouse'];
        }

        return [];
    }
}