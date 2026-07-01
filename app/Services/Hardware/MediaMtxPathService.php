<?php

namespace App\Services\Hardware;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MediaMtxPathService — Provisioning path mediamtx secara dinamis via REST API.
 *
 * ════════════════════════════════════════════════════════════════════════
 * KENAPA INI DIBUTUHKAN
 *
 * mediamtx.yml adalah file statis yang di-mount read-only ke container
 * (docker-compose.yml: `./docker/mediamtx/mediamtx.yml:/mediamtx.yml:ro`).
 * Sebelum service ini ada, menambah kamera fisik BARU berarti: edit YAML
 * secara manual → docker compose up -d --force-recreate mediamtx → restart
 * container. Ini tidak praktis untuk operasional sehari-hari (company_admin
 * tidak punya akses server/Docker, dan setiap perubahan kecil butuh
 * developer turun tangan).
 *
 * mediamtx punya REST config API bawaan (sudah aktif di mediamtx.yml via
 * `api: yes`, port 9997) yang mendukung tambah/hapus path SECARA RUNTIME
 * tanpa restart container:
 *   GET    /v3/config/paths/get/{name}    → cek apakah path ada
 *   POST   /v3/config/paths/add/{name}    → tambah path baru
 *   DELETE /v3/config/paths/delete/{name} → hapus path
 *
 * Service ini membungkus 3 endpoint tersebut agar CctvController bisa
 * meng-otomasi provisioning path live-view setiap kali admin menambah
 * kamera fisik baru lewat halaman Settings — TANPA sentuh YAML sama sekali.
 *
 * KETERBATASAN PENTING — path yang ditambahkan lewat API HANYA hidup di
 * memori runtime mediamtx, TIDAK ditulis balik ke mediamtx.yml. Jika
 * container mediamtx di-restart, path dinamis ini akan hilang. Untuk
 * itu, ensurePath() dipanggil ulang secara lazy oleh CctvController setiap
 * kali halaman monitor/settings dibuka — sehingga sistem "self-healing":
 * begitu ada request masuk setelah container restart, path akan otomatis
 * dibuat ulang tanpa perlu intervensi manual.
 * ════════════════════════════════════════════════════════════════════════
 */
class MediaMtxPathService
{
    private string $apiBase;

    public function __construct()
    {
        $this->apiBase = rtrim(config('cctv.mediamtx_api', 'http://localhost:9997'), '/');
    }

    /**
     * Cek apakah path dengan nama tertentu sudah terdaftar di mediamtx
     * (baik dari mediamtx.yml statis maupun hasil provisioning dinamis
     * sebelumnya).
     */
    public function pathExists(string $name): bool
    {
        try {
            $response = Http::timeout(3)->get("{$this->apiBase}/v3/config/paths/get/{$name}");
            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('MediaMtxPathService::pathExists — mediamtx tidak terjangkau.', [
                'path'  => $name,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Pastikan path live-view untuk kamera fisik tersedia di mediamtx.
     * Idempotent — no-op jika path sudah ada (baik statis dari YAML maupun
     * hasil provisioning sebelumnya).
     *
     * @param  string $name       Nama path mediamtx, mis. "live_cam_5"
     * @param  string $sourceRtsp RTSP URL kamera fisik lengkap dengan kredensial
     * @return bool   true jika path tersedia (sudah ada ATAU berhasil dibuat)
     */
    public function ensurePath(string $name, string $sourceRtsp): bool
    {
        if ($this->pathExists($name)) {
            return true;
        }

        try {
            $response = Http::timeout(5)
                ->post("{$this->apiBase}/v3/config/paths/add/{$name}", [
                    'source'             => $sourceRtsp,
                    'sourceOnDemand'     => false,
                    'sourceProtocol'     => 'tcp',
                ]);

            if ($response->successful()) {
                Log::info('MediaMtxPathService::ensurePath — path berhasil di-provision.', [
                    'path' => $name,
                ]);
                return true;
            }

            Log::error('MediaMtxPathService::ensurePath — mediamtx menolak path baru.', [
                'path'   => $name,
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return false;
        } catch (\Throwable $e) {
            Log::error('MediaMtxPathService::ensurePath — gagal provisioning path.', [
                'path'  => $name,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Hapus path dari mediamtx. Dipanggil saat kamera dihapus/di-nonaktifkan
     * DAN dipastikan tidak ada kamera lain yang masih memakai path yang sama
     * (lihat CctvController — cek referensi hls_url sebelum memanggil ini).
     */
    public function removePath(string $name): void
    {
        try {
            $response = Http::timeout(3)->delete("{$this->apiBase}/v3/config/paths/delete/{$name}");

            if ($response->successful() || $response->status() === 404) {
                Log::info('MediaMtxPathService::removePath — path dibersihkan (atau memang sudah tidak ada).', [
                    'path' => $name,
                ]);
                return;
            }

            Log::warning('MediaMtxPathService::removePath — mediamtx menolak penghapusan path.', [
                'path'   => $name,
                'status' => $response->status(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('MediaMtxPathService::removePath — mediamtx tidak terjangkau saat cleanup.', [
                'path'  => $name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Bangun nama path yang deterministik dan unik per kamera, berbasis ID
     * baris CctvCamera (dijamin unik, tidak butuh sanitasi nama bebas-teks
     * dari admin yang bisa mengandung karakter tidak valid untuk nama path).
     */
    public function deriveLivePathName(int $cameraId): string
    {
        return "live_cam_{$cameraId}";
    }

    /**
     * Bangun URL HLS lengkap dari nama path.
     */
    public function buildHlsUrl(string $pathName): string
    {
        $base = rtrim(config('cctv.hls_base_url', 'http://localhost:8888'), '/');
        return "{$base}/{$pathName}/index.m3u8";
    }
}
