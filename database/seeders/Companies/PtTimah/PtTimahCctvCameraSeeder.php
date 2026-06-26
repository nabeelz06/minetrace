<?php

namespace Database\Seeders\Companies\PtTimah;

use App\Models\CctvCamera;
use Illuminate\Database\Seeder;

/**
 * PtTimahCctvCameraSeeder — Seed 3 kamera CCTV simulasi untuk PT Timah.
 *
 * Kamera sesuai stage produksi:
 *   1. cam_gate1     → Receiving Area    (stage: receiving)
 *   2. cam_wet       → Wet Process Area  (stage: wet_process)
 *   3. cam_warehouse → LTJ Warehouse     (stage: warehouse)
 *
 * Jika CCTV_USE_REAL=false (default), effective_rtsp_url & effective_hls_url
 * otomatis mengarah ke local mediamtx simulation stream di CctvCamera model.
 *
 * Seeder ini idempotent — gunakan updateOrCreate(company_id + location_key).
 */
class PtTimahCctvCameraSeeder extends Seeder
{
    public function run(): void
    {
        $hlsBase  = rtrim(env('CCTV_HLS_BASE_URL', 'http://localhost:8888'), '/');
        $rtspBase = rtrim(env('CCTV_MEDIAMTX_RTSP', 'rtsp://127.0.0.1:8554'), '/');

        $cameras = [
            [
                'location_key' => 'receiving',
                'name'         => 'Gate 1 - Receiving Area',
                'stream'       => 'cam_gate1',
                'rtsp_real'    => 'rtsp://admin_minetra:Tapo123!@192.168.0.8/stream2',
            ],
            [
                'location_key' => 'wet_process',
                'name'         => 'Wet Process Area',
                'stream'       => 'cam_wet',
                'rtsp_real'    => 'rtsp://admin_minetra:Tapo123!@192.168.0.9/stream2',
            ],
            [
                'location_key' => 'warehouse',
                'name'         => 'LTJ Warehouse',
                'stream'       => 'cam_warehouse',
                'rtsp_real'    => 'rtsp://admin_minetra:Tapo123!@192.168.0.10/stream2',
            ],
        ];

        foreach ($cameras as $cam) {
            CctvCamera::updateOrCreate(
                [
                    'company_id'   => 1,
                    'location_key' => $cam['location_key'],
                ],
                [
                    'name'      => $cam['name'],
                    'rtsp_url'  => env('CCTV_USE_REAL', false)
                        ? $cam['rtsp_real']
                        : "{$rtspBase}/{$cam['stream']}",
                    'hls_url'   => $hlsBase
                        ? "{$hlsBase}/{$cam['stream']}/index.m3u8"
                        : null,
                    'is_active' => true,
                ]
            );

            $this->command->info("  📹 CCTV seeded: {$cam['name']} ({$cam['location_key']})");
        }
    }
}
