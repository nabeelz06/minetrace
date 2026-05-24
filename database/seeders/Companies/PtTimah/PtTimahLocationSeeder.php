<?php

namespace Database\Seeders\Companies\PtTimah;

use Illuminate\Database\Seeder;
use App\Models\Company;
use App\Models\Location;

/**
 * PtTimahLocationSeeder — 6 lokasi operasional PT Timah.
 * Namespace: Database\Seeders\Companies\PtTimah
 */
class PtTimahLocationSeeder extends Seeder
{
    public function run(): void
    {
        $timah = Company::where('code', 'TIMAH')->firstOrFail();

        $locations = [
            [
                'code'          => 'SGT',
                'name'          => 'Sungailiat',
                'full_name'     => 'Stasiun Uji Lab Kimia Sungailiat',
                'location_type' => 'lab_kimia',
                'city'          => 'Sungailiat',
                'province'      => 'Bangka Belitung',
                'latitude'      => -1.8573,
                'longitude'     => 106.1278,
                'notes'         => 'Titik awal sistem. Operator Lab Kimia membuat batch pertama setelah uji kadar.',
            ],
            [
                'code'          => 'LBX',
                'name'          => 'Lab X',
                'full_name'     => 'Stasiun Uji Lab Kimia (Lokasi Cadangan)',
                'location_type' => 'lab_kimia',
                'province'      => 'Bangka Belitung',
                'notes'         => 'Placeholder lokasi Lab Kimia tambahan.',
            ],
            [
                'code'          => 'MTK',
                'name'          => 'Muntok',
                'full_name'     => 'Receiving Area & Fasilitas Proses Muntok',
                'location_type' => 'receiving',
                'city'          => 'Muntok',
                'province'      => 'Bangka Belitung',
                'latitude'      => -2.0656,
                'longitude'     => 105.1730,
                'notes'         => 'Receiving, Wet Process, Dry Process, dan Smelter Muntok.',
            ],
            [
                'code'          => 'KND',
                'name'          => 'Kundur',
                'full_name'     => 'Receiving Area & Fasilitas Proses Kundur',
                'location_type' => 'receiving',
                'city'          => 'Kundur',
                'province'      => 'Kepulauan Riau',
                'latitude'      => 0.8667,
                'longitude'     => 103.4833,
                'notes'         => 'Receiving, Wet Process, Dry Process, dan Smelter Kundur.',
            ],
            [
                'code'                => 'TJU',
                'name'                => 'Tanjung Ular',
                'full_name'           => 'Project Plant & LTJ Warehouse Tanjung Ular',
                'location_type'       => 'warehouse',
                'max_capacity_kg'     => 500000.00,
                'capacity_warning_pct'=> 80.00,
                'city'                => 'Tanjung Ular',
                'province'            => 'Bangka Belitung',
                'latitude'            => -2.1000,
                'longitude'           => 105.7500,
                'notes'               => 'Pusat pengolahan LTJ dan penyimpanan akhir. Lokasi ekspor ZIR dan ILM.',
            ],
            [
                'code'          => 'SML',
                'name'          => 'Smelter',
                'full_name'     => 'Unit Metalurgi dan Smelter',
                'location_type' => 'smelter',
                'city'          => 'Muntok',
                'province'      => 'Bangka Belitung',
                'notes'         => 'TERMINAL: Batch Sn>70% masuk ke sini. Status batch menjadi terminated.',
            ],
        ];

        foreach ($locations as $data) {
            Location::firstOrCreate(
                ['company_id' => $timah->id, 'code' => $data['code']],
                array_merge($data, ['company_id' => $timah->id, 'is_active' => true])
            );
        }

        $this->command->info('  ✅ 6 Locations PT Timah seeded.');
    }
}