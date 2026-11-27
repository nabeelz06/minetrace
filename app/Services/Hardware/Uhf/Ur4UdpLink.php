<?php

namespace App\Services\Hardware\Uhf;

use Closure;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Ur4UdpLink — penerima untuk reader UR4 mode "Destination IP" (push UDP).
 *
 * Pada mode ini reader (work mode 1 = auto, WorkModePara[5] = 0x01 UDP) mengirim setiap pembacaan tag
 * sebagai datagram UDP ke IP:port tujuan yang diset di reader. Bingkainya SAMA dengan laporan tag 0x83
 * pada TCP, jadi diurai dengan ChainwayUr4Protocol. Tidak ada koneksi, handshake, atau perintah keluar:
 * server tidak perlu bisa menjangkau LAN reader (tidak butuh subnet router di site).
 *
 * Satu port per reader: di balik NAT site semua reader berbagi satu IP publik, jadi reader dibedakan
 * dari port tujuan. Sumber dibatasi rfid.uhf.udp_allowed_cidrs (UDP tanpa enkripsi dan bisa dipalsukan).
 *
 * Antarmuka sengaja sama dengan bagian Ur4Link yang dipakai UhfGate (tick/poll/status/stop).
 */
final class Ur4UdpLink
{
    /** Datagram maksimum yang dibaca per onReadable() agar satu reader ramai tidak memonopoli loop. */
    private const MAX_PER_READ = 256;

    private const REBIND_EVERY = 5.0;

    /** @var resource|null */
    private $socket = null;

    private float $nextBind = 0.0;

    private ?string $lastError = null;

    private string $detail = '';

    private ?float $lastPacketAt = null;

    private ?float $lastTagAt = null;

    private ?string $peer = null;

    /** @var array<string,int> */
    private array $counters = [
        'datagrams' => 0,
        'tags' => 0,
        'rejected_source' => 0,
        'non_tag_frames' => 0,
        'bad_datagrams' => 0,
        'bind_failures' => 0,
    ];

    /**
     * @param  list<string>  $allowedCidrs  kosong = semua sumber diterima
     * @param  Closure():float  $now
     * @param  Closure(array):void  $onTag  tag terurai: epc, tid, rssi, antenna
     * @param  Closure(string,int):mixed|null  $binder  pembuat soket UDP (bawaan: stream_socket_server udp://)
     * @param  Closure(string,string):void|null  $log
     */
    public function __construct(
        private readonly int $port,
        private readonly string $bind,
        private readonly array $allowedCidrs,
        private readonly float $freshSeconds,
        private readonly Closure $now,
        private readonly Closure $onTag,
        private readonly ?Closure $binder = null,
        private readonly ?Closure $log = null,
    ) {}

    public static function udpBinder(): Closure
    {
        return function (string $bind, int $port) {
            $s = @stream_socket_server("udp://{$bind}:{$port}", $errno, $errstr, STREAM_SERVER_BIND);
            if (! $s) {
                return null;
            }
            stream_set_blocking($s, false);

            return $s;
        };
    }

    public function port(): int
    {
        return $this->port;
    }

    /** IP sumber datagram sah terakhir (untuk last_ip / meta). */
    public function peer(): ?string
    {
        return $this->peer;
    }

    public function tick(): void
    {
        $now = ($this->now)();
        if ($this->socket || $now < $this->nextBind) {
            return;
        }
        $this->socket = ($this->binder ?? self::udpBinder())($this->bind, $this->port) ?: null;
        if (! $this->socket) {
            $this->counters['bind_failures']++;
            $this->nextBind = $now + self::REBIND_EVERY;
            $this->fail('bind_failed', "gagal membuka UDP {$this->bind}:{$this->port}");

            return;
        }
        $this->lastError = null;
        $this->detail = "menunggu datagram di UDP {$this->port}";
        $this->say('info', "mendengar UDP {$this->bind}:{$this->port}");
    }

    /** @return resource|null */
    public function wantsRead()
    {
        return $this->socket;
    }

    /** UDP tidak pernah menulis. */
    public function wantsWrite()
    {
        return null;
    }

