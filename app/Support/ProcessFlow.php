<?php

namespace App\Support;

/**
 * ProcessFlow — SATU definisi alur tahap proses (sebelumnya tersebar di ≥4 tempat: ProcessController,
 * RfidService, 3 Strategy, AppLayout). Semua pembaca (web, RFID, frontend via props Inertia)
 * mengambil dari sini agar tidak ada drift.
 *
 * Alur: lab_kimia → receiving → wet_process → dry_process → warehouse;
 *       warehouse (MON) → project_plant → warehouse.
 *
 * Model serah-terima (skenario b):
 *   DISPATCH (kirim) : batch TETAP di tahap asal, status → in_transit, berat dicatat.
 *   RECEIVE  (terima): batch PINDAH ke tahap penerima; status in_process (warehouse: in_warehouse).
 */
final class ProcessFlow
{
    public const ORDER = ['lab_kimia', 'receiving', 'wet_process', 'dry_process', 'project_plant', 'warehouse'];

    /** Status batch yang sudah tidak ikut alur tahap. */
    public const CLOSED_STATUSES = ['exported', 'terminated', 'transformed', 'split'];

    public const STATUS_AFTER_RECEIVE = 'in_process';
    public const STATUS_AFTER_SEND    = 'in_transit';
    public const STATUS_WAREHOUSE     = 'in_warehouse';

    /**
     * Kunci:
     *  label/label_en/icon(nama ikon lucide)/color/location_type/desc/desc_en : tampilan
     *  prev_stage / next_stage : rantai utama
     *  roles                   : peran yang boleh menerima/mengirim di tahap ini
     *  receive_from            : sumber penerimaan sah [stage, require_status?, require_product?]
     *  *_cp                    : nilai ENUM checkpoint_stage per aksi
     *  rfid                    : label LCD + checkpoint audit scan (null = tahap tidak punya scan RFID)
     */
    public const STAGES = [
        'lab_kimia' => [
            'label' => 'Lab Kimia', 'label_en' => 'Chemical Lab',
            'icon' => 'flask-conical', 'color' => 'violet', 'location_type' => 'lab_kimia',
            'prev_stage' => null, 'next_stage' => 'receiving',
            'desc' => 'Pembuatan batch Mineral Ikutan Timah (MIT)', 'desc_en' => 'Creation of Tin Mineral By-product (MIT) batches',
            'roles' => ['company_admin', 'operator_lab_station'],
            'receive_from' => [],
            'receive_cp' => null, 'forward_cp' => 'in_transit', 'record_cp' => 'lab_sample_taken',
            'rfid' => null,
        ],
        'receiving' => [
            'label' => 'Receiving Area', 'label_en' => 'Receiving Area',
            'icon' => 'package-open', 'color' => 'blue', 'location_type' => 'receiving',
            'prev_stage' => 'lab_kimia', 'next_stage' => 'wet_process',
            'desc' => 'Penerimaan batch MIT dari Lab Kimia', 'desc_en' => 'Receiving MIT batches from Chemical Lab',
            'roles' => ['company_admin', 'operator_wet'],
            'receive_from' => [['stage' => 'lab_kimia']],
            'receive_cp' => 'receiving_verify', 'forward_cp' => 'in_transit', 'record_cp' => 'receiving_verify',
            'rfid' => ['label' => 'Receiving Area', 'label_en' => 'Receiving Area', 'in_cp' => 'receiving_verify', 'out_cp' => 'in_transit'],
        ],
        'wet_process' => [
            'label' => 'Wet Process', 'label_en' => 'Wet Process',
            'icon' => 'droplets', 'color' => 'cyan', 'location_type' => 'wet_process',
            'prev_stage' => 'receiving', 'next_stage' => 'dry_process',
            'desc' => 'Shaking table — ekstraksi timah (Sn) dari MIT', 'desc_en' => 'Shaking table — tin (Sn) extraction from MIT',
            'roles' => ['company_admin', 'operator_wet'],
            'receive_from' => [['stage' => 'receiving']],
            'receive_cp' => 'wet_process_input', 'forward_cp' => 'wet_process_output', 'record_cp' => 'wet_process_input',
            'rfid' => ['label' => 'Wet Process', 'label_en' => 'Wet Process', 'in_cp' => 'wet_process_input', 'out_cp' => 'wet_process_output'],
        ],
        'dry_process' => [
            'label' => 'Dry Process', 'label_en' => 'Dry Process',
            'icon' => 'flame', 'color' => 'amber', 'location_type' => 'dry_process',
            'prev_stage' => 'wet_process', 'next_stage' => 'warehouse',
            'desc' => 'Magnetic separator — pisahkan MIT menjadi MON + ZIR + ILM', 'desc_en' => 'Magnetic separator — split MIT into MON + ZIR + ILM',
            'roles' => ['company_admin', 'operator_dry'],
            'receive_from' => [['stage' => 'wet_process']],
            'receive_cp' => 'dry_process_input', 'forward_cp' => 'dry_process_output', 'record_cp' => 'dry_process_input',
            'rfid' => ['label' => 'Dry Process', 'label_en' => 'Dry Process', 'in_cp' => 'dry_process_input', 'out_cp' => 'dry_process_output'],
        ],
        'project_plant' => [
            'label' => 'Project Plant', 'label_en' => 'Project Plant',
            'icon' => 'microscope', 'color' => 'emerald', 'location_type' => 'project_plant',
            'prev_stage' => 'warehouse', 'next_stage' => 'warehouse',
            'desc' => 'Lab Analisis LTJ — analisis kandungan La, Ce, Pr, Nd, Y', 'desc_en' => 'LTJ Lab Analysis — La, Ce, Pr, Nd, Y content analysis',
            'roles' => ['company_admin', 'operator_lab_ltj'],
            // Hanya Monasit (MON) yang sudah dikirim (in_transit) dari warehouse.
            'receive_from' => [['stage' => 'warehouse', 'require_status' => 'in_transit', 'require_product' => 'MON']],
            'receive_cp' => 'project_plant_input', 'forward_cp' => 'project_plant_output', 'record_cp' => 'project_plant_input',
            'rfid' => ['label' => 'Lab Analisis LTJ', 'label_en' => 'LTJ Lab Analysis', 'in_cp' => 'project_plant_input', 'out_cp' => 'project_plant_output'],
        ],
        'warehouse' => [
            'label' => 'LTJ Warehouse', 'label_en' => 'LTJ Warehouse',
            'icon' => 'warehouse', 'color' => 'teal', 'location_type' => 'warehouse',
            'prev_stage' => 'dry_process', 'next_stage' => null,
            'desc' => 'Gudang konsentrat: ZIR, ILM, MON, dan hasil unsur LTJ', 'desc_en' => 'Concentrate warehouse: ZIR, ILM, MON, and LTJ elements',
            'roles' => ['company_admin', 'operator_warehouse'],
            // Dari Dry Process, atau kembali dari Project Plant setelah analisis (hanya yang sudah dikirim).
            'receive_from' => [['stage' => 'dry_process'], ['stage' => 'project_plant', 'require_status' => 'in_transit']],
            'receive_cp' => 'warehouse_inbound', 'forward_cp' => 'warehouse_outbound', 'record_cp' => 'warehouse_transfer',
            'rfid' => ['label' => 'LTJ Warehouse', 'label_en' => 'LTJ Warehouse', 'in_cp' => 'warehouse_inbound', 'out_cp' => 'warehouse_transfer'],
        ],
    ];

