<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkpoints', function (Blueprint $table) {
            $table->string('rfid_origin', 20)->nullable()->after('snapshot_status')
                  ->comment('simulation | real_esp32 | api');
            $table->boolean('capture_attempted')->default(false)->after('rfid_origin');
            $table->boolean('capture_succeeded')->default(false)->after('capture_attempted');

            $table->index('rfid_origin');
        });
    }

    public function down(): void
    {
        Schema::table('checkpoints', function (Blueprint $table) {
            $table->dropIndex(['rfid_origin']);
            $table->dropColumn(['rfid_origin', 'capture_attempted', 'capture_succeeded']);
        });
    }
};
