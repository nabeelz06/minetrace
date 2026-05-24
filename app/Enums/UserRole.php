<?php

namespace App\Enums;

/**
 * UserRole — semua role yang valid dalam sistem MINETRA.
 *
 * WORKFLOW LENGKAP:
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ operator_lab_station                                                │
 * │   → Buat batch MIK (mineral ikutan) di Lab Kimia (Sungailiat, dll) │
 * │   → Input: berat awal, kode produk MIK, timestamp                  │
 * │   → Output: batch MIK aktif → siap ke Receiving Area               │
 * ├─────────────────────────────────────────────────────────────────────┤
 * │ operator_wet                                                        │
 * │   → Receiving Area: terima batch MIK dari Lab Kimia                │
 * │   → Wet Process (shaking table): pisahkan fraksi Sn dari MIK       │
 * │   → Input: berat MIK masuk, berat MIK keluar (Sn berkurang)        │
 * │   → Output: batch MIK ter-update → kirim ke Dry Process            │
 * ├─────────────────────────────────────────────────────────────────────┤
 * │ operator_dry                                                        │
 * │   → Dry Process (magnetic separator): pisahkan 3 konsentrat        │
 * │   → Input: berat MON (kg) + pct, berat ZIR (kg) + pct,            │
 * │            berat ILM (kg) + pct                                     │
 * │   → Output: 3 batch baru (MON, ZIR, ILM) → kirim ke Warehouse     │
 * ├─────────────────────────────────────────────────────────────────────┤
 * │ operator_lab_ltj                                                    │
 * │   → Terima batch MON dari Warehouse                                 │
 * │   → Project Plant / Lab Analisis LTJ                                │
 * │   → Input: kandungan La%, Ce%, Pr%, Nd%, Y%, ThO2%, U3O8%         │
 * │   → Output: batch LTJ (LAT, CER, PRS, NEO, YTR) → kirim ke WHS    │
 * ├─────────────────────────────────────────────────────────────────────┤
 * │ operator_warehouse                                                  │
 * │   → Terima: ZIR + ILM dari Dry Process                             │
 * │   → Terima: MON (belum diproses), hasil LTJ dari Lab LTJ           │
 * │   → Kelola stok warehouse, siapkan batch untuk ekspor              │
 * │   → Buat ekspor (ZIR, ILM, LTJ elements)                          │
 * └─────────────────────────────────────────────────────────────────────┘
 */
enum UserRole: string
{
    // ─── System ───────────────────────────────────────────────────────
    case SuperAdmin         = 'super_admin';

    // ─── Regulator (BIM) ──────────────────────────────────────────────
    case BimAdmin           = 'bim_admin';
    case BimViewer          = 'bim_viewer';
    case GovernmentAuditor  = 'government_auditor';

    // ─── Company Management ───────────────────────────────────────────
    case CompanyAdmin       = 'company_admin';

    // ─── Company Operators — setiap operator spesifik per tahap ───────
    case OperatorLabStation = 'operator_lab_station'; // Lab Kimia (buat batch MIK)
    case OperatorLabLtj     = 'operator_lab_ltj';     // Lab LTJ Analisis (analisis+kirim)
    case OperatorWet        = 'operator_wet';          // Wet Process (shaking table)
    case OperatorDry        = 'operator_dry';          // Dry Process (magnetic separator)
    case OperatorWarehouse  = 'operator_warehouse';    // Gudang (terima+kelola+ekspor)

    // ─── Auditor ──────────────────────────────────────────────────────
    case CompanyAuditor     = 'company_auditor';

    // ─────────────────────────────────────────────────────────────────

    /**
     * Label tampilan sesuai bahasa.
     */
    public function label(string $lang = 'id'): string
    {
        $labels = [
            'id' => [
                self::SuperAdmin->value         => 'Super Administrator',
                self::BimAdmin->value           => 'Admin BIM',
                self::BimViewer->value          => 'Viewer BIM',
                self::GovernmentAuditor->value  => 'Auditor Pemerintah',
                self::CompanyAdmin->value       => 'Admin Perusahaan',
                self::OperatorLabStation->value => 'Operator Lab Kimia (Stasiun)',
                self::OperatorLabLtj->value     => 'Operator Lab LTJ (Analisis)',
                self::OperatorWet->value        => 'Operator Wet Process',
                self::OperatorDry->value        => 'Operator Dry Process',
                self::OperatorWarehouse->value  => 'Operator Gudang (Warehouse)',
                self::CompanyAuditor->value     => 'Auditor Internal',
            ],
            'en' => [
                self::SuperAdmin->value         => 'Super Administrator',
                self::BimAdmin->value           => 'BIM Admin',
                self::BimViewer->value          => 'BIM Viewer',
                self::GovernmentAuditor->value  => 'Government Auditor',
                self::CompanyAdmin->value       => 'Company Admin',
                self::OperatorLabStation->value => 'Lab Station Operator',
                self::OperatorLabLtj->value     => 'LTJ Lab Analyst',
                self::OperatorWet->value        => 'Wet Process Operator',
                self::OperatorDry->value        => 'Dry Process Operator',
                self::OperatorWarehouse->value  => 'Warehouse Operator',
                self::CompanyAuditor->value     => 'Internal Auditor',
            ],
        ];
        return $labels[$lang][$this->value] ?? $this->value;
    }

