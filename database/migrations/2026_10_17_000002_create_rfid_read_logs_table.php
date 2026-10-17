<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Log SETIAP pembacaan RFID (append-only), apa pun hasilnya:
 *   accepted  : tercatat sebagai checkpoint (scan IN/OUT atau registrasi)
 *   rejected  : ditolak (tag asing, arah salah, tag sudah dipakai, …) — sebelumnya hanya di cache 1 jam
 *   duplicate : pembacaan ulang dalam jendela dedupe / event_id yang sama (tanpa capture)
 *
 * Setiap log accepted/rejected memicu capture CCTV (Tapo) — hasilnya ditautkan lewat snapshot_id.
 * cctv_snapshots.batch_id dibuat nullable karena tag asing tidak punya batch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfid_read_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reader_id')->nullable()->constrained('rfid_readers')->nullOnDelete();
            $table->string('epc', 100);
            $table->string('tid', 64)->nullable();
            $table->string('action', 10);                  // in | out | register
            $table->string('stage', 30)->nullable();
            $table->unsignedSmallInteger('antenna')->nullable();
            $table->smallInteger('rssi')->nullable();      // dBm
            $table->string('origin', 20)->nullable();      // real_uhf | real_esp32 | manual | simulation | api
            $table->string('result', 10);                  // accepted | rejected | duplicate
            $table->string('result_code', 40)->nullable(); // _code dari hasil scan/registrasi
            $table->string('message', 255)->nullable();
            $table->foreignId('batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('checkpoint_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('snapshot_status', 10)->default('none'); // none | pending | captured | failed
            $table->foreignId('snapshot_id')->nullable()->constrained('cctv_snapshots')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamp('read_at')->useCurrent();

            $table->index(['company_id', 'id']);
            $table->index(['company_id', 'read_at']);
            $table->index(['reader_id', 'read_at']);
            $table->index('epc');
        });

        Schema::table('cctv_snapshots', function (Blueprint $table) {
            $table->unsignedBigInteger('batch_id')->nullable()->change();
            $table->foreignId('read_log_id')->nullable()->after('checkpoint_id')
                ->constrained('rfid_read_logs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cctv_snapshots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('read_log_id');
        });
        Schema::dropIfExists('rfid_read_logs');
        // batch_id dibiarkan nullable: mengembalikan NOT NULL gagal bila sudah ada snapshot tanpa batch.
    }
};
