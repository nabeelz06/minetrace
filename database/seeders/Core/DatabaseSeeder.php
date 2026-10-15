<?php

namespace Database\Seeders\Core;

use Illuminate\Database\Seeder;
use Database\Seeders\Companies\PtTimah\PtTimahLocationSeeder;
use Database\Seeders\Companies\PtTimah\PtTimahProductCodeSeeder;
use Database\Seeders\Companies\PtTimah\PtTimahMassBalanceSeeder;
use Database\Seeders\Companies\PtTimah\PtTimahCompanySettingsSeeder;
use Database\Seeders\Companies\PtTimah\PtTimahWorkflowSeeder;
use Database\Seeders\Companies\PtTimah\PtTimahCctvCameraSeeder;

/**
 * DatabaseSeeder — master entry point semua seeder MINETRACE.
 *
 * Urutan eksekusi penting karena ada foreign key dependencies:
 * 1. Roles & Permissions (tidak ada dependency)
 * 2. Companies (butuh permissions)
 * 3. Users (butuh companies + roles)
 * 4. Operational data per company (butuh users + companies)
 *
 * Untuk onboard company baru (PT Vale):
 * Tambahkan Phase 3 di bawah dengan pattern yang sama.
 * Tidak perlu mengubah kode Phase 1 & 2 sama sekali.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('');
        $this->command->info('╔══════════════════════════════════════════╗');
        $this->command->info('║   MINETRACE — Database Seeding             ║');
        $this->command->info('╚══════════════════════════════════════════╝');
        $this->command->newLine();

        // ─── Phase 1: Core auth & master data ─────────────────────────────
        $this->command->info('📋 Phase 1: Core Auth & Master Data');
        $this->call([
            RolesAndPermissionsSeeder::class,
            CompanySeeder::class,
            UserSeeder::class,
        ]);

        // ─── Phase 2: PT Timah operational data ───────────────────────────
        $this->command->newLine();
        $this->command->info('🏭 Phase 2: PT Timah Operational Data');
        $this->call([
            PtTimahLocationSeeder::class,
            PtTimahProductCodeSeeder::class,
            PtTimahMassBalanceSeeder::class,
            PtTimahCompanySettingsSeeder::class,
            PtTimahWorkflowSeeder::class,
            PtTimahCctvCameraSeeder::class,
        ]);

        /*
        |─────────────────────────────────────────────────────────────────
        | TEMPLATE ONBOARD COMPANY BARU — Salin blok ini untuk PT Vale:
        |
        | use Database\Seeders\Companies\PtVale\PtValeLocationSeeder;
        | use Database\Seeders\Companies\PtVale\PtValeProductCodeSeeder;
        | ...
        |
        | $this->command->newLine();
        | $this->command->info('🏭 Phase 3: PT Vale Operational Data');
        | $this->call([
        |     PtValeLocationSeeder::class,
        |     PtValeProductCodeSeeder::class,
        |     PtValeMassBalanceSeeder::class,
        |     PtValeCompanySettingsSeeder::class,
        |     PtValeWorkflowSeeder::class,
        | ]);
        |─────────────────────────────────────────────────────────────────
        */

        $this->command->newLine();
        $this->command->info('✅ Seeding selesai!');
    }
}