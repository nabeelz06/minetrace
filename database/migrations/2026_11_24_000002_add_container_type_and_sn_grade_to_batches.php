<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Receiving + Wet digabung (docs/superpowers/specs/2026-11-24-receiving-wet-merge-design.md):
 *  batches.container_type : drum_besi | container | product_drum (null = data lama)
 *  batches.sn_grade_pct   : kadar Sn (%) drum besi saat ditimbang di Receiving
 *  status awaiting_intake : drum besi tiba, belum ditimbang & dicek kadar
 */
return new class extends Migration
{
    private const STATUSES = [
        'draft', 'active', 'in_transit', 'in_process', 'in_warehouse', 'completed',
        'split', 'transformed', 'exported', 'terminated', 'pooled', 'awaiting_weight', 'awaiting_intake',
    ];

    private const PREVIOUS = [
        'draft', 'active', 'in_transit', 'in_process', 'in_warehouse', 'completed',
        'split', 'transformed', 'exported', 'terminated', 'pooled', 'awaiting_weight',
    ];

    public function up(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->string('container_type', 20)->nullable()->after('rfid_tag');
            $table->decimal('sn_grade_pct', 6, 3)->nullable()->after('container_type');
        });

        $this->replaceStatusCheck(self::STATUSES);
    }

    public function down(): void
    {
        DB::table('batches')->where('status', 'awaiting_intake')->update(['status' => 'in_process']);
        $this->replaceStatusCheck(self::PREVIOUS);

        Schema::table('batches', function (Blueprint $table) {
            $table->dropColumn(['container_type', 'sn_grade_pct']);
        });
    }

    private function replaceStatusCheck(array $values): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $list = implode(', ', array_map(fn ($v) => "'{$v}'::character varying", $values));
        DB::statement('ALTER TABLE batches DROP CONSTRAINT IF EXISTS batches_status_check');
        DB::statement("ALTER TABLE batches ADD CONSTRAINT batches_status_check CHECK (((status)::text = ANY ((ARRAY[{$list}])::text[])))");
    }
};
