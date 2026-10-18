<?php

namespace App\Services\Hardware\Uhf;

use Closure;

/**
 * Ur4Link — mesin status SATU koneksi TCP ke reader Chainway UR4.
 *
 * Tidak menyentuh database, tidak memanggil sleep, tidak memblokir. Waktu (`now`), pembuat soket (`connector`) dan
 * pemeriksa sambungan disuntikkan lewat konstruktor sehingga seluruh perilaku dapat diuji dengan
 * stream_socket_pair dan jam palsu. Pemanggil (daemon) menjalankan: tick() berkala + stream_select pada
 * wantsRead()/wantsWrite() + onReadable()/onWritable().
 *
 *   Backoff ─▶ Connecting ─▶ Handshake ─▶ Configuring ─▶ Streaming
 *                                │              │            └─▶ Backoff | Contested | Stalled
 *                                └─ mati-sejak-lahir ─▶ Backoff       └─▶ ConfigMismatch (daya/mode/antena)
 *
 * Fakta perilaku UR4 yang ditangani (sumber: dokumen protokol, satu unit uji; verifikasi ulang di unit sendiri):
 *  - melayani SATU klien; klien kedua merebut aliran dan soket pertama diam tanpa FIN, baru kena RST saat menulis
 *    -> liveness lewat tulis byte 00 berkala, putus/RST = "reset"; dua reset pada tautan sehat dalam jendela = Contested;
 *  - sebagian koneksi baru "mati sejak lahir" (STOP tak dijawab) -> deteksi dan sambung ulang dengan jeda;
 *  - saat inventory kontinu reader tidak menjawab perintah apa pun selain STOP -> konfigurasi dibaca SEBELUM start;
 *  - tanpa antena terpasang inventory diam tanpa bingkai galat (tidak bisa dibedakan dari "tidak ada tag").
 */
final class Ur4Link
{
    private const READ_BYTES = 8192;

    /** Urutan pembacaan konfigurasi sebelum inventory dimulai. */
    private const CONFIG_STEPS = [
        Ur4Command::Version, Ur4Command::Power, Ur4Command::Region,
        Ur4Command::AntennaMask, Ur4Command::AntennaConnection, Ur4Command::InventoryMode,
    ];

    private const RESET_ERRORS = ['eof', 'write_failed', 'read_error'];

    private Ur4LinkState $state = Ur4LinkState::Backoff;

    /** @var resource|null */
    private $stream = null;

    private ChainwayUr4Protocol $proto;

    private float $stateSince = 0.0;

    private float $nextTry = 0.0;

    private float $lastRxAt = 0.0;

    private float $lastTxAt = 0.0;

    private float $lastStopAt = 0.0;

    private ?float $ackAt = null;

    private float $lastHandshakeFrameAt = 0.0;

    private int $cfgIndex = -1;

    private float $cfgSentAt = 0.0;

    private int $backoffExp = 0;

    private int $doaStreak = 0;

    private ?string $lastError = null;

    private ?string $detail = null;

    private ?float $lastFrameAt = null;

    private ?float $lastTagAt = null;

    /** @var list<float> */
    private array $resetTimes = [];

    /** @var list<float> */
    private array $rearmTimes = [];

    /** @var list<string> */
    private array $warnings = [];

    /** @var array<string,mixed> */
    private array $device = [];

    /** @var array<string,int> */
    private array $counters = ['reconnects' => 0, 'dead_on_arrival' => 0, 'contested' => 0, 'rearms' => 0, 'tags_emitted' => 0, 'handshake_dropped' => 0];

    /** @var array<string,int> statistik protokol dari koneksi-koneksi sebelumnya */
    private array $protoTotals = [];

    private bool $stopped = false;

