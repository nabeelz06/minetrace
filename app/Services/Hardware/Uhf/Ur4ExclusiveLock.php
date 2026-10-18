<?php

namespace App\Services\Hardware\Uhf;

use Illuminate\Support\Facades\Cache;

/**
 * Kunci eksklusif per endpoint UR4 (host:port).
 *
 * Server TCP UR4 melayani SATU klien: koneksi kedua merebut aliran dan soket pertama diam tanpa FIN.
 * Daemon memegang kunci ini selama tautan hidup (TTL pendek, diperpanjang berkala); alat kooperatif
 * (rfid:uhf-probe, hardware:check) memeriksanya sebelum membuka TCP. Kunci tidak bisa memblokir klien
 * pihak ketiga (UHFAPP.exe, nc) — itulah gunanya deteksi "contested" di Ur4Link.
 */
final class Ur4ExclusiveLock
{
    public static function key(string $host, int $port): string
    {
        return "rfid:ur4:lock:{$host}:{$port}";
    }

    /** True bila berhasil memegang kunci (atau sudah dipegang pemilik yang sama). */
    public static function acquire(string $host, int $port, string $owner, int $ttl): bool
    {
        $key = self::key($host, $port);
        if (Cache::add($key, $owner, $ttl)) {
            return true;
        }
        if (Cache::get($key) === $owner) {
            Cache::put($key, $owner, $ttl);

            return true;
        }

        return false;
    }

    public static function refresh(string $host, int $port, string $owner, int $ttl): bool
    {
        $key = self::key($host, $port);
        if (Cache::get($key) !== $owner) {
            return false;
        }
        Cache::put($key, $owner, $ttl);

        return true;
    }

    public static function release(string $host, int $port, string $owner): void
    {
        $key = self::key($host, $port);
        if (Cache::get($key) === $owner) {
            Cache::forget($key);
        }
    }

    /** Pemilik kunci saat ini (null bila bebas). */
    public static function holder(string $host, int $port): ?string
    {
        $v = Cache::get(self::key($host, $port));

        return is_string($v) ? $v : null;
    }
}
