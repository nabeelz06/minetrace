<?php

namespace App\Services\Company;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Location;
use App\Models\MassBalanceThreshold;
use App\Models\ProductCode;
use App\Models\WorkflowTemplate;
use Illuminate\Support\Facades\DB;

/**
 * OnboardingService — setup otomatis company baru dari template.
 *
 * Arsitektur fleksibel:
 * - Template baru bisa ditambah sebagai method baru tanpa mengubah kode lain
 * - Setiap company mendapat copy data sendiri (bukan shared)
 * - Semua data bisa di-customize setelah onboarding
 *
 * Untuk menambah company baru (contoh PT Vale):
 * 1. Panggil setupFromTemplate($company, 'nickel_mining')
 * 2. Atau buat template baru: private function setupNickelMiningTemplate($company)
 */
class OnboardingService
{
    /**
     * Setup company dari template yang dipilih.
     * Entry point utama — dispatch ke template yang sesuai.
     */
    public function setupFromTemplate(Company $company, string $template): void
    {
        DB::transaction(function () use ($company, $template) {
            match($template) {
                'tin_mining'    => $this->setupTinMiningTemplate($company),
                'nickel_mining' => $this->setupNickelMiningTemplate($company),
                default         => null,
            };

            // Default settings selalu dibuat untuk semua template
            $this->setupDefaultSettings($company);
        });
    }

    /**
     * Template: Tin Mining (PT Timah style).
     * Locations: Lab Kimia, Receiving, Wet/Dry Process, Warehouse, Smelter
     * Products: MIK, SNH, MON, ZIR, ILM, REE, LAT, CER, PRS, NEO, YTR
     */
    private function setupTinMiningTemplate(Company $company): void
    {
        // ─── Locations ────────────────────────────────────────────────────
        $locationData = [
            ['code' => 'LAB',  'name' => 'Lab Kimia',     'location_type' => 'lab_kimia',     'notes' => 'Titik awal sistem traceability.'],
            ['code' => 'RCV',  'name' => 'Receiving',      'location_type' => 'receiving',     'notes' => 'Area penerimaan material.'],
            ['code' => 'WTP',  'name' => 'Wet Process',    'location_type' => 'wet_process',   'notes' => 'Fasilitas Wet Process (shaking table).'],
            ['code' => 'DRP',  'name' => 'Dry Process',    'location_type' => 'dry_process',   'notes' => 'Fasilitas Dry Process (magnetik).'],
            ['code' => 'PPL',  'name' => 'Project Plant',  'location_type' => 'project_plant', 'notes' => 'Fasilitas ekstraksi LTJ.'],
            ['code' => 'WHS',  'name' => 'LTJ Warehouse',  'location_type' => 'warehouse',     'max_capacity_kg' => 500000, 'capacity_warning_pct' => 80, 'notes' => 'Penyimpanan akhir.'],
            ['code' => 'SML',  'name' => 'Smelter',        'location_type' => 'smelter',       'notes' => 'Terminal tracking.'],
        ];

        foreach ($locationData as $data) {
            Location::firstOrCreate(
                ['company_id' => $company->id, 'code' => $data['code']],
                array_merge($data, ['company_id' => $company->id, 'is_active' => true])
            );
        }

        // ─── Product Codes ────────────────────────────────────────────────
        $this->createTinMiningProductCodes($company->id);

        // ─── Mass Balance Thresholds ──────────────────────────────────────
        $this->createTinMiningThresholds($company->id);

        // ─── Workflow Template ────────────────────────────────────────────
        $this->createTinMiningWorkflow($company->id);
    }

