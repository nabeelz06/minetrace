<?php

namespace App\Services\Hardware\Uhf;

/**
 * Reader UHF membaca tag yang sama puluhan kali per detik selama tag berada di medan antena.
 * Filter ini mengubah aliran itu menjadi SATU peristiwa per kehadiran: kunci baru diproses lagi
 * hanya setelah tidak terbaca selama $windowSeconds (jendela bergeser — tiap pembacaan memperpanjang).
 */
final class UhfPresenceFilter
{
    /** @var array<string,float> kunci → waktu terakhir terbaca */
    private array $lastSeen = [];

    public function __construct(private readonly float $windowSeconds) {}

    public function shouldProcess(string $key, ?float $now = null): bool
    {
        $now ??= microtime(true);
        $prev = $this->lastSeen[$key] ?? null;
        $this->lastSeen[$key] = $now;

        return $prev === null || ($now - $prev) >= $this->windowSeconds;
    }

    /** Buang kunci yang sudah lama tidak terbaca (jaga memori daemon). */
    public function prune(?float $now = null): void
    {
        $now ??= microtime(true);
        foreach ($this->lastSeen as $key => $at) {
            if ($now - $at >= $this->windowSeconds) {
                unset($this->lastSeen[$key]);
            }
        }
    }

    public function count(): int
    {
        return count($this->lastSeen);
    }
}
