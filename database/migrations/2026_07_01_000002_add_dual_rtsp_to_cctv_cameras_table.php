<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cctv_cameras', function (Blueprint $table) {
            $table->text('rtsp_url_real')->nullable()->after('rtsp_url');
            $table->text('rtsp_url_simulation')->nullable()->after('rtsp_url_real');
            $table->boolean('is_simulation')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('cctv_cameras', function (Blueprint $table) {
            $table->dropColumn(['rtsp_url_real', 'rtsp_url_simulation', 'is_simulation']);
        });
    }
};
