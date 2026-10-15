<?php

namespace Database\Seeders\Core;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * UserSeeder — 13 users default MINETRACE.
 *
 * PT Timah roles per workflow:
 * ┌────────────────────────────────────┬───────────────────────────────┐
 * │ Email                              │ Role                          │
 * ├────────────────────────────────────┼───────────────────────────────┤
 * │ superadmin@minetra.id              │ super_admin                   │
 * │ admin@bim.go.id                    │ bim_admin                     │
 * │ viewer@bim.go.id                   │ bim_viewer                    │
 * │ admin@ptimah.co.id                 │ company_admin                 │
 * │ lab.sgt@ptimah.co.id               │ operator_lab_station (SGT)    │
 * │ lab.lbx@ptimah.co.id               │ operator_lab_station (LBX)    │
 * │ lab.ltj@ptimah.co.id               │ operator_lab_ltj              │
 * │ wet.mtk@ptimah.co.id               │ operator_wet (Muntok)         │
 * │ wet.knd@ptimah.co.id               │ operator_wet (Kundur)         │
 * │ dry.mtk@ptimah.co.id               │ operator_dry (Muntok)         │
 * │ warehouse.tju@ptimah.co.id         │ operator_warehouse (Tj. Ular) │
 * │ audit@ptimah.co.id                 │ company_auditor               │
 * │ audit.gov@esdm.go.id               │ government_auditor            │
 * └────────────────────────────────────┴───────────────────────────────┘
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $bim   = Company::where('code', 'BIM')->first();
        $timah = Company::where('code', 'TIMAH')->first();
        $esdm  = Company::where('code', 'BIM')->first(); // ESDM pakai company BIM

        $users = [
            // ─── System ──────────────────────────────────────────────
            [
                'company_id'  => null,
                'name'        => 'Super Administrator',
                'email'       => 'superadmin@minetra.id',
                'password'    => 'SuperAdmin123!',
                'role'        => 'super_admin',
                'employee_id' => 'SYS-001',
                'department'  => 'System',
                'language'    => 'id',
            ],

            // ─── BIM ─────────────────────────────────────────────────
            [
                'company_id'  => $bim?->id,
                'name'        => 'Admin BIM',
                'email'       => 'admin@bim.go.id',
                'password'    => 'BimAdmin123!',
                'role'        => 'bim_admin',
                'employee_id' => 'BIM-ADM-001',
                'department'  => 'Badan Informasi Mineral',
                'language'    => 'id',
            ],
            [
                'company_id'  => $bim?->id,
                'name'        => 'Viewer BIM',
                'email'       => 'viewer@bim.go.id',
                'password'    => 'BimViewer123!',
                'role'        => 'bim_viewer',
                'employee_id' => 'BIM-VIEW-001',
                'department'  => 'Badan Informasi Mineral',
                'language'    => 'id',
            ],

            // ─── PT Timah — Company Admin ─────────────────────────────
            [
                'company_id'  => $timah?->id,
                'name'        => 'Admin PT Timah',
                'email'       => 'admin@ptimah.co.id',
                'password'    => 'TimahAdmin123!',
                'role'        => 'company_admin',
                'employee_id' => 'TMH-ADM-001',
                'department'  => 'Manajemen Sistem',
                'language'    => 'id',
            ],

            // ─── Operator Lab Kimia — Stasiun ─────────────────────────
            [
                'company_id'  => $timah?->id,
                'name'        => 'Operator Lab Kimia SGT',
                'email'       => 'lab.sgt@ptimah.co.id',
                'password'    => 'LabSgt123!',
                'role'        => 'operator_lab_station',
                'employee_id' => 'TMH-LAB-SGT-001',
                'department'  => 'Lab Kimia Sungailiat',
                'language'    => 'id',
            ],
            [
                'company_id'  => $timah?->id,
                'name'        => 'Operator Lab Kimia LBX',
                'email'       => 'lab.lbx@ptimah.co.id',
                'password'    => 'LabLbx123!',
                'role'        => 'operator_lab_station',
                'employee_id' => 'TMH-LAB-LBX-001',
                'department'  => 'Lab Kimia (Cadangan)',
                'language'    => 'id',
            ],

            // ─── Operator Lab LTJ (Analisis) ──────────────────────────
            [
                'company_id'  => $timah?->id,
                'name'        => 'Analis Lab LTJ',
                'email'       => 'lab.ltj@ptimah.co.id',
                'password'    => 'LabLtj123!',
                'role'        => 'operator_lab_ltj',
                'employee_id' => 'TMH-LAB-LTJ-001',
                'department'  => 'Lab Analisis LTJ — Project Plant',
                'language'    => 'id',
            ],

            // ─── Operator Wet Process ─────────────────────────────────
            [
                'company_id'  => $timah?->id,
                'name'        => 'Operator Wet Process Muntok',
                'email'       => 'wet.mtk@ptimah.co.id',
                'password'    => 'WetMtk123!',
                'role'        => 'operator_wet',
                'employee_id' => 'TMH-WET-MTK-001',
                'department'  => 'Wet Process — Muntok',
                'language'    => 'id',
            ],
            [
                'company_id'  => $timah?->id,
                'name'        => 'Operator Wet Process Kundur',
                'email'       => 'wet.knd@ptimah.co.id',
                'password'    => 'WetKnd123!',
                'role'        => 'operator_wet',
                'employee_id' => 'TMH-WET-KND-001',
                'department'  => 'Wet Process — Kundur',
                'language'    => 'id',
            ],

            // ─── Operator Dry Process ─────────────────────────────────
            [
                'company_id'  => $timah?->id,
                'name'        => 'Operator Dry Process Muntok',
                'email'       => 'dry.mtk@ptimah.co.id',
                'password'    => 'DryMtk123!',
                'role'        => 'operator_dry',
                'employee_id' => 'TMH-DRY-MTK-001',
                'department'  => 'Dry Process — Muntok',
                'language'    => 'id',
            ],

            // ─── Operator Warehouse ───────────────────────────────────
            [
                'company_id'  => $timah?->id,
                'name'        => 'Operator Gudang LTJ',
                'email'       => 'warehouse.tju@ptimah.co.id',
                'password'    => 'WhsTju123!',
                'role'        => 'operator_warehouse',
                'employee_id' => 'TMH-WHS-TJU-001',
                'department'  => 'LTJ Warehouse — Tanjung Ular',
                'language'    => 'id',
            ],

            // ─── Auditor ──────────────────────────────────────────────
            [
                'company_id'  => $timah?->id,
                'name'        => 'Auditor Internal PT Timah',
                'email'       => 'audit@ptimah.co.id',
                'password'    => 'Auditor123!',
                'role'        => 'company_auditor',
                'employee_id' => 'TMH-AUD-001',
                'department'  => 'Audit Internal',
                'language'    => 'id',
            ],
            [
                'company_id'  => $bim?->id,
                'name'        => 'Auditor ESDM',
                'email'       => 'audit.gov@esdm.go.id',
                'password'    => 'GovAudit123!',
                'role'        => 'government_auditor',
                'employee_id' => 'ESDM-AUD-001',
                'department'  => 'Kementerian ESDM',
                'language'    => 'id',
            ],
        ];

        foreach ($users as $data) {
            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'company_id'  => $data['company_id'],
                    'name'        => $data['name'],
                    'password'    => Hash::make($data['password']),
                    'employee_id' => $data['employee_id'],
                    'department'  => $data['department'],
                    'language'    => $data['language'],
                    'is_active'   => true,
                    'force_password_change' => false,
                ]
            );
            $user->syncRoles([$data['role']]);
        }

        $this->command->info('  ✅ 13 Users seeded.');
        $this->command->table(
            ['Email', 'Password', 'Role', 'Department'],
            collect($users)->map(fn($u) => [
                $u['email'],
                $u['password'],
                $u['role'],
                $u['department'] ?? '-',
            ])->toArray()
        );
    }
}