    /**
     * Template: Nickel Mining (PT Vale style).
     * Dapat dikembangkan sesuai kebutuhan PT Vale.
     */
    private function setupNickelMiningTemplate(Company $company): void
    {
        // ─── Locations (Nickel processing chain) ──────────────────────────
        $locationData = [
            ['code' => 'LAB',  'name' => 'Lab Kimia',    'location_type' => 'lab_kimia',     'notes' => 'Titik awal sistem.'],
            ['code' => 'MIN',  'name' => 'Mine Site',     'location_type' => 'receiving',     'notes' => 'Area tambang nikel.'],
            ['code' => 'SMT',  'name' => 'Smelting',      'location_type' => 'wet_process',   'notes' => 'Fasilitas peleburan nikel.'],
            ['code' => 'REF',  'name' => 'Refinery',      'location_type' => 'dry_process',   'notes' => 'Pemurnian nikel.'],
            ['code' => 'WHS',  'name' => 'Warehouse',     'location_type' => 'warehouse',     'max_capacity_kg' => 1000000, 'capacity_warning_pct' => 75],
        ];

        foreach ($locationData as $data) {
            Location::firstOrCreate(
                ['company_id' => $company->id, 'code' => $data['code']],
                array_merge($data, ['company_id' => $company->id, 'is_active' => true])
            );
        }

        // ─── Basic Product Codes (Nickel) ─────────────────────────────────
        $products = [
            ['code' => 'NIO',  'name' => 'Nickel Ore',      'category' => 'raw_material', 'chemical_symbol' => 'Ni'],
            ['code' => 'NIC',  'name' => 'Nickel Concentrate','category' => 'concentrate', 'chemical_symbol' => 'Ni'],
            ['code' => 'NIS',  'name' => 'Nickel Sulfide',   'category' => 'concentrate', 'chemical_symbol' => 'NiS'],
            ['code' => 'NIP',  'name' => 'Nickel Pig Iron',  'category' => 'mixed_ree',   'chemical_symbol' => 'Ni+Fe'],
            ['code' => 'COB',  'name' => 'Cobalt',           'category' => 'ree_element', 'chemical_symbol' => 'Co'],
        ];

        foreach ($products as $data) {
            ProductCode::firstOrCreate(
                ['company_id' => $company->id, 'code' => $data['code']],
                array_merge($data, ['company_id' => $company->id, 'is_active' => true,
                    'is_hazardous' => false, 'radioactive_level' => 'none',
                    'hazard_types' => [], 'is_export_blocked' => false])
            );
        }

        // ─── Default thresholds ───────────────────────────────────────────
        $this->createDefaultThresholds($company->id);

        // ─── Simple workflow ──────────────────────────────────────────────
        WorkflowTemplate::firstOrCreate(
            ['company_id' => $company->id, 'slug' => strtolower($company->code) . '-default'],
            [
                'company_id'  => $company->id,
                'name'        => "Workflow Default {$company->name}",
                'slug'        => strtolower($company->code) . '-default',
                'is_default'  => true,
                'is_active'   => true,
                'stages'      => $this->getNickelMiningStages(),
                'transformation_rules' => [],
                'description' => "Alur proses traceability {$company->name}.",
            ]
        );
    }

    /**
     * Default settings untuk semua company.
     */
    private function setupDefaultSettings(Company $company): void
    {
        $defaults = [
            ['key' => 'monthly_report_enabled', 'value' => 'true',  'type' => 'boolean', 'group' => 'reporting', 'label' => 'Laporan Bulanan Otomatis'],
            ['key' => 'monthly_report_email',   'value' => $company->email ?? '', 'type' => 'string', 'group' => 'reporting', 'label' => 'Email Laporan Bulanan'],
            ['key' => 'warehouse_capacity_alert', 'value' => 'true', 'type' => 'boolean', 'group' => 'warehouse', 'label' => 'Alert Kapasitas Warehouse'],
            ['key' => 'default_timezone',       'value' => 'Asia/Jakarta', 'type' => 'string', 'group' => 'general', 'label' => 'Zona Waktu'],
            ['key' => 'export_block_codes',     'value' => '[]', 'type' => 'json', 'group' => 'export', 'label' => 'Kode Produk Blokir Ekspor'],
        ];

        foreach ($defaults as $setting) {
            CompanySetting::firstOrCreate(
                ['company_id' => $company->id, 'key' => $setting['key']],
                array_merge($setting, ['company_id' => $company->id])
            );
        }
    }

    // ─── Helper: Tin Mining Product Codes ─────────────────────────────────

