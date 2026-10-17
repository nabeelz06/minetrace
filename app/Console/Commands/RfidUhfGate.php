<?php

namespace App\Console\Commands;

use App\Models\RfidReader;
use App\Services\Hardware\DeviceContext;
use App\Services\Hardware\HardwareEventService;
use App\Services\Hardware\RfidDeviceService;
use App\Services\Hardware\RfidScanService;
use App\Services\Hardware\Uhf\ChainwayUr4Protocol;
use App\Services\Hardware\Uhf\UhfPresenceFilter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * rfid:uhf-gate — daemon pembaca gate UHF Chainway UR4 (TCP) untuk Wet Process, Wet→Dry, dan Gudang.
 *
 * Satu proses melayani SEMUA reader ber-jenis `uhf_chainway_ur4` yang aktif (atau yang dipilih dengan
 * --reader). Untuk tiap reader: sambung ke connection.host:port, kirim perintah mulai inventory,
 * urai laporan tag, saring kehadiran (satu peristiwa per tag per kehadiran), lalu proses lewat
 * RfidScanService — jalur yang sama dengan ESP32/UI, sehingga:
 *   - checkpoint, mode otorisasi per tahap, dedupe, isolasi tenant tetap berlaku;
 *   - setiap pembacaan tercatat di rfid_read_logs dan memicu capture CCTV (Tapo) tahap reader.
 *
 * Arah (IN/OUT) per pembacaan: connection.antennas {"1":"in","2":"out"} → arah tetap reader → IN.
 * Sambung ulang otomatis dengan jeda bertahap; daftar reader dimuat ulang tiap 60 detik sehingga
 * reader baru/diubah dari panel web terpakai tanpa restart.
 *
 *   php artisan rfid:uhf-gate                       # semua reader UR4 aktif
 *   php artisan rfid:uhf-gate --reader=WET-GATE-01  # reader tertentu (boleh diulang)
 *
 * Jalankan di mesin yang SATU JARINGAN dengan reader (supervisor/systemd/docker, restart: always).
 */
class RfidUhfGate extends Command
{
    protected $signature = 'rfid:uhf-gate
        {--reader=* : kode reader (default: semua reader uhf_chainway_ur4 aktif)}
        {--max-runtime=0 : berhenti setelah N detik (0 = tanpa batas; untuk supervisor/uji)}';

    protected $description = 'Daemon gate UHF Chainway UR4: baca tag via TCP lalu catat scan + log + capture CCTV';

    private const SELECT_TIMEOUT_US = 500_000;
    private const RELOAD_EVERY      = 60;
    private const HEARTBEAT_EVERY   = 20;
    private const CONNECT_TIMEOUT   = 3;

    private bool $running = true;

    /** @var array<int,array{reader:RfidReader,socket:resource|null,proto:ChainwayUr4Protocol,presence:UhfPresenceFilter,next_try:float,backoff:int,reads:int,events:int,connected_at:?float}> */
    private array $links = [];

    public function handle(RfidScanService $scans, RfidDeviceService $devices, HardwareEventService $events): int
    {
        $this->trapSignals();

        $maxRuntime = (int) $this->option('max-runtime');
        $startedAt  = microtime(true);
        $lastReload = 0.0;
        $lastBeat   = 0.0;

        $this->info('rfid:uhf-gate mulai — Ctrl+C untuk berhenti');

        while ($this->running) {
            $now = microtime(true);

            if ($now - $lastReload >= self::RELOAD_EVERY) {
                $this->reloadReaders();
                $lastReload = $now;
                if (! $this->links) {
                    $this->warn('Tidak ada reader uhf_chainway_ur4 aktif dengan connection.host. Menunggu…');
                }
            }

            foreach ($this->links as $id => $link) {
                if (! $link['socket'] && $now >= $link['next_try']) {
                    $this->connect($id);
                }
            }

            $this->pump($scans, $events);

            if ($now - $lastBeat >= self::HEARTBEAT_EVERY) {
                $this->heartbeat($devices);
                $lastBeat = $now;
            }

            if ($maxRuntime > 0 && $now - $startedAt >= $maxRuntime) {
                break;
            }
            if (! $this->links) {
                usleep(self::SELECT_TIMEOUT_US);
            }
        }

        foreach (array_keys($this->links) as $id) {
            $this->disconnect($id, 'berhenti', reconnect: false);
        }
        $this->info('rfid:uhf-gate berhenti.');

        return self::SUCCESS;
    }

    // ─── Reader & koneksi ─────────────────────────────────────────────────

