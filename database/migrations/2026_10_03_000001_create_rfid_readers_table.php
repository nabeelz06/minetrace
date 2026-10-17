<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: rfid_readers (Fase 7 — Device Registry untuk RFID Reader).
 *
 * ════════════════════════════════════════════════════════════════════════
 * KONTEKS ARSITEKTUR
 *
 * Sebelum tabel ini ada, SEMUA reader RFID (ESP32/RC522 maupun bridge UHF
 * HW-VX6336 yang baru) berbagi SATU token statis (RFID_API_TOKEN di .env,
 * global untuk seluruh aplikasi — lihat RfidService::validateToken()).
 * Konsekuensinya:
 *   - Tidak ada identitas per-unit fisik — tidak bisa tahu reader mana
 *     yang mengirim scan tertentu di luar field opsional `reader_id` yang
 *     tidak divalidasi.
 *   - Tidak bisa mencabut SATU reader (hilang/dicuri/rusak) tanpa me-rotasi
 *     token untuk SEMUA reader sekaligus.
 *   - Tidak ada visibilitas online/offline di web panel.
 *
 * Tabel ini menambahkan device registry ringan (pola AWS IoT Core "Thing" /
 * Azure IoT Hub "Device Identity"): admin mendaftarkan reader via web UI →
 * sistem membuatkan device_key unik (ditampilkan SEKALI, hanya hash yang
 * disimpan — identik pola Laravel Sanctum personal access token).
 *
 * BERSIFAT ADDITIVE — tidak mengubah/menghapus kolom atau tabel manapun
 * yang sudah ada. Reader ESP32 lama yang masih mengirim X-Rfid-Token +
 * X-Company-Code (tanpa device_key) TETAP berfungsi penuh tanpa perubahan
 * apa pun; lihat ApiRfidController::resolveDeviceContext().
 * ════════════════════════════════════════════════════════════════════════
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfid_readers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // Nama bebas yang mudah dibaca admin, mis. "Reader Gudang Masuk".
            $table->string('name');

            // Identifier publik, mis. "RDR-TIMAH-001" — aman ditampilkan di
            // UI/log, BUKAN credential (lihat device_key_hash di bawah).
            $table->string('device_code')->unique();

            // Hash SHA-256 dari device_key plaintext. Plaintext HANYA pernah
            // ada sesaat di response saat create/regenerate — tidak pernah
            // disimpan ke DB. Lookup pakai hash (bukan bcrypt) secara
            // SENGAJA agar bisa di-index & di-query langsung (device_key
            // adalah token acak beren­tropi tinggi, bukan password manusia
            // yang butuh salt lambat seperti bcrypt — pola ini identik
            // Laravel Sanctum untuk personal_access_tokens.hashed_token).
            $table->string('device_key_hash')->unique();

            // Lokasi/stage opsional — dipakai untuk konteks tampilan saja,
            // harus salah satu dari RfidService::STAGE_FLOW (divalidasi di
            // controller, bukan di level DB, agar tidak perlu migration
            // baru setiap kali daftar stage berubah).
            $table->string('location_key')->nullable();

            // 'esp32_rc522' | 'uhf_vx6336' | 'other' — plain string (bukan
            // DB enum) agar tipe hardware baru di masa depan tidak perlu
            // migration ALTER TYPE.
            $table->string('hardware_type')->default('other');

            $table->boolean('is_active')->default(true);

            // Heartbeat/scan terakhir — dasar status online/offline di UI.
            // Lihat RfidReader::getStatusAttribute().
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_seen_ip', 64)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rfid_readers');
    }
};
