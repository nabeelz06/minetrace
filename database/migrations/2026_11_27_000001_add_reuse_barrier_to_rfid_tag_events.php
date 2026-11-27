<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Bebaskan tag" (pakai ulang): peristiwa rfid_tag_events 'cleared' menyatakan UID kembali dianggap BARU.
 * Riwayat lama TIDAK dihapus (append-only); yang disimpan hanya batas id agar pemeriksaan "tag bekas"
 * (PoolService::uidHasHistory) mengabaikan jejak yang lebih lama dari batas itu. Memakai id (monoton) bukan jam,
 * karena beberapa tabel mencatat waktu per detik dan pembebasan + pemakaian ulang bisa terjadi dalam detik yang sama.
 *
 * Aditif: dua kolom nullable, tanpa perubahan data. Aman dijalankan pada tabel berisi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rfid_tag_events', function (Blueprint $table) {
            $table->unsignedBigInteger('barrier_checkpoint_id')->nullable()->after('reader_id');
            $table->unsignedBigInteger('barrier_pool_input_id')->nullable()->after('barrier_checkpoint_id');
        });
    }

    public function down(): void
    {
        Schema::table('rfid_tag_events', function (Blueprint $table) {
            $table->dropColumn(['barrier_checkpoint_id', 'barrier_pool_input_id']);
        });
    }
};
