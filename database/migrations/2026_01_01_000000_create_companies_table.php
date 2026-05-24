<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel companies — registry semua tenant dalam MINETRA.
 * HARUS dibuat SEBELUM tabel users karena users punya FK ke companies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {

            $table->id();

            // ─── Identitas ────────────────────────────────────────────────
            $table->string('code', 20)->unique();   // 'BIM', 'TIMAH', 'VALE'
            $table->string('name');                  // 'PT Timah Tbk'
            $table->string('legal_name')->nullable();
            $table->string('industry', 100)->nullable(); // 'Mining - Tin'

            // ─── Branding (White-label) ───────────────────────────────────
            $table->string('logo_path', 500)->nullable();
            $table->string('primary_color', 7)->default('#3E5C74');
            $table->string('secondary_color', 7)->default('#5B8BB4');

            // ─── Schema Tenant (stancl/tenancy) ──────────────────────────
            // Nama schema PostgreSQL yang digunakan tenant ini
            $table->string('schema_name', 63)->unique()->nullable();

            // ─── Kontak ────────────────────────────────────────────────────
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();

            // ─── Status ───────────────────────────────────────────────────
            $table->boolean('is_active')->default(true);
            $table->boolean('is_regulator')->default(false); // true = BIM

            // ─── Pengaturan Fleksibel ─────────────────────────────────────
            $table->jsonb('settings')->default('{}');

            $table->timestamp('onboarded_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->index('code');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};