<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel user_location_roles — permission scope berdasarkan LOKASI.
 *
 * Contoh: Ahmad → Operator Wet Process → HANYA di Muntok
 * Support temporary assignment via valid_from / valid_until.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_location_roles', function (Blueprint $table) {

            $table->id();

            // ─── Relasi ────────────────────────────────────────────────────
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Tenant schema + location ID dari schema tenant tersebut
            $table->string('company_schema', 63);       // 'pt_timah'
            $table->unsignedBigInteger('location_id');   // ID dari tabel locations tenant

            // ─── Role di Lokasi Ini ───────────────────────────────────────
            $table->string('role_name', 50);
            // Contoh: 'operator_process', 'operator_lab', 'company_admin'

            // ─── Primary Location ─────────────────────────────────────────
            $table->boolean('is_primary')->default(true);
            // Saat login, default lokasi aktif adalah lokasi primary

            // ─── Temporary Assignment ─────────────────────────────────────
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable(); // NULL = permanen

            // ─── Audit ────────────────────────────────────────────────────
            $table->foreignId('assigned_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->text('assignment_notes')->nullable();

            $table->timestamps();

            // Satu user tidak bisa punya role yang sama di lokasi yang sama
            $table->unique(
                ['user_id', 'company_schema', 'location_id', 'role_name'],
                'unique_user_location_role'
            );
        });

        Schema::table('user_location_roles', function (Blueprint $table) {
            $table->index('user_id');
            $table->index(['company_schema', 'location_id']);
            $table->index('valid_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_location_roles');
    }
};