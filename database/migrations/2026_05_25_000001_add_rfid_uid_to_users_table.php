<?php
// database/migrations/2026_05_25_000001_add_rfid_uid_to_users_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah kolom rfid_uid ke tabel users.
 * Setiap user dapat memiliki satu kartu RFID yang terdaftar.
 * UID bersifat unik per company (satu kartu = satu user).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // UID fisik kartu RFID (hex string, contoh: "A3B2C1D0")
            $table->string('rfid_uid', 50)->nullable()->unique()->after('email');

            // Timestamp terakhir scan RFID (login/logout)
            $table->timestamp('rfid_last_scan_at')->nullable()->after('rfid_uid');

            // Status kehadiran via RFID
            $table->boolean('rfid_is_present')->default(false)->after('rfid_last_scan_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['rfid_uid', 'rfid_last_scan_at', 'rfid_is_present']);
        });
    }
};