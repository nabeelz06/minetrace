<?php

namespace Database\Seeders\Companies\PtTimah;

use App\Models\CctvCamera;
use App\Models\Company;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * PtTimahCctvCameraSeeder — Seed kamera CCTV simulasi dan real untuk PT Timah.
 *
 * Dual-RTSP columns (Phase 3):
 *   rtsp_url_simulation → selalu mengarah ke local mediamtx simulation stream
 *   rtsp_url_real       → RTSP real camera (TAPO, Hikvision, dll)
 *   rtsp_url            → dipakai CctvCamera::effective_rtsp_url sebagai legacy fallback
 *
 * Selector: CCTV_USE_REAL=false (default) → simulation; true → real
 *
 * PENTING — company_id HARUS di-resolve dinamis via kode 'TIMAH', BUKAN
 * di-hardcode. company_id tidak selalu 1: pada seed data ini, company_id=1
 * adalah "Badan Industri Mineral" (BIM, regulator), sedangkan PT Timah Tbk
 * sendiri ber-id 2. Seeder versi sebelumnya salah hardcode company_id=1,
 * menyebabkan seluruh kamera ter-seed ke tenant BIM, bukan PT Timah — root
 * cause insiden "snapshot CCTV tidak pernah muncul" untuk batch PT Timah
 * yang sesungguhnya (lihat changelog Bagian 12).
 */
class PtTimahCctvCameraSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::where('code', 'TIMAH')->first();

        if (!$company) {
            Log::warning('PtTimahCctvCameraSeeder: company dengan code=TIMAH tidak ditemukan, seeding dibatalkan.');
            $this->command->warn('  ⚠ Company dengan code=TIMAH tidak ditemukan — seeding CCTV dibatalkan.');
            return;
        }

        $companyId = $company->id;
        $hlsBase   = rtrim(env('CCTV_HLS_BASE_URL', 'http://localhost:8888'), '/');
        $rtspBase  = rtrim(env('CCTV_MEDIAMTX_RTSP', 'rtsp://127.0.0.1:8554'), '/');

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

        $useReal = env('CCTV_USE_REAL', false);

        foreach ($cameras as $cam) {
            $simRtsp = "{$rtspBase}/{$cam['stream']}";
            $simHls  = $hlsBase ? "{$hlsBase}/{$cam['stream']}/index.m3u8" : null;

            CctvCamera::updateOrCreate(
                [
                    'company_id'   => $companyId,
                    'location_key' => $cam['location_key'],
                ],
                [
                    'name'               => $cam['name'],
                    'rtsp_url_simulation' => $simRtsp,
                    'rtsp_url_real'       => $cam['rtsp_real'],
                    'rtsp_url'            => $useReal ? $cam['rtsp_real'] : $simRtsp,
                    'hls_url'             => $simHls,
                    'is_active'           => true,
                    'is_simulation'       => !$useReal,
                ]
            );

            $this->command->info("  📹 CCTV seeded: {$cam['name']} ({$cam['location_key']}) — " . ($useReal ? 'REAL' : 'SIMULATION'));
        }
    }
}
