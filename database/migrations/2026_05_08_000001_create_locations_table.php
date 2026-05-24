<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel locations — titik-titik proses fisik operasional.
 * Setiap lokasi memiliki tipe (lab, receiving, warehouse, dll.)
 * dan dapat memiliki kapasitas maksimum (untuk warehouse).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();

            // ─── Relasi ke company ────────────────────────────────────────
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            // ─── Identifikasi ─────────────────────────────────────────────
            $table->string('code', 10);          // 'SGT', 'MTK', 'KND', 'TJU'
            $table->string('name');               // 'Sungailiat'
            $table->string('full_name')->nullable(); // 'Stasiun Uji Lab Kimia Sungailiat'

            // ─── Tipe lokasi ──────────────────────────────────────────────
            $table->enum('location_type', [
                'lab_kimia',
                'receiving',
                'wet_process',
                'dry_process',
                'project_plant',
                'warehouse',
                'smelter',
            ]);

            // ─── Kapasitas (khusus warehouse) ─────────────────────────────
            $table->decimal('max_capacity_kg', 15, 2)->nullable();
            $table->decimal('capacity_warning_pct', 5, 2)->default(80.00);

            // ─── Informasi geografis ──────────────────────────────────────
            $table->string('city', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Kode lokasi unik per company
            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};