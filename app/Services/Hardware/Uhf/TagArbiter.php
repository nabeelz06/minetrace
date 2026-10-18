<?php

namespace App\Services\Hardware\Uhf;

/**
 * Arbitrasi antena IN/OUT untuk gate berantena ganda.
 *
 * Tag yang berada di zona tumpang tindih terbaca oleh antena IN dan OUT hampir bersamaan; tanpa arbitrasi satu
 * tag menghasilkan DUA peristiwa (masuk dan keluar). Pembacaan satu EPC dikumpulkan selama jendela pendek lalu
 * diputuskan: antena dengan RSSI rata-rata tertinggi menang (seri: yang pertama terlihat); keluar SATU peristiwa.
 * Reader satu antena atau satu arah tidak memakai kelas ini (tanpa latensi tambahan).
 */
final class TagArbiter
{
    /** @var array<string,array{first:float,tid:?string,ants:array<int,array{sum:float,n:int,first:float}>}> */
    private array $pending = [];

    public function __construct(private readonly float $windowSeconds) {}

    public function add(string $epc, int $antenna, ?int $rssi, ?string $tid, float $now): void
    {
        $entry = $this->pending[$epc] ??= ['first' => $now, 'tid' => $tid, 'ants' => []];
        $a = $entry['ants'][$antenna] ?? ['sum' => 0.0, 'n' => 0, 'first' => $now];
        $a['sum'] += $rssi ?? -120;
        $a['n']++;
        $entry['ants'][$antenna] = $a;
        $entry['tid'] ??= $tid;
        $this->pending[$epc] = $entry;
    }

    /**
     * Keputusan untuk EPC yang jendelanya sudah habis.
     *
     * @return list<array{epc:string,antenna:int,rssi:int,tid:?string}>
     */
    public function flush(float $now): array
    {
        $out = [];
        foreach ($this->pending as $epc => $entry) {
            if ($now - $entry['first'] < $this->windowSeconds) {
                continue;
            }
            $best = null;
            foreach ($entry['ants'] as $ant => $a) {
                $avg = $a['sum'] / $a['n'];
                if ($best === null || $avg > $best['avg'] || ($avg === $best['avg'] && $a['first'] < $best['first'])) {
                    $best = ['ant' => $ant, 'avg' => $avg, 'first' => $a['first']];
                }
            }
            $out[] = ['epc' => (string) $epc, 'antenna' => $best['ant'], 'rssi' => (int) round($best['avg']), 'tid' => $entry['tid']];
            unset($this->pending[$epc]);
        }

        return $out;
    }

    public function pendingCount(): int
    {
        return count($this->pending);
    }
}
