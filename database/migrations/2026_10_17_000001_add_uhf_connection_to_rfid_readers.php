<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reader UHF:
 *  - uhf_desktop_r2000 : reader desktop USB (protokol R2000 "0xA0") di Receiving Area,
 *                        dibaca langsung oleh browser lewat Web Serial — tidak butuh field koneksi.
 *  - uhf_chainway_ur4  : reader jaringan Chainway UR4 di gate (TCP), dibaca oleh daemon
 *                        `php artisan rfid:uhf-gate`. Butuh host/port + pemetaan antena → arah.
 *
 * `connection` (json, nullable): { host, port, antennas: {"1":"in","2":"out"}, min_rssi, presence_seconds }
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rfid_readers', function (Blueprint $table) {
            $table->json('connection')->nullable()->after('device_type');
        });
    }

    public function down(): void
    {
        Schema::table('rfid_readers', function (Blueprint $table) {
            $table->dropColumn('connection');
        });
    }
};
