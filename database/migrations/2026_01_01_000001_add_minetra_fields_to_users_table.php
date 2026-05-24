<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menambahkan kolom MINETRA ke tabel users yang sudah ada.
 * Tabel users default Laravel sudah ada — kita extend dengan
 * kolom-kolom yang dibutuhkan sistem traceability.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {

            // ─── Relasi ke Company (Tenant) ───────────────────────────────
            // NULL = super_admin yang tidak terikat company
            $table->foreignId('company_id')
                ->nullable()
                ->after('id')
                ->constrained('companies')
                ->nullOnDelete();

            // ─── Profil Tambahan ──────────────────────────────────────────
            $table->string('avatar_path', 500)->nullable()->after('email');
            $table->string('phone', 50)->nullable()->after('avatar_path');
            $table->string('employee_id', 50)->nullable()->after('phone');
            $table->string('department', 100)->nullable()->after('employee_id');

            // ─── Preferensi Per-User ──────────────────────────────────────
            $table->enum('language', ['id', 'en'])->default('id')->after('department');
            $table->boolean('dark_mode')->default(false)->after('language');
            $table->string('timezone', 50)->default('Asia/Jakarta')->after('dark_mode');

            // ─── Status & Keamanan ────────────────────────────────────────
            $table->boolean('is_active')->default(true)->after('timezone');
            $table->boolean('force_password_change')->default(false)->after('is_active');
            $table->timestamp('last_login_at')->nullable()->after('force_password_change');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            $table->timestamp('password_changed_at')->nullable()->after('last_login_ip');

            // ─── Soft Delete ──────────────────────────────────────────────
            $table->softDeletes();
        });

        // ─── Indexes ──────────────────────────────────────────────────────
        Schema::table('users', function (Blueprint $table) {
            $table->index('company_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn([
                'company_id', 'avatar_path', 'phone', 'employee_id', 'department',
                'language', 'dark_mode', 'timezone', 'is_active',
                'force_password_change', 'last_login_at', 'last_login_ip',
                'password_changed_at', 'deleted_at',
            ]);
        });
    }
};