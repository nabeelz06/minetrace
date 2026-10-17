<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Hardware\ApiRfidController;
use App\Http\Controllers\Api\V1\Rfid\RfidReadLogController;
use App\Http\Controllers\Api\V1\Rfid\RfidReaderController;
use App\Http\Controllers\Api\V1\Rfid\RfidRegistrationController;
use App\Http\Controllers\Api\V1\Rfid\RfidScanController;
use App\Http\Controllers\Api\V1\Rfid\RfidTagController;
use App\Http\Controllers\Api\V1\Auth\ApiAuthController;
use App\Http\Controllers\Api\V1\Batch\ApiBatchController;
use App\Http\Controllers\Api\V1\Checkpoint\ApiCheckpointController;
use App\Http\Controllers\Api\V1\Company\ApiCompanyController;
use App\Http\Controllers\Api\V1\Verify\ApiVerifyController;
use App\Http\Controllers\Api\V1\Hardware\ApiHardwareController;

/*
|==============================================================================
| BAGIAN 1 — RFID HARDWARE ENDPOINTS
|==============================================================================
| Dipanggil langsung oleh ESP32 Arduino — TANPA autentikasi.
| ESP32 tidak bisa kirim session cookie maupun Bearer token.
|
| URL:  POST /api/rfid/register
|        POST /api/rfid/login
|        POST /api/rfid/logout
|==============================================================================
*/

// CORS preflight untuk ESP32 dan browser
Route::options('rfid/{any}', [ApiRfidController::class, 'handleOptions'])
    ->where('any', '.*');

Route::prefix('rfid')->group(function () {

    // Register: link UID ke batch (dipanggil ESP32 mode REGISTER)
    Route::post('register', [ApiRfidController::class, 'registerCard'])
        ->middleware('throttle:rfid-device')
        ->name('api.rfid.register');

    // Login: checkpoint IN karung masuk gudang
    Route::post('login', [ApiRfidController::class, 'loginCard'])
        ->middleware('throttle:rfid-device')
        ->name('api.rfid.login');

    // Logout: checkpoint OUT karung keluar gudang
    Route::post('logout', [ApiRfidController::class, 'logoutCard'])
        ->middleware('throttle:rfid-device')
        ->name('api.rfid.logout');

    // Heartbeat reader terdaftar (wajib X-Device-Key): status online/offline + sinkronisasi jam.
    Route::post('heartbeat', [ApiRfidController::class, 'heartbeat'])
        ->middleware('throttle:rfid-device')
        ->name('api.rfid.heartbeat');

}); // end prefix('rfid')

