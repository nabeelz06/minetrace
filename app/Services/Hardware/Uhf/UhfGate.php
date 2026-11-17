<?php

namespace App\Services\Hardware\Uhf;

use App\Models\RfidReader;
use App\Services\Hardware\DeviceContext;
use App\Services\Hardware\HardwareEventService;
use App\Services\Hardware\RfidReadLogService;
use App\Services\Hardware\RfidRegistrationService;
use App\Services\Hardware\RfidScanService;
use Carbon\Carbon;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * UhfGate — orkestrator gate UHF Chainway UR4: banyak reader, satu proses.
 *
 * Tanggung jawab (semua yang BUKAN protokol/soket, yang ada di Ur4Link):
 *   - memuat reader aktif bertipe jaringan; memuat ulang saat revisi cache berubah (≤ 2 dtk) atau tiap 60 dtk;
 *   - EndpointGuard (cegah SSRF) dan satu klien per endpoint: reader ganda ke host:port yang sama dilewati,
 *     kunci eksklusif Ur4ExclusiveLock dipegang selama tautan hidup (alat kooperatif menghormatinya);
 *   - tag → UID kanonik → filter RSSI → sesi registrasi aktif? → commit registrasi (lewati presence/arbiter;
 *     satu tap = satu niat) : (arbitrasi antena IN/OUT) → filter kehadiran (Redis) → RfidScanService,
 *     jalur yang SAMA dengan ESP32/UI, sehingga checkpoint, mode otorisasi, dedupe, isolasi tenant, rfid_read_logs
 *     dan capture CCTV tetap berlaku;
 *   - "Pakai reader" di UI Daftarkan Tag: operator start sesi menunggu untuk reader UR4 tertentu →
 *     tap di reader itu mengikat tag ke batch (findForDevice + commit), BUKAN scan in/out. Pembacaan dikumpulkan
 *     dalam jendela singkat (rfid.uhf.registration_window_ms): bila lebih dari satu tag terbaca dan tidak ada yang
 *     jelas terkuat (rfid.uhf.registration_dominance_db), registrasi DITOLAK ('multiple_tags') — tidak mengikat
 *     tag tetangga secara diam-diam;
 *   - status jujur di rfid_readers.last_status: "online" (last_seen_at) hanya diperbarui saat tautan SEHAT.
 *
 * Tidak memiliki loop: command memanggil reloadIfNeeded(), tick(), poll(), heartbeat(), refreshLocks().
 */
final class UhfGate
{
    public const RELOAD_EVERY = 60.0;

    public const REV_CHECK_EVERY = 2.0;

    public const HEARTBEAT_EVERY = 20.0;

    public const LOCK_REFRESH_EVERY = 10.0;

    /** @var array<int,array{reader:RfidReader,link:Ur4Link,sig:string,host:string,port:int,presence:PresenceFilter,presence_sec:float,arbiter:?TagArbiter,reads:int,events:int,rejected:array<string,int>}> */
    private array $gates = [];

    /** @var array<int,string> alasan reader dilewati (agar log tidak berulang) */
    private array $skipped = [];

    /** @var array<int,array{opened:float,tags:array<string,array{rssi:?int,antenna:int,tid:?string,reads:int}>}> jendela kumpul registrasi per reader */
    private array $regWindows = [];

    private string $owner;

    private ?int $seenRev = null;

    private float $lastRevCheck = -1e9;

    private float $lastReload = -1e9;

    private float $lastBeat = -1e9;

    private float $lastLock = -1e9;

    /**
     * @param  Closure(string,string):void  $out  level (info|warning|error|line), pesan
     * @param  Closure():float  $now
     * @param  Closure(string,int):mixed  $connector  pembuat soket (bawaan Ur4Link::tcpConnector)
     * @param  list<string>  $codes  batasi ke kode reader tertentu (kosong = semua)
     */
    public function __construct(
        private readonly RfidScanService $scans,
        private readonly HardwareEventService $events,
        private readonly Closure $out,
        private readonly Closure $now,
        private readonly Closure $connector,
        private readonly array $codes = [],
        ?string $owner = null,
        private readonly ?Closure $connectCheck = null,
        private readonly ?RfidRegistrationService $registrations = null,
        private readonly ?RfidReadLogService $readLogs = null,
    ) {
        $this->owner = $owner ?? ('gate:'.(gethostname() ?: 'host').':'.getmypid());
    }

