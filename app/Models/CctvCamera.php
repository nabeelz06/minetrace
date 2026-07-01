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
     * Nama path mediamtx yang mem-proxy kamera FISIK (live view HLS) untuk
     * stage tertentu. Hanya terisi untuk stage yang benar-benar sudah punya
     * kamera fisik terpasang dan dikonfigurasi di mediamtx.yml — BUKAN
     * derivasi otomatis dari simulationStreamName(), karena path simulasi
     * dan path live-view kamera asli sengaja dipisah agar tidak saling
     * bentrok (lihat catatan di docker/mediamtx/mediamtx.yml).
     *
     * null berarti belum ada proxy live-view untuk stage ini — capture
     * snapshot tetap bisa jalan (langsung ke rtsp_url_real, bypass
     * mediamtx), tapi live HLS view di CctvMonitor.vue tidak tersedia
     * sampai kamera fisiknya benar-benar dipasang dan path-nya ditambahkan.
     */
    private function liveStreamName(): ?string
    {
        $map = [
            'receiving' => 'live_gate1',
        ];
        return $map[$this->location_key] ?? null;
    }

    public function getEffectiveHlsUrlAttribute(): string
    {
        if ($this->isSimulationCamera()) {
            return $this->hls_url ?: $this->buildSimulationHlsUrl();
        }

        $liveStream = $this->liveStreamName();
        if ($liveStream) {
            $base = rtrim(config('cctv.hls_base_url', 'http://localhost:8888'), '/');
            return "{$base}/{$liveStream}/index.m3u8";
        }

        // Tidak ada proxy live-view mediamtx untuk kamera real ini —
        // kembalikan hls_url manual jika di-set eksplisit, jika tidak
        // kosongkan (frontend menampilkan "live view belum tersedia").
        return $this->hls_url ?: '';
    }

    private function buildSimulationHlsUrl(): string
    {
        $base   = rtrim(config('cctv.hls_base_url', 'http://localhost:8888'), '/');
        $stream = $this->simulationStreamName();
        return "{$base}/{$stream}/index.m3u8";
    }
}