    private function createTinMiningProductCodes(int $companyId): void
    {
        $products = [
            ['code'=>'MIK','name'=>'Mineral Ikutan Konsentrat','name_en'=>'Mineral Concentrate','category'=>'raw_material','is_export_blocked'=>false,'is_hazardous'=>false,'radioactive_level'=>'none'],
            ['code'=>'SNH','name'=>'Timah Kadar Tinggi (Sn>70%)','name_en'=>'High-Grade Tin','category'=>'raw_material','chemical_symbol'=>'Sn','is_export_blocked'=>false,'is_hazardous'=>false,'radioactive_level'=>'none'],
            ['code'=>'MON','name'=>'Monasit (Monazite)','name_en'=>'Monazite Concentrate','category'=>'concentrate','is_export_blocked'=>true,'export_block_reason'=>'Diblokir regulasi nasional. Wajib diproses di Project Plant.','is_hazardous'=>true,'radioactive_level'=>'medium','hazard_types'=>['radioactive']],
            ['code'=>'ZIR','name'=>'Zirkon (Zircon)','name_en'=>'Zircon Concentrate','category'=>'concentrate','chemical_symbol'=>'ZrO2','is_export_blocked'=>false,'is_hazardous'=>false,'radioactive_level'=>'none'],
            ['code'=>'ILM','name'=>'Ilmenit (Ilmenite)','name_en'=>'Ilmenite Concentrate','category'=>'concentrate','chemical_symbol'=>'FeTiO3','is_export_blocked'=>false,'is_hazardous'=>false,'radioactive_level'=>'none'],
            ['code'=>'REE','name'=>'Campuran Unsur LTJ','name_en'=>'Mixed REE','category'=>'mixed_ree','chemical_symbol'=>'REE','is_export_blocked'=>false,'is_hazardous'=>false,'radioactive_level'=>'none'],
            ['code'=>'LAT','name'=>'Lantanum (La)','name_en'=>'Lanthanum','category'=>'ree_element','chemical_symbol'=>'La','is_export_blocked'=>false,'is_hazardous'=>false,'radioactive_level'=>'none'],
            ['code'=>'CER','name'=>'Serium (Ce)','name_en'=>'Cerium','category'=>'ree_element','chemical_symbol'=>'Ce','is_export_blocked'=>false,'is_hazardous'=>false,'radioactive_level'=>'none'],
            ['code'=>'PRS','name'=>'Praseodimium (Pr)','name_en'=>'Praseodymium','category'=>'ree_element','chemical_symbol'=>'Pr','is_export_blocked'=>false,'is_hazardous'=>false,'radioactive_level'=>'none'],
            ['code'=>'NEO','name'=>'Neodimium (Nd)','name_en'=>'Neodymium','category'=>'ree_element','chemical_symbol'=>'Nd','is_export_blocked'=>false,'is_hazardous'=>false,'radioactive_level'=>'none'],
            ['code'=>'YTR','name'=>'Itrium (Y)','name_en'=>'Yttrium','category'=>'ree_element','chemical_symbol'=>'Y','is_export_blocked'=>false,'is_hazardous'=>false,'radioactive_level'=>'none'],
        ];

        foreach ($products as $data) {
            ProductCode::firstOrCreate(
                ['company_id' => $companyId, 'code' => $data['code']],
                array_merge($data, [
                    'company_id'   => $companyId,
                    'is_active'    => true,
                    'hazard_types' => $data['hazard_types'] ?? [],
                    'specifications' => [],
                ])
            );
        }
    }

    private function createTinMiningThresholds(int $companyId): void
    {
        $thresholds = [
            ['process_stage'=>'lab_kimia',     'stage_label'=>'Lab Kimia',     'warning_threshold_pct'=>1.00,  'critical_threshold_pct'=>3.00],
            ['process_stage'=>'receiving',      'stage_label'=>'Receiving Area', 'warning_threshold_pct'=>1.00,  'critical_threshold_pct'=>3.00],
            ['process_stage'=>'wet_process',    'stage_label'=>'Wet Process',   'warning_threshold_pct'=>15.00, 'critical_threshold_pct'=>30.00],
            ['process_stage'=>'dry_process',    'stage_label'=>'Dry Process',   'warning_threshold_pct'=>3.00,  'critical_threshold_pct'=>8.00],
            ['process_stage'=>'project_plant',  'stage_label'=>'Project Plant', 'warning_threshold_pct'=>25.00, 'critical_threshold_pct'=>45.00],
            ['process_stage'=>'warehouse',      'stage_label'=>'LTJ Warehouse', 'warning_threshold_pct'=>0.50,  'critical_threshold_pct'=>1.00],
            ['process_stage'=>'in_transit',     'stage_label'=>'Transit',       'warning_threshold_pct'=>0.50,  'critical_threshold_pct'=>2.00],
        ];

        foreach ($thresholds as $data) {
            MassBalanceThreshold::firstOrCreate(
                ['company_id' => $companyId, 'process_stage' => $data['process_stage']],
                array_merge($data, ['company_id' => $companyId])
            );
        }
    }

    private function createDefaultThresholds(int $companyId): void
    {
        $thresholds = [
            ['process_stage'=>'receiving', 'stage_label'=>'Receiving', 'warning_threshold_pct'=>2.00, 'critical_threshold_pct'=>5.00],
            ['process_stage'=>'warehouse', 'stage_label'=>'Warehouse', 'warning_threshold_pct'=>0.50, 'critical_threshold_pct'=>1.00],
        ];
        foreach ($thresholds as $data) {
            MassBalanceThreshold::firstOrCreate(
                ['company_id' => $companyId, 'process_stage' => $data['process_stage']],
                array_merge($data, ['company_id' => $companyId])
            );
        }
    }

