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

    public function getEffectiveRtspUrlAttribute(): string
    {
        $useReal = env('CCTV_USE_REAL', false);

        if (!$useReal) {
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

        // Real mode: prefer explicit real column, then legacy rtsp_url, then env fallback
        return $this->rtsp_url_real ?: $this->rtsp_url ?: config('cctv.rtsp_url', '');
    }

    public function getCaptureMode(): string
    {
        return env('CCTV_USE_REAL', false) ? 'real' : 'simulation';
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

    public function getEffectiveHlsUrlAttribute(): string
    {
        $simulationMode = !env('CCTV_USE_REAL', false) && $this->location_key;

        if ($this->hls_url) {
            return $simulationMode ? $this->buildSimulationHlsUrl() : $this->hls_url;
        }

        if ($simulationMode) {
            return $this->buildSimulationHlsUrl();
        }

        $base = rtrim(config('cctv.hls_base_url', ''), '/');
        return $base ? $base . '/stream.m3u8' : '';
    }

    private function buildSimulationHlsUrl(): string
    {
        $base   = rtrim(config('cctv.hls_base_url', 'http://localhost:8888'), '/');
        $stream = $this->simulationStreamName();
        return "{$base}/{$stream}/index.m3u8";
    }
}
