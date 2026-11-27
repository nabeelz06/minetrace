<?php

namespace App\Services\Hardware;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Antrean UID baru yang terbaca reader gabungan Receiving & Wet.
 *
 * Reader tidak bisa membedakan tag kosong untuk timah basah yang baru datang dari tag kosong untuk container hasil
 * Wet: keduanya ditimbang di timbangan yang sama. UID baru karena itu tidak langsung diproses; operator memilih di PC
 * (NewTagChoiceService). Entri hilang sendiri bila tidak dipilih dalam TTL_MINUTES, sehingga tag yang hanya lewat
 * tidak menumpuk.
 */
class NewTagQueue
{
    public const TTL_MINUTES = 10;

    private const MAX_ENTRIES = 30;

    /** Tambah atau perbarui UID (pembacaan berulang hanya memperbarui waktu terakhir terbaca). */
    public function add(int $companyId, string $uid, ?int $readerId = null, ?int $rssi = null, bool $recycled = false): void
    {
        $list = $this->fresh($companyId);
        $now = now()->toIso8601String();

        $list[$uid] = [
            'uid'        => $uid,
            'first_seen' => $list[$uid]['first_seen'] ?? $now,
            'last_seen'  => $now,
            'reads'      => ($list[$uid]['reads'] ?? 0) + 1,
            'reader_id'  => $readerId ?? ($list[$uid]['reader_id'] ?? null),
            'rssi'       => $rssi ?? ($list[$uid]['rssi'] ?? null),
            // Tag bekas: operator boleh memilih memakainya ulang (RfidTagService::markFresh dijalankan saat memilih).
            'recycled'   => $recycled,
        ];

        uasort($list, fn ($a, $b) => strcmp($b['last_seen'], $a['last_seen']));
        $this->save($companyId, array_slice($list, 0, self::MAX_ENTRIES, true));
    }

    /** @return list<array{uid:string,first_seen:string,last_seen:string,reads:int,reader_id:?int,rssi:?int,recycled?:bool}> */
    public function list(int $companyId): array
    {
        return array_values($this->fresh($companyId));
    }

    public function has(int $companyId, string $uid): bool
    {
        return isset($this->fresh($companyId)[$uid]);
    }

    public function remove(int $companyId, string $uid): void
    {
        $list = $this->fresh($companyId);
        unset($list[$uid]);
        $this->save($companyId, $list);
    }

    /** Entri yang belum kedaluwarsa (berdasarkan pembacaan terakhir). */
    private function fresh(int $companyId): array
    {
        $cutoff = now()->subMinutes(self::TTL_MINUTES);

        return array_filter(
            Cache::get($this->key($companyId), []),
            fn ($e) => Carbon::parse($e['last_seen'])->greaterThan($cutoff)
        );
    }

    private function save(int $companyId, array $list): void
    {
        $list
            ? Cache::put($this->key($companyId), $list, now()->addMinutes(self::TTL_MINUTES))
            : Cache::forget($this->key($companyId));
    }

    private function key(int $companyId): string
    {
        return "rfid:new-tags:{$companyId}";
    }
}
