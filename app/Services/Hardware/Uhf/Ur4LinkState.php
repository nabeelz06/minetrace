<?php

namespace App\Services\Hardware\Uhf;

/**
 * Status mesin tautan satu reader UR4.
 *
 *   Backoff        menunggu waktu sambung ulang (setelah putus/gagal)
 *   Connecting     soket dibuka (async), menunggu siap tulis
 *   Handshake      kirim STOP berulang sampai ack 0x8D, lalu hening singkat
 *   Configuring    membaca konfigurasi reader (versi, daya, region, mask, mode) satu per satu
 *   Streaming      inventory berjalan; tag diteruskan
 *   Contested      klien lain merebut satu-satunya slot reader; jeda panjang agar tidak saling rebut
 *   Stalled        reader berhenti sendiri berulang kali
 *   ConfigMismatch daya melebihi batas, mode inventory tak didukung, atau tak ada antena aktif; inventory TIDAK dimulai
 */
enum Ur4LinkState: string
{
    case Backoff = 'backoff';
    case Connecting = 'connecting';
    case Handshake = 'handshake';
    case Configuring = 'configuring';
    case Streaming = 'streaming';
    case Contested = 'contested';
    case Stalled = 'stalled';
    case ConfigMismatch = 'config_mismatch';

    /** Menunggu waktu coba berikutnya (tidak ada soket). */
    public function isWaiting(): bool
    {
        return match ($this) {
            self::Backoff, self::Contested, self::Stalled, self::ConfigMismatch => true,
            default => false,
        };
    }
}
