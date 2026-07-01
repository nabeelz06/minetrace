<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * rfid_authorization_modes — Progressive Authorization Model per stage per company.
 *
 * mode values:
 *   audit_only        → scan = audit trail only (default, current behavior)
 *   scan_to_receive   → scan IN triggers receiveBatch() via Chain-of-Responsibility
 *   scan_to_dispatch  → scan OUT triggers forwardBatch() via Chain-of-Responsibility
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfid_authorization_modes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 30);
            $table->string('mode', 30)->default('audit_only');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'stage']);
            $table->index(['company_id', 'stage', 'mode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rfid_authorization_modes');
    }
};
