<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Company\BatchController;
use App\Http\Controllers\Company\HardwareController;
use App\Http\Controllers\Company\LabController;
use App\Http\Controllers\Company\ExportController;
use App\Http\Controllers\Company\ReportController;
use App\Http\Controllers\Company\SettingsController;
use App\Http\Controllers\Company\ProcessController;
use App\Http\Controllers\SuperAdmin\UserController;
use App\Http\Controllers\SuperAdmin\CompanyController;
use Illuminate\Support\Facades\Route;

/*
|─────────────────────────────────────────────────────────────────────────────
| MINETRA Web Routes — 11 Roles
|─────────────────────────────────────────────────────────────────────────────
|
| ROLE WORKFLOW:
|   operator_lab_station → Buat batch MIK di Lab Kimia
|   operator_wet         → Receiving + Wet Process (shaking table)
|   operator_dry         → Dry Process (magnetic separator) → ZIR+ILM+MON
|   operator_lab_ltj     → Lab Analisis LTJ (Project Plant) → kirim ke WHS
|   operator_warehouse   → Gudang: terima semua, kelola stok, siapkan ekspor
|
| ROUTE ORDER RULES (Laravel reads top to bottom):
|   ✅ Static routes BEFORE {parameter} routes
|   ✅ /resource/create BEFORE /resource/{id}
|   ✅ /resource/action BEFORE /resource/{id}
|─────────────────────────────────────────────────────────────────────────────
*/

// ─── Root ───────────────────────────────────────────────────────────────────
Route::get('/', fn() => auth()->check()
    ? redirect()->route('dashboard.redirect')
    : redirect()->route('login')
);

