<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Integritas & skala scan RFID pada `checkpoints`:
 *  - company_id  : denormalisasi (tenant) agar umpan scan tidak perlu JOIN batches
 *  - event_id    : idempotensi dari klien/perangkat (unik per perusahaan)
 *  - reader_id   : asal reader (provenans)
 *  - voided_*    : pembatalan scan lewat penanda beralasan (BUKAN delete; riwayat tetap)
 *  - indeks      : (batch_id, recorded_at), rfid_scan_code, (company_id, id)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkpoints', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('event_id', 64)->nullable();
            $table->foreignId('reader_id')->nullable()->constrained('rfid_readers')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();

            $table->unique(['company_id', 'event_id'], 'checkpoints_company_event_unique');
            $table->index(['batch_id', 'recorded_at'], 'checkpoints_batch_recorded_idx');
            $table->index('rfid_scan_code', 'checkpoints_rfid_scan_code_idx');
            $table->index(['company_id', 'id'], 'checkpoints_company_id_id_idx');
        });

        // Backfill tenant dari batch (aman diulang; hanya baris yang masih kosong).
        DB::statement(
            'UPDATE checkpoints SET company_id = batches.company_id
               FROM batches
              WHERE checkpoints.batch_id = batches.id AND checkpoints.company_id IS NULL'
        );
    }

    public function down(): void
    {
        Schema::table('checkpoints', function (Blueprint $table) {
            $table->dropIndex('checkpoints_company_id_id_idx');
            $table->dropIndex('checkpoints_rfid_scan_code_idx');
            $table->dropIndex('checkpoints_batch_recorded_idx');
            $table->dropUnique('checkpoints_company_event_unique');
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['voided_at', 'void_reason', 'event_id']);
            $table->dropConstrainedForeignId('reader_id');
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
