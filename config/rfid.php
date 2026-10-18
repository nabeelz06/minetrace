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

        // ─── Keamanan alamat reader (EndpointGuard) ───────────────────────────
        // Hanya IPv4 literal. Loopback hanya di local/testing atau bila allow_loopback=true (server UR4 tiruan).
        'allow_loopback'   => (bool) env('RFID_UHF_ALLOW_LOOPBACK', false),
        'allowed_cidrs'    => array_values(array_filter(array_map('trim', explode(',', (string) env('RFID_UHF_ALLOWED_CIDRS', '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16'))))),
        'allowed_ports'    => array_values(array_filter(array_map('intval', explode(',', (string) env('RFID_UHF_ALLOWED_PORTS', '8888'))))),

        // ─── Protokol & tautan UR4 ────────────────────────────────────────────
        'verify_xor'           => (bool) env('RFID_UHF_VERIFY_XOR', true),
        'handshake_timeout'    => (float) env('RFID_UHF_HANDSHAKE_TIMEOUT', 2.0),
        // Jeda sebelum sambung ulang. Sumber protokol mencatat koneksi mati-sejak-lahir 1/20 tanpa jeda,
        // 3/20 setelah 1 dtk, 0/20 setelah 2 dtk (sampel kecil, satu unit): ukur di unit sendiri (rfid:uhf-probe --cycles).
        'reconnect_pause'      => (float) env('RFID_UHF_RECONNECT_PAUSE', 2.0),
        'idle_heartbeat_after' => (float) env('RFID_UHF_IDLE_HEARTBEAT_AFTER', 5.0),
        'heartbeat_interval'   => (float) env('RFID_UHF_HEARTBEAT_INTERVAL', 3.0),
        'rearm_per_minute'     => (int) env('RFID_UHF_REARM_PER_MINUTE', 3),
        'contested_resets'     => (int) env('RFID_UHF_CONTESTED_RESETS', 2),
        'contested_window'     => (int) env('RFID_UHF_CONTESTED_WINDOW', 120),
        'contested_backoff'    => (int) env('RFID_UHF_CONTESTED_BACKOFF', 60),
        'lock_ttl'             => (int) env('RFID_UHF_LOCK_TTL', 30),
        'arbitration_ms'       => (int) env('RFID_UHF_ARBITRATION_MS', 1200),

        // ─── Keselamatan RF ──────────────────────────────────────────────────
        // max_power_dbm terisi: bila daya yang TERBACA dari reader (terbesar antara baca dan tulis, pada antena
        // aktif) melebihi batas, tautan masuk config_mismatch dan inventory TIDAK dimulai. Meja uji: 15.
        'max_power_dbm'    => is_numeric(env('RFID_UHF_MAX_POWER_DBM')) ? (float) env('RFID_UHF_MAX_POWER_DBM') : null,
        'warn_power_dbm'   => (float) env('RFID_UHF_WARN_POWER_DBM', 26),
    ],
];
