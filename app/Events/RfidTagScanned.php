<?php

namespace App\Events;

use App\Models\RfidReadLog;

/**
 * RfidTagScanned — satu pembacaan Scan IN/OUT yang BERHASIL (punya checkpoint) baru dicatat ke
 * rfid_read_logs. Ditembakkan hanya untuk action 'in'/'out', TIDAK untuk 'register' (registrasi
 * punya jalur capture sendiri — lihat RfidReadLogService::capture()).
 *
 * Titik perluasan: listener lain boleh ditambah di app/Listeners (auto-discovery Laravel 11; cek
 * `php artisan event:list`) tanpa mengubah RfidScanService. Hanya DispatchCctvSnapshotForScan
 * terdaftar saat ini — JANGAN didaftarkan lagi manual via Event::listen() (dobel dispatch job).
 */
class RfidTagScanned
{
    public function __construct(public readonly RfidReadLog $log) {}
}