    // ─── Siklus hidup ─────────────────────────────────────────────────────

    public function reloadIfNeeded(): void
    {
        $now = ($this->now)();
        $due = $now - $this->lastReload >= self::RELOAD_EVERY;

        if ($now - $this->lastRevCheck >= self::REV_CHECK_EVERY) {
            $this->lastRevCheck = $now;
            $rev = (int) Cache::get(RfidReader::REVISION_KEY, 0);
            if ($this->seenRev === null || $rev !== $this->seenRev) {
                $due = true;
            }
            $this->seenRev = $rev;
        }

        if ($due) {
            $this->reload();
            $this->lastReload = $now;
        }
    }

    public function reload(): void
    {
        $readers = RfidReader::whereIn('device_type', RfidReader::NETWORK_TYPES)
            ->where('is_active', true)
            ->when($this->codes, fn ($q) => $q->whereIn('code', array_map('strtoupper', $this->codes)))
            ->orderBy('id')
            ->get()
            ->filter(fn (RfidReader $r) => trim((string) ($r->connectionConfig()['host'] ?? '')) !== '')
            ->keyBy('id');

        // Reader dihapus/dinonaktifkan atau koneksinya berubah: tutup. Perubahan stage/arah tidak butuh sambung ulang.
        foreach ($this->gates as $id => $gate) {
            $fresh = $readers->get($id);
            if (! $fresh) {
                $this->closeGate($id, 'reader nonaktif/dihapus');

                continue;
            }
            if ($this->signature($fresh) !== $gate['sig']) {
                $this->closeGate($id, 'alamat atau pemetaan antena berubah');

                continue;
            }
            $this->gates[$id]['reader'] = $fresh;
            $sec = $this->presenceSeconds($fresh);
            if ($sec !== $gate['presence_sec']) {
                $this->gates[$id]['presence'] = new CachePresenceFilter($sec);
                $this->gates[$id]['presence_sec'] = $sec;
            }
        }

        $claimed = [];
        foreach ($this->gates as $id => $g) {
            $claimed["{$g['host']}:{$g['port']}"] = $id;
        }

        foreach ($readers as $id => $reader) {
            if (isset($this->gates[$id])) {
                continue;
            }
            [$host, $port] = $this->endpointOf($reader);

            if ($code = EndpointGuard::validate($host, $port)) {
                $this->skip($reader, 'config_mismatch', "endpoint_{$code}", "alamat {$host}:{$port} ditolak ({$code}): ".EndpointGuard::message($code));

                continue;
            }
            if (isset($claimed["{$host}:{$port}"])) {
                $this->skip($reader, 'config_mismatch', 'endpoint_duplicate', "{$host}:{$port} sudah dipakai reader id={$claimed["{$host}:{$port}"]} (UR4 hanya melayani satu klien)");

                continue;
            }
            if (! Ur4ExclusiveLock::acquire($host, $port, $this->owner, (int) config('rfid.uhf.lock_ttl', 30))) {
                $holder = Ur4ExclusiveLock::holder($host, $port) ?? '?';
                $this->skip($reader, 'contested', 'lock_held', "{$host}:{$port} dipegang {$holder}; dicoba lagi pada reload berikutnya");

                continue;
            }

            $this->openGate($reader, $host, $port);
            $claimed["{$host}:{$port}"] = $id;
            unset($this->skipped[$id]);
        }
    }

    /** Majukan semua tautan dan arbiter. Tidak memblokir. */
    public function tick(): void
    {
        $now = ($this->now)();
        foreach ($this->gates as $id => $gate) {
            $gate['link']->tick();

            if ($gate['arbiter']) {
                foreach ($gate['arbiter']->flush($now) as $d) {
                    $this->process($id, $d['epc'], $d['antenna'], $d['rssi'], $d['tid']);
                }
            }
            if (isset($this->regWindows[$id]) && $now - $this->regWindows[$id]['opened'] >= $this->registrationWindow()) {
                $this->finishRegistration($id);
            }
            $gate['presence']->prune($now);
        }
    }

