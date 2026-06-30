<?php

return [
    /*
     * CATATAN ARSITEKTUR: CCTV_USE_REAL dibaca via env() langsung di runtime
     * (CctvCamera model, CctvController) — BUKAN via config('cctv.use_real') —
     * untuk menghindari config:cache staleness, identik dengan pola RFID_API_TOKEN.
     * Key ini tetap didefinisikan di sini sebagai dokumentasi dan default reference.
     */
    'use_real'       => (bool) env('CCTV_USE_REAL', false),

    'rtsp_url'       => env('CCTV_RTSP_URL', ''),
    'hls_base_url'   => env('CCTV_HLS_BASE_URL', 'http://localhost:8888'),
    'mediamtx_rtsp'  => env('CCTV_MEDIAMTX_RTSP', 'rtsp://127.0.0.1:8554'),
    'mediamtx_api'   => env('CCTV_MEDIAMTX_API', 'http://localhost:9997'),
    'snapshot_dir'   => env('CCTV_SNAPSHOT_DIR', 'public/cctv-snapshots'),
    'ffmpeg_timeout' => (int) env('CCTV_FFMPEG_TIMEOUT', 30),
    'ffmpeg_quality' => 2,
    'ffmpeg_scale'   => '1280:720',
    'snapshot_delay' => 2,
    'queue'          => env('CCTV_QUEUE', 'default'),
];
