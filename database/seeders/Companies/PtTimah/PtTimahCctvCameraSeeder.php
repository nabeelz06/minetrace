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
 * PENTING — is_simulation sekarang di-set PER-KAMERA secara eksplisit di
 * array $cameras di bawah, BUKAN diturunkan dari satu flag global
 * env('CCTV_USE_REAL'). Armada kamera CCTV MINETRA bisa CAMPURAN: sebagian
 * stage sudah punya kamera fisik terpasang (mis. Gate 1 — TP-Link Tapo C500
 * di 192.168.18.38), sementara stage lain masih menunggu instalasi hardware
 * dan tetap memakai simulation stream mediamtx. Satu toggle global tidak
 * bisa merepresentasikan kondisi ini — lihat
 * CctvCamera::isSimulationCamera() untuk detail arsitektur.
 *
 * env('CCTV_USE_REAL') tetap dipakai HANYA sebagai default fallback jika
 * suatu kamera baru ditambahkan tanpa is_simulation eksplisit di sini.
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
        $defaultUseReal = (bool) env('CCTV_USE_REAL', false);

        $cameras = [
            [
                'location_key'  => 'receiving',
                'name'          => 'Gate 1 - Receiving Area',
                'stream'        => 'cam_gate1',
                // Kamera fisik TP-Link Tapo C500 SUDAH TERPASANG dan aktif
                // di jaringan lokal — lihat docker/mediamtx/mediamtx.yml
                // path "live_gate1" untuk proxy HLS live-view-nya.
                'rtsp_real'     => 'rtsp://admin_minetra:Tapo123!@192.168.18.38/stream2',
                'is_simulation' => false,
            ],
            [
                'location_key'  => 'wet_process',
                'name'          => 'Wet Process Area',
                'stream'        => 'cam_wet',
                // Placeholder — belum ada kamera fisik terpasang untuk stage ini.
                'rtsp_real'     => 'rtsp://admin_minetra:Tapo123!@192.168.0.9/stream2',
                'is_simulation' => true,
            ],
            [
                'location_key'  => 'warehouse',
                'name'          => 'LTJ Warehouse',
                'stream'        => 'cam_warehouse',
                // Placeholder — belum ada kamera fisik terpasang untuk stage ini.
                'rtsp_real'     => 'rtsp://admin_minetra:Tapo123!@192.168.0.10/stream2',
                'is_simulation' => true,
            ],
        ];

        foreach ($cameras as $cam) {
            $isSimulation = $cam['is_simulation'] ?? $defaultUseReal === false;
            $simRtsp      = "{$rtspBase}/{$cam['stream']}";
            $simHls       = $hlsBase ? "{$hlsBase}/{$cam['stream']}/index.m3u8" : null;

            CctvCamera::updateOrCreate(
                [
                    'company_id'   => $companyId,
                    'location_key' => $cam['location_key'],
                ],
                [
                    'name'                => $cam['name'],
                    'rtsp_url_simulation' => $simRtsp,
                    'rtsp_url_real'       => $cam['rtsp_real'],
                    'rtsp_url'            => $isSimulation ? $simRtsp : $cam['rtsp_real'],
                    'hls_url'             => $simHls,
                    'is_active'           => true,
                    'is_simulation'       => $isSimulation,
                ]
            );

            $this->command->info("  📹 CCTV seeded: {$cam['name']} ({$cam['location_key']}) — " . ($isSimulation ? 'SIMULATION' : 'REAL'));
        }
    }
}
