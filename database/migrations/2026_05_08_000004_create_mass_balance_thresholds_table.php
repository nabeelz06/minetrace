<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel mass_balance_thresholds — toleransi susut material per stage.
 * Configurable oleh company_admin melalui halaman Settings.
 * Warning (kuning) dan Critical (merah) dapat diatur terpisah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mass_balance_thresholds', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            $table->string('process_stage', 50);   // 'wet_process', 'dry_process'
            $table->string('stage_label');           // Label tampilan: 'Wet Process'

            $table->decimal('warning_threshold_pct', 5, 2)->default(10.00);
            $table->decimal('critical_threshold_pct', 5, 2)->default(20.00);

            // Catatan konteks untuk membantu admin memahami setting ini
            $table->text('notes')->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'process_stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mass_balance_thresholds');
    }
};