/*
|==============================================================================
| BAGIAN 2 — MINETRACE REST API v1
|==============================================================================
*/
Route::prefix('v1')->name('api.v1.')->group(function () {

    /*
    |──────────────────────────────────────────────────────────────────────
    | 2A. PUBLIC ENDPOINTS — Tidak butuh autentikasi
    |──────────────────────────────────────────────────────────────────────
    */

    // Health check
    Route::get('health', function () {
        return response()->json([
            'success' => true,
            'data'    => [
                'status'  => 'operational',
                'version' => 'v1',
                'time'    => now()->toIso8601String(),
            ],
            'message' => 'MINETRACE API is running.',
        ]);
    })->name('health');

    // Auth login (public — belum login)
    // Throttle login: 5 percobaan/menit per IP (anti brute-force token API).
    Route::post('auth/login', [ApiAuthController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('auth.login');

    // Verifikasi batch via QR code (public)
    Route::get('verify/batch/{batchId}', [ApiVerifyController::class, 'verifyBatch'])
        ->middleware('throttle:verify')
        ->name('verify.batch');

    // Hardware reader config (dipanggil ESP32 saat boot)
    Route::get('hardware/reader-config', [ApiHardwareController::class, 'readerConfig'])
        ->name('hardware.reader-config');

    Route::post('hardware/rfid-scan', [ApiHardwareController::class, 'rfidScan'])
        ->middleware('throttle:120,1')
        ->name('hardware.rfid-scan');

    // Konfigurasi ESP32 saat startup
    Route::get('hardware/rfid/esp-config', [ApiRfidController::class, 'espBootConfig'])
        ->name('hardware.rfid.esp-config');

    /*
    |──────────────────────────────────────────────────────────────────────
    | 2B. SANCTUM AUTHENTICATED ENDPOINTS
    | Untuk client API / mobile — menggunakan Bearer token
    |──────────────────────────────────────────────────────────────────────
    */
    Route::middleware(['auth:sanctum'])->group(function () {

        // Auth
        Route::post('auth/logout',  [ApiAuthController::class, 'logout'])->name('auth.logout');
        Route::get('auth/me',       [ApiAuthController::class, 'me'])->name('auth.me');
        Route::post('auth/refresh', [ApiAuthController::class, 'refresh'])->name('auth.refresh');

        // Company endpoints
        Route::prefix('company')->name('company.')->middleware('roles:company_admin,operator_lab_station,operator_lab_ltj,operator_wet,operator_dry,operator_warehouse,company_auditor')->group(function () {

            Route::get('info', [ApiCompanyController::class, 'info'])->name('info');

            // Batches
            Route::prefix('batches')->name('batches.')->group(function () {
                Route::get('/',                [ApiBatchController::class, 'index'])->name('index');
                Route::post('/',               [ApiBatchController::class, 'store'])->middleware('roles:company_admin,operator_lab_station,operator_wet')->name('store');
                Route::get('{batch}',          [ApiBatchController::class, 'show'])->name('show');
                Route::get('{batch}/qr',       [ApiBatchController::class, 'qrCode'])->name('qr');
                Route::get('{batch}/timeline', [ApiBatchController::class, 'timeline'])->name('timeline');
            }); // end prefix('batches')

            // Checkpoints
            Route::prefix('checkpoints')->name('checkpoints.')->group(function () {
                Route::post('{batch}', [ApiCheckpointController::class, 'store'])->middleware('roles:company_admin,operator_lab_station,operator_lab_ltj,operator_wet,operator_dry,operator_warehouse')->name('store');
                Route::get('{batch}',  [ApiCheckpointController::class, 'index'])->name('index');
            }); // end prefix('checkpoints')

            // Hardware (generic)
            Route::prefix('hardware')->name('hardware.')->group(function () {
                Route::post('weight',        [ApiHardwareController::class, 'weightInput'])->middleware('roles:company_admin,operator_lab_station,operator_lab_ltj,operator_wet,operator_dry,operator_warehouse')->name('weight');
                Route::get('events',         [ApiHardwareController::class, 'pollEvents'])->name('events');
            }); // end prefix('hardware')

        }); // end prefix('company')

        // BIM endpoints
        Route::prefix('bim')->name('bim.')->middleware('roles:super_admin,bim_admin,bim_viewer,government_auditor')->group(function () {
            Route::get('overview',  [ApiCompanyController::class, 'overview'])->name('overview');
            Route::get('companies', [ApiCompanyController::class, 'list'])->name('companies');
            Route::get('alerts',    [ApiBatchController::class, 'alerts'])->name('alerts');
        }); // end prefix('bim')

    }); // end middleware(['auth:sanctum'])

    /*
    |──────────────────────────────────────────────────────────────────────
    | 2C. RFID WEB UI ENDPOINTS
    |──────────────────────────────────────────────────────────────────────
    | Dipanggil dari halaman RFID (Pages/Company/Rfid/Index.vue, sesi browser + CSRF)
    |
    | KENAPA ['web', 'auth'] bukan auth:sanctum?
    |   Inertia.js menggunakan session cookie browser, BUKAN Bearer token.
    |   Sanctum butuh token di header → tidak cocok untuk Inertia.
    |   Middleware 'web' mengaktifkan session → 'auth' cek guard default.
    |
    | URL pattern: /api/v1/company/rfid/...
    |──────────────────────────────────────────────────────────────────────
    */
    // ─────────────────────────────────────────────────────────────────────────
    // 2D. CCTV WEB UI ENDPOINTS
    // Dipanggil dari CctvMonitor.vue — menggunakan session cookie (web guard)
    // ─────────────────────────────────────────────────────────────────────────
    Route::middleware(['web', 'auth'])
        ->prefix('company/cctv')
        ->name('company.cctv.')
        ->group(function () {
            // Status stream dari MediaMTX API
            Route::get('status', [\App\Http\Controllers\Company\CctvController::class, 'streamStatus'])
                ->name('status');

            // 5 snapshot CCTV terbaru dari RFID scan
            Route::get('recent-snapshots', [\App\Http\Controllers\Company\CctvController::class, 'recentSnapshots'])
                ->name('recent-snapshots');

            // Trigger snapshot manual via kamera tertentu
            Route::post('cameras/{id}/snapshot', [\App\Http\Controllers\Company\CctvController::class, 'snapshot'])
                ->name('cameras.snapshot');
        });

    Route::middleware(['web', 'auth'])
        ->prefix('company/rfid')
        ->name('company.rfid.')
        ->group(function () {

        // Status dashboard: ESP32 IP, batch ter-tag, aktivitas, pending
        Route::get('status', [ApiRfidController::class, 'getStatus'])
            ->name('status');

        // Daftar batch belum ter-tag (untuk dropdown simulasi & register)
        Route::get('batches', [ApiRfidController::class, 'getUntaggedBatches'])
            ->name('batches');

        // Set pending batch: batch mana yang akan menerima tag RFID
        Route::post('pending-batch', [ApiRfidController::class, 'setPendingBatch'])
            ->name('pending-batch.set');

        // Cancel pending batch
        Route::delete('pending-batch', [ApiRfidController::class, 'cancelPendingBatch'])
            ->name('pending-batch.cancel');

        // Hapus RFID tag dari batch (admin only)
        Route::delete('batches/{batchId}/tag', [ApiRfidController::class, 'removeTag'])
            ->name('batches.remove-tag');

        // Simpan IP ESP32 ke CompanySetting
        Route::post('device', [ApiRfidController::class, 'saveDeviceIp'])
            ->name('device');

        // Set mode aktif ESP32 (mode + stage → di-cache untuk hardware)
        Route::post('set-mode',   [ApiRfidController::class, 'setActiveMode'])
            ->name('set-mode');

        // Cek mode aktif saat ini
        Route::get('active-mode', [ApiRfidController::class, 'getActiveModeStatus'])
            ->name('active-mode');

        // Daftar stage yang tersedia (untuk dropdown Vue)
        Route::get('stages', [ApiRfidController::class, 'getAvailableStages'])
            ->name('stages');

        // Progressive Authorization Mode — baca dan set mode per stage
        Route::get('authorization-modes',  [ApiRfidController::class, 'getAuthorizationModes'])
            ->name('authorization-modes.index');

        Route::post('authorization-modes', [ApiRfidController::class, 'setAuthorizationMode'])
            ->name('authorization-modes.set');

        // ── API UI baru (kontrak: code + message + message_en; lihat App\Support\RfidApi) ──
        Route::get('overview', [RfidScanController::class, 'overview'])->name('overview');

        // Registri perangkat (admin kelola; peran lain hanya lihat)
        Route::get('readers',                 [RfidReaderController::class, 'index'])->name('readers.index');
        Route::post('readers',                [RfidReaderController::class, 'store'])->middleware('throttle:rfid-ui')->name('readers.store');
        Route::patch('readers/{id}',          [RfidReaderController::class, 'update'])->middleware('throttle:rfid-ui')->name('readers.update');
        Route::post('readers/{id}/rotate-key', [RfidReaderController::class, 'rotateKey'])->middleware('throttle:rfid-ui')->name('readers.rotate-key');

        // Registrasi tag: sesi per reader (paralel) atau input manual/USB
        Route::get('registration',                    [RfidRegistrationController::class, 'index'])->name('registration.index');
        Route::post('registration',                   [RfidRegistrationController::class, 'store'])->middleware('throttle:rfid-ui')->name('registration.store');
        Route::delete('registration/{sessionId}',     [RfidRegistrationController::class, 'destroy'])->middleware('throttle:rfid-ui')->name('registration.destroy');
        Route::get('batches/{batch}/registration',    [RfidRegistrationController::class, 'status'])->name('registration.status');

        // Scan: umpan, scan manual/simulasi, batalkan (koreksi beralasan)
        Route::get('scans',              [RfidScanController::class, 'index'])->name('scans.index');
        Route::post('scans',             [RfidScanController::class, 'store'])->middleware('throttle:rfid-ui')->name('scans.store');
        Route::post('scans/{id}/void',   [RfidScanController::class, 'void'])->middleware('throttle:rfid-ui')->name('scans.void');

        // Log setiap pembacaan (accepted/rejected/duplicate) + foto CCTV per log
        Route::get('read-logs', [RfidReadLogController::class, 'index'])->name('read-logs.index');

        // Siklus hidup tag
        Route::post('batches/{batch}/release-tag', [RfidTagController::class, 'release'])->middleware('throttle:rfid-ui')->name('batches.release-tag');
        Route::get('batches/{batch}/tag-history',  [RfidTagController::class, 'history'])->name('batches.tag-history');
        Route::get('batch-search', [RfidTagController::class, 'search'])->name('batches.search');

    });

}); 