    /** stream_select atas semua tautan; batas tunggu mengikuti peristiwa berwaktu terdekat (maks $maxWait). */
    public function poll(float $maxWait = 0.25): void
    {
        $read = $write = [];
        $wait = $maxWait;
        foreach ($this->gates as $id => $gate) {
            if ($s = $gate['link']->wantsRead()) {
                $read[$id] = $s;
            }
            if ($s = $gate['link']->wantsWrite()) {
                $write[$id] = $s;
            }
            $wait = min($wait, $gate['link']->nextWake());
            if (isset($this->regWindows[$id])) {
                $wait = min($wait, $this->regWindows[$id]['opened'] + $this->registrationWindow() - ($this->now)());
            }
        }
        $wait = max(0.01, $wait);

        if (! $read && ! $write) {
            usleep((int) ($wait * 1_000_000));   // tidak ada soket aktif: tidur terukur, BUKAN loop sibuk

            return;
        }

        $except = null;
        $r = $read;
        $w = $write;
        $ready = @stream_select($r, $w, $except, 0, (int) ($wait * 1_000_000));
        if (! $ready) {
            return;
        }
        foreach (array_keys($w) as $id) {
            $this->gates[$id]['link']->onWritable();
        }
        foreach (array_keys($r) as $id) {
            if (isset($this->gates[$id])) {
                $this->gates[$id]['link']->onReadable();
            }
        }
    }

    public function heartbeatIfDue(): void
    {
        $now = ($this->now)();
        if ($now - $this->lastBeat >= self::HEARTBEAT_EVERY) {
            $this->lastBeat = $now;
            $this->heartbeat();
        }
    }

    public function refreshLocksIfDue(): void
    {
        $now = ($this->now)();
        if ($now - $this->lastLock < self::LOCK_REFRESH_EVERY) {
            return;
        }
        $this->lastLock = $now;
        $ttl = (int) config('rfid.uhf.lock_ttl', 30);
        foreach ($this->gates as $id => $g) {
            if (! Ur4ExclusiveLock::refresh($g['host'], $g['port'], $this->owner, $ttl)
                && ! Ur4ExclusiveLock::acquire($g['host'], $g['port'], $this->owner, $ttl)) {
                $this->closeGate($id, 'kunci eksklusif direbut proses lain');
            }
        }
    }

    public function shutdown(): void
    {
        foreach (array_keys($this->gates) as $id) {
            $this->closeGate($id, 'berhenti');
        }
    }

    // ─── Akses untuk command/tes ──────────────────────────────────────────

    /** @return list<int> */
    public function readerIds(): array
    {
        return array_keys($this->gates);
    }

    public function link(int $readerId): ?Ur4Link
    {
        return $this->gates[$readerId]['link'] ?? null;
    }

    public function count(): int
    {
        return count($this->gates);
    }

    // ─── Gate ─────────────────────────────────────────────────────────────

    private function openGate(RfidReader $reader, string $host, int $port): void
    {
        $id = $reader->id;
        $map = $this->antennaMap($reader);
        $presenceSec = $this->presenceSeconds($reader);

        $link = new Ur4Link(
            $host,
            $port,
            Ur4LinkConfig::fromConfig($map),
            $this->connector,
            $this->now,
            fn (array $tag) => $this->onTag($id, $tag),
            connectCheck: $this->connectCheck,
            log: function (string $level, string $message) use ($reader) {
                ($this->out)($level === 'warning' ? 'warning' : 'info', "Reader {$reader->code}: {$message}");
                Log::log($level === 'warning' ? 'warning' : 'info', "rfid:uhf-gate {$reader->code} {$message}");
            },
        );

        $this->gates[$id] = [
            'reader' => $reader,
            'link' => $link,
            'sig' => $this->signature($reader),
            'host' => $host,
            'port' => $port,
            'presence' => new CachePresenceFilter($presenceSec),
            'presence_sec' => $presenceSec,
            // Arbitrasi hanya bila ada antena IN dan OUT sekaligus (satu antena/satu arah: tanpa latensi tambahan).
            'arbiter' => (in_array('in', $map, true) && in_array('out', $map, true))
                ? new TagArbiter(((int) config('rfid.uhf.arbitration_ms', 1200)) / 1000)
                : null,
            'reads' => 0,
            'events' => 0,
            'rejected' => [],
        ];

        ($this->out)('line', "Reader {$reader->code} ({$host}:{$port}) tahap ".($reader->stage ?? '-').', arah '.$reader->direction);
    }

