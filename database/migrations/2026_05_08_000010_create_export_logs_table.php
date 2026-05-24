<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel export_logs — pencatatan ekspor dan penjualan domestik.
 * Hard block: MON tidak bisa diekspor (divalidasi di service layer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            // ─── 1 export bisa mencakup beberapa batch (consolidated) ─────
            $table->json('batch_ids')->default('[]');
            $table->decimal('total_weight_kg', 15, 3);

            // ─── Tipe Ekspor ──────────────────────────────────────────────
            $table->enum('export_type', [
                'international',  // Ekspor luar negeri
                'domestic',       // Penjualan dalam negeri
            ]);

            // ─── Tujuan ───────────────────────────────────────────────────
            $table->string('destination_country', 100)->nullable();
            $table->string('destination_company')->nullable();
            $table->string('destination_address')->nullable();

            // ─── Nomor Dokumen ────────────────────────────────────────────
            $table->string('manifest_number', 100)->nullable();
            $table->string('invoice_number', 100)->nullable();
            $table->string('coa_number', 100)->nullable();

            // ─── Pengiriman ───────────────────────────────────────────────
            $table->string('container_number', 100)->nullable();
            $table->string('vessel_name')->nullable();
            $table->string('port_of_loading', 100)->nullable();
            $table->string('port_of_discharge', 100)->nullable();
            $table->timestamp('estimated_departure')->nullable();
            $table->timestamp('actual_departure')->nullable();

            // ─── Nilai ────────────────────────────────────────────────────
            $table->decimal('value_usd', 18, 2)->nullable();
            $table->string('currency', 5)->default('USD');

            // ─── Status ───────────────────────────────────────────────────
            $table->enum('status', [
                'draft',     // Sedang disiapkan
                'confirmed', // Dikonfirmasi
                'shipped',   // Dikirim
                'delivered', // Diterima (Proof of Delivery)
            ])->default('draft');

            // ─── Path file dokumen yang di-generate ──────────────────────
            $table->json('generated_documents')->default('{}');

            // ─── Soft Warning flags (acknowledged oleh user) ─────────────
            $table->json('warnings_acknowledged')->default('[]');

            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamp('exported_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('export_logs', function (Blueprint $table) {
            $table->index('company_id');
            $table->index('export_type');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_logs');
    }
};