    public static function stages(): array
    {
        return self::STAGES;
    }

    public static function get(string $stage): ?array
    {
        return self::STAGES[$stage] ?? null;
    }

    public static function has(string $stage): bool
    {
        return isset(self::STAGES[$stage]);
    }

    public static function roles(string $stage): array
    {
        return self::STAGES[$stage]['roles'] ?? ['company_admin'];
    }

    /** Tahap yang memiliki scan RFID, dalam bentuk lama RfidService::STAGE_FLOW (label LCD tidak berubah). */
    public static function rfidFlow(): array
    {
        $flow = [];
        foreach (self::STAGES as $stage => $cfg) {
            if ($cfg['rfid']) {
                $flow[$stage] = [
                    'prev_stage'     => $cfg['prev_stage'],
                    'in_checkpoint'  => $cfg['rfid']['in_cp'],
                    'out_checkpoint' => $cfg['rfid']['out_cp'],
                    'location_type'  => $cfg['location_type'],
                    'label'          => $cfg['rfid']['label'],
                    'label_en'       => $cfg['rfid']['label_en'],
                ];
            }
        }

        return $flow;
    }

    /** Tahap yang bisa dipilih untuk scan/reader. */
    public static function rfidStages(): array
    {
        return array_keys(self::rfidFlow());
    }

    /** Untuk frontend (props Inertia): tanpa data internal. */
    public static function forFrontend(): array
    {
        $out = [];
        foreach (self::ORDER as $stage) {
            $c = self::STAGES[$stage];
            $out[] = [
                'key' => $stage, 'label' => $c['label'], 'label_en' => $c['label_en'],
                'icon' => $c['icon'], 'color' => $c['color'],
                'prev' => $c['prev_stage'], 'next' => $c['next_stage'],
                'rfid' => $c['rfid'] !== null,
            ];
        }

        return $out;
    }
}
