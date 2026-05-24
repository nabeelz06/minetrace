<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel company_settings — konfigurasi dinamis per company.
 * Key-value store untuk pengaturan yang bisa diubah admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_settings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            $table->string('key', 100);
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string'); // 'string','integer','boolean','json'
            $table->string('group', 50)->default('general');
            $table->string('label')->nullable();           // Label UI yang ditampilkan
            $table->text('description')->nullable();
            $table->boolean('is_public')->default(false);

            $table->timestamps();

            $table->unique(['company_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_settings');
    }
};