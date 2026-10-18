<?php

namespace Tests\Support;

/**
 * "Reader UR4" palsu di ujung lain pasangan soket: membaca permintaan tautan dengan parser sederhana sendiri,
 * mencatatnya, dan menjawab sesuai perilaku yang terdokumentasi (ack STOP 8D, balasan konfigurasi cmd+1).
 * Opsi menyalakan perilaku bermasalah: tak menjawab STOP (mati sejak lahir), daya tinggi, mode salah, dll.
 */
final class FakeUr4Peer
{
    /** @var list<int> cmd permintaan yang diterima (urut) */
    public array $commands = [];

    public int $heartbeats = 0;

    /** @var list<string> bingkai tulis-konfigurasi yang diterima (hex) */
    public array $writes = [];

    private string $buf = '';

    private bool $closed = false;

    public function __construct(
        public $stream,
        public bool $ackStop = true,
        public float $powerDbm = 15.0,
        public int $antennaMask = 0b0001,
        public int $antennaConn = 0b0001,
        public int $inventoryMode = 0,
        public int $region = 0x3F,
        public bool $answerConfig = true,
    ) {
        stream_set_blocking($stream, false);
    }

    /** Baca semua yang ditulis tautan dan jawab. */
    public function service(): void
    {
        if ($this->closed) {
            return;
        }
        $data = @fread($this->stream, 8192);
        if ($data !== false && $data !== '') {
            $this->buf .= $data;
        }

        while ($this->buf !== '') {
            if ($this->buf[0] === "\x00") {          // heartbeat byte tunggal
                $this->heartbeats++;
                $this->buf = substr($this->buf, 1);

                continue;
            }
            if (strlen($this->buf) < 4) {
                return;
            }
            $len = unpack('n', substr($this->buf, 2, 2))[1];
            if (strlen($this->buf) < $len) {
                return;
            }
            $cmd = ord($this->buf[4]);
            $raw = substr($this->buf, 0, $len);
            $this->buf = substr($this->buf, $len);
            $this->commands[] = $cmd;
            if (in_array($cmd, [0x10, 0x2C, 0x70, 0x28], true)) {
                $this->writes[] = strtoupper(bin2hex($raw));
            }
            $this->reply($cmd);
        }
    }

    private function reply(int $cmd): void
    {
        $send = fn (int $c, string $p) => @fwrite($this->stream, Ur4Frames::build($c, $p));

        match ($cmd) {
            0x8C => $this->ackStop ? $send(0x8D, "\x01") : null,
            0x02 => $this->answerConfig ? $send(0x03, "\x07\x28\x01") : null,
            0x12 => $this->answerConfig ? $send(0x13, $this->powerPayload()) : null,
            0x2E => $this->answerConfig ? $send(0x2F, "\x01".chr($this->region)) : null,
            0x2A => $this->answerConfig ? $send(0x2B, pack('n', $this->antennaMask)) : null,
            0x4E => $this->answerConfig ? $send(0x4F, pack('n', $this->antennaConn)) : null,
            0x72 => $this->answerConfig ? $send(0x73, "\x01".chr($this->inventoryMode)."\x00\x00\x00\x00\x00\x00") : null,
            0x26 => $send(0x27, "\x01\x10\x02\x00\x03\x00\x04\x00"),
            0x10, 0x2C, 0x70, 0x28 => $send($cmd + 1, "\x01"),
            default => null,   // 0x82 start: tanpa ack, langsung aliran
        };
    }

    private function powerPayload(): string
    {
        $centi = (int) round($this->powerDbm * 100);
        $p = "\x00";
        for ($ant = 1; $ant <= 4; $ant++) {
            $p .= chr($ant).pack('nn', $centi, $centi);
        }

        return $p;
    }

    public function send(string $bytes): void
    {
        if ($this->closed) {
            return;
        }
        @fwrite($this->stream, $bytes);
    }

    public function close(): void
    {
        if (! $this->closed) {
            @fclose($this->stream);
        }
        $this->closed = true;
    }

    public function sawCommand(int $cmd): bool
    {
        return in_array($cmd, $this->commands, true);
    }
}
