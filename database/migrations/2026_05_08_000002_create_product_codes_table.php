<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel product_codes — master material/produk LTJ.
 * 12 kode produk PT Timah dengan hazard flags dan export block.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_codes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            // ─── Identifikasi ─────────────────────────────────────────────
            $table->string('code', 5);
            $table->string('name');
            $table->string('name_en')->nullable();

            // ─── Klasifikasi ──────────────────────────────────────────────
            $table->enum('category', [
                'raw_material',
                'concentrate',
                'ree_element',
                'mixed_ree',
            ]);

            // Fix: varchar(10) untuk menampung 'FeTiO3', 'ZrO2', dll.
            $table->string('chemical_symbol', 10)->nullable();
            $table->string('unit', 10)->default('kg');

            // ─── Hazard & Radioaktivitas ──────────────────────────────────
            $table->boolean('is_hazardous')->default(false);
            $table->enum('radioactive_level', [
                'none', 'low', 'medium', 'high'
            ])->default('none');
            $table->json('hazard_types')->default('[]');
            $table->text('regulatory_notes')->nullable();

            // ─── Kontrol ekspor ───────────────────────────────────────────
            $table->boolean('is_export_blocked')->default(false);
            $table->text('export_block_reason')->nullable();

            // ─── Metadata ─────────────────────────────────────────────────
            $table->string('origin_stage', 50)->nullable();
            $table->text('description')->nullable();
            $table->json('specifications')->default('{}');
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_codes');
    }
};