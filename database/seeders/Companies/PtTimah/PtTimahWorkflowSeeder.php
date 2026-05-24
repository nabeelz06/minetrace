<?php

namespace Database\Seeders\Companies\PtTimah;

use Illuminate\Database\Seeder;
use App\Models\Company;
use App\Models\WorkflowTemplate;
use App\Models\User;

/**
 * PtTimahWorkflowSeeder — workflow template default PT Timah (7 stages).
 */
class PtTimahWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        $timah = Company::where('code', 'TIMAH')->firstOrFail();
        $admin = User::where('email', 'admin@ptimah.co.id')->first();

        $stages = [
            [
                'code'              => 'lab_kimia',
                'name'              => 'Lab Kimia',
                'name_en'           => 'Chemical Lab',
                'description'       => 'Titik awal. Operator Lab Kimia membuat batch MIK pertama.',
                'allowed_locations' => ['SGT', 'LBX'],
                'input_products'    => [],
                'output_products'   => ['MIK'],
                'next_stages'       => ['receiving'],
                'is_terminal'       => false,
                'creates_new_batch' => true,
                'required_fields'   => ['product_code_id', 'initial_weight', 'location_id'],
                'optional_fields'   => ['notes', 'moisture_content'],
            ],
            [
                'code'              => 'receiving',
                'name'              => 'Receiving Area',
                'name_en'           => 'Receiving Area',
                'description'       => 'Verifikasi fisik batch dari Lab Kimia.',
                'allowed_locations' => ['MTK', 'KND'],
                'input_products'    => ['MIK'],
                'output_products'   => ['MIK'],
                'next_stages'       => ['wet_process', 'dry_process'],
                'is_terminal'       => false,
                'creates_new_batch' => false,
                'required_fields'   => ['weight_recorded', 'is_verified'],
                'optional_fields'   => ['notes', 'truck_number', 'driver_name'],
            ],
            [
                'code'              => 'wet_process',
                'name'              => 'Wet Process',
                'name_en'           => 'Wet Process',
                'description'       => 'Pemisahan menggunakan shaking table.',
                'allowed_locations' => ['MTK', 'KND'],
                'input_products'    => ['MIK'],
                'output_products'   => ['MIK', 'SNH'],
                'next_stages'       => ['dry_process', 'smelter'],
                'is_terminal'       => false,
                'creates_new_batch' => false,
                'required_fields'   => ['weight_recorded'],
                'optional_fields'   => ['moisture_content_before', 'moisture_content_after', 'sn_content_removed_kg', 'notes'],
            ],
            [
                'code'              => 'dry_process',
                'name'              => 'Dry Process',
                'name_en'           => 'Dry Process',
                'description'       => 'Ekstraksi magnetik. 1 input → 3 output (MON + ZIR + ILM).',
                'allowed_locations' => ['MTK', 'KND'],
                'input_products'    => ['MIK'],
                'output_products'   => ['MON', 'ZIR', 'ILM'],
                'next_stages'       => ['project_plant', 'warehouse'],
                'is_terminal'       => false,
                'creates_new_batch' => true,
                'transformation'    => [
                    'type'            => 'multi_output',
                    'outputs'         => ['MON', 'ZIR', 'ILM'],
                    'requires_pct'    => true,
                    'requires_weight' => true,
                ],
                'required_fields'   => ['mon_weight_kg', 'mon_pct', 'zir_weight_kg', 'zir_pct', 'ilm_weight_kg', 'ilm_pct'],
                'optional_fields'   => ['notes'],
            ],
            [
                'code'              => 'project_plant',
                'name'              => 'Project Plant',
                'name_en'           => 'Project Plant',
                'description'       => 'Ekstraksi LTJ murni dari Monasit. Output: REE campuran.',
                'allowed_locations' => ['TJU'],
                'input_products'    => ['MON'],
                'output_products'   => ['REE'],
                'next_stages'       => ['warehouse'],
                'is_terminal'       => false,
                'creates_new_batch' => true,
                'transformation'    => ['type' => 'single_output', 'outputs' => ['REE']],
                'required_fields'   => ['output_weight_kg', 'la_pct', 'ce_pct', 'pr_pct', 'nd_pct', 'y_pct'],
                'optional_fields'   => ['tho2_pct', 'u3o8_pct', 'lab_method', 'notes'],
            ],
            [
                'code'              => 'warehouse',
                'name'              => 'LTJ Warehouse',
                'name_en'           => 'LTJ Warehouse',
                'description'       => 'Penyimpanan akhir. Kelola ekspor ZIR dan ILM.',
                'allowed_locations' => ['TJU'],
                'input_products'    => ['MON', 'ZIR', 'ILM', 'REE', 'LAT', 'CER', 'PRS', 'NEO', 'YTR'],
                'output_products'   => ['ZIR', 'ILM', 'REE', 'LAT', 'CER', 'PRS', 'NEO', 'YTR'],
                'next_stages'       => ['exported', 'project_plant'],
                'is_terminal'       => false,
                'creates_new_batch' => false,
                'export_blocked'    => ['MON'],
                'required_fields'   => ['storage_location'],
                'optional_fields'   => ['notes'],
            ],
            [
                'code'              => 'smelter',
                'name'              => 'Smelter (Terminal)',
                'name_en'           => 'Smelter',
                'description'       => 'BATAS AKHIR PELACAKAN. Material Sn >70% masuk ke sini.',
                'allowed_locations' => ['SML'],
                'input_products'    => ['SNH'],
                'output_products'   => [],
                'next_stages'       => [],
                'is_terminal'       => true,
                'creates_new_batch' => false,
                'required_fields'   => ['weight_recorded'],
                'optional_fields'   => ['notes'],
            ],
        ];

        $transformationRules = [
            'dry_process'   => [
                'type'        => 'multi_output',
                'outputs'     => ['MON', 'ZIR', 'ILM'],
                'description' => '1 batch MIK input → MON + ZIR + ILM sebagai 3 batch output baru.',
            ],
            'project_plant' => [
                'type'        => 'single_output_splittable',
                'outputs'     => ['REE'],
                'split_into'  => ['LAT', 'CER', 'PRS', 'NEO', 'YTR'],
                'description' => 'MON input → REE parent batch → split menjadi elemen individual.',
            ],
        ];

        WorkflowTemplate::firstOrCreate(
            ['company_id' => $timah->id, 'slug' => 'pt-timah-default'],
            [
                'company_id'           => $timah->id,
                'name'                 => 'Workflow Default PT Timah',
                'slug'                 => 'pt-timah-default',
                'is_default'           => true,
                'is_active'            => true,
                'stages'               => $stages,
                'transformation_rules' => $transformationRules,
                'description'          => 'Alur proses traceability LTJ PT Timah — dari Lab Kimia hingga Ekspor atau Smelter.',
                'created_by'           => $admin?->id,
            ]
        );

        $this->command->info('  ✅ Workflow Template PT Timah seeded (7 stages).');
    }
}