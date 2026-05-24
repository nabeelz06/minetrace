<?php

namespace Database\Seeders\Core;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * RolesAndPermissionsSeeder — 11 roles + permissions komprehensif.
 *
 * Permissions mengikuti format: action.resource
 * Contoh: batch.create, lab.input, warehouse.manage
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles & permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // ─── Buat semua permissions ────────────────────────────────────────
        $permissions = [
            // System
            'system.manage',         // super_admin only

            // Company management
            'company.manage',        // company_admin
            'company.view',

            // User management
            'user.manage',           // super_admin + company_admin (own company)
            'user.view',

            // Batch
            'batch.view',            // semua roles
            'batch.create',          // operator_lab_station + company_admin
            'batch.update',          // operator + company_admin
            'batch.delete',          // company_admin only

            // Lab Kimia (Station) — membuat batch MIK awal
            'lab_station.create_batch',  // operator_lab_station
            'lab_station.view',

            // Wet Process — receiving + shaking table
            'wet_process.receive',       // operator_wet
            'wet_process.process',
            'wet_process.send',

            // Dry Process — magnetic separator
            'dry_process.receive',       // operator_dry
            'dry_process.process',       // input ZIR+ILM+MON
            'dry_process.send',

            // Lab LTJ Analisis — analisis kandungan + kirim ke warehouse
            'lab_ltj.receive',           // operator_lab_ltj
            'lab_ltj.input_analysis',    // input La, Ce, Pr, Nd, Y
            'lab_ltj.validate',
            'lab_ltj.send_warehouse',

            // Warehouse — kelola semua konsentrat + LTJ
            'warehouse.receive',         // operator_warehouse
            'warehouse.manage',          // kelola stok
            'warehouse.send_ltj_lab',    // kirim MON ke lab LTJ
            'warehouse.prepare_export',  // siapkan batch untuk ekspor

            // Export
            'export.create',             // operator_warehouse + company_admin
            'export.view',               // semua company roles
            'export.manage',             // company_admin

            // Reports
            'report.view',               // company_admin + company_auditor + bim
            'report.download',
            'report.generate',

            // Settings
            'settings.manage',           // company_admin only
            'settings.view',

            // Checkpoint
            'checkpoint.record',         // semua operator + company_admin

            // Mass balance
            'mass_balance.view',
            'mass_balance.alert',

            // BIM
            'bim.view_all',              // bim roles + gov auditor
            'bim.export_data',           // bim_admin

            // Audit log
            'audit.view',               // company_auditor + company_admin
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // ─── Buat roles & assign permissions ──────────────────────────────

        // 1. SUPER ADMIN — semua permissions
        $superAdmin = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $superAdmin->syncPermissions(Permission::all());

        // 2. BIM ADMIN
        $bimAdmin = Role::firstOrCreate(['name' => 'bim_admin', 'guard_name' => 'web']);
        $bimAdmin->syncPermissions([
            'bim.view_all', 'bim.export_data',
            'batch.view', 'export.view', 'report.view', 'report.download', 'report.generate',
            'mass_balance.view', 'company.view', 'user.view',
        ]);

        // 3. BIM VIEWER
        $bimViewer = Role::firstOrCreate(['name' => 'bim_viewer', 'guard_name' => 'web']);
        $bimViewer->syncPermissions([
            'bim.view_all', 'batch.view', 'export.view', 'report.view',
            'mass_balance.view', 'company.view',
        ]);

        // 4. GOVERNMENT AUDITOR
        $govAuditor = Role::firstOrCreate(['name' => 'government_auditor', 'guard_name' => 'web']);
        $govAuditor->syncPermissions([
            'bim.view_all', 'batch.view', 'export.view', 'report.view', 'report.download',
            'mass_balance.view', 'audit.view',
        ]);

        // 5. COMPANY ADMIN — semua akses dalam company-nya
        $companyAdmin = Role::firstOrCreate(['name' => 'company_admin', 'guard_name' => 'web']);
        $companyAdmin->syncPermissions([
            'company.manage', 'company.view',
            'user.manage', 'user.view',
            'batch.view', 'batch.create', 'batch.update', 'batch.delete',
            'lab_station.create_batch', 'lab_station.view',
            'wet_process.receive', 'wet_process.process', 'wet_process.send',
            'dry_process.receive', 'dry_process.process', 'dry_process.send',
            'lab_ltj.receive', 'lab_ltj.input_analysis', 'lab_ltj.validate', 'lab_ltj.send_warehouse',
            'warehouse.receive', 'warehouse.manage', 'warehouse.send_ltj_lab', 'warehouse.prepare_export',
            'export.create', 'export.view', 'export.manage',
            'report.view', 'report.download', 'report.generate',
            'settings.manage', 'settings.view',
            'checkpoint.record',
            'mass_balance.view', 'mass_balance.alert',
            'audit.view',
        ]);

        // 6. OPERATOR LAB STATION — buat batch MIK di Lab Kimia
        $opLabStation = Role::firstOrCreate(['name' => 'operator_lab_station', 'guard_name' => 'web']);
        $opLabStation->syncPermissions([
            'batch.view', 'batch.create', 'batch.update',
            'lab_station.create_batch', 'lab_station.view',
            'checkpoint.record',
            'mass_balance.view',
        ]);

        // 7. OPERATOR LAB LTJ — analisis LTJ + kirim ke warehouse
        $opLabLtj = Role::firstOrCreate(['name' => 'operator_lab_ltj', 'guard_name' => 'web']);
        $opLabLtj->syncPermissions([
            'batch.view', 'batch.update',
            'lab_ltj.receive', 'lab_ltj.input_analysis', 'lab_ltj.validate', 'lab_ltj.send_warehouse',
            'checkpoint.record',
            'mass_balance.view',
        ]);

        // 8. OPERATOR WET PROCESS — receiving + shaking table
        $opWet = Role::firstOrCreate(['name' => 'operator_wet', 'guard_name' => 'web']);
        $opWet->syncPermissions([
            'batch.view', 'batch.update',
            'wet_process.receive', 'wet_process.process', 'wet_process.send',
            'checkpoint.record',
            'mass_balance.view',
        ]);

        // 9. OPERATOR DRY PROCESS — magnetic separator + 3 output
        $opDry = Role::firstOrCreate(['name' => 'operator_dry', 'guard_name' => 'web']);
        $opDry->syncPermissions([
            'batch.view', 'batch.update',
            'dry_process.receive', 'dry_process.process', 'dry_process.send',
            'checkpoint.record',
            'mass_balance.view',
        ]);

        // 10. OPERATOR WAREHOUSE — gudang semua material
        $opWarehouse = Role::firstOrCreate(['name' => 'operator_warehouse', 'guard_name' => 'web']);
        $opWarehouse->syncPermissions([
            'batch.view', 'batch.update',
            'warehouse.receive', 'warehouse.manage', 'warehouse.send_ltj_lab', 'warehouse.prepare_export',
            'export.create', 'export.view',
            'checkpoint.record',
            'mass_balance.view',
        ]);

        // 11. COMPANY AUDITOR — read-only + laporan
        $companyAuditor = Role::firstOrCreate(['name' => 'company_auditor', 'guard_name' => 'web']);
        $companyAuditor->syncPermissions([
            'batch.view', 'export.view',
            'report.view', 'report.download', 'report.generate',
            'mass_balance.view', 'settings.view',
            'audit.view',
        ]);

        $this->command->info('  ✅ 11 Roles + ' . count($permissions) . ' Permissions seeded.');
    }
}