<?php

return [
    'rtsp_url'       => env('CCTV_RTSP_URL'),
    'hls_base_url'   => env('CCTV_HLS_BASE_URL', 'http://localhost:8888'),
    'mediamtx_rtsp'  => env('CCTV_MEDIAMTX_RTSP', 'rtsp://localhost:8554'),
    'mediamtx_api'   => env('CCTV_MEDIAMTX_API', 'http://localhost:9997'),
    'snapshot_dir'   => env('CCTV_SNAPSHOT_DIR', 'public/cctv-snapshots'),
    'ffmpeg_timeout' => env('CCTV_FFMPEG_TIMEOUT', 30),
    'ffmpeg_quality' => 2,
    'ffmpeg_scale'   => '1280:720',
    'snapshot_delay' => 2,
    'queue'          => env('CCTV_QUEUE', 'default'),
];
