<?php

namespace Database\Seeders\Companies\PtTimah;

use App\Models\CctvCamera;
use Illuminate\Database\Seeder;

class PtTimahCctvCameraSeeder extends Seeder
{
    public function run(): void
    {
        $hlsBase   = rtrim(env('CCTV_HLS_BASE_URL', 'http://localhost:8888'), '/');
        $streamPath = 'cam_gate1';

        CctvCamera::firstOrCreate(
            ['company_id' => 1, 'name' => 'Gate Receiving Area'],
            [
                'location_key' => 'receiving',
                'rtsp_url'     => env('CCTV_RTSP_URL', 'rtsp://admin_minetra:Tapo123!@192.168.0.8/stream2'),
                'hls_url'      => $hlsBase ? "{$hlsBase}/{$streamPath}/index.m3u8" : null,
                'is_active'    => true,
            ]
        );

        $this->command->info('  📹 CCTV camera seeded: Gate Receiving Area');
    }
}
