<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel checkpoints — pencatatan setiap perpindahan/verifikasi batch.
 * Setiap kali batch berpindah lokasi atau statusnya berubah,
 * satu record checkpoint dibuat sebagai audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkpoints', function (Blueprint $table) {
            $table->id();

            $table->foreignId('batch_id')
                ->constrained('batches')
                ->cascadeOnDelete();

            $table->foreignId('location_id')
                ->nullable()
                ->constrained('locations')
                ->nullOnDelete();

            // ─── Stage Checkpoint ─────────────────────────────────────────
            $table->enum('checkpoint_stage', [
                'lab_kimia_create',
                'in_transit',
                'receiving_verify',
                'wet_process_input',
                'wet_process_output',
                'dry_process_input',
                'dry_process_output',
                'project_plant_input',
                'project_plant_output',
                'warehouse_inbound',
                'warehouse_outbound',
                'warehouse_transfer',
                'lab_sample_taken',
                'smelter_input',
                'export_release',
                'reprocess_return',
            ]);

            // ─── Tracking Berat ───────────────────────────────────────────
            $table->decimal('weight_recorded', 15, 3)->nullable();
            $table->decimal('weight_previous', 15, 3)->nullable();
            $table->decimal('weight_loss', 15, 3)->nullable();
            $table->decimal('weight_loss_pct', 5, 2)->nullable();
            $table->boolean('has_discrepancy')->default(false);

            // ─── Verifikasi ───────────────────────────────────────────────
            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();

            // Stage berikutnya yang dipilih operator (untuk branching)
            $table->string('next_stage', 50)->nullable();

            // ─── Data Fleksibel per Stage ─────────────────────────────────
            $table->json('checkpoint_data')->default('{}');

            // ─── Media ────────────────────────────────────────────────────
            $table->json('photos')->default('[]');
            $table->text('notes')->nullable();

            // ─── RFID / QR Placeholder ────────────────────────────────────
            $table->string('rfid_scan_code', 100)->nullable();
            $table->string('qr_scan_code', 100)->nullable();
            $table->timestamp('scan_timestamp')->nullable();

            // ─── Audit ────────────────────────────────────────────────────
            $table->unsignedBigInteger('recorded_by');
            $table->timestamp('recorded_at')->useCurrent();

            $table->timestamps();
        });

        Schema::table('checkpoints', function (Blueprint $table) {
            $table->index('batch_id');
            $table->index('location_id');
            $table->index('checkpoint_stage');
            $table->index('has_discrepancy');
            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkpoints');
    }
};