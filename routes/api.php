<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Hardware\ApiRfidController;
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
        ->middleware('throttle:120,1')
        ->name('api.rfid.register');

    // Login: checkpoint IN karung masuk gudang
    Route::post('login', [ApiRfidController::class, 'loginCard'])
        ->middleware('throttle:120,1')
        ->name('api.rfid.login');

    // Logout: checkpoint OUT karung keluar gudang
    Route::post('logout', [ApiRfidController::class, 'logoutCard'])
        ->middleware('throttle:120,1')
        ->name('api.rfid.logout');

}); // end prefix('rfid')

/*
|==============================================================================
| BAGIAN 2 — MINETRA REST API v1
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
            'message' => 'MINETRA API is running.',
        ]);
    })->name('health');

    // Auth login (public — belum login)
    Route::post('auth/login', [ApiAuthController::class, 'login'])
        ->name('auth.login');

    // Verifikasi batch via QR code (public)
    Route::get('verify/batch/{batchId}', [ApiVerifyController::class, 'verifyBatch'])
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
        Route::prefix('company')->name('company.')->group(function () {

            Route::get('info', [ApiCompanyController::class, 'info'])->name('info');

            // Batches
            Route::prefix('batches')->name('batches.')->group(function () {
                Route::get('/',                [ApiBatchController::class, 'index'])->name('index');
                Route::post('/',               [ApiBatchController::class, 'store'])->name('store');
                Route::get('{batch}',          [ApiBatchController::class, 'show'])->name('show');
                Route::get('{batch}/qr',       [ApiBatchController::class, 'qrCode'])->name('qr');
                Route::get('{batch}/timeline', [ApiBatchController::class, 'timeline'])->name('timeline');
            }); // end prefix('batches')

            // Checkpoints
            Route::prefix('checkpoints')->name('checkpoints.')->group(function () {
                Route::post('{batch}', [ApiCheckpointController::class, 'store'])->name('store');
                Route::get('{batch}',  [ApiCheckpointController::class, 'index'])->name('index');
            }); // end prefix('checkpoints')

            // Hardware (generic)
            Route::prefix('hardware')->name('hardware.')->group(function () {
                Route::post('rfid-assign',   [ApiHardwareController::class, 'rfidAssign'])->name('rfid-assign');
                Route::post('simulate-scan', [ApiHardwareController::class, 'simulateScan'])->name('simulate-scan');
                Route::post('weight',        [ApiHardwareController::class, 'weightInput'])->name('weight');
                Route::get('events',         [ApiHardwareController::class, 'pollEvents'])->name('events');
            }); // end prefix('hardware')

        }); // end prefix('company')

        // BIM endpoints
        Route::prefix('bim')->name('bim.')->group(function () {
            Route::get('overview',  [ApiCompanyController::class, 'overview'])->name('overview');
            Route::get('companies', [ApiCompanyController::class, 'list'])->name('companies');
            Route::get('alerts',    [ApiBatchController::class, 'alerts'])->name('alerts');
        }); // end prefix('bim')

    }); // end middleware(['auth:sanctum'])

    /*
    |──────────────────────────────────────────────────────────────────────
    | 2C. RFID WEB UI ENDPOINTS
    |──────────────────────────────────────────────────────────────────────
    | Dipanggil dari RfidPanel.vue (Inertia.js / browser session)
    |
    | KENAPA ['web', 'auth'] bukan auth:sanctum?
    |   Inertia.js menggunakan session cookie browser, BUKAN Bearer token.
    |   Sanctum butuh token di header → tidak cocok untuk Inertia.
    |   Middleware 'web' mengaktifkan session → 'auth' cek guard default.
    |
    | URL pattern: /api/v1/company/rfid/...
    |──────────────────────────────────────────────────────────────────────
    */
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

    }); // end middleware(['web', 'auth'])

}); 