    private function createTinMiningWorkflow(int $companyId): void
    {
        $stages = [
            ['code'=>'lab_kimia','name'=>'Lab Kimia','name_en'=>'Chemical Lab','allowed_locations'=>['LAB'],'output_products'=>['MIK'],'next_stages'=>['receiving'],'is_terminal'=>false,'creates_new_batch'=>true,'required_fields'=>['product_code_id','initial_weight','location_id']],
            ['code'=>'receiving','name'=>'Receiving Area','name_en'=>'Receiving Area','allowed_locations'=>['RCV'],'input_products'=>['MIK'],'output_products'=>['MIK'],'next_stages'=>['wet_process','dry_process'],'is_terminal'=>false,'creates_new_batch'=>false,'required_fields'=>['weight_recorded','is_verified']],
            ['code'=>'wet_process','name'=>'Wet Process','name_en'=>'Wet Process','allowed_locations'=>['WTP'],'input_products'=>['MIK'],'output_products'=>['MIK','SNH'],'next_stages'=>['dry_process','smelter'],'is_terminal'=>false,'creates_new_batch'=>false,'required_fields'=>['weight_recorded']],
            ['code'=>'dry_process','name'=>'Dry Process','name_en'=>'Dry Process','allowed_locations'=>['DRP'],'input_products'=>['MIK'],'output_products'=>['MON','ZIR','ILM'],'next_stages'=>['project_plant','warehouse'],'is_terminal'=>false,'creates_new_batch'=>true,'transformation'=>['type'=>'multi_output','outputs'=>['MON','ZIR','ILM']],'required_fields'=>['mon_weight_kg','zir_weight_kg','ilm_weight_kg']],
            ['code'=>'project_plant','name'=>'Project Plant','name_en'=>'Project Plant','allowed_locations'=>['PPL'],'input_products'=>['MON'],'output_products'=>['REE'],'next_stages'=>['warehouse'],'is_terminal'=>false,'creates_new_batch'=>true,'required_fields'=>['output_weight_kg','la_pct','ce_pct','pr_pct','nd_pct','y_pct']],
            ['code'=>'warehouse','name'=>'LTJ Warehouse','name_en'=>'LTJ Warehouse','allowed_locations'=>['WHS'],'next_stages'=>['exported','project_plant'],'is_terminal'=>false,'creates_new_batch'=>false,'export_blocked'=>['MON'],'required_fields'=>['storage_location']],
            ['code'=>'smelter','name'=>'Smelter (Terminal)','name_en'=>'Smelter','allowed_locations'=>['SML'],'input_products'=>['SNH'],'next_stages'=>[],'is_terminal'=>true,'creates_new_batch'=>false,'required_fields'=>['weight_recorded']],
        ];

        WorkflowTemplate::firstOrCreate(
            ['company_id' => $companyId, 'slug' => 'default-tin-mining'],
            [
                'company_id'           => $companyId,
                'name'                 => 'Workflow Tin Mining (Default)',
                'slug'                 => 'default-tin-mining',
                'is_default'           => true,
                'is_active'            => true,
                'stages'               => $stages,
                'transformation_rules' => [
                    'dry_process'   => ['type'=>'multi_output','outputs'=>['MON','ZIR','ILM']],
                    'project_plant' => ['type'=>'single_output_splittable','outputs'=>['REE'],'split_into'=>['LAT','CER','PRS','NEO','YTR']],
                ],
                'description' => 'Template workflow untuk perusahaan pertambangan timah dan LTJ.',
            ]
        );
    }

    private function getNickelMiningStages(): array
    {
        return [
            ['code'=>'lab_kimia','name'=>'Lab Kimia','name_en'=>'Chemical Lab','next_stages'=>['receiving'],'is_terminal'=>false,'creates_new_batch'=>true,'required_fields'=>['product_code_id','initial_weight','location_id']],
            ['code'=>'receiving','name'=>'Mine Site','name_en'=>'Mine Site','next_stages'=>['wet_process'],'is_terminal'=>false,'creates_new_batch'=>false,'required_fields'=>['weight_recorded']],
            ['code'=>'wet_process','name'=>'Smelting','name_en'=>'Smelting','next_stages'=>['dry_process'],'is_terminal'=>false,'creates_new_batch'=>false,'required_fields'=>['weight_recorded']],
            ['code'=>'dry_process','name'=>'Refinery','name_en'=>'Refinery','next_stages'=>['warehouse'],'is_terminal'=>false,'creates_new_batch'=>false,'required_fields'=>['weight_recorded']],
            ['code'=>'warehouse','name'=>'Warehouse','name_en'=>'Warehouse','next_stages'=>['exported'],'is_terminal'=>false,'creates_new_batch'=>false,'required_fields'=>['storage_location']],
        ];
    }
}