    private function reloadReaders(): void
    {
        $codes = array_map('strtoupper', (array) $this->option('reader'));

        $readers = RfidReader::whereIn('device_type', RfidReader::NETWORK_TYPES)
            ->where('is_active', true)
            ->when($codes, fn ($q) => $q->whereIn('code', $codes))
            ->get()
            ->filter(fn (RfidReader $r) => ! empty($r->connection['host']))
            ->keyBy('id');

        // Reader dihapus/dinonaktifkan/berubah alamat → putus.
        foreach ($this->links as $id => $link) {
            $fresh = $readers->get($id);
            if (! $fresh || $this->endpoint($fresh) !== $this->endpoint($link['reader'])) {
                $this->disconnect($id, $fresh ? 'alamat berubah' : 'reader nonaktif', reconnect: false);
                unset($this->links[$id]);
            } else {
                $this->links[$id]['reader'] = $fresh;   // stage/arah/antena terbaru
            }
        }

        foreach ($readers as $id => $reader) {
            if (! isset($this->links[$id])) {
                $this->links[$id] = [
                    'reader'       => $reader,
                    'socket'       => null,
                    'proto'        => new ChainwayUr4Protocol(),
                    'presence'     => new UhfPresenceFilter($this->presenceSeconds($reader)),
                    'next_try'     => 0.0,
                    'backoff'      => 1,
                    'reads'        => 0,
                    'events'       => 0,
                    'connected_at' => null,
                ];
                $this->line("Reader {$reader->code} ({$this->endpoint($reader)}) — tahap " . ($reader->stage ?? '-'));
            }
        }
    }

    private function connect(int $id): void
    {
        $reader = $this->links[$id]['reader'];
        $target = 'tcp://' . $this->endpoint($reader);

        $socket = @stream_socket_client($target, $errno, $errstr, self::CONNECT_TIMEOUT);
        if (! $socket) {
            $backoff = $this->links[$id]['backoff'];
            $this->links[$id]['next_try'] = microtime(true) + $backoff;
            $this->links[$id]['backoff']  = min($backoff * 2, (int) config('rfid.uhf.reconnect_max', 30));
            $this->warn("Reader {$reader->code}: gagal sambung {$target} ({$errstr}); coba lagi {$backoff} dtk");
            Log::warning("rfid:uhf-gate {$reader->code} gagal sambung {$target}: {$errstr}");

            return;
        }

        stream_set_blocking($socket, false);
        // Stop dulu (reader mungkin masih inventory dari sesi lama), lalu mulai inventory kontinu.
        @fwrite($socket, ChainwayUr4Protocol::STOP_INVENTORY);
        usleep(100_000);
        @fwrite($socket, ChainwayUr4Protocol::START_INVENTORY);

        $this->links[$id]['socket']       = $socket;
        $this->links[$id]['backoff']      = 1;
        $this->links[$id]['proto']        = new ChainwayUr4Protocol();
        $this->links[$id]['connected_at'] = microtime(true);

        $this->info("Reader {$reader->code}: terhubung {$target}, inventory berjalan");
        Log::info("rfid:uhf-gate {$reader->code} terhubung {$target}");
    }

    private function disconnect(int $id, string $why, bool $reconnect = true): void
    {
        $link = $this->links[$id] ?? null;
        if (! $link || ! $link['socket']) {
            return;
        }

        @fwrite($link['socket'], ChainwayUr4Protocol::STOP_INVENTORY);
        @fclose($link['socket']);
        $this->links[$id]['socket']       = null;
        $this->links[$id]['connected_at'] = null;
        $this->links[$id]['next_try']     = $reconnect ? microtime(true) + $link['backoff'] : PHP_FLOAT_MAX;

        $this->warn("Reader {$link['reader']->code}: terputus ({$why})");
        Log::warning("rfid:uhf-gate {$link['reader']->code} terputus: {$why}");
    }

    // ─── Baca & proses ────────────────────────────────────────────────────

    private function pump(RfidScanService $scans, HardwareEventService $events): void
    {
        $read = [];
        foreach ($this->links as $id => $link) {
            if ($link['socket']) {
                $read[$id] = $link['socket'];
            }
        }
        if (! $read) {
            return;
        }

        $write = $except = null;
        $ready = @stream_select($read, $write, $except, 0, self::SELECT_TIMEOUT_US);
        if (! $ready) {
            return;
        }

        foreach ($read as $id => $socket) {
            $chunk = @fread($socket, 8192);
            if ($chunk === false || ($chunk === '' && feof($socket))) {
                $this->disconnect($id, 'koneksi ditutup reader');
                continue;
            }

            foreach ($this->links[$id]['proto']->feed($chunk) as $tag) {
                $this->links[$id]['reads']++;
                $this->handleTag($id, $tag, $scans, $events);
            }
        }

        foreach ($this->links as $link) {
            $link['presence']->prune();
        }
    }

