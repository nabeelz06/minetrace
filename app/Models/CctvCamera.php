<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CctvCamera extends Model
{
    protected $fillable = [
        'company_id', 'name', 'location_key',
        'rtsp_url', 'rtsp_url_real', 'rtsp_url_simulation',
        'hls_url', 'is_active', 'is_simulation',
    ];

    protected $casts = [
        'is_active'    => 'boolean',
        'is_simulation' => 'boolean',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    // ─── Static helpers ───────────────────────────────────────────────────────

    /**
     * [Strategy Pattern] Resolve kamera untuk stage tertentu.
     * Priority: stage-specific camera → first active camera → null (env fallback).
     */
    public static function getDefaultForStage(int $companyId, ?string $stage): ?self
    {
        if ($stage) {
            $camera = static::where('company_id', $companyId)
                ->where('location_key', $stage)
                ->active()
                ->orderBy('id')
                ->first();

            if ($camera) return $camera;
        }

        return static::where('company_id', $companyId)
            ->active()
            ->orderBy('id')
            ->first();
    }

    // ─── Accessors ────────────────────────────────────────────────────────────

    /**
     * Apakah kamera INI (bukan flag global) berjalan dalam mode simulasi.
     *
     * PENTING — sumber kebenaran adalah kolom is_simulation PER-KAMERA, bukan
     * env('CCTV_USE_REAL') global. Alasan: armada kamera bisa CAMPURAN —
     * mis. Gate 1 sudah pakai kamera fisik TP-Link asli sementara Wet
     * Process dan Warehouse masih simulasi karena hardware belum terpasang.
     * Satu toggle global tidak bisa merepresentasikan kondisi ini dengan
     * benar; kolom is_simulation memang sudah ada di skema sejak awal untuk
     * tujuan ini, namun sebelumnya tidak pernah benar-benar dibaca oleh
     * accessor manapun (dead column) — root cause laporan "kamera yang
     * ditampilkan tidak sesuai dengan CCTV yang saya miliki" (lihat
     * changelog Bagian 12).
     *
     * env('CCTV_USE_REAL') tetap dipertahankan sebagai DEFAULT saat seeding
     * kamera baru (lihat PtTimahCctvCameraSeeder), bukan sebagai otoritas
     * runtime untuk kamera yang sudah tersimpan di DB.
     */
    public function isSimulationCamera(): bool
    {
        return (bool) $this->is_simulation;
    }

    public function getEffectiveRtspUrlAttribute(): string
    {
        if ($this->isSimulationCamera()) {
            // Prefer explicit simulation column, then derive from stream map
            if ($this->rtsp_url_simulation) {
                return $this->rtsp_url_simulation;
            }
            if ($this->location_key) {
                $base   = rtrim(env('CCTV_MEDIAMTX_RTSP', 'rtsp://127.0.0.1:8554'), '/');
                $stream = $this->simulationStreamName();
                return "{$base}/{$stream}";
            }
        }

        // Mode real: prefer kolom real eksplisit, lalu rtsp_url legacy, lalu env fallback
        return $this->rtsp_url_real ?: $this->rtsp_url ?: config('cctv.rtsp_url', '');
    }

    public function getCaptureMode(): string
    {
        return $this->isSimulationCamera() ? 'simulation' : 'real';
    }

    private function simulationStreamName(): string
    {
        $map = [
            'receiving'     => 'cam_gate1',
            'wet_process'   => 'cam_wet',
            'dry_process'   => 'cam_wet',
            'warehouse'     => 'cam_warehouse',
            'project_plant' => 'cam_warehouse',
        ];
        return $map[$this->location_key] ?? 'cam_gate1';
    }

    /**
     * Untuk kamera REAL, hls_url adalah HASIL PROVISIONING yang sudah
     * dihitung dan disimpan saat kamera dibuat/diedit — lihat
     * CctvController::store()/update() dan MediaMtxPathService. Backend
     * secara otomatis:
     *   1. Mengecek apakah kamera lain (di company yang sama) sudah punya
     *      rtsp_url_real yang IDENTIK — jika ya, hls_url-nya DIPAKAI ULANG
     *      (beberapa stage/checkpoint boleh berbagi satu kamera fisik yang
     *      sama tanpa membuka koneksi RTSP duplikat ke perangkat).
     *   2. Jika benar-benar kamera baru, provisioning path mediamtx baru
     *      via MediaMtxPathService::ensurePath(), lalu simpan hasil HLS
     *      URL-nya ke kolom ini.
     *
     * Accessor ini SENGAJA tidak lagi menurunkan nama path secara otomatis
     * dari location_key (pendekatan lama, private liveStreamName()) —
     * pendekatan itu mengasumsikan satu kamera fisik per stage, padahal
     * armada bisa lebih sedikit dari jumlah stage (lihat changelog Bagian 12
     * — kondisi saat ini: 1 kamera fisik melayani 5 checkpoint).
     */
    public function getEffectiveHlsUrlAttribute(): string
    {
        if ($this->isSimulationCamera()) {
            return $this->hls_url ?: $this->buildSimulationHlsUrl();
        }

        return $this->hls_url ?: '';
    }

    private function buildSimulationHlsUrl(): string
    {
        $base   = rtrim(config('cctv.hls_base_url', 'http://localhost:8888'), '/');
        $stream = $this->simulationStreamName();
        return "{$base}/{$stream}/index.m3u8";
    }
}
