<?php

namespace App\Console\Commands;

use App\Models\RfidReader;
use App\Services\Hardware\Uhf\EndpointGuard;
use App\Services\Hardware\Uhf\UhfTagIdentity;
use App\Services\Hardware\Uhf\Ur4ExclusiveLock;
use App\Services\Hardware\Uhf\Ur4Link;
use App\Services\Hardware\Uhf\Ur4LinkConfig;
use App\Services\Hardware\Uhf\Ur4LinkState;
use Illuminate\Console\Command;

/**
 * rfid:uhf-probe — diagnostik satu reader UR4 TANPA mencatat scan dan TANPA menulis konfigurasi.
 *
 * Bawaan: handshake + baca konfigurasi lalu berhenti (reader TIDAK memancar). `--listen=N` menyalakan inventory
 * N detik dan mencetak tag yang terbaca (memancar RF; batas daya `rfid.uhf.max_power_dbm` tetap berlaku).
 * UR4 melayani satu klien: probe menolak berjalan bila daemon/alat lain memegang kunci eksklusif (`--force` untuk
 * mengabaikan; ini akan merebut aliran dari daemon).
 *
 * Kode keluar: 0 sehat · 2 tak terjangkau · 3 handshake/direbut/macet · 4 konfigurasi tidak aman · 5 alamat ditolak/kunci dipegang.
 */
class RfidUhfProbe extends Command
{
    protected $signature = 'rfid:uhf-probe
        {--reader= : kode reader terdaftar}
        {--host= : alamat IPv4 reader (bila tanpa --reader)}
        {--port=8888 : port TCP}
        {--antennas=1:in : pemetaan antena untuk --host, mis. 1:in,2:out}
        {--listen=0 : nyalakan inventory N detik dan cetak tag (memancar RF)}
        {--timeout=15 : batas waktu mencapai siap, detik}
        {--force : abaikan kunci eksklusif (merebut aliran dari daemon)}';

    protected $description = 'Diagnostik reader UR4 (baca-saja): handshake, versi, daya, antena; opsional dengar tag';

    public function handle(): int
    {
        $map = [];
        if ($code = $this->option('reader')) {
            $r = RfidReader::where('code', strtoupper((string) $code))->first();
            if (! $r) {
                $this->error("Reader {$code} tidak ditemukan.");

                return 5;
            }
            $c = $r->connectionConfig();
            $host = trim((string) ($c['host'] ?? ''));
            $port = (int) ($c['port'] ?? config('rfid.uhf.ur4_port', 8888));
            foreach ((array) ($c['antennas'] ?? []) as $a => $d) {
                $map[(int) $a] = $d;
            }
        } else {
            $host = trim((string) $this->option('host'));
            $port = (int) $this->option('port');
            foreach (explode(',', (string) $this->option('antennas')) as $pair) {
                [$a, $d] = array_pad(explode(':', trim($pair), 2), 2, '');
                if ((int) $a > 0 && in_array($d, ['in', 'out'], true)) {
                    $map[(int) $a] = $d;
                }
            }
        }

        if ($err = EndpointGuard::validate($host, $port)) {
            $this->error("Alamat {$host}:{$port} ditolak ({$err}): ".EndpointGuard::message($err));

            return 5;
        }

        $owner = 'probe:'.getmypid();
        $locked = Ur4ExclusiveLock::acquire($host, $port, $owner, 60);
        if (! $locked && ! $this->option('force')) {
            $this->error("{$host}:{$port} dipegang ".(Ur4ExclusiveLock::holder($host, $port) ?? '?').'. Hentikan daemon dulu, atau --force (akan merebut aliran).');

            return 5;
        }

        $listen = max(0, (int) $this->option('listen'));
        $tags = [];
        $cfg = Ur4LinkConfig::fromConfig($map);
        $cfg = new Ur4LinkConfig(...[...get_object_vars($cfg), 'startInventory' => $listen > 0]);

        $t = fn () => microtime(true);
        $link = new Ur4Link($host, $port, $cfg, Ur4Link::tcpConnector(), $t,
            function (array $tag) use (&$tags) {
                $tags[$tag['epc']] = ($tags[$tag['epc']] ?? ['n' => 0]) + $tag;
                $tags[$tag['epc']]['n']++;
            },
            log: fn (string $lvl, string $m) => $this->line("  · {$m}"),
        );

        $this->info("Probe {$host}:{$port}".($listen ? " (dengar {$listen} dtk, memancar RF)" : ' (baca-saja, tidak memancar)'));

        $deadline = $t() + max(3, (int) $this->option('timeout'));
        $listenUntil = null;
        $exit = 3;
        try {
            while (true) {
                $link->tick();
                $read = array_filter([$link->wantsRead()]);
                $write = array_filter([$link->wantsWrite()]);
                if ($read || $write) {
                    $except = null;
                    if (@stream_select($read, $write, $except, 0, 100_000)) {
                        $write && $link->onWritable();
                        $read && $link->onReadable();
                    }
                } else {
                    usleep(50_000);
                }

                $state = $link->state();
                if ($state === Ur4LinkState::ConfigMismatch) {
                    $exit = 4;
                    break;
                }
                if ($state === Ur4LinkState::Contested) {
                    $exit = 3;
                    break;
                }
                if ($listen === 0 && $state === Ur4LinkState::Ready) {
                    $exit = 0;
                    break;
                }
                if ($listen > 0 && $state === Ur4LinkState::Streaming) {
                    $listenUntil ??= $t() + $listen;
                    if ($t() >= $listenUntil) {
                        $exit = 0;
                        break;
                    }
                } elseif ($t() >= $deadline) {
                    $exit = in_array($link->status()['last_error'], ['connect_failed', 'connect_timeout', 'connect_refused'], true) ? 2 : 3;
                    break;
                }
            }
        } finally {
            $status = $link->status();
            $link->stop();
            if ($locked) {
                Ur4ExclusiveLock::release($host, $port, $owner);
            }
        }

        $this->report($status, $tags, $exit);

        return $exit;
    }

    /** @param array<string,array> $tags */
    private function report(array $s, array $tags, int $exit): void
    {
        $this->newLine();
        $this->line('Status tautan : '.$s['link_state'].($s['last_error'] ? " ({$s['last_error']})" : ''));
        if ($s['detail']) {
            $this->line('Rincian       : '.$s['detail']);
        }
        foreach ((array) $s['device'] as $k => $v) {
            $this->line(sprintf('  %-12s %s', $k, is_scalar($v) ? $v : json_encode($v)));
        }
        foreach ($s['warnings'] as $w) {
            $this->warn("  ! {$w}");
        }
        if ($tags) {
            $this->line('Tag terbaca  :');
            foreach ($tags as $epc => $t) {
                $this->line(sprintf('  %s  ant:%s rssi:%s  x%d%s', UhfTagIdentity::canonical($epc) ?? $epc, $t['antenna'], $t['rssi'] ?? '-', $t['n'], ! empty($t['tid']) ? "  tid:{$t['tid']}" : ''));
            }
        }
        $this->line($exit === 0 ? 'HASIL: OK' : "HASIL: GAGAL (kode {$exit})");
    }
}
