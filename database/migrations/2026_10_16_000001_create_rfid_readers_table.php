<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registri perangkat RFID (reader). Satu reader = satu "read point" tetap
 * (tahap + arah), menggantikan ketergantungan pada toggle mode berbasis cache.
 * Kunci perangkat disimpan sebagai hash SHA-256 (kunci asli hanya tampil sekali).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfid_readers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);                       // mis. WET-IN-01
            $table->string('name', 100);
            // Read point tetap: tahap + arah. 'any' = arah ditentukan oleh endpoint yang dipanggil.
            $table->string('stage', 30)->nullable();
            $table->string('direction', 10)->default('any');  // in | out | any
            $table->string('device_type', 30)->default('esp32_rc522'); // esp32_rc522 | uhf_epc_gen2 | keyboard_wedge | other
            $table->string('key_prefix', 12);                 // 8 karakter pertama untuk identifikasi di UI
            $table->string('key_hash', 64)->unique();         // sha256(kunci)
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->string('firmware_version', 40)->nullable();
            $table->json('last_status')->nullable();          // payload heartbeat terakhir (rssi, uptime, antrean offline, skew jam)
            $table->timestamp('key_rotated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rfid_readers');
    }
};