// ─── Auth ────────────────────────────────────────────────────────────────────
Route::middleware('guest')->group(function () {
    Route::get('login',  [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store']);
});

Route::post('logout', [LoginController::class, 'destroy'])
    ->middleware('auth')->name('logout');

// ─── Universal dashboard redirect ─────────────────────────────────────────────
Route::middleware('auth')
    ->get('/dashboard', [DashboardController::class, 'redirect'])
    ->name('dashboard.redirect');

// ─── User preferences ─────────────────────────────────────────────────────────
Route::middleware('auth')->group(function () {
    Route::patch('/user/language',  function (\Illuminate\Http\Request $req) {
        $req->validate(['language' => 'required|in:id,en']);
        $req->user()->update(['language' => $req->language]);
        return back();
    })->name('user.language');

    Route::patch('/user/dark-mode', function (\Illuminate\Http\Request $req) {
        $req->validate(['dark_mode' => 'required|boolean']);
        $req->user()->update(['dark_mode' => $req->dark_mode]);
        return back();
    })->name('user.dark-mode');
});

/*
|═══════════════════════════════════════════════════════════════════════════
| SUPER ADMIN
|═══════════════════════════════════════════════════════════════════════════
*/
Route::middleware(['auth', 'role:super_admin'])
    ->prefix('super-admin')
    ->name('super-admin.')
    ->group(function () {
        Route::get('dashboard', [DashboardController::class, 'superAdmin'])->name('dashboard');

        // User Management
        Route::resource('users', UserController::class)
            ->only(['index','create','store','edit','update','destroy'])
            ->names('users');
        Route::patch('users/{id}/restore',        [UserController::class, 'restore'])->name('users.restore');
        Route::patch('users/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggle-active');

        // Company Management
        Route::resource('companies', CompanyController::class)
            ->only(['index','create','store','show','update'])
            ->names('companies');
        Route::patch('companies/{company}/toggle-active', [CompanyController::class, 'toggleActive'])
            ->name('companies.toggle-active');
    });

/*
|═══════════════════════════════════════════════════════════════════════════
| BIM (Regulator)
|═══════════════════════════════════════════════════════════════════════════
*/
Route::middleware(['auth', 'role:bim_admin|bim_viewer|government_auditor'])
    ->prefix('bim')
    ->name('bim.')
    ->group(function () {
        Route::get('dashboard', [DashboardController::class, 'bim'])->name('dashboard');
    });

/*
|═══════════════════════════════════════════════════════════════════════════
| COMPANY — semua 7 company roles
|═══════════════════════════════════════════════════════════════════════════
| Semua company roles:
|   company_admin, operator_lab_station, operator_lab_ltj,
|   operator_wet, operator_dry, operator_warehouse, company_auditor
*/
$allCompanyRoles = 'company_admin|operator_lab_station|operator_lab_ltj|operator_wet|operator_dry|operator_warehouse|company_auditor';

Route::middleware(['auth', "role:{$allCompanyRoles}"])
    ->prefix('company')
    ->name('company.')
    ->group(function () use ($allCompanyRoles) {

        // ── Dashboard ──────────────────────────────────────────────────────
        Route::get('dashboard', [DashboardController::class, 'company'])->name('dashboard');

        // ─────────────────────────────────────────────────────────────────────
        // BATCHES
        // ─────────────────────────────────────────────────────────────────────
        // READ — semua roles
        Route::get('batches', [BatchController::class, 'index'])->name('batches.index');

        // ← STATIC 'create' HARUS sebelum {batch} (fix SQLSTATE bug)
        Route::middleware('role:company_admin|operator_lab_station|operator_wet')->group(function () {
            Route::get('batches/create', [BatchController::class, 'create'])->name('batches.create');
            Route::post('batches',       [BatchController::class, 'store'])->name('batches.store');
        });

        // ← PARAMETER setelah static
        Route::get('batches/{batch}',    [BatchController::class, 'show'])->name('batches.show');
        Route::get('batches/{batch}/qr', [BatchController::class, 'generateQR'])->name('batches.qr');

        // ─────────────────────────────────────────────────────────────────────
        // LAB ANALYSIS (operator_lab_ltj = Analisis LTJ)
        // ─────────────────────────────────────────────────────────────────────
        Route::get('lab', [LabController::class, 'index'])->name('lab.index');

        Route::middleware('role:company_admin|operator_lab_ltj')->group(function () {
            // ← 'create' sebelum {batch}
            Route::get('batches/{batch}/lab/create', [LabController::class, 'create'])->name('lab.create');
            Route::post('batches/{batch}/lab',       [LabController::class, 'store'])->name('lab.store');
            Route::patch('lab/{analysis}/validate',  [LabController::class, 'validate'])->name('lab.validate');
            // Kirim batch LTJ ke Warehouse
            Route::post('batches/{batch}/send-to-warehouse', [LabController::class, 'sendToWarehouse'])
                ->name('lab.send_warehouse');
        });

        // ─────────────────────────────────────────────────────────────────────
        // PROCESS PRODUKSI — per stage
        // ─────────────────────────────────────────────────────────────────────
        // Overview — semua company roles
        Route::get('process', [ProcessController::class, 'overview'])->name('process.overview');

        // ← 'overview' static sebelum {stage}
        Route::get('process/{stage}',             [ProcessController::class, 'stage'])->name('process.stage');
        Route::post('process/{stage}/checkpoint', [ProcessController::class, 'recordCheckpoint'])
            ->middleware("role:{$allCompanyRoles}")
            ->name('process.checkpoint');

        // Dry Process: input 3 konsentrat (ZIR + ILM + MON)
        Route::post('process/dry/transform', [ProcessController::class, 'dryTransform'])
            ->middleware('role:company_admin|operator_dry')
            ->name('process.dry.transform');

        // Wet Process: send ke Dry setelah shaking table
        Route::post('process/wet/forward', [ProcessController::class, 'wetForward'])
            ->middleware('role:company_admin|operator_wet')
            ->name('process.wet.forward');

        // Warehouse: kirim MON ke Lab LTJ
        Route::post('process/warehouse/send-to-ltj', [ProcessController::class, 'warehouseSendToLtj'])
            ->middleware('role:company_admin|operator_warehouse')
            ->name('process.warehouse.send_ltj');

        // Terima batch dari stage sebelumnya 
        Route::post('process/{stage}/receive-batch', [ProcessController::class, 'receiveBatch'])
            ->middleware("role:{$allCompanyRoles}")
            ->name('process.receive-batch');

        // Forward batch ke stage berikutnya (dengan update berat)
        Route::post('process/{stage}/forward-batch', [ProcessController::class, 'forwardBatch'])
            ->middleware("role:{$allCompanyRoles}")
            ->name('process.forward-batch');

        // ─────────────────────────────────────────────────────────────────────────────
        // EXPORT
        // ─────────────────────────────────────────────────────────────────────────────
        Route::get('exports', [ExportController::class, 'index'])->name('exports.index');

        // ← Semua static routes didaftarkan
        Route::middleware('role:company_admin|operator_warehouse')->group(function () {
            Route::get('exports/create',            [ExportController::class, 'create'])
                ->name('exports.create');
            Route::post('exports',                  [ExportController::class, 'store'])
                ->name('exports.store');
            Route::post('exports/validate-batches', [ExportController::class, 'validateBatches'])
                ->name('exports.validate-batches');
        });

        // ← Parameter routes
        Route::get('exports/{exportLog}',                 [ExportController::class, 'show'])
            ->name('exports.show');
        Route::get('exports/{exportLog}/download/{type}', [ExportController::class, 'downloadDocument'])
            ->name('exports.download');

        // ─────────────────────────────────────────────────────────────────────
        // REPORTS
        // ─────────────────────────────────────────────────────────────────────
        Route::middleware('role:company_admin|company_auditor')->group(function () {
        Route::get('reports',            [ReportController::class, 'index'])->name('reports.index');
        Route::post('reports/generate',  [ReportController::class, 'generate'])->name('reports.generate');
        Route::get('reports/download',   [ReportController::class, 'download'])->name('reports.download');
        });

        // ─────────────────────────────────────────────────────────────────────
        // SETTINGS
        // ─────────────────────────────────────────────────────────────────────
        Route::middleware('role:company_admin')->group(function () {
            Route::get('settings',               [SettingsController::class, 'index'])->name('settings.index');
            Route::patch('settings/general',     [SettingsController::class, 'updateGeneral'])->name('settings.general');
            Route::patch('settings/thresholds',  [SettingsController::class, 'updateThresholds'])->name('settings.thresholds');
            Route::patch('settings/warehouse',   [SettingsController::class, 'updateWarehouse'])->name('settings.warehouse');
        });

        // ─────────────────────────────────────────────────────────────────────
        // HARDWARE 
        // ─────────────────────────────────────────────────────────────────────
        Route::middleware('role:company_admin|operator_lab_station|operator_lab_ltj|operator_wet|operator_dry|operator_warehouse|company_auditor')
            ->group(function () {
                Route::get('/hardware', [\App\Http\Controllers\Company\HardwareController::class, 'index']);
                Route::get('hardware/rfid', [HardwareController::class, 'rfidPanel'])
                    ->name('hardware.rfid');
            });
    });