    private function closeGate(int $id, string $why): void
    {
        $g = $this->gates[$id] ?? null;
        if (! $g) {
            return;
        }
        $g['link']->stop();
        Ur4ExclusiveLock::release($g['host'], $g['port'], $this->owner);
        unset($this->gates[$id], $this->regWindows[$id]);
        ($this->out)('warning', "Reader {$g['reader']->code}: ditutup ({$why})");
    }

    private function skip(RfidReader $reader, string $linkState, string $error, string $message): void
    {
        if (($this->skipped[$reader->id] ?? null) === $error) {
            return;   // sudah dilaporkan
        }
        $this->skipped[$reader->id] = $error;
        ($this->out)('warning', "Reader {$reader->code}: dilewati. {$message}");
        Log::warning("rfid:uhf-gate {$reader->code} dilewati: {$message}");

        $reader->forceFill(['last_status' => [
            'link_state' => $linkState,
            'last_error' => $error,
            'detail' => $message,
            'daemon' => 'rfid:uhf-gate',
            'updated_at' => now()->toIso8601String(),
        ]])->save();
    }

    // ─── Tag ──────────────────────────────────────────────────────────────

    /** @param array{epc:string,tid:?string,rssi:?int,antenna:int} $tag */
    private function onTag(int $id, array $tag): void
    {
        $gate = $this->gates[$id] ?? null;
        if (! $gate) {
            return;
        }
        $this->gates[$id]['reads']++;

        $epc = UhfTagIdentity::canonical($tag['epc']);
        if ($epc === null) {
            $this->reject($id, 'bad_epc');

            return;
        }

        $minRssi = $gate['reader']->connectionConfig()['min_rssi'] ?? config('rfid.uhf.min_rssi');
        if ($minRssi !== null && $tag['rssi'] !== null && $tag['rssi'] < (int) $minRssi) {
            $this->reject($id, 'below_min_rssi');

            return;
        }

        if ($gate['arbiter']) {
            $gate['arbiter']->add($epc, $tag['antenna'], $tag['rssi'], $tag['tid'], ($this->now)());

            return;
        }
        $this->process($id, $epc, $tag['antenna'], $tag['rssi'], $tag['tid']);
    }

    private function process(int $id, string $epc, int $antenna, ?int $rssi, ?string $tid): void
    {
        $gate = $this->gates[$id] ?? null;
        if (! $gate) {
            return;
        }
        $reader = $gate['reader'];

        if ($this->registrations && $this->tryRegister($id, $reader, $gate, $epc, $antenna, $rssi, $tid)) {
            return;
        }

        // Kunci per reader+EPC (bukan per arah): satu tag di zona tumpang tindih tidak menghasilkan dua peristiwa.
        if (! $gate['presence']->shouldProcess("{$reader->id}:{$epc}")) {
            return;
        }
        $direction = $reader->directionForAntenna($antenna);
        $this->gates[$id]['events']++;

        try {
            [$result, $duplicate] = $this->scans->scan(
                new DeviceContext($reader->company_id, $reader, 'device_key'),
                $direction,
                $epc,
                $reader->stage,
                [
                    'origin' => 'real_uhf',
                    'event_id' => 'ur4-'.$reader->id.'-'.Str::ulid(),
                    'ip' => $gate['host'],
                    'antenna' => $antenna,
                    'rssi' => $rssi,
                    'tid' => $tid,
                ],
            );
        } catch (\Throwable $e) {
            $this->reject($id, 'scan_error');
            Log::error("rfid:uhf-gate {$reader->code} gagal memproses EPC {$epc}: {$e->getMessage()}");
            ($this->out)('error', "Reader {$reader->code}: galat memproses {$epc}: {$e->getMessage()}");

            return;
        }

        ($this->out)('line', sprintf(
            '[%s] %s %s %s ant:%s rssi:%s → %s %s',
            now('Asia/Jakarta')->format('H:i:s'), $reader->code, strtoupper($direction), $epc,
            $antenna, $rssi ?? '-', $duplicate ? 'DUPLIKAT' : strtoupper($result['status']), $result['detail'] ?? $result['message'] ?? '',
        ));

        if ($result['status'] === 'success' && ! $duplicate) {
            $data = array_filter($result, fn ($k) => ! str_starts_with((string) $k, '_'), ARRAY_FILTER_USE_KEY);
            $this->events->dispatchRfidScan($data, (int) ($result['_company_id'] ?? $reader->company_id));
        }
    }

