<?php

namespace Database\Seeders\Companies\PtTimah;

use Illuminate\Database\Seeder;
use App\Models\Company;
use App\Models\CompanySetting;

/**
 * PtTimahCompanySettingsSeeder — pengaturan awal PT Timah.
 */
class PtTimahCompanySettingsSeeder extends Seeder
{
    public function run(): void
    {
        $timah = Company::where('code', 'TIMAH')->firstOrFail();

        $settings = [
            [
                'key'         => 'monthly_report_email',
                'value'       => 'admin@ptimah.co.id',
                'type'        => 'string',
                'group'       => 'reporting',
                'label'       => 'Email Laporan Bulanan',
                'description' => 'Email tujuan pengiriman laporan bulanan otomatis setiap tanggal 1.',
            ],
            [
                'key'         => 'monthly_report_enabled',
                'value'       => 'true',
                'type'        => 'boolean',
                'group'       => 'reporting',
                'label'       => 'Laporan Bulanan Otomatis',
                'description' => 'Aktifkan pengiriman laporan bulanan otomatis via email.',
            ],
            [
                'key'         => 'export_block_codes',
                'value'       => '["MON"]',
                'type'        => 'json',
                'group'       => 'export',
                'label'       => 'Kode Produk Blokir Ekspor',
                'description' => 'Daftar kode produk yang diblokir untuk ekspor.',
            ],
            [
                'key'         => 'warehouse_capacity_alert',
                'value'       => 'true',
                'type'        => 'boolean',
                'group'       => 'warehouse',
                'label'       => 'Alert Kapasitas Warehouse',
                'description' => 'Aktifkan notifikasi saat kapasitas warehouse mendekati batas.',
            ],
            [
                'key'         => 'default_timezone',
                'value'       => 'Asia/Jakarta',
                'type'        => 'string',
                'group'       => 'general',
                'label'       => 'Zona Waktu Default',
                'description' => 'Zona waktu operasional sistem.',
            ],
        ];

        foreach ($settings as $data) {
            CompanySetting::firstOrCreate(
                ['company_id' => $timah->id, 'key' => $data['key']],
                array_merge($data, ['company_id' => $timah->id])
            );
        }

        $this->command->info('  ✅ Company Settings PT Timah seeded.');
    }
}