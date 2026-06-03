<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            // Hanya tambah jika belum ada
            if (!Schema::hasColumn('batches', 'rfid_tag')) {
                $table->string('rfid_tag', 50)->nullable()->unique()->after('batch_code')
                    ->comment('UID RFID tag yang ditempel ke karung mineral');
            }
        });
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            if (Schema::hasColumn('batches', 'rfid_tag')) {
                $table->dropColumn('rfid_tag');
            }
        });
    }
};
