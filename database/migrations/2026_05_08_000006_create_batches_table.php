<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel batches — tabel INTI sistem traceability.
 * Setiap batch merepresentasikan satu kantung/lot material
 * yang dilacak sepanjang rantai proses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batches', function (Blueprint $table) {
            $table->id();

            // ─── Kepemilikan ──────────────────────────────────────────────
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            // ─── Identifikasi Batch ───────────────────────────────────────
            // Formula: [ENT].[LOK]-[PROC].[PROD]-[YYMMDD]-[SEQ]
            // Contoh: TMH.SGT-LK.MIK-260504-001
            $table->string('batch_code', 60)->unique();
            $table->string('lot_number', 80)->nullable()->unique();

            // ─── Relasi Produk & Hierarki ─────────────────────────────────
            $table->foreignId('product_code_id')
                ->constrained('product_codes');

            // Parent dari operasi split (self-referential)
            $table->foreignId('parent_batch_id')
                ->nullable()
                ->constrained('batches')
                ->nullOnDelete();

            // ─── Tracking Berat ───────────────────────────────────────────
            $table->decimal('initial_weight', 15, 3)
                ->comment('Berat awal saat batch dibuat (kg)');
            $table->decimal('current_weight', 15, 3)
                ->comment('Berat terkini setelah susut/split (kg)');
            $table->string('weight_unit', 5)->default('kg');

            // ─── Tahap & Status Proses ────────────────────────────────────
            $table->enum('process_stage', [
                'lab_kimia',
                'receiving',
                'wet_process',
                'dry_process',
                'project_plant',
                'warehouse',
                'smelter',
                'exported',
                'terminated',
            ]);

            $table->enum('status', [
                'draft',        // Baru dibuat, belum dikonfirmasi
                'active',       // Aktif di stage saat ini
                'in_transit',   // Dalam perjalanan antar lokasi
                'in_process',   // Sedang diproses
                'in_warehouse', // Tersimpan di gudang
                'completed',    // Proses selesai
                'split',        // Sudah di-split
                'transformed',  // Sudah diubah menjadi output baru
                'exported',     // Sudah diekspor
                'terminated',   // Masuk smelter / batas akhir
            ]);

            // ─── Lokasi Saat Ini ──────────────────────────────────────────
            $table->foreignId('current_location_id')
                ->nullable()
                ->constrained('locations')
                ->nullOnDelete();

            // ─── Split Tracking ───────────────────────────────────────────
            $table->boolean('is_split')->default(false);
            $table->integer('split_count')->default(0);
            $table->string('split_suffix', 5)->nullable(); // '/A', '/B'
            $table->text('split_reason')->nullable();

            // ─── Mass Balance Alert ───────────────────────────────────────
            $table->decimal('mass_loss_pct', 5, 2)->nullable();
            $table->boolean('has_discrepancy')->default(false);

            // ─── Export Status ────────────────────────────────────────────
            $table->enum('export_status', [
                'not_applicable',
                'available',
                'reserved',
                'exported',
            ])->default('not_applicable');

            // ─── QR Code / RFID Placeholder ──────────────────────────────
            $table->string('qr_code_path', 500)->nullable();
            $table->string('rfid_tag', 100)->nullable();

            // ─── Metadata Fleksibel ───────────────────────────────────────
            $table->json('metadata')->default('{}');

            // ─── Audit ────────────────────────────────────────────────────
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        // Indexes untuk query performance
        Schema::table('batches', function (Blueprint $table) {
            $table->index(['company_id', 'process_stage']);
            $table->index(['company_id', 'status']);
            $table->index('batch_code');
            $table->index('product_code_id');
            $table->index('parent_batch_id');
            $table->index('current_location_id');
            $table->index('has_discrepancy');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batches');
    }
};