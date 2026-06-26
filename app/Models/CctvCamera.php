<?php

namespace App\Models;

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

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function getEffectiveRtspUrlAttribute(): string
    {
        return $this->rtsp_url ?: config('cctv.rtsp_url', '');
    }

    public function getEffectiveHlsUrlAttribute(): string
    {
        if ($this->hls_url) {
            return $this->hls_url;
        }
        $base = rtrim(config('cctv.hls_base_url', ''), '/');
        return $base ? $base . '/stream.m3u8' : '';
    }
}
