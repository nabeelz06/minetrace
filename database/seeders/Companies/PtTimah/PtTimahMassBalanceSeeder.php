<?php

namespace Database\Seeders\Companies\PtTimah;

use Illuminate\Database\Seeder;
use App\Models\Company;
use App\Models\MassBalanceThreshold;

/**
 * PtTimahMassBalanceSeeder — threshold susut per tahap proses PT Timah.
 */
class PtTimahMassBalanceSeeder extends Seeder
{
    public function run(): void
    {
        $timah = Company::where('code', 'TIMAH')->firstOrFail();

        $thresholds = [
            [
                'process_stage'          => 'lab_kimia',
                'stage_label'            => 'Lab Kimia',
                'warning_threshold_pct'  => 1.00,
                'critical_threshold_pct' => 3.00,
                'notes'                  => 'Toleransi minimal. Hanya sampling dan transit pendek.',
            ],
            [
                'process_stage'          => 'receiving',
                'stage_label'            => 'Receiving Area',
                'warning_threshold_pct'  => 1.00,
                'critical_threshold_pct' => 3.00,
                'notes'                  => 'Toleransi timbangan ±1-2%. Bukan proses fisik.',
            ],
            [
                'process_stage'          => 'wet_process',
                'stage_label'            => 'Wet Process',
                'warning_threshold_pct'  => 15.00,
                'critical_threshold_pct' => 30.00,
                'notes'                  => 'Loss normal karena pemisahan Sn. Konfirmasi dengan data operasional.',
            ],
            [
                'process_stage'          => 'dry_process',
                'stage_label'            => 'Dry Process',
                'warning_threshold_pct'  => 3.00,
                'critical_threshold_pct' => 8.00,
                'notes'                  => 'Loss rendah. Proses magnetik tanpa bahan yang hilang signifikan.',
            ],
            [
                'process_stage'          => 'project_plant',
                'stage_label'            => 'Project Plant',
                'warning_threshold_pct'  => 25.00,
                'critical_threshold_pct' => 45.00,
                'notes'                  => 'Loss tertinggi. Proses kimia ekstraksi LTJ. Konfirmasi dengan data operasional.',
            ],
            [
                'process_stage'          => 'warehouse',
                'stage_label'            => 'LTJ Warehouse',
                'warning_threshold_pct'  => 0.50,
                'critical_threshold_pct' => 1.00,
                'notes'                  => 'TIDAK boleh ada loss signifikan. Loss >1% = anomali — investigasi segera.',
            ],
            [
                'process_stage'          => 'in_transit',
                'stage_label'            => 'Transit (Pengiriman)',
                'warning_threshold_pct'  => 0.50,
                'critical_threshold_pct' => 2.00,
                'notes'                  => 'Toleransi transit antar lokasi.',
            ],
        ];

        foreach ($thresholds as $data) {
            MassBalanceThreshold::firstOrCreate(
                ['company_id' => $timah->id, 'process_stage' => $data['process_stage']],
                array_merge($data, ['company_id' => $timah->id])
            );
        }

        $this->command->info('  ✅ 7 Mass Balance Thresholds PT Timah seeded.');
        $this->command->warn('  ⚠️  Konfirmasi nilai threshold dengan data operasional PT Timah!');
    }
}