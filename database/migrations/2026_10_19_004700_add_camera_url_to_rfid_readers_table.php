<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * camera_url — override kamera PER-READER di atas CctvCamera (stage-based). Kosong = pakai resolusi
 * lama (CctvCamera per-stage → kamera aktif pertama → env fallback). Dibaca CaptureCctvSnapshotJob
 * saat reader_id diketahui (lihat resolveCamera()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rfid_readers', function (Blueprint $table) {
            $table->string('camera_url')->nullable()->after('connection');
        });
    }

    public function down(): void
    {
        Schema::table('rfid_readers', function (Blueprint $table) {
            $table->dropColumn('camera_url');
        });
    }
};