    public function onWritable(): void {}

    public function onReadable(): void
    {
        if (! $this->socket) {
            return;
        }
        for ($i = 0; $i < self::MAX_PER_READ; $i++) {
            $peer = '';
            $data = @stream_socket_recvfrom($this->socket, 4096, 0, $peer);
            if ($data === false || $data === '') {
                break;
            }
            $this->receive($data, (string) $peer);
        }
    }

    /** Proses satu datagram. Publik agar bisa diuji tanpa soket. */
    public function receive(string $data, string $peer): void
    {
        $ip = self::hostOf($peer);
        if ($this->allowedCidrs && ($ip === '' || ! IpUtils::checkIp($ip, $this->allowedCidrs))) {
            $this->counters['rejected_source']++;
            if ($this->counters['rejected_source'] === 1 || $this->counters['rejected_source'] % 100 === 0) {
                $this->say('warning', "datagram dari {$peer} ditolak (di luar udp_allowed_cidrs), total {$this->counters['rejected_source']}");
            }

            return;
        }

        $now = ($this->now)();
        $this->counters['datagrams']++;
        $this->lastPacketAt = $now;
        $this->peer = $ip;

        // Satu datagram = bingkai utuh; parser baru per datagram agar sisa byte tidak menyambung antar paket.
        $proto = new ChainwayUr4Protocol;
        $frames = $proto->feedFrames($data);
        if (! $frames) {
            $this->counters['bad_datagrams']++;

            return;
        }
        foreach ($frames as $frame) {
            $tag = $proto->decodeFrame($frame);
            if (! $tag) {
                $this->counters['non_tag_frames']++;

                continue;
            }
            $this->counters['tags']++;
            $this->lastTagAt = $now;
            ($this->onTag)($tag);
        }
    }

    public function nextWake(): float
    {
        if ($this->socket) {
            return 1.0;
        }

        return max(0.0, $this->nextBind - ($this->now)());
    }

    public function stop(): void
    {
        if ($this->socket) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    public function uiState(): string
    {
        if (! $this->socket) {
            return 'config_mismatch';
        }

        return $this->isFresh() ? 'streaming' : 'listening';
    }

    /** Ringkasan untuk rfid_readers.last_status (bentuk sama dengan Ur4Link::status()). */
    public function status(): array
    {
        $state = $this->uiState();
        $detail = $this->detail;
        if ($state === 'listening') {
            $detail = $this->lastPacketAt === null
                ? "UDP {$this->port} siap; belum ada datagram dari reader"
                : "UDP {$this->port} siap; tidak ada datagram > {$this->freshSeconds} dtk (reader mode auto hanya mengirim saat ada tag)";
        }

        return [
            'link_state' => $state,
            'state' => 'udp_push',
            'state_since' => null,
            'last_error' => $this->lastError,
            'detail' => $detail,
            'last_frame_at' => $this->lastPacketAt,
            'last_tag_at' => $this->lastTagAt,
            'counters' => $this->counters,
            'device' => ['transport' => 'udp', 'port' => $this->port, 'peer' => $this->peer],
            'warnings' => [],
        ];
    }

    private function isFresh(): bool
    {
        return $this->lastPacketAt !== null && ($this->now)() - $this->lastPacketAt <= $this->freshSeconds;
    }

    private function fail(string $error, string $detail): void
    {
        if ($this->lastError !== $error) {
            $this->say('warning', $detail);
        }
        $this->lastError = $error;
        $this->detail = $detail;
    }

    private function say(string $level, string $message): void
    {
        if ($this->log) {
            ($this->log)($level, $message);
        }
    }

    /** "1.2.3.4:5678" atau "[::1]:5678" → host. */
    private static function hostOf(string $peer): string
    {
        if ($peer === '') {
            return '';
        }
        if (str_starts_with($peer, '[')) {
            return substr($peer, 1, (int) strpos($peer, ']') - 1);
        }
        $pos = strrpos($peer, ':');

        return $pos === false ? $peer : substr($peer, 0, $pos);
    }
}
