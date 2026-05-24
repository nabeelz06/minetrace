<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel workflow_templates — definisi alur proses per company.
 * Disimpan dalam format JSONB untuk fleksibilitas konfigurasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_templates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            $table->string('name');
            $table->string('slug', 100);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            /**
             * Definisi lengkap tahapan workflow dalam format JSON.
             * Setiap stage memiliki: code, name, next_stages (branching),
             * allowed_locations, required_fields, transformation_type.
             */
            $table->json('stages')->default('[]');

            /**
             * Aturan transformasi batch (misal: Dry Process 1→3 output).
             * Mendefinisikan tipe transformasi dan output yang dihasilkan.
             */
            $table->json('transformation_rules')->default('{}');

            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_templates');
    }
};