    /**
     * Deskripsi tugas singkat.
     */
    public function description(string $lang = 'id'): string
    {
        $desc = [
            'id' => [
                self::OperatorLabStation->value => 'Membuat batch mineral ikutan (MIK) awal di Lab Kimia. Input berat, timestamp, dan kode produk.',
                self::OperatorLabLtj->value     => 'Menerima batch Monasit dari Warehouse, menganalisis kandungan LTJ (La, Ce, Pr, Nd, Y), dan mengirim hasil ke Warehouse.',
                self::OperatorWet->value        => 'Menerima batch MIK di Receiving Area, memproses dengan shaking table (ekstraksi Sn), dan mengirim ke Dry Process.',
                self::OperatorDry->value        => 'Menerima batch MIK dari Wet Process, memisahkan dengan magnetic separator (ZIR + ILM + MON), dan mengirim ke Warehouse.',
                self::OperatorWarehouse->value  => 'Menerima semua konsentrat (ZIR, ILM, MON) dari Dry Process dan hasil LTJ dari Lab LTJ. Mengelola stok dan menyiapkan ekspor.',
            ],
            'en' => [
                self::OperatorLabStation->value => 'Creates initial mineral concentrate (MIK) batches at Chemical Lab. Records weight, timestamp, and product code.',
                self::OperatorLabLtj->value     => 'Receives Monazite batches from Warehouse, analyzes LTJ content (La, Ce, Pr, Nd, Y), and sends results to Warehouse.',
                self::OperatorWet->value        => 'Receives MIK batches at Receiving Area, processes via shaking table (Sn extraction), and forwards to Dry Process.',
                self::OperatorDry->value        => 'Receives MIK from Wet Process, separates via magnetic separator (ZIR + ILM + MON), and sends to Warehouse.',
                self::OperatorWarehouse->value  => 'Receives all concentrates (ZIR, ILM, MON) from Dry Process and LTJ results from LTJ Lab. Manages stock and prepares exports.',
            ],
        ];
        return $desc[$lang][$this->value] ?? '';
    }

    /**
     * Badge color untuk UI.
     */
    public function badgeStyle(): string
    {
        return match($this) {
            self::SuperAdmin         => 'background:rgba(147,51,234,0.15);color:#c084fc',
            self::BimAdmin           => 'background:rgba(239,68,68,0.12);color:#f87171',
            self::BimViewer          => 'background:rgba(239,68,68,0.08);color:#fca5a5',
            self::GovernmentAuditor  => 'background:rgba(249,115,22,0.12);color:#fb923c',
            self::CompanyAdmin       => 'background:rgba(62,92,116,0.18);color:#7cb4d4',
            self::OperatorLabStation => 'background:rgba(16,185,129,0.12);color:#34d399',
            self::OperatorLabLtj     => 'background:rgba(124,58,237,0.12);color:#a78bfa',
            self::OperatorWet        => 'background:rgba(6,182,212,0.12);color:#22d3ee',
            self::OperatorDry        => 'background:rgba(245,158,11,0.12);color:#fbbf24',
            self::OperatorWarehouse  => 'background:rgba(20,184,166,0.12);color:#2dd4bf',
            self::CompanyAuditor     => 'background:rgba(107,114,128,0.12);color:#9ca3af',
        };
    }

    /** Apakah role ini BIM/regulator? */
    public function isRegulator(): bool
    {
        return in_array($this, [
            self::BimAdmin, self::BimViewer, self::GovernmentAuditor
        ]);
    }

    /** Apakah role ini operator company? */
    public function isCompanyOperator(): bool
    {
        return in_array($this, [
            self::OperatorLabStation, self::OperatorLabLtj,
            self::OperatorWet, self::OperatorDry, self::OperatorWarehouse,
        ]);
    }

    /** Semua roles yang membutuhkan company_id */
    public static function companyRoles(): array
    {
        return [
            self::BimAdmin, self::BimViewer, self::GovernmentAuditor,
            self::CompanyAdmin,
            self::OperatorLabStation, self::OperatorLabLtj,
            self::OperatorWet, self::OperatorDry, self::OperatorWarehouse,
            self::CompanyAuditor,
        ];
    }

    /** Semua operator roles (non-admin, non-auditor) */
    public static function operatorRoles(): array
    {
        return [
            self::OperatorLabStation, self::OperatorLabLtj,
            self::OperatorWet, self::OperatorDry, self::OperatorWarehouse,
        ];
    }

    /** Stage process yang dapat diakses role ini */
    public function allowedStages(): array
    {
        return match($this) {
            self::OperatorLabStation => ['lab_kimia'],
            self::OperatorWet        => ['receiving', 'wet_process'],
            self::OperatorDry        => ['dry_process'],
            self::OperatorLabLtj     => ['project_plant'],
            self::OperatorWarehouse  => ['warehouse'],
            self::CompanyAdmin       => ['lab_kimia','receiving','wet_process','dry_process','project_plant','warehouse','smelter'],
            default                  => [],
        };
    }
}