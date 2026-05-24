<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel lab_analyses — hasil analisis kimia di Project Plant / Lab.
 * 5 parameter LTJ aktif (La, Ce, Pr, Nd, Y) + opsional ThO2 & U3O8.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_analyses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('batch_id')
                ->constrained('batches')
                ->cascadeOnDelete();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            $table->foreignId('location_id')
                ->nullable()
                ->constrained('locations')
                ->nullOnDelete();

            // ─── 5 Parameter LTJ Aktif (wajib diisi) ─────────────────────
            $table->decimal('la_pct', 6, 3)->nullable()->comment('Lantanum (La) %');
            $table->decimal('ce_pct', 6, 3)->nullable()->comment('Serium (Ce) %');
            $table->decimal('pr_pct', 6, 3)->nullable()->comment('Praseodimium (Pr) %');
            $table->decimal('nd_pct', 6, 3)->nullable()->comment('Neodimium (Nd) %');
            $table->decimal('y_pct',  6, 3)->nullable()->comment('Itrium (Y) %');

            // ─── Total REO (dihitung otomatis) ───────────────────────────
            $table->decimal('total_reo_pct', 6, 3)->nullable()
                ->comment('Total Rare Earth Oxide = sum(La+Ce+Pr+Nd+Y)');

            // ─── Parameter Radioaktif Opsional ────────────────────────────
            $table->decimal('tho2_pct', 6, 3)->nullable()->comment('Thorium Oxide (ThO2) %');
            $table->decimal('u3o8_pct', 6, 3)->nullable()->comment('Uranium Oxide (U3O8) %');

            // ─── Berat per Elemen (kg) — dihitung otomatis ───────────────
            $table->decimal('la_kg',  12, 3)->nullable();
            $table->decimal('ce_kg',  12, 3)->nullable();
            $table->decimal('pr_kg',  12, 3)->nullable();
            $table->decimal('nd_kg',  12, 3)->nullable();
            $table->decimal('y_kg',   12, 3)->nullable();

            // ─── Info Lab ─────────────────────────────────────────────────
            $table->string('lab_method', 50)->nullable();  // 'XRF', 'ICP-MS', 'AAS'
            $table->string('equipment_used', 100)->nullable();
            $table->decimal('sample_weight_g', 8, 3)->nullable();

            // ─── Validasi ─────────────────────────────────────────────────
            $table->boolean('is_validated')->default(false);
            $table->timestamp('validated_at')->nullable();
            $table->unsignedBigInteger('validated_by')->nullable();

            // ─── Dokumen ──────────────────────────────────────────────────
            $table->string('report_file_path', 500)->nullable();

            $table->text('notes')->nullable();
            $table->unsignedBigInteger('analyzed_by');
            $table->timestamp('analyzed_at')->useCurrent();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('lab_analyses', function (Blueprint $table) {
            $table->index('batch_id');
            $table->index('company_id');
            $table->index('is_validated');
            $table->index('analyzed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_analyses');
    }
};