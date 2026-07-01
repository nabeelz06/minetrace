<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkpoints', function (Blueprint $table) {
            // null    = no RFID capture attempted (N/A checkpoint)
            // pending = job dispatched, awaiting execution
            // captured = ffmpeg frame saved successfully
            // failed  = all retries exhausted (3×) or no RTSP URL
            $table->string('snapshot_status', 20)->nullable()->after('photos');
            $table->index('snapshot_status');
        });
    }

    public function down(): void
    {
        Schema::table('checkpoints', function (Blueprint $table) {
            $table->dropIndex(['snapshot_status']);
            $table->dropColumn('snapshot_status');
        });
    }
};
