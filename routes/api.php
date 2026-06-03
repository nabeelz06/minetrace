<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Hardware\ApiRfidController;
use App\Http\Controllers\Api\V1\Auth\ApiAuthController;
use App\Http\Controllers\Api\V1\Batch\ApiBatchController;
use App\Http\Controllers\Api\V1\Checkpoint\ApiCheckpointController;
use App\Http\Controllers\Api\V1\Export\ApiExportController;
use App\Http\Controllers\Api\V1\Company\ApiCompanyController;
use App\Http\Controllers\Api\V1\Verify\ApiVerifyController;
use App\Http\Controllers\Api\V1\Hardware\ApiHardwareController;

/*
|─────────────────────────────────────────────────────────────────────────────
| MINETRA REST API — v1
|─────────────────────────────────────────────────────────────────────────────
| Auth: Laravel Sanctum token
| Rate: 60/min authenticated | 10/min public
| Format: { "success": bool, "data": mixed, "message": string, "meta"?: obj }
*/

Route::prefix('v1')->name('api.v1.')->group(function () {

    // ─── Public ──────────────────────────────────────────────────────────
    Route::get('health', function () {
        return response()->json([
            'success' => true,
            'data'    => ['status' => 'operational', 'version' => 'v1', 'time' => now()->toIso8601String()],
            'message' => 'MINETRA API is running.',
        ]);
    })->name('health');

    Route::post('auth/login', [ApiAuthController::class, 'login'])->name('auth.login');
    Route::get('verify/batch/{batchId}', [ApiVerifyController::class, 'verifyBatch'])->name('verify.batch');

    // Hardware reader config (public — dipanggil saat boot reader)
    Route::get('hardware/reader-config', [ApiHardwareController::class, 'readerConfig'])
        ->name('hardware.reader-config');

    // RFID scan (public — hardware tidak selalu bisa bawa auth token)
    Route::post('hardware/rfid-scan', [ApiHardwareController::class, 'rfidScan'])
        ->middleware('throttle:120,1')  // 120 scans per menit
        ->name('hardware.rfid-scan');

    // ─── ESP32 Boot Config (public, dipanggil ESP32 saat startup) ─────────────
    Route::get('hardware/rfid/esp-config', [ApiRfidController::class, 'espBootConfig'])
        ->name('hardware.rfid.esp-config');

    // ─── Authenticated ────────────────────────────────────────────────────
    Route::middleware(['auth:sanctum'])->group(function () {

        // Auth
        Route::post('auth/logout',  [ApiAuthController::class, 'logout'])->name('auth.logout');
        Route::get('auth/me',       [ApiAuthController::class, 'me'])->name('auth.me');
        Route::post('auth/refresh', [ApiAuthController::class, 'refresh'])->name('auth.refresh');

        // ─── Company endpoints ─────────────────────────────────────────────
        Route::prefix('company')->name('company.')->group(function () {

            Route::get('info', [ApiCompanyController::class, 'info'])->name('info');

            // Batches
            Route::prefix('batches')->name('batches.')->group(function () {
                Route::get('/',               [ApiBatchController::class, 'index'])->name('index');
                Route::post('/',              [ApiBatchController::class, 'store'])->name('store');
                Route::get('{batch}',         [ApiBatchController::class, 'show'])->name('show');
                Route::get('{batch}/qr',      [ApiBatchController::class, 'qrCode'])->name('qr');
                Route::get('{batch}/timeline',[ApiBatchController::class, 'timeline'])->name('timeline');
            });

            // Checkpoints
            Route::prefix('checkpoints')->name('checkpoints.')->group(function () {
                Route::post('{batch}', [ApiCheckpointController::class, 'store'])->name('store');
                Route::get('{batch}',  [ApiCheckpointController::class, 'index'])->name('index');
            });

            // Hardware
            Route::prefix('hardware')->name('hardware.')->group(function () {
                Route::post('rfid-assign',    [ApiHardwareController::class, 'rfidAssign'])->name('rfid-assign');
                Route::post('simulate-scan',  [ApiHardwareController::class, 'simulateScan'])->name('simulate-scan');
                Route::post('weight',         [ApiHardwareController::class, 'weightInput'])->name('weight');
                Route::get('events',          [ApiHardwareController::class, 'pollEvents'])->name('events');
            });

            // ─── RFID Web UI endpoints (dari RfidPanel.vue, Sanctum auth) ─────────────
            // (Sesuai instruksi Anda, diletakkan di dalam company -> hardware/sejajar)
            Route::prefix('rfid')->group(function () {
                // Status dashboard RFID (batch ter-tag, aktivitas terbaru, pending)
                Route::get('status',        [ApiRfidController::class, 'getStatus'])
                    ->name('rfid.status');
                
                // Batch belum ter-tag (untuk dropdown register)
                Route::get('batches',       [ApiRfidController::class, 'getUntaggedBatches'])
                    ->name('rfid.batches');
                
                // Set/cancel pending batch
                Route::post('pending-batch',   [ApiRfidController::class, 'setPendingBatch'])
                    ->name('rfid.pending-batch.set');
                Route::delete('pending-batch', [ApiRfidController::class, 'cancelPendingBatch'])
                    ->name('rfid.pending-batch.cancel');
                
                // Hapus tag dari batch
                Route::delete('batches/{batchId}/tag', [ApiRfidController::class, 'removeTag'])
                    ->name('rfid.batches.remove-tag');
                
                // Simpan IP ESP32
                Route::post('device', [ApiRfidController::class, 'saveDeviceIp'])
                    ->name('rfid.device');
            });
        });

        // ─── BIM endpoints ─────────────────────────────────────────────────
        Route::prefix('bim')->name('bim.')->group(function () {
            Route::get('overview',  [ApiCompanyController::class, 'overview'])->name('overview');
            Route::get('companies', [ApiCompanyController::class, 'list'])->name('companies');
            Route::get('alerts',    [ApiBatchController::class, 'alerts'])->name('alerts');
        });
    });

/*
|──────────────────────────────────────────────────────────────────────────────
| RFID HARDWARE ENDPOINTS — Dipanggil langsung oleh ESP32 Arduino
|──────────────────────────────────────────────────────────────────────────────
| NO AUTH — ESP32 tidak bisa kirim session cookie / Sanctum token
| Token opsional via header X-Rfid-Token atau body api_token
*/
Route::options('rfid/{any}', [ApiRfidController::class, 'handleOptions'])
    ->where('any', '.*');

Route::prefix('rfid')->group(function () {
    Route::post('register', [ApiRfidController::class, 'registerCard'])
        ->middleware('throttle:120,1')
        ->name('api.rfid.register');

    Route::post('login',    [ApiRfidController::class, 'loginCard'])
        ->middleware('throttle:120,1')
        ->name('api.rfid.login');

    Route::post('logout',   [ApiRfidController::class, 'logoutCard'])
        ->middleware('throttle:120,1')
        ->name('api.rfid.logout');
});

/*
|──────────────────────────────────────────────────────────────────────────────
| RFID WEB UI ENDPOINTS — Dipanggil oleh RfidPanel.vue (session-based)
|──────────────────────────────────────────────────────────────────────────────
| PENTING: Gunakan middleware ['web', 'auth'] — BUKAN auth:sanctum
| Inertia.js menggunakan session cookie browser, BUKAN Bearer token
| Prefix: /api/v1/company/rfid/... (sudah sesuai fix bug #1)
*/
Route::prefix('v1')->group(function () {

    // ─── ESP32 Boot Config (public) ──────────────────────────────────────────
    Route::get('hardware/rfid/esp-config', [ApiRfidController::class, 'espBootConfig'])
        ->name('api.v1.hardware.rfid.esp-config');

    // ─── Web UI RFID — session auth, bukan Sanctum ───────────────────────────
    Route::middleware(['web', 'auth'])
        ->prefix('company/rfid')
        ->name('api.v1.company.rfid.')
        ->group(function () {

        // Status dashboard (tagged batches, activity, pending)
        Route::get('status',        [ApiRfidController::class, 'getStatus'])
            ->name('status');

        // Daftar batch belum ter-tag (untuk dropdown simulasi & register)
        Route::get('batches',       [ApiRfidController::class, 'getUntaggedBatches'])
            ->name('batches');

        // Set / cancel pending batch untuk registrasi
        Route::post('pending-batch',   [ApiRfidController::class, 'setPendingBatch'])
            ->name('pending-batch.set');
        Route::delete('pending-batch', [ApiRfidController::class, 'cancelPendingBatch'])
            ->name('pending-batch.cancel');

        // Hapus tag dari batch
        Route::delete('batches/{batchId}/tag', [ApiRfidController::class, 'removeTag'])
            ->name('batches.remove-tag');

        // Simpan IP ESP32
        Route::post('device', [ApiRfidController::class, 'saveDeviceIp'])
            ->name('device');
    });
});