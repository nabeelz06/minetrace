<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penghimpunan batch di Wet/Dry (docs/superpowers/specs/2026-11-24-pooling-retag-design.md).
 *
 *  batch_pools        : satu himpunan per tahap (collecting → outputting → closed)
 *  batch_pool_inputs  : batch yang masuk himpunan (tag-nya dilepas saat masuk)
 *  batch_lineage      : silsilah banyak-ke-banyak input → output (bisa di-void)
 *  batches.pool_id    : himpunan yang menghasilkan batch (drum output)
 *  batches.rfid_tag   : UNIQUE (satu UID hanya terikat ke satu batch)
 */
return new class extends Migration
{
    private const STATUSES = [
        'draft', 'active', 'in_transit', 'in_process', 'in_warehouse', 'completed',
        'split', 'transformed', 'exported', 'terminated', 'pooled', 'awaiting_weight',
    ];

    private const OLD_STATUSES = [
        'draft', 'active', 'in_transit', 'in_process', 'in_warehouse', 'completed',
        'split', 'transformed', 'exported', 'terminated',
    ];

    private const TYPES = ['dry_process_extraction', 'project_plant_extraction', 'warehouse_split', 'reprocess', 'pooling'];

    private const OLD_TYPES = ['dry_process_extraction', 'project_plant_extraction', 'warehouse_split', 'reprocess'];

    public function up(): void
    {
        $this->assertNoDuplicateTags();

        Schema::create('batch_pools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 30);
            $table->string('status', 20)->default('collecting');
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('total_input_weight', 15, 3)->nullable();
            $table->decimal('total_output_weight', 15, 3)->nullable();
            $table->decimal('loss_pct', 7, 2)->nullable();
            $table->boolean('has_discrepancy')->default(false);
            $table->foreignId('transformation_id')->nullable()->constrained('batch_transformations')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'stage', 'status']);
        });

        Schema::create('batch_pool_inputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pool_id')->constrained('batch_pools')->cascadeOnDelete();
            $table->foreignId('batch_id')->unique()->constrained('batches')->cascadeOnDelete();
            $table->decimal('weight_at_join', 15, 3)->nullable();
            $table->string('released_rfid_tag', 100)->nullable()->index();
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('batch_lineage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_batch_id')->constrained('batches')->cascadeOnDelete();
            $table->foreignId('child_batch_id')->constrained('batches')->cascadeOnDelete();
            $table->foreignId('pool_id')->nullable()->constrained('batch_pools')->nullOnDelete();
            $table->decimal('contributed_weight', 15, 3)->nullable();
            $table->boolean('weight_is_estimate')->default(false);
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();
            $table->index(['child_batch_id', 'voided_at']);
            $table->index(['parent_batch_id', 'voided_at']);
        });

        Schema::table('batches', function (Blueprint $table) {
            $table->foreignId('pool_id')->nullable()->after('parent_batch_id')->constrained('batch_pools')->nullOnDelete();
        });

        Schema::table('batch_transformations', function (Blueprint $table) {
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();
        });

        $this->replaceCheck('batches', 'status', self::STATUSES);
        $this->replaceCheck('batch_transformations', 'transformation_type', self::TYPES);

        Schema::table('batches', function (Blueprint $table) {
            $table->unique('rfid_tag', 'batches_rfid_tag_unique');
        });
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->dropUnique('batches_rfid_tag_unique');
        });

        DB::table('batches')->whereIn('status', ['pooled', 'awaiting_weight'])->update(['status' => 'terminated']);
        DB::table('batch_transformations')->where('transformation_type', 'pooling')->delete();
        $this->replaceCheck('batches', 'status', self::OLD_STATUSES);
        $this->replaceCheck('batch_transformations', 'transformation_type', self::OLD_TYPES);

        Schema::table('batch_transformations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['voided_at', 'void_reason']);
        });

        Schema::table('batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pool_id');
        });

        Schema::dropIfExists('batch_lineage');
        Schema::dropIfExists('batch_pool_inputs');
        Schema::dropIfExists('batch_pools');
    }

    /** Enum Laravel di PostgreSQL = varchar + CHECK "<tabel>_<kolom>_check". */
    private function replaceCheck(string $table, string $column, array $values): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $list = implode(', ', array_map(fn ($v) => "'{$v}'::character varying", $values));
        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_{$column}_check");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_check CHECK ((({$column})::text = ANY ((ARRAY[{$list}])::text[])))");
    }

    private function assertNoDuplicateTags(): void
    {
        $dupes = DB::table('batches')->whereNotNull('rfid_tag')
            ->select('rfid_tag')->groupBy('rfid_tag')->havingRaw('COUNT(*) > 1')->pluck('rfid_tag');

        if ($dupes->isNotEmpty()) {
            throw new RuntimeException('UID RFID ganda di tabel batches, rapikan dulu sebelum migrasi: ' . $dupes->implode(', '));
        }
    }
};
