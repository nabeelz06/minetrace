<?php

namespace App\Services\Hardware\Uhf;

/**
 * Reader UHF membaca tag yang sama puluhan kali per detik selama tag berada di medan antena.
 * Filter mengubah aliran itu menjadi SATU peristiwa per kehadiran: kunci baru diproses lagi hanya setelah
 * tidak terbaca selama jendela waktu (jendela bergeser: tiap pembacaan memperpanjang).
 */
interface PresenceFilter
{
    public function shouldProcess(string $key, ?float $now = null): bool;

    /** Buang kunci yang sudah lama tidak terbaca (jaga memori). */
    public function prune(?float $now = null): void;

    public function count(): int;
}