    /**
     * "Pakai reader" (Daftarkan Tag di UI): bila ADA sesi registrasi menunggu reader ini (atau sesi
     * "any" perusahaan), pembacaan di reader ini adalah NIAT registrasi — bukan scan in/out.
     * Pembacaan dikumpulkan dalam jendela singkat lalu diputuskan oleh finishRegistration(); selama jendela
     * terbuka, pembacaan berikutnya hanya menambah data (RSSI terkuat per EPC).
     *
     * @return bool true bila pembacaan ini DITANGANI sebagai registrasi (proses scan normal dilewati)
     */
    private function tryRegister(int $id, RfidReader $reader, array $gate, string $epc, int $antenna, ?int $rssi, ?string $tid): bool
    {
        if (! isset($this->regWindows[$id])) {
            if (! $this->registrations->findForDevice(new DeviceContext($reader->company_id, $reader, 'device_key'))) {
                return false;
            }
            $this->regWindows[$id] = ['opened' => ($this->now)(), 'tags' => []];
        }

        $t = $this->regWindows[$id]['tags'][$epc] ?? ['rssi' => null, 'antenna' => $antenna, 'tid' => $tid, 'reads' => 0];
        $t['reads']++;
        if ($rssi !== null && ($t['rssi'] === null || $rssi > $t['rssi'])) {
            $t['rssi'] = $rssi;
            $t['antenna'] = $antenna;
        }
        $t['tid'] ??= $tid;
        $this->regWindows[$id]['tags'][$epc] = $t;

        if ($this->registrationWindow() <= 0.0) {
            $this->finishRegistration($id);   // tanpa jendela: putuskan seketika (perilaku lama)
        }

        return true;
    }

    /**
     * Putuskan registrasi dari tag yang terkumpul:
     *  - satu tag → ikat ke batch pada sesi (commit() sendiri atomik: tap berulang hanya sukses sekali);
     *  - beberapa tag → ikat yang terkuat HANYA bila unggul >= registration_dominance_db dari yang kedua;
     *    selain itu tolak ('multiple_tags') dan beri tahu UI lewat last_attempt agar operator menyisakan satu tag.
     * Semua tag yang terkumpul ditandai "hadir" supaya pembacaan lanjutan selama masih di medan antena tidak
     * berubah menjadi scan hantu di reader Receiving.
     */
    private function finishRegistration(int $id): void
    {
        $window = $this->regWindows[$id] ?? null;
        unset($this->regWindows[$id]);
        $gate = $this->gates[$id] ?? null;
        if (! $window || ! $gate || ! $window['tags']) {
            return;
        }

        $reader = $gate['reader'];
        $session = $this->registrations->findForDevice(new DeviceContext($reader->company_id, $reader, 'device_key'));
        if (! $session) {
            return;   // sesi dibatalkan/kedaluwarsa selama jendela
        }

        foreach (array_keys($window['tags']) as $seen) {
            $gate['presence']->shouldProcess("{$reader->id}:{$seen}");
        }

        [$epc, $info, $ambiguous] = $this->pickRegistrationTag($window['tags']);

        if ($ambiguous) {
            $count = count($window['tags']);
            $result = [
                'status' => 'failed', '_code' => 'multiple_tags', '_count' => $count,
                'name' => 'Banyak Tag', 'message' => 'Beberapa tag terbaca',
                'detail' => "{$count} tag terbaca bersamaan di {$reader->code}. Sisakan satu tag di dekat reader lalu coba lagi.",
            ];
            $this->registrations->noteAttempt($session, $epc, $result);
        } else {
            $result = $this->registrations->commit($session, $epc, 'real_uhf', $reader);
        }
        $this->gates[$id]['events']++;

        $this->readLogs?->registration($session['company_id'] ?? $reader->company_id, $reader, $epc, $result + ['batch_id' => $session['batch_id'] ?? null], [
            'origin' => 'real_uhf',
            'antenna' => $info['antenna'],
            'rssi' => $info['rssi'],
            'tid' => $info['tid'],
            'meta' => ['ip' => $gate['host']],
        ]);

        ($this->out)('line', sprintf(
            '[%s] %s REGISTER %s ant:%s rssi:%s → %s %s',
            now('Asia/Jakarta')->format('H:i:s'), $reader->code, $epc,
            $info['antenna'], $info['rssi'] ?? '-', strtoupper($result['status']), $result['detail'] ?? $result['message'] ?? '',
        ));
    }