    /** @param array{epc:string, tid:?string, rssi:?int, antenna:?int} $tag */
    private function handleTag(int $id, array $tag, RfidScanService $scans, HardwareEventService $events): void
    {
        $reader = $this->links[$id]['reader'];

        if ($tag['epc'] === '' || strlen($tag['epc']) > 50) {
            return;
        }
        $minRssi = $reader->connection['min_rssi'] ?? config('rfid.uhf.min_rssi');
        if ($minRssi !== null && $tag['rssi'] !== null && $tag['rssi'] < (int) $minRssi) {
            return;
        }

        $direction = $reader->directionForAntenna($tag['antenna']);
        if (! $this->links[$id]['presence']->shouldProcess("{$tag['epc']}|{$direction}")) {
            return;
        }
        $this->links[$id]['events']++;

        try {
            [$result, $duplicate] = $scans->scan(
                new DeviceContext($reader->company_id, $reader, 'device_key'),
                $direction,
                $tag['epc'],
                $reader->stage,
                [
                    'origin'   => 'real_uhf',
                    'event_id' => 'ur4-' . $reader->id . '-' . Str::ulid(),
                    'ip'       => $reader->connection['host'] ?? null,
                    'antenna'  => $tag['antenna'],
                    'rssi'     => $tag['rssi'],
                    'tid'      => $tag['tid'],
                ]
            );
        } catch (\Throwable $e) {
            Log::error("rfid:uhf-gate {$reader->code} gagal memproses EPC {$tag['epc']}: {$e->getMessage()}");
            $this->error("Reader {$reader->code}: galat memproses {$tag['epc']} — {$e->getMessage()}");

            return;
        }

        $label = strtoupper($direction);
        $state = $duplicate ? 'DUPLIKAT' : strtoupper($result['status']);
        $this->line(sprintf(
            '[%s] %s %s %s ant:%s rssi:%s → %s %s',
            now('Asia/Jakarta')->format('H:i:s'), $reader->code, $label, $tag['epc'],
            $tag['antenna'] ?? '-', $tag['rssi'] ?? '-', $state, $result['detail'] ?? $result['message'] ?? ''
        ));

        if ($result['status'] === 'success' && ! $duplicate) {
            $data = array_filter($result, fn ($k) => ! str_starts_with((string) $k, '_'), ARRAY_FILTER_USE_KEY);
            $events->dispatchRfidScan($data, (int) ($result['_company_id'] ?? $reader->company_id));
        }
    }

    private function heartbeat(RfidDeviceService $devices): void
    {
        foreach ($this->links as $id => $link) {
            if (! $link['socket']) {
                continue;
            }
            $devices->heartbeat($link['reader'], [
                'firmware' => 'UR4/tcp',
                'uptime_s' => (int) (microtime(true) - $link['connected_at']),
            ], $link['reader']->connection['host'] ?? null);

            // Statistik daemon ditambahkan ke last_status (dibaca panel Perangkat).
            $reader = $link['reader']->fresh();
            if (! $reader) {
                continue;   // reader dihapus — reloadReaders() memutusnya
            }
            $reader->forceFill(['last_status' => array_merge($reader->last_status ?? [], [
                'reads_total'  => $link['reads'],
                'events_total' => $link['events'],
                'tags_present' => $link['presence']->count(),
                'daemon'       => 'rfid:uhf-gate',
            ])])->save();
            $this->links[$id]['reader'] = $reader;
        }
    }

    // ─── Util ─────────────────────────────────────────────────────────────

    private function endpoint(RfidReader $reader): string
    {
        $host = $reader->connection['host'] ?? '';
        $port = (int) ($reader->connection['port'] ?? config('rfid.uhf.ur4_port', 8888));

        return "{$host}:{$port}";
    }

    private function presenceSeconds(RfidReader $reader): float
    {
        return (float) ($reader->connection['presence_seconds'] ?? config('rfid.uhf.presence_seconds', 10));
    }

    private function trapSignals(): void
    {
        if (! function_exists('pcntl_async_signals')) {
            return;
        }
        pcntl_async_signals(true);
        foreach ([SIGINT, SIGTERM] as $signal) {
            pcntl_signal($signal, function () {
                $this->running = false;
            });
        }
    }
}
