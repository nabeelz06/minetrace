<?php

namespace Database\Seeders\Companies\PtTimah;

use App\Models\CctvCamera;
use App\Models\Company;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * PtTimahCctvCameraSeeder — Seed kamera CCTV untuk PT Timah.
 *
 * Dual-RTSP columns (Phase 3):
 *   rtsp_url_simulation → selalu mengarah ke local mediamtx simulation stream
 *   rtsp_url_real       → RTSP real camera (TAPO, Hikvision, dll)
 *   rtsp_url            → dipakai CctvCamera::effective_rtsp_url sebagai legacy fallback
 *
 * ════════════════════════════════════════════════════════════════════════
 * KONDISI SAAT INI — 1 KAMERA FISIK MELAYANI SEMUA CHECKPOINT
 *
 * Perusahaan baru memasang SATU kamera fisik (TP-Link Tapo C500 di
 * 192.168.18.38), namun MINETRA melacak 5 checkpoint/stage produksi
 * (receiving, wet_process, dry_process, warehouse, project_plant). Sesuai
 * arahan operasional, kamera fisik yang sama ini didaftarkan untuk KELIMA
 * stage — setiap RFID scan di stage manapun akan memakai kamera yang sama
 * untuk audit visual, sampai kamera tambahan benar-benar terpasang per
 * lokasi.
 *
 * Kelima baris CctvCamera di bawah SENGAJA berbagi rtsp_url_real dan
 * hls_url yang IDENTIK (path mediamtx "live_gate1" yang sama) — ini bukan
 * duplikasi yang keliru, melainkan representasi yang akurat: satu
 * perangkat fisik, banyak peran logis. CctvController::provisionOrReuseLiveView()
 * mendeteksi kondisi berbagi kamera ini secara otomatis untuk kamera yang
 * ditambahkan lewat UI Settings; di seeder ini kita set hls_url secara
 * eksplisit karena path "live_gate1" sudah pasti ada (statis di
 * mediamtx.yml, sudah diverifikasi berjalan).
 *
 * MENAMBAH KAMERA FISIK BARU DI MASA DEPAN: TIDAK PERLU mengedit seeder
 * ini atau mediamtx.yml. Gunakan halaman
 * /company/hardware/cctv/settings (company_admin) — cukup masukkan nama,
 * pilih stage, dan RTSP URL kamera baru; backend akan otomatis
 * men-provisioning path live-view mediamtx yang sesuai (lihat
 * MediaMtxPathService). Seeder ini hanya untuk bootstrap data awal.
 *
 * PENTING — is_simulation di-set PER-KAMERA secara eksplisit, BUKAN
 * diturunkan dari satu flag global env('CCTV_USE_REAL'), karena armada
 * kamera bisa CAMPURAN di masa depan (sebagian real, sebagian masih
 * simulasi) — lihat CctvCamera::isSimulationCamera().
 *
 * PENTING — company_id HARUS di-resolve dinamis via kode 'TIMAH', BUKAN
 * di-hardcode. company_id=1 adalah "Badan Industri Mineral" (BIM,
 * regulator), sedangkan PT Timah Tbk sendiri ber-id 2 — lihat changelog
 * Bagian 12 untuk insiden yang disebabkan asumsi keliru ini.
 * ════════════════════════════════════════════════════════════════════════
 */
class PtTimahCctvCameraSeeder extends Seeder
{
    /**
     * Kredensial kamera fisik TUNGGAL yang saat ini melayani seluruh
     * checkpoint. Ubah nilai ini (atau, lebih baik, gunakan UI Settings)
     * saat kamera fisik diganti/upgrade.
     */
    private const SHARED_CAMERA_RTSP = 'rtsp://admin_minetra:Tapo123!@192.168.18.38/stream2';
    private const SHARED_LIVE_PATH   = 'live_gate1';

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
        $sharedHls = $hlsBase ? "{$hlsBase}/" . self::SHARED_LIVE_PATH . '/index.m3u8' : null;

        $cameras = [
            [
                'location_key' => 'receiving',
                'name'         => 'CCTV Utama (Gate 1) — Receiving Area',
                'sim_stream'   => 'cam_gate1',
            ],
            [
                'location_key' => 'wet_process',
                'name'         => 'CCTV Utama (Gate 1) — Wet Process',
                'sim_stream'   => 'cam_wet',
            ],
            [
                'location_key' => 'dry_process',
                'name'         => 'CCTV Utama (Gate 1) — Dry Process',
                'sim_stream'   => 'cam_wet',
            ],
            [
                'location_key' => 'warehouse',
                'name'         => 'CCTV Utama (Gate 1) — LTJ Warehouse',
                'sim_stream'   => 'cam_warehouse',
            ],
            [
                'location_key' => 'project_plant',
                'name'         => 'CCTV Utama (Gate 1) — Lab Analisis LTJ',
                'sim_stream'   => 'cam_warehouse',
            ],
        ];

        // is_simulation di-seed dari CCTV_USE_REAL (default simulasi jika env
        // belum di-set/false) — SESUAI docblock CctvCamera::isSimulationCamera()
        // yang menyatakan env ini dipakai sebagai DEFAULT saat seeding, bukan
        // otoritas runtime. Sebelumnya method ini hardcode `false` (selalu
        // REAL) terlepas dari env, sehingga deployment simulation-only
        // (Phase 1 production, CCTV_USE_REAL=false) tetap men-seed kamera
        // sebagai REAL mengarah ke IP LAN fisik yang tidak reachable dari VPS.
        $isSimulation = !(bool) env('CCTV_USE_REAL', false);

        foreach ($cameras as $cam) {
            $simRtsp = "{$rtspBase}/{$cam['sim_stream']}";

            CctvCamera::updateOrCreate(
                [
                    'company_id'   => $companyId,
                    'location_key' => $cam['location_key'],
                ],
                [
                    'name'                => $cam['name'],
                    'rtsp_url_simulation' => $simRtsp,
                    'rtsp_url_real'       => self::SHARED_CAMERA_RTSP,
                    'rtsp_url'            => $isSimulation ? $simRtsp : self::SHARED_CAMERA_RTSP,
                    // hls_url HANYA diisi eksplisit untuk mode real (path fisik
                    // "live_gate1" yang statis). Mode simulasi dibiarkan null
                    // agar CctvCamera::getEffectiveHlsUrlAttribute() menurunkan
                    // path per-stream (cam_gate1/cam_wet/cam_warehouse) secara
                    // otomatis dari location_key — bukan memakai hls_url
                    // bersama milik kamera real yang tidak eksis di mediamtx
                    // simulation-only.
                    'hls_url'             => $isSimulation ? null : $sharedHls,
                    'is_active'           => true,
                    'is_simulation'       => $isSimulation,
                ]
            );

            $mode = $isSimulation ? 'SIMULASI' : 'REAL (kamera bersama)';
            $this->command->info("  📹 CCTV seeded: {$cam['name']} ({$cam['location_key']}) — {$mode}");
        }
    }
}