    /**
     * @param  array<string,array{rssi:?int,antenna:int,tid:?string,reads:int}>  $tags
     * @return array{0:string,1:array,2:bool} [EPC terkuat, datanya, ambigu?]
     */
    private function pickRegistrationTag(array $tags): array
    {
        uasort($tags, fn (array $a, array $b) => ($b['rssi'] ?? -999) <=> ($a['rssi'] ?? -999));
        $epcs = array_keys($tags);
        $best = $epcs[0];

        if (count($tags) === 1) {
            return [$best, $tags[$best], false];
        }

        $second = $tags[$epcs[1]];
        $margin = (int) config('rfid.uhf.registration_dominance_db', 6);
        $dominant = $tags[$best]['rssi'] !== null && $second['rssi'] !== null
            && ($tags[$best]['rssi'] - $second['rssi']) >= $margin;

        return [$best, $tags[$best], ! $dominant];
    }

    private function registrationWindow(): float
    {
        return max(0.0, (float) config('rfid.uhf.registration_window_ms', 400) / 1000);
    }

    private function reject(int $id, string $reason): void
    {
        $this->gates[$id]['rejected'][$reason] = ($this->gates[$id]['rejected'][$reason] ?? 0) + 1;
    }

    // ─── Status jujur ─────────────────────────────────────────────────────

    private function heartbeat(): void
    {
        foreach ($this->gates as $id => $gate) {
            $reader = RfidReader::find($id);
            if (! $reader) {
                continue;   // dihapus: reload menutup gate-nya
            }
            $s = $gate['link']->status();
            $healthy = $s['link_state'] === 'streaming';
            $iso = fn (?float $t) => $t === null ? null : Carbon::createFromTimestampMs((int) ($t * 1000), 'UTC')->toIso8601String();

            $attrs = ['last_status' => [
                'link_state' => $s['link_state'],
                'last_error' => $s['last_error'],
                'detail' => $s['detail'],
                'warnings' => $s['warnings'],
                'last_frame_at' => $iso($s['last_frame_at']),
                'last_tag_at' => $iso($s['last_tag_at']),
                'counters' => $s['counters'],
                'device' => $s['device'],
                'reads_total' => $gate['reads'],
                'events_total' => $gate['events'],
                'rejected' => $gate['rejected'],
                'tags_present' => $gate['presence']->count(),
                'daemon' => 'rfid:uhf-gate',
                'pid' => getmypid(),
                'updated_at' => now()->toIso8601String(),
            ]];

            // "Online" hanya bila tautan SEHAT (inventory berjalan); selain itu last_seen_at dibiarkan menua.
            if ($healthy) {
                $attrs += ['last_seen_at' => now(), 'last_ip' => $gate['host']];
                if (! empty($s['device']['firmware'])) {
                    $attrs['firmware_version'] = 'UR4 '.$s['device']['firmware'];
                }
            }

            $reader->forceFill($attrs)->save();
            $this->gates[$id]['reader'] = $reader;
        }
    }

    // ─── Util ─────────────────────────────────────────────────────────────

    /** @return array{0:string,1:int} */
    private function endpointOf(RfidReader $reader): array
    {
        $c = $reader->connectionConfig();

        return [trim((string) ($c['host'] ?? '')), (int) ($c['port'] ?? config('rfid.uhf.ur4_port', 8888))];
    }

    /** @return array<int,string> */
    private function antennaMap(RfidReader $reader): array
    {
        $map = [];
        foreach ((array) ($reader->connectionConfig()['antennas'] ?? []) as $ant => $dir) {
            if (in_array($dir, ['in', 'out'], true)) {
                $map[(int) $ant] = $dir;
            }
        }
        ksort($map);

        return $map;
    }

    private function signature(RfidReader $reader): string
    {
        [$host, $port] = $this->endpointOf($reader);

        return md5(json_encode([$host, $port, $this->antennaMap($reader)]));
    }

    private function presenceSeconds(RfidReader $reader): float
    {
        return (float) ($reader->connectionConfig()['presence_seconds'] ?? config('rfid.uhf.presence_seconds', 10));
    }
}
