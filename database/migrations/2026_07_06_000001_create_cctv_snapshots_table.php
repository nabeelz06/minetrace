<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel cctv_snapshots — riwayat capture CCTV sebagai entitas first-class.
 *
 * Sebelumnya snapshot hanya tersimpan sebagai JSON blob di checkpoints.photos,
 * yang tidak mendukung CRUD nyata, soft-delete dengan audit trail, filter
 * lintas-batch, atau alur kerja reviewer pada skala berarti. Tabel ini
 * menjadi sumber kebenaran baru; checkpoints.photos tetap ditulis sebagai
 * mirror ringan untuk kompatibilitas mundur (lihat CaptureCctvSnapshotJob).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cctv_snapshots', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checkpoint_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('camera_id')->nullable()->constrained('cctv_cameras')->nullOnDelete();

            $table->string('file_path')->nullable();
            $table->enum('capture_mode', ['simulation', 'real']);
            $table->enum('capture_status', ['success', 'failed']);
            $table->text('failure_reason')->nullable();
            $table->enum('scan_type', ['scan_in', 'scan_out'])->nullable();
            $table->string('rfid_uid')->nullable();
            $table->string('stage', 50)->nullable();
            $table->unsignedInteger('filesize_kb')->nullable();
            $table->timestamp('captured_at');

            $table->boolean('is_verified')->default(false);
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('review_notes')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['batch_id', 'captured_at']);
            $table->index(['company_id', 'capture_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cctv_snapshots');
    }
};
