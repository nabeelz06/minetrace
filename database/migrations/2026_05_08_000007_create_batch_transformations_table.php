<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel batch_transformations — audit trail transformasi batch.
 * Menyimpan riwayat ketika 1 batch berubah menjadi N batch baru.
 * Contoh: Dry Process 1 MIK → [MON, ZIR, ILM]
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batch_transformations', function (Blueprint $table) {
            $table->id();

            // Batch yang dikonsumsi/diubah
            $table->foreignId('input_batch_id')
                ->constrained('batches')
                ->restrictOnDelete();

            // Array ID batch output yang dihasilkan: [15, 16, 17]
            $table->json('output_batch_ids')->default('[]');

            $table->enum('transformation_type', [
                'dry_process_extraction',   // MIK → MON + ZIR + ILM
                'project_plant_extraction', // MON → REE
                'warehouse_split',          // 1 batch → sub-batches
                'reprocess',                // Dikembalikan untuk re-proses
            ]);

            $table->foreignId('location_id')
                ->nullable()
                ->constrained('locations')
                ->nullOnDelete();

            /**
             * Detail transformasi dalam format JSON:
             * {
             *   "input_weight": 1000.0,
             *   "outputs": [
             *     {"batch_id": 15, "product": "MON", "weight": 350.0, "pct": 35.0},
             *     {"batch_id": 16, "product": "ZIR", "weight": 200.0, "pct": 20.0},
             *     {"batch_id": 17, "product": "ILM", "weight": 400.0, "pct": 40.0}
             *   ],
             *   "total_output_weight": 950.0,
             *   "loss_pct": 5.0
             * }
             */
            $table->json('transformation_details')->default('{}');

            $table->text('notes')->nullable();
            $table->unsignedBigInteger('performed_by');
            $table->timestamp('transformed_at')->useCurrent();

            $table->timestamps();
        });

        Schema::table('batch_transformations', function (Blueprint $table) {
            $table->index('input_batch_id');
            $table->index('transformation_type');
            $table->index('transformed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_transformations');
    }
};