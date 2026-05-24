<?php

namespace Database\Seeders\Core;

use Illuminate\Database\Seeder;
use App\Models\Company;

/**
 * CompanySeeder
 *
 * Membuat 2 company awal: BIM (regulator) dan PT Timah (tenant).
 * Teams mode: OFF — roles dibuat tanpa team_id di RolesAndPermissionsSeeder.
 */
class CompanySeeder extends Seeder
{
    public function run(): void
    {
        // ─── Company 1: BIM (Regulator) ───────────────────────────────────
        Company::firstOrCreate(
            ['code' => 'BIM'],
            [
                'name'            => 'Badan Industri Mineral',
                'legal_name'      => 'Badan Industri Mineral (BIM)',
                'industry'        => 'Government - Mineral Regulator',
                'primary_color'   => '#CC0000',
                'secondary_color' => '#1A1A1A',
                'schema_name'     => null,
                'city'            => 'Jakarta',
                'province'        => 'DKI Jakarta',
                'is_active'       => true,
                'is_regulator'    => true,
                'onboarded_at'    => now(),
                'settings'        => json_encode([
                    'timezone'       => 'Asia/Jakarta',
                    'default_locale' => 'id',
                ]),
            ]
        );

        // ─── Company 2: PT Timah (Tenant) ─────────────────────────────────
        Company::firstOrCreate(
            ['code' => 'TIMAH'],
            [
                'name'            => 'PT Timah Tbk',
                'legal_name'      => 'PT Timah Tbk (Persero)',
                'industry'        => 'Mining - Tin & Rare Earth',
                'primary_color'   => '#3E5C74',
                'secondary_color' => '#5B8BB4',
                'schema_name'     => 'pt_timah',
                'city'            => 'Pangkal Pinang',
                'province'        => 'Bangka Belitung',
                'phone'           => '+62717421091',
                'email'           => 'info@timah.com',
                'website'         => 'https://www.timah.com',
                'is_active'       => true,
                'is_regulator'    => false,
                'onboarded_at'    => now(),
                'settings'        => json_encode([
                    'timezone'           => 'Asia/Jakarta',
                    'default_locale'     => 'id',
                    'workflow_type'      => 'tin_processing',
                    'export_block_codes' => ['MON'],
                ]),
            ]
        );

        $this->command->info('  ✅ Companies seeded: BIM + PT Timah.');
    }
}