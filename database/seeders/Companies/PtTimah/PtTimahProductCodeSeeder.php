<?php

namespace Database\Seeders\Companies\PtTimah;

use Illuminate\Database\Seeder;
use App\Models\Company;
use App\Models\ProductCode;

/**
 * PtTimahProductCodeSeeder — 10 kode produk resmi PT Timah.
 *
 * PERUBAHAN:
 *   - MIK (Mineral Ikutan Konsentrat) → MIT (Mineral Ikutan Timah)
 *   - SNH (Timah Kadar Tinggi) DIHAPUS — komoditas timah tidak di-trace
 *
 * TERMINOLOGI:
 *   MIT = Mineral Ikutan Timah     → batch awal dari Lab Kimia (belum dipisah)
 *   MON = Monasit                  → output Dry Process, dikirim ke Lab LTJ
 *   ZIR = Zirkon                   → output Dry Process, langsung ke Warehouse
 *   ILM = Ilmenit                  → output Dry Process, langsung ke Warehouse
 *   REE, LAT, CER, PRS, NEO, YTR  → hasil analisis Project Plant
 */
class PtTimahProductCodeSeeder extends Seeder
{
    public function run(): void
    {
        $timah = Company::where('code', 'TIMAH')->firstOrFail();

        $products = [
            // ─── RAW MATERIAL: Mineral Ikutan Timah ──────────────────────
            // Batch PERTAMA dalam sistem. Dibuat oleh operator_lab_station
            // atau operator_wet di Lab Kimia / Receiving Area.
            // Belum dipisahkan: ZIR + ILM + MON masih tercampur.
            [
                'code'             => 'MIT',
                'name'             => 'Mineral Ikutan Timah',
                'name_en'          => 'Tin Mineral By-product (MIT)',
                'category'         => 'raw_material',
                'chemical_symbol'  => null,
                'is_hazardous'     => false,
                'radioactive_level'=> 'none',
                'hazard_types'     => [],
                'is_export_blocked'=> false,
                'origin_stage'     => 'lab_kimia',
                'description'      => 'Material input utama sistem traceability. ' .
                                      'Hasil samping penambangan timah yang belum dipisahkan. ' .
                                      'Batch pertama dibuat di Lab Kimia oleh Operator Lab Kimia atau Wet Process.',
            ],

            // ─── KONSENTRAT: Output Dry Process (Magnetic Separator) ─────
            // Dihasilkan dari transformasi batch MIT di Dry Process.
            // 3 batch output terpisah: MON + ZIR + ILM.
            [
                'code'                => 'MON',
                'name'                => 'Monasit (Monazite)',
                'name_en'             => 'Monazite Concentrate',
                'category'            => 'concentrate',
                'chemical_symbol'     => null,
                'is_hazardous'        => true,
                'radioactive_level'   => 'medium',
                'hazard_types'        => ['radioactive'],
                'is_export_blocked'   => true,
                'export_block_reason' => 'Monasit diblokir ekspor berdasarkan regulasi nasional (UU Minerba & Peraturan BAPETEN).',
                'origin_stage'        => 'dry_process',
                'regulatory_notes'    => 'Material radioaktif — mengandung ThO₂ dan U₃O₈. Wajib mengikuti prosedur BAPETEN. ' .
                                         'Tidak boleh diekspor. Dikirim ke Project Plant untuk analisis LTJ.',
                'description'         => 'Konsentrat Monasit — sumber utama unsur La, Ce, Pr, Nd, Y. ' .
                                         'Dari Dry Process → Warehouse → Project Plant → kembali ke Warehouse.',
            ],
            [
                'code'             => 'ZIR',
                'name'             => 'Zirkon (Zircon)',
                'name_en'          => 'Zircon Concentrate',
                'category'         => 'concentrate',
                'chemical_symbol'  => 'ZrO2',
                'is_hazardous'     => false,
                'radioactive_level'=> 'none',
                'hazard_types'     => [],
                'is_export_blocked'=> false,
                'origin_stage'     => 'dry_process',
                'description'      => 'Konsentrat Zirkon (ZrO₂). Industri keramik, bahan tahan api, nuklir. ' .
                                      'Dapat diekspor langsung dari LTJ Warehouse.',
            ],
            [
                'code'             => 'ILM',
                'name'             => 'Ilmenit (Ilmenite)',
                'name_en'          => 'Ilmenite Concentrate',
                'category'         => 'concentrate',
                'chemical_symbol'  => 'FeTiO3',
                'is_hazardous'     => false,
                'radioactive_level'=> 'none',
                'hazard_types'     => [],
                'is_export_blocked'=> false,
                'origin_stage'     => 'dry_process',
                'description'      => 'Konsentrat Ilmenit (FeTiO₃). Industri pigmen TiO₂ dan titanium. ' .
                                      'Dapat diekspor langsung dari LTJ Warehouse.',
            ],

            // ─── REE ELEMENTS: Output Project Plant ──────────────────────
            // Dihasilkan dari analisis LTJ (Lab Analisis) terhadap batch MON.
            [
                'code'             => 'REE',
                'name'             => 'Campuran Unsur LTJ (Mixed REE)',
                'name_en'          => 'Mixed Rare Earth Elements',
                'category'         => 'mixed_ree',
                'chemical_symbol'  => 'REE',
                'is_hazardous'     => false,
                'radioactive_level'=> 'none',
                'hazard_types'     => [],
                'is_export_blocked'=> false,
                'origin_stage'     => 'project_plant',
                'description'      => 'Campuran unsur LTJ dari hasil analisis Monasit. Dapat di-split menjadi LAT, CER, PRS, NEO, YTR.',
            ],
            [
                'code'             => 'LAT',
                'name'             => 'Lantanum (La)',
                'name_en'          => 'Lanthanum',
                'category'         => 'ree_element',
                'chemical_symbol'  => 'La',
                'is_hazardous'     => false,
                'radioactive_level'=> 'none',
                'hazard_types'     => [],
                'is_export_blocked'=> false,
                'origin_stage'     => 'project_plant',
                'description'      => 'Lantanum murni (La, No.57). Baterai hibrid Ni-MH, katalis minyak, kaca optik.',
            ],
            [
                'code'             => 'CER',
                'name'             => 'Serium (Ce)',
                'name_en'          => 'Cerium',
                'category'         => 'ree_element',
                'chemical_symbol'  => 'Ce',
                'is_hazardous'     => false,
                'radioactive_level'=> 'none',
                'hazard_types'     => [],
                'is_export_blocked'=> false,
                'origin_stage'     => 'project_plant',
                'description'      => 'Serium murni (Ce, No.58). REE paling melimpah. Katalis konverter otomotif, pemoles kaca.',
            ],
            [
                'code'             => 'PRS',
                'name'             => 'Praseodimium (Pr)',
                'name_en'          => 'Praseodymium',
                'category'         => 'ree_element',
                'chemical_symbol'  => 'Pr',
                'is_hazardous'     => false,
                'radioactive_level'=> 'none',
                'hazard_types'     => [],
                'is_export_blocked'=> false,
                'origin_stage'     => 'project_plant',
                'description'      => 'Praseodimium murni (Pr, No.59). Komponen magnet NdFeB untuk motor EV dan turbin angin.',
            ],
            [
                'code'             => 'NEO',
                'name'             => 'Neodimium (Nd)',
                'name_en'          => 'Neodymium',
                'category'         => 'ree_element',
                'chemical_symbol'  => 'Nd',
                'is_hazardous'     => false,
                'radioactive_level'=> 'none',
                'hazard_types'     => [],
                'is_export_blocked'=> false,
                'origin_stage'     => 'project_plant',
                'description'      => 'Neodimium murni (Nd, No.60). Magnet NdFeB terkuat di dunia. Material strategis EV.',
            ],
            [
                'code'             => 'YTR',
                'name'             => 'Itrium (Y)',
                'name_en'          => 'Yttrium',
                'category'         => 'ree_element',
                'chemical_symbol'  => 'Y',
                'is_hazardous'     => false,
                'radioactive_level'=> 'none',
                'hazard_types'     => [],
                'is_export_blocked'=> false,
                'origin_stage'     => 'project_plant',
                'description'      => 'Itrium murni (Y, No.39). Layar LED, laser medis, dan superkonduktor suhu tinggi.',
            ],
        ];

        // Update MIK → MIT jika sudah ada di database (untuk existing data)
        ProductCode::where('company_id', $timah->id)
            ->where('code', 'MIK')
            ->update([
                'code'    => 'MIT',
                'name'    => 'Mineral Ikutan Timah',
                'name_en' => 'Tin Mineral By-product (MIT)',
            ]);

        // Hapus SNH jika ada
        ProductCode::where('company_id', $timah->id)
            ->where('code', 'SNH')
            ->delete();

        // Upsert semua product codes
        foreach ($products as $data) {
            ProductCode::firstOrCreate(
                ['company_id' => $timah->id, 'code' => $data['code']],
                array_merge($data, [
                    'company_id'     => $timah->id,
                    'is_active'      => true,
                    'specifications' => [],
                ])
            );
        }

        $this->command->info('  ✅ 10 Product Codes PT Timah seeded. (MIT menggantikan MIK, SNH dihapus)');
    }
}