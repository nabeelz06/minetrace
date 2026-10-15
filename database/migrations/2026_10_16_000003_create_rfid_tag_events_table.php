<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat siklus hidup tag RFID (append-only): registered → released (lepas/ganti/hilang/rusak).
 * Tidak ada UPDATE/DELETE oleh aplikasi; koreksi = peristiwa baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfid_tag_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('uid', 100);
            $table->string('event', 20);       // registered | released
            $table->string('reason', 20)->nullable();  // replaced | lost | damaged | other
            $table->string('note', 255)->nullable();
            $table->foreignId('reader_id')->nullable()->constrained('rfid_readers')->nullOnDelete();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['uid', 'created_at']);
            $table->index(['batch_id', 'created_at']);
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rfid_tag_events');
    }
};
