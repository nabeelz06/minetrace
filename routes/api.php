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
    |--------------------------------------------------------------------------
    | RFID Hardware API Routes
    |--------------------------------------------------------------------------
    |
    | Endpoints untuk integrasi hardware ESP32 + RFID RC522.
    |
    | HARDWARE ENDPOINTS (dipanggil ESP32 — tanpa session auth):
    |   POST /api/rfid/register  → ESP32 dalam mode REGISTER, kartu baru dibaca
    |   POST /api/rfid/login     → ESP32 dalam mode LOGIN, operator scan masuk
    |   POST /api/rfid/logout    → ESP32 dalam mode LOGOUT, operator scan keluar
    |
    | WEB UI ENDPOINTS (dipanggil Vue via Sanctum):
    |   GET    /api/rfid/status           → Status sistem + daftar user + operator hadir
    |   GET    /api/rfid/users            → Daftar users untuk dropdown registrasi
    |   POST   /api/rfid/pending          → Mulai sesi registrasi kartu ke user tertentu
    |   DELETE /api/rfid/pending          → Batalkan sesi registrasi
    |   DELETE /api/rfid/users/{id}/card  → Hapus kartu dari user
    |
    | CORS sudah ditangani di level controller untuk ESP32 compatibility.
    */

    // ─── Preflight OPTIONS (CORS untuk ESP32 & browser) ───────────────────────
    Route::options('rfid/{any}', [ApiRfidController::class, 'handleOptions'])
        ->where('any', '.*');

    // ─── HARDWARE ENDPOINTS — No session auth, token via header/body ──────────
    // Endpoint ini dipanggil langsung oleh ESP32 Arduino.
    // Autentikasi via X-Rfid-Token header atau body.api_token
    Route::prefix('rfid')->group(function () {

        Route::post('register', [ApiRfidController::class, 'registerCard'])
            ->name('api.rfid.register');

        Route::post('login', [ApiRfidController::class, 'loginCard'])
            ->name('api.rfid.login');

        Route::post('logout', [ApiRfidController::class, 'logoutCard'])
            ->name('api.rfid.logout');

    });

    // ─── WEB UI ENDPOINTS — Requires Sanctum session auth ────────────────────
    Route::middleware(['auth:sanctum'])->prefix('rfid')->group(function () {

        Route::get('status',                    [ApiRfidController::class, 'getStatus'])
            ->name('api.rfid.status');

        Route::get('users',                     [ApiRfidController::class, 'getUsers'])
            ->name('api.rfid.users');

        Route::post('pending',                  [ApiRfidController::class, 'setPending'])
            ->name('api.rfid.pending.set');

        Route::delete('pending',                [ApiRfidController::class, 'cancelPending'])
            ->name('api.rfid.pending.cancel');

        Route::delete('users/{userId}/card',    [ApiRfidController::class, 'unregisterCard'])
            ->name('api.rfid.users.unregister');

    });
});


/*
|──────────────────────────────────────────────────────────────────────────
| RFID ALIAS ROUTES (tanpa v1 prefix)
|──────────────────────────────────────────────────────────────────────────
| Untuk kompatibilitas dengan konfigurasi ESP32 yang menggunakan:
|   ENDPOINT_REGISTER = "/api/rfid/register"
|   ENDPOINT_LOGIN    = "/api/rfid/login"
|   ENDPOINT_LOGOUT   = "/api/rfid/logout"
|
| Jika ESP32 sudah dikonfigurasi ulang ke /api/v1/rfid/..., routes ini bisa
| dihapus. Untuk saat ini dibiarkan agar tidak perlu flash ulang ESP32.
*/
Route::prefix('rfid')->group(function () {
    Route::options('{any}', [ApiRfidController::class, 'handleOptions'])
        ->where('any', '.*');
    Route::post('register', [ApiRfidController::class, 'registerCard'])
        ->middleware('throttle:120,1');
    Route::post('login',    [ApiRfidController::class, 'loginCard'])
        ->middleware('throttle:120,1');
    Route::post('logout',   [ApiRfidController::class, 'logoutCard'])
        ->middleware('throttle:120,1');
});