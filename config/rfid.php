<?php

/**
 * Konfigurasi RFID. Nilai dibaca saat `config:cache` (jalankan `php artisan optimize`
 * setelah mengubah .env di produksi).
 */
return [
    // Jendela dedupe pembacaan berulang tag yang sama pada arah+tahap yang sama (detik).
    // Respons yang dikirim ulang IDENTIK dengan respons pertama, sehingga LCD tidak berubah.
    'dedupe_seconds' => (int) env('RFID_DEDUPE_SECONDS', 3),

    // TTL sesi registrasi tag (detik) — sama dengan perilaku lama (90 dtk).
    'registration_ttl' => (int) env('RFID_REGISTRATION_TTL', 90),

    // Reader dianggap offline bila tidak ada heartbeat/scan selama N detik.
    'offline_after_seconds' => (int) env('RFID_OFFLINE_AFTER', 120),

    // Selisih jam perangkat vs server yang dianggap berbahaya (detik) — ditandai di status reader.
    'clock_skew_seconds' => (int) env('RFID_CLOCK_SKEW', 300),

    // Tombol/endpoint Simulasi (demo & pelatihan). Matikan di produksi setelah pelatihan agar data uji
    // tidak tercampur data nyata. Data simulasi selalu bertanda rfid_origin=simulation.
    'simulation_enabled' => (bool) env('RFID_SIMULATION_ENABLED', true),

    // Retensi hasil idempotensi event_id (detik).
    'event_ttl' => 86400,

    /*
     * Kompatibilitas perangkat legacy (ESP32 yang sedang berjalan; firmware tidak ada di repo).
     * Perangkat "anonim" = tidak mengirim X-Device-Key maupun X-Company-Code.
     *   legacy_anonymous      : tetap dilayani (default true; K8 — tidak mewajibkan autentikasi)
     *   legacy_global_tag_fallback : untuk perangkat anonim saja, bila tag tidak ada di perusahaan
     *                           default, cari di semua perusahaan (perilaku lama). Perangkat ber-kunci
     *                           atau ber-X-Company-Code TIDAK PERNAH jatuh ke fallback ini.
     *   require_device_key    : bila true, semua perangkat wajib X-Device-Key (target jangka menengah).
     */
    'legacy_anonymous'           => (bool) env('RFID_ALLOW_LEGACY_ANONYMOUS', true),
    'legacy_global_tag_fallback' => (bool) env('RFID_LEGACY_GLOBAL_FALLBACK', true),
    'require_device_key'         => (bool) env('RFID_REQUIRE_DEVICE_KEY', false),

    /*
     * Log pembacaan (rfid_read_logs) + capture CCTV per log.
     *   capture_on_reject : pembacaan yang DITOLAK (mis. tag asing di gate) juga di-capture — bukti visual.
     *   capture_on_register : registrasi tag di Receiving Area di-capture.
     */
    'read_log' => [
        'capture_on_reject'   => (bool) env('RFID_CAPTURE_ON_REJECT', true),
        'capture_on_register' => (bool) env('RFID_CAPTURE_ON_REGISTER', true),
    ],

    /*
     * Reader UHF.
     * Gate (Chainway UR4, TCP) dibaca daemon `php artisan rfid:uhf-gate`:
     *   presence_seconds : tag yang terus terbaca dianggap SATU kehadiran; baru diproses lagi setelah
     *                      tidak terbaca selama N detik (UHF membaca tag puluhan kali per detik).
     *   min_rssi         : abaikan pembacaan lebih lemah dari ini (dBm), mis. -70. null = tanpa filter.
     *   reconnect_max    : jeda maksimum (detik) antar percobaan sambung ulang.
     * Desktop (R2000 USB) dibaca browser lewat Web Serial — baud default di sisi UI.
     */
    'uhf' => [
        'ur4_port'         => (int) env('RFID_UR4_PORT', 8888),
        'presence_seconds' => (int) env('RFID_UHF_PRESENCE_SECONDS', 10),
        'min_rssi'         => is_numeric(env('RFID_UHF_MIN_RSSI')) ? (int) env('RFID_UHF_MIN_RSSI') : null,
        'reconnect_max'    => (int) env('RFID_UHF_RECONNECT_MAX', 30),
        'desktop_baud'     => (int) env('RFID_UHF_DESKTOP_BAUD', 9600),
    ],
];
