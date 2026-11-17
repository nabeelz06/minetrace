<?php

namespace App\Services\Hardware\Uhf;

use Illuminate\Support\Facades\Cache;

/**
 * Filter kehadiran yang bertahan lintas restart daemon (Redis/cache): tag yang MASIH berada di medan antena saat
 * daemon dihidupkan ulang tidak diproses ulang sebagai peristiwa baru. Penulisan perpanjangan dibatasi 1 kali
 * per detik per kunci supaya aliran ±100 pembacaan/dtk tidak membanjiri Redis.
 */
final class CachePresenceFilter implements PresenceFilter
{
    public const PREFIX = 'rfid:presence:';

    /** @var array<string,float> kunci lokal => terakhir terbaca (untuk prune/count dan pembatas tulis) */
    private array $seen = [];

    /** @var array<string,float> */
    private array $extended = [];

    public function __construct(private readonly float $windowSeconds, private readonly string $prefix = self::PREFIX) {}

    /** Lupakan kehadiran satu kunci (reader:EPC) — pembacaan berikutnya diproses seperti pertama kali (reset/lepas tag). */
    public static function forget(string $key, string $prefix = self::PREFIX): void
    {
        Cache::forget($prefix.$key);
    }

    public function shouldProcess(string $key, ?float $now = null): bool
    {
        $now ??= microtime(true);
        $cacheKey = $this->prefix.$key;
        $ttl = max(1, (int) ceil($this->windowSeconds));
        $this->seen[$key] = $now;

        if (Cache::add($cacheKey, $now, $ttl)) {
            $this->extended[$key] = $now;

            return true;
        }

        // Masih hadir: geser jendela (paling sering 1x/dtk).
        if ($now - ($this->extended[$key] ?? 0.0) >= 1.0) {
            Cache::put($cacheKey, $now, $ttl);
            $this->extended[$key] = $now;
        }

        return false;
    }

    public function prune(?float $now = null): void
    {
        $now ??= microtime(true);
        foreach ($this->seen as $key => $at) {
            if ($now - $at >= $this->windowSeconds) {
                unset($this->seen[$key], $this->extended[$key]);
            }
        }
    }

    public function count(): int
    {
        return count($this->seen);
    }
}
