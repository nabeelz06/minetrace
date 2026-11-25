<?php

namespace App\Services\Hardware\Uhf;

use Closure;

/**
 * Ur4InboundListener — soket server TCP untuk reader UR4 dalam mode "TCP Client".
 *
 * Dalam mode ini reader yang menyambung ke server (Remote IP/Port di reader), sehingga reader di belakang
 * CGNAT tetap terjangkau tanpa VPN. Satu port dengar per reader: semua reader di satu site keluar dengan IP
 * publik yang sama, jadi port tujuan yang membedakan reader.
 *
 * connector($port) mengembalikan Closure dengan bentuk yang sama dengan Ur4Link::tcpConnector(): soket
 * tersambung (resource) atau false bila belum ada reader yang menyambung. Tidak memblokir.
 *
 * Pengaman: sumber koneksi dapat dibatasi dengan rfid.uhf.inbound_allowed_cidrs (kosong = semua sumber).
 * Bila beberapa koneksi menunggu sekaligus, yang TERBARU dipakai (reader yang menyambung ulang) dan sisanya ditutup.
 */
final class Ur4InboundListener
{
    /** @var array<int,resource> */
    private array $servers = [];

    /** @var array<int,string> alamat sumber koneksi terakhir yang diterima, per port */
    private array $peers = [];

    /**
     * @param  list<string>  $allowedCidrs  sumber yang diizinkan (kosong = semua)
     * @param  Closure(string,string):void|null  $log  level, pesan
     */
    public function __construct(
        private readonly string $bindHost = '0.0.0.0',
        private readonly array $allowedCidrs = [],
        private readonly ?Closure $log = null,
    ) {}

    public static function fromConfig(?Closure $log = null): self
    {
        return new self(
            (string) config('rfid.uhf.inbound_bind', '0.0.0.0'),
            (array) config('rfid.uhf.inbound_allowed_cidrs', []),
            $log,
        );
    }

    /** @return Closure(string,int):mixed */
    public function connector(int $port): Closure
    {
        return fn (string $host, int $ignored) => $this->accept($port);
    }

    /** @return resource|false */
    public function accept(int $port)
    {
        $server = $this->server($port);
        if (! $server) {
            return false;
        }

        $chosen = null;
        $chosenPeer = null;
        // Kuras antrean: koneksi terbaru menang, koneksi lama (reader yang sudah menyambung ulang) ditutup.
        for ($i = 0; $i < 16; $i++) {
            $r = [$server];
            $w = $e = null;
            if (! @stream_select($r, $w, $e, 0, 0)) {
                break;
            }
            $sock = @stream_socket_accept($server, 0, $peer);
            if (! $sock) {
                break;
            }
            $ip = self::peerIp((string) $peer);
            if (! $this->allowed($ip)) {
                $this->say('warning', "port {$port}: koneksi dari {$ip} ditolak (di luar inbound_allowed_cidrs)");
                @fclose($sock);

                continue;
            }
            if ($chosen) {
                @fclose($chosen);
            }
            $chosen = $sock;
            $chosenPeer = $ip;
        }

        if (! $chosen) {
            return false;
        }
        stream_set_blocking($chosen, false);
        $this->peers[$port] = $chosenPeer;
        $this->say('info', "port {$port}: reader tersambung dari {$chosenPeer}");

        return $chosen;
    }

    public function peer(int $port): ?string
    {
        return $this->peers[$port] ?? null;
    }

    /** Tutup port yang tidak lagi dipakai reader mana pun. */
    public function retain(array $ports): void
    {
        foreach (array_keys($this->servers) as $port) {
            if (! in_array($port, $ports, true)) {
                @fclose($this->servers[$port]);
                unset($this->servers[$port], $this->peers[$port]);
            }
        }
    }

    public function close(): void
    {
        $this->retain([]);
    }

    /** @return resource|null */
    private function server(int $port)
    {
        if (isset($this->servers[$port])) {
            return $this->servers[$port];
        }
        $s = @stream_socket_server("tcp://{$this->bindHost}:{$port}", $errno, $errstr);
        if (! $s) {
            $this->say('warning', "port {$port}: gagal membuka soket dengar ({$errstr})");

            return null;
        }
        stream_set_blocking($s, false);
        $this->say('info', "mendengarkan reader TCP Client di {$this->bindHost}:{$port}");

        return $this->servers[$port] = $s;
    }

    private function allowed(string $ip): bool
    {
        if (! $this->allowedCidrs) {
            return true;
        }
        foreach ($this->allowedCidrs as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        [$net, $bits] = array_pad(explode('/', trim($cidr), 2), 2, null);
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton((string) $net);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $bits = $bits === null ? strlen($ipBin) * 8 : (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }

    private static function peerIp(string $peer): string
    {
        // "1.2.3.4:5678" atau "[::1]:5678"
        if (str_starts_with($peer, '[')) {
            return trim(substr($peer, 1, (int) strpos($peer, ']') - 1));
        }
        $pos = strrpos($peer, ':');

        return $pos === false ? $peer : substr($peer, 0, $pos);
    }

    private function say(string $level, string $message): void
    {
        if ($this->log) {
            ($this->log)($level, "TCP Client {$message}");
        }
    }
}