    /**
     * @param Closure(string,int):mixed $connector     membuka soket non-blocking (resource) atau false
     * @param Closure():float           $now           jam (detik, pecahan)
     * @param Closure(array):void       $onTag         tag terurai: epc, tid, rssi, antenna
     * @param Closure(mixed):bool|null  $connectCheck  true bila soket benar-benar tersambung (bawaan: peer TCP ada)
     * @param Closure():float|null      $jitter        pengali 0,8..1,2 untuk backoff (bawaan acak)
     * @param Closure(string,string):void|null $log    level, pesan
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly Ur4LinkConfig $cfg,
        private readonly Closure $connector,
        private readonly Closure $now,
        private readonly Closure $onTag,
        private readonly ?Closure $connectCheck = null,
        private readonly ?Closure $jitter = null,
        private readonly ?Closure $log = null,
    ) {
        $this->proto = new ChainwayUr4Protocol($cfg->verifyXor);
        $this->stateSince = ($this->now)();
    }

    /** Penyambung TCP non-blocking bawaan (async: kesiapan diperiksa lewat stream_select tulis). */
    public static function tcpConnector(): Closure
    {
        return static function (string $host, int $port) {
            $s = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 0, STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT);
            if (! $s) {
                return false;
            }
            stream_set_blocking($s, false);

            return $s;
        };
    }

    // ─── API untuk pemanggil ──────────────────────────────────────────────

    public function state(): Ur4LinkState
    {
        return $this->state;
    }

    /** Soket untuk stream_select (baca): setiap state yang memegang soket tersambung. */
    public function wantsRead()
    {
        return in_array($this->state, [Ur4LinkState::Handshake, Ur4LinkState::Configuring, Ur4LinkState::Streaming], true) ? $this->stream : null;
    }

    /** Soket untuk stream_select (tulis): hanya saat menunggu sambungan async selesai. */
    public function wantsWrite()
    {
        return $this->state === Ur4LinkState::Connecting ? $this->stream : null;
    }

    /** Majukan penghitung waktu: sambung, timeout, handshake, heartbeat, backoff. Tidak memblokir. */
    public function tick(): void
    {
        if ($this->stopped) {
            return;
        }
        $now = ($this->now)();

        if ($this->state->isWaiting()) {
            if ($now >= $this->nextTry) {
                $this->startConnect($now);
            }

            return;
        }

        match ($this->state) {
            Ur4LinkState::Connecting => $now - $this->stateSince >= $this->cfg->connectTimeout ? $this->fail('connect_timeout', $now) : null,
            Ur4LinkState::Handshake => $this->tickHandshake($now),
            Ur4LinkState::Configuring => $now - $this->cfgSentAt >= $this->cfg->configTimeout ? $this->fail('config_timeout', $now) : null,
            Ur4LinkState::Streaming => $this->tickStreaming($now),
            default => null,
        };
    }

    public function onWritable(): void
    {
        if ($this->state !== Ur4LinkState::Connecting || ! $this->stream) {
            return;
        }
        $now = ($this->now)();
        $ok = $this->connectCheck ? ($this->connectCheck)($this->stream) : (@stream_socket_get_name($this->stream, true) !== false);
        if (! $ok) {
            $this->fail('connect_refused', $now);

            return;
        }
        $this->beginHandshake($now);
    }

    public function onReadable(): void
    {
        if (! $this->stream || ! in_array($this->state, [Ur4LinkState::Handshake, Ur4LinkState::Configuring, Ur4LinkState::Streaming], true)) {
            return;
        }
        $now = ($this->now)();

        $data = @fread($this->stream, self::READ_BYTES);
        if ($data === false) {
            $this->fail('read_error', $now);

            return;
        }
        if ($data === '') {
            if (feof($this->stream)) {
                $this->fail('eof', $now);
            }

            return;
        }

        $this->lastRxAt = $now;
        foreach ($this->proto->feedFrames($data) as $frame) {
            $this->lastFrameAt = $now;
            $this->dispatch($frame, $now);
            if (! $this->stream) {
                break;   // tautan ditutup oleh dispatch (fail/mismatch)
            }
        }
    }

    /** Berhenti dengan rapi: kirim STOP bila inventory berjalan, tutup soket, jangan sambung ulang. */
    public function stop(): void
    {
        $this->stopped = true;
        if ($this->stream && $this->state === Ur4LinkState::Streaming) {
            @fwrite($this->stream, ChainwayUr4Protocol::request(Ur4Command::StopInventory));
        }
        $this->closeStream();
        $this->state = Ur4LinkState::Backoff;
        $this->nextTry = PHP_FLOAT_MAX;
    }

    /** Detik sampai peristiwa berwaktu berikutnya (untuk batas waktu stream_select). */
    public function nextWake(): float
    {
        $now = ($this->now)();

        return max(0.0, match ($this->state) {
            Ur4LinkState::Backoff, Ur4LinkState::Contested, Ur4LinkState::Stalled, Ur4LinkState::ConfigMismatch => $this->nextTry - $now,
            Ur4LinkState::Connecting => $this->stateSince + $this->cfg->connectTimeout - $now,
            Ur4LinkState::Handshake => $this->cfg->handshakeResendEvery,
            Ur4LinkState::Configuring => $this->cfgSentAt + $this->cfg->configTimeout - $now,
            Ur4LinkState::Streaming => max($this->lastRxAt + $this->cfg->idleHeartbeatAfter, $this->lastTxAt + $this->cfg->heartbeatInterval) - $now,
        });
    }

    /** Label status untuk UI (tabel di Bagian 9.3 brief). */
    public function uiState(): string
    {
        return match ($this->state) {
            Ur4LinkState::Streaming => 'streaming',
            Ur4LinkState::Connecting, Ur4LinkState::Handshake => 'connecting',
            Ur4LinkState::Configuring => 'configuring',
            Ur4LinkState::Contested => 'contested',
            Ur4LinkState::Stalled => 'stalled',
            Ur4LinkState::ConfigMismatch => 'config_mismatch',
            Ur4LinkState::Backoff => in_array($this->lastError, ['connect_failed', 'connect_timeout', 'connect_refused'], true) ? 'unreachable' : 'connecting',
        };
    }

    /** Ringkasan jujur untuk rfid_readers.last_status. */
    public function status(): array
    {
        $proto = $this->protoTotals;
        foreach ($this->proto->stats() as $k => $v) {
            $proto[$k] = ($proto[$k] ?? 0) + $v;
        }

        return [
            'link_state' => $this->uiState(),
            'state' => $this->state->value,
            'state_since' => $this->stateSince,
            'last_error' => $this->lastError,
            'detail' => $this->detail,
            'last_frame_at' => $this->lastFrameAt,
            'last_tag_at' => $this->lastTagAt,
            'counters' => $this->counters + $proto,
            'device' => $this->device,
            'warnings' => array_values(array_unique($this->warnings)),
        ];
    }

    // ─── Koneksi ──────────────────────────────────────────────────────────

    private function startConnect(float $now): void
    {
        $stream = ($this->connector)($this->host, $this->port);
        if (! $stream) {
            $this->fail('connect_failed', $now);

            return;
        }
        $this->stream = $stream;
        $this->setState(Ur4LinkState::Connecting, $now);
    }

    private function beginHandshake(float $now): void
    {
        $this->absorbProtoStats();
        $this->proto = new ChainwayUr4Protocol($this->cfg->verifyXor);
        $this->ackAt = null;
        $this->lastHandshakeFrameAt = $now;
        $this->lastRxAt = $now;
        $this->setState(Ur4LinkState::Handshake, $now);
        $this->lastStopAt = $now;
        $this->send(ChainwayUr4Protocol::request(Ur4Command::StopInventory), $now);
    }

    private function tickHandshake(float $now): void
    {
        if ($this->ackAt === null) {
            if ($now - $this->stateSince >= $this->cfg->handshakeTimeout) {
                $this->deadOnArrival($now);

                return;
            }
            if ($now - $this->lastStopAt >= $this->cfg->handshakeResendEvery
                && $now - $this->stateSince <= $this->cfg->handshakeResendFor) {
                $this->lastStopAt = $now;
                $this->send(ChainwayUr4Protocol::request(Ur4Command::StopInventory), $now);
            }

            return;
        }

        if ($now - $this->lastHandshakeFrameAt >= $this->cfg->quietAfterAck) {
            $this->beginConfiguring($now);
        } elseif ($now - $this->ackAt >= $this->cfg->handshakeTimeout + 1.0) {
            $this->fail('handshake_noisy', $now);   // reader terus mengalirkan data setelah STOP
        }
    }

    /** Koneksi baru yang tak pernah menjawab STOP ("mati sejak lahir"): tutup dan coba lagi setelah jeda. */
    private function deadOnArrival(float $now): void
    {
        $this->closeStream();
        $this->counters['dead_on_arrival']++;
        $this->counters['reconnects']++;
        $this->doaStreak++;
        $this->lastError = 'dead_on_arrival';
        $this->log('warning', 'koneksi mati sejak lahir (STOP tak dijawab)');

        $delay = $this->doaStreak <= 3 ? $this->cfg->reconnectPause : $this->exponentialDelay();
        $this->enterWaiting(Ur4LinkState::Backoff, $now + $delay * $this->jitter());
    }

    // ─── Dispatch bingkai ─────────────────────────────────────────────────

    private function dispatch(Ur4Frame $frame, float $now): void
    {
        match ($this->state) {
            Ur4LinkState::Handshake => $this->onHandshakeFrame($frame, $now),
            Ur4LinkState::Configuring => $this->onConfigFrame($frame, $now),
            Ur4LinkState::Streaming => $this->onStreamFrame($frame, $now),
            default => null,
        };
    }

    private function onHandshakeFrame(Ur4Frame $frame, float $now): void
    {
        $this->lastHandshakeFrameAt = $now;
        if ($frame->cmd === ChainwayUr4Protocol::CMD_STOP_ACK && $this->ackAt === null) {
            $this->ackAt = $now;
            $this->doaStreak = 0;

            return;
        }
        if ($frame->isTagReport()) {
            $this->counters['handshake_dropped']++;   // sisa aliran sesi lama sebelum STOP bekerja
        }
    }

    private function onStreamFrame(Ur4Frame $frame, float $now): void
    {
        if ($frame->isTagReport()) {
            $tag = $this->proto->decodeFrame($frame);
            if ($tag !== null) {
                $this->lastTagAt = $now;
                $this->counters['tags_emitted']++;
                ($this->onTag)($tag);
            }

            return;
        }

        if ($frame->cmd === ChainwayUr4Protocol::CMD_STOP_ACK) {
            $this->rearm($now);
        }
    }

    /** Reader berhenti sendiri (ack STOP tak diminta saat streaming): mulai lagi, dengan batas per menit. */
    private function rearm(float $now): void
    {
        $this->rearmTimes = array_values(array_filter($this->rearmTimes, fn (float $t) => $now - $t < 60.0));
        if (count($this->rearmTimes) >= $this->cfg->rearmPerMinute) {
            $this->closeStream();
            $this->lastError = 'stalled';
            $this->log('warning', 'reader berhenti sendiri berulang kali');
            $this->enterWaiting(Ur4LinkState::Stalled, $now + $this->cfg->stalledRetry);

            return;
        }

        $this->rearmTimes[] = $now;
        $this->counters['rearms']++;
        $this->send(ChainwayUr4Protocol::request(Ur4Command::StartInventory), $now);
        $this->lastTxAt = $now;
    }

    private function tickStreaming(float $now): void
    {
        // UR4 tidak menjawab heartbeat dan diam bila tak ada tag, jadi keheningan BUKAN tanda mati. Yang mendeteksi
        // tautan direbut/putus adalah tulisan ini: soket yang direbut menjawab RST pada tulisan berikutnya.
        if ($now - $this->lastRxAt >= $this->cfg->idleHeartbeatAfter && $now - $this->lastTxAt >= $this->cfg->heartbeatInterval) {
            $this->lastTxAt = $now;
            $this->send("\x00", $now);
        }
    }

    // ─── Konfigurasi sebelum inventory ────────────────────────────────────

    private function beginConfiguring(float $now): void
    {
        $this->device = [];
        $this->warnings = [];
        $this->detail = null;
        $this->cfgIndex = -1;
        $this->setState(Ur4LinkState::Configuring, $now);
        $this->sendNextConfig($now);
    }

    private function sendNextConfig(float $now): void
    {
        $this->cfgIndex++;
        if ($this->cfgIndex >= count(self::CONFIG_STEPS)) {
            $this->evaluateConfig($now);

            return;
        }
        $this->cfgSentAt = $now;
        $this->send(ChainwayUr4Protocol::request(self::CONFIG_STEPS[$this->cfgIndex]), $now);
    }

    private function onConfigFrame(Ur4Frame $frame, float $now): void
    {
        $cmd = self::CONFIG_STEPS[$this->cfgIndex] ?? null;
        if ($cmd === null || $frame->cmd !== $cmd->replyCmd()) {
            return;   // ack STOP sisa handshake atau bingkai lain: abaikan
        }

        $parsed = match ($cmd) {
            Ur4Command::Version => ChainwayUr4Protocol::parseVersion($frame),
            Ur4Command::Power => ChainwayUr4Protocol::parsePower($frame),
            Ur4Command::Region => ChainwayUr4Protocol::parseRegion($frame),
            Ur4Command::AntennaMask, Ur4Command::AntennaConnection => ChainwayUr4Protocol::parseMask($frame, $cmd),
            Ur4Command::InventoryMode => ChainwayUr4Protocol::parseInventoryMode($frame),
            default => null,
        };
        if ($parsed === null) {
            $this->fail('config_malformed', $now);

            return;
        }

        $key = match ($cmd) {
            Ur4Command::Version => 'firmware',
            Ur4Command::Power => 'power',
            Ur4Command::Region => 'region',
            Ur4Command::AntennaMask => 'antenna_mask',
            Ur4Command::AntennaConnection => 'antenna_connection',
            default => 'inventory_mode',
        };
        $this->device[$key] = $cmd === Ur4Command::Version ? $parsed['text'] : $parsed;
        $this->sendNextConfig($now);
    }

    /** Tentukan aman-tidaknya memulai inventory. Inventory TIDAK dimulai bila ada pelanggaran. */
    private function evaluateConfig(float $now): void
    {
        $mode = $this->device['inventory_mode'] ?? null;
        if ($mode !== 0 && $mode !== 1) {
            $this->mismatch('inventory_mode_unsupported', "mode={$mode}", $now);

            return;
        }

        $active = ChainwayUr4Protocol::antennasInMask((int) ($this->device['antenna_mask'] ?? 0));
        if ($active === []) {
            $this->mismatch('no_active_antenna', null, $now);

            return;
        }
        if ($this->cfg->antennaMap !== [] && array_intersect($active, array_keys($this->cfg->antennaMap)) === []) {
            $this->mismatch('no_mapped_antenna', 'aktif=' . implode(',', $active), $now);

            return;
        }

        // Batas daya = nilai TERBESAR antara baca dan tulis pada antena aktif (dokumen tidak tegas mana yang dipakai inventory).
        $power = null;
        foreach ($active as $ant) {
            if (isset($this->device['power'][$ant])) {
                $p = max($this->device['power'][$ant]['read_dbm'], $this->device['power'][$ant]['write_dbm']);
                $power = $power === null ? $p : max($power, $p);
            }
        }
        $this->device['power_dbm_max'] = $power;

        if ($this->cfg->maxPowerDbm !== null) {
            if ($power === null) {
                $this->mismatch('power_unknown', 'batas daya diset tetapi daya reader tidak terbaca', $now);

                return;
            }
            if ($power > $this->cfg->maxPowerDbm) {
                $this->mismatch('power_exceeds_limit', "{$power} dBm > batas {$this->cfg->maxPowerDbm} dBm", $now);

                return;
            }
        }
        if ($power !== null && $power > $this->cfg->warnPowerDbm) {
            $this->warnings[] = 'power_above_warn';
        }

        // Makna 0x4E di unit nyata belum terbukti: hanya peringatan, bukan kegagalan.
        $connected = ChainwayUr4Protocol::antennasInMask((int) ($this->device['antenna_connection'] ?? 0));
        if (array_diff($active, $connected) !== []) {
            $this->warnings[] = 'antenna_maybe_disconnected';
        }

        $this->becomeStreaming($now);
    }

    private function mismatch(string $code, ?string $detail, float $now): void
    {
        $this->closeStream();
        $this->lastError = $code;
        $this->detail = $detail;
        $this->log('warning', "konfigurasi reader tidak sesuai: {$code}" . ($detail ? " ({$detail})" : ''));
        $this->enterWaiting(Ur4LinkState::ConfigMismatch, $now + $this->cfg->mismatchRetry);
    }

    private function becomeStreaming(float $now): void
    {
        $this->setState(Ur4LinkState::Streaming, $now);
        $this->backoffExp = 0;
        $this->lastError = null;
        $this->lastRxAt = $now;
        $this->lastTxAt = $now;
        $this->send(ChainwayUr4Protocol::request(Ur4Command::StartInventory), $now);
    }

    // ─── Kegagalan, backoff, kontestasi ───────────────────────────────────

    private function fail(string $code, float $now): void
    {
        $wasStreaming = $this->state === Ur4LinkState::Streaming;
        $this->closeStream();
        $this->lastError = $code;
        $this->counters['reconnects']++;
        $this->log('warning', "tautan gagal: {$code}");

        if ($wasStreaming && in_array($code, self::RESET_ERRORS, true)) {
            $this->resetTimes = array_values(array_filter($this->resetTimes, fn (float $t) => $now - $t < $this->cfg->contestedWindow));
            $this->resetTimes[] = $now;
            if (count($this->resetTimes) >= $this->cfg->contestedResets) {
                $this->counters['contested']++;
                $this->resetTimes = [];
                $this->log('warning', 'tautan direbut klien lain (reset berulang); jeda agar tidak saling rebut');
                $this->enterWaiting(Ur4LinkState::Contested, $now + $this->cfg->contestedBackoff);

                return;
            }
        }

        $this->enterWaiting(Ur4LinkState::Backoff, $now + $this->exponentialDelay() * $this->jitter());
    }

    private function exponentialDelay(): float
    {
        $delay = max($this->cfg->reconnectPause, (float) min(2 ** $this->backoffExp, $this->cfg->reconnectMax));
        $this->backoffExp = min($this->backoffExp + 1, 10);

        return $delay;
    }

    private function jitter(): float
    {
        return $this->jitter ? ($this->jitter)() : mt_rand(80, 120) / 100;
    }

    private function enterWaiting(Ur4LinkState $state, float $nextTry): void
    {
        $this->state = $state;
        $this->stateSince = ($this->now)();
        $this->nextTry = $nextTry;
    }

    private function setState(Ur4LinkState $state, float $now): void
    {
        $this->state = $state;
        $this->stateSince = $now;
    }

    // ─── Soket ────────────────────────────────────────────────────────────

    private function send(string $bytes, float $now): bool
    {
        if (! $this->stream) {
            return false;
        }
        if (@fwrite($this->stream, $bytes) === false) {
            $this->fail('write_failed', $now);

            return false;
        }

        return true;
    }

    private function closeStream(): void
    {
        if ($this->stream) {
            @fclose($this->stream);
        }
        $this->stream = null;
    }

    private function absorbProtoStats(): void
    {
        foreach ($this->proto->stats() as $k => $v) {
            $this->protoTotals[$k] = ($this->protoTotals[$k] ?? 0) + $v;
        }
    }

    private function log(string $level, string $message): void
    {
        if ($this->log) {
            ($this->log)($level, "{$this->host}:{$this->port} {$message}");
        }
    }
}
