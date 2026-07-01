<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CctvCamera extends Model
{
    protected $fillable = [
        'company_id', 'name', 'location_key',
        'rtsp_url', 'hls_url', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
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
        // Simulation mode override: gunakan local mediamtx stream
        if (!env('CCTV_USE_REAL', false) && $this->location_key) {
            $base = rtrim(env('CCTV_MEDIAMTX_RTSP', 'rtsp://127.0.0.1:8554'), '/');
            $streamMap = [
                'receiving'     => 'cam_gate1',
                'wet_process'   => 'cam_wet',
                'dry_process'   => 'cam_wet',
                'warehouse'     => 'cam_warehouse',
                'project_plant' => 'cam_warehouse',
            ];
            $stream = $streamMap[$this->location_key] ?? 'cam_gate1';
            return "{$base}/{$stream}";
        }

        return $this->rtsp_url ?: config('cctv.rtsp_url', '');
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
        $base = rtrim(config('cctv.hls_base_url', 'http://localhost:8888'), '/');
        $streamMap = [
            'receiving'     => 'cam_gate1',
            'wet_process'   => 'cam_wet',
            'dry_process'   => 'cam_wet',
            'warehouse'     => 'cam_warehouse',
            'project_plant' => 'cam_warehouse',
        ];
        $stream = $streamMap[$this->location_key] ?? 'cam_gate1';
        return "{$base}/{$stream}/index.m3u8";
    }
}
