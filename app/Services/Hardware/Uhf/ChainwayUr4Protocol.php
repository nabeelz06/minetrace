<?php

namespace App\Services\Hardware\Uhf;

use InvalidArgumentException;

/**
 * Protokol TCP/serial Chainway UR4 (port TCP bawaan 8888). Kelas murni: tanpa I/O, tanpa jam, tanpa Laravel.
 *
 * Bingkai (dua arah):
 *
 *     Head(2)  Len(2, big-endian = ukuran TOTAL bingkai, sah 8..2048)  Cmd(1)  Payload(Len-8)  XOR(1)  0D 0A
 *
 *   Head = A5 5A atau C8 8C (permintaan memakai A5 5A; keduanya diterima).
 *   XOR  = XOR byte dari Len sampai akhir Payload. Balasan: Cmd permintaan + 1.
 *   Ekor TIDAK dicari lewat pembatas: payload tag boleh memuat 0D 0A, jadi bingkai dipotong menurut Len.
 *
 * Rekaman tag (Cmd 0x83, didorong reader selama inventory):
 *
 *     PC(2)  EPC((PC[0]>>3)*2 byte)  [TID(12)]  RSSI(2, int16 big-endian, dBm x 10)  ANT(1, mulai dari 1)
 *
 * Dekode KETAT: sisa setelah EPC harus tepat 3 byte (RSSI+ANT) atau 15 byte (TID+RSSI+ANT). Selain itu
 * (mis. mode inventory dengan fase/frekuensi atau USER memory) rekaman dianggap tak terurai dan DIHITUNG,
 * tidak ditebak. Menebak EPC yang salah membuat tag sah tercatat sebagai "tag asing" + capture CCTV palsu.
 *
 * Asal fakta: dokumen protokol hasil rekayasa balik (chainway-serial docs/protocol.md), diuji hidup pada satu
 * unit UR4. Verifikasi ulang pada unit sendiri di Fase 3; vektor emas independen di tests/Fixtures/uhf/ur4.
 */
final class ChainwayUr4Protocol
{
    public const CMD_TAG_REPORT = 0x83;

    public const CMD_STOP_ACK = 0x8D;

    public const MIN_FRAME = 8;

    public const MAX_FRAME = 2048;

    /** Alias kompatibilitas: payload start baku firmware = 00 00 (27 10 hanya kebiasaan klien npm pihak ketiga). */
    public const START_INVENTORY = "\xA5\x5A\x00\x0A\x82\x00\x00\x88\x0D\x0A";

    public const STOP_INVENTORY = "\xA5\x5A\x00\x08\x8C\x84\x0D\x0A";

    private const HEADERS = ["\xA5\x5A", "\xC8\x8C"];

    private const TID_LEN = 12;

    private const MAX_EPC_BYTES = 25;

    private string $buffer = '';

    /** @var array<string,int> */
    private array $stats = [
        'frames_ok' => 0,
        'frames_bad' => 0,
        'resyncs' => 0,
        'bytes_discarded' => 0,
        'idle_bytes' => 0,
        'tags_ok' => 0,
        'unparseable' => 0,
        'epc_too_long' => 0,
        'buffer_overflows' => 0,
    ];

    public function __construct(private readonly bool $verifyXor = true) {}

    // ─── Penerimaan ───────────────────────────────────────────────────────

    /**
     * Masukkan potongan byte dari soket; kembalikan SEMUA bingkai sah (tag, ack, balasan konfigurasi).
     * Byte 00 tunggal di luar bingkai adalah heartbeat SDK vendor: ditoleransi tanpa dihitung sebagai rusak.
     *
     * @return list<Ur4Frame>
     */
    public function feedFrames(string $bytes): array
    {
        $this->buffer .= $bytes;
        $frames = [];

        while (true) {
            $start = $this->findHeader();
            if ($start === null) {
                // Simpan byte terakhir saja: bisa jadi separuh header yang terpotong.
                if (strlen($this->buffer) > 1) {
                    $this->discardLeading(strlen($this->buffer) - 1);
                }
                break;
            }
            if ($start > 0) {
                $this->discardLeading($start);
            }
            if (strlen($this->buffer) < 4) {
                break;
            }

            $len = unpack('n', substr($this->buffer, 2, 2))[1];
            if ($len < self::MIN_FRAME || $len > self::MAX_FRAME) {
                $this->rejectOneByte();   // header palsu: sinkron ulang

                continue;
            }
            if (strlen($this->buffer) < $len) {
                break;                    // tunggu sisa bingkai
            }

            $raw = substr($this->buffer, 0, $len);
            if (substr($raw, -2) !== "\r\n" || ($this->verifyXor && ! $this->xorValid($raw))) {
                $this->rejectOneByte();

                continue;
            }

            $this->buffer = substr($this->buffer, $len);
            $this->stats['frames_ok']++;
            $frames[] = new Ur4Frame(substr($raw, 0, 2), ord($raw[4]), substr($raw, 5, $len - 8));
        }

        if (strlen($this->buffer) > self::MAX_FRAME * 4) {
            $this->buffer = '';       // pengaman: aliran sampah tanpa header sah
            $this->stats['buffer_overflows']++;
        }

        return $frames;
    }

    /**
     * Seperti feedFrames, tetapi hanya mengembalikan tag yang terurai.
     *
     * @return list<array{epc:string, tid:?string, rssi:?int, antenna:int}>
     */
    public function feed(string $bytes): array
    {
        $tags = [];
        foreach ($this->feedFrames($bytes) as $frame) {
            if ($tag = $this->decodeFrame($frame)) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    /** Urai rekaman tag dari satu bingkai (menghitung unparseable / epc_too_long). Null bila bukan tag atau tak terurai. */
    public function decodeFrame(Ur4Frame $frame): ?array
    {
        if (! $frame->isTagReport()) {
            return null;
        }

        $tag = self::decodeTag($frame->payload, $reason);
        if ($tag === null) {
            $this->stats[$reason === 'epc_too_long' ? 'epc_too_long' : 'unparseable']++;

            return null;
        }
        $this->stats['tags_ok']++;

        return $tag;
    }

    /** @return array<string,int> */
    public function stats(): array
    {
        return $this->stats;
    }

    /**
     * Dekode ketat satu rekaman 0x83. $reason: 'unparseable' | 'epc_too_long' bila hasil null.
     *
     * @return array{epc:string, tid:?string, rssi:?int, antenna:int}|null
     */
    public static function decodeTag(string $payload, ?string &$reason = null): ?array
    {
        $reason = null;
        $n = strlen($payload);
        if ($n < 5) {
            $reason = 'unparseable';

            return null;
        }

        $pc0 = ord($payload[0]);
        $epcLen = ($pc0 >> 3) * 2;
        if ($epcLen === 0 || 2 + $epcLen > $n || ($pc0 & 0x02) !== 0) {   // 0x02 = XI: tag ber-XPC tidak didukung
            $reason = 'unparseable';

            return null;
        }
        if ($epcLen > self::MAX_EPC_BYTES) {
            $reason = 'epc_too_long';

            return null;
        }

        $rest = $n - 2 - $epcLen;
        if ($rest !== 3 && $rest !== 3 + self::TID_LEN) {
            $reason = 'unparseable';

            return null;
        }

        $epc = UhfTagIdentity::fromBytes(substr($payload, 2, $epcLen));
        if ($epc === null) {
            $reason = 'unparseable';

            return null;
        }
        $tid = $rest === 3 + self::TID_LEN ? strtoupper(bin2hex(substr($payload, 2 + $epcLen, self::TID_LEN))) : null;

        $raw = unpack('n', substr($payload, $n - 3, 2))[1];
        $dbm = ($raw >= 0x8000 ? $raw - 0x10000 : $raw) / 10;   // int16 bertanda, satuan 0,1 dBm

        return [
            'epc' => $epc,
            'tid' => $tid,
            'rssi' => ($dbm < 0 && $dbm > -200) ? (int) round($dbm) : null,
            'antenna' => ord($payload[$n - 1]),
        ];
    }

    // ─── Permintaan (HANYA dari daftar izin) ──────────────────────────────

    /**
     * Bingkai permintaan baca-saja. Payload tidak bisa diberikan pemanggil: ditentukan per perintah.
     * Perintah tulis konfigurasi ditolak di sini; gunakan setPower() dan kawan-kawannya (selalu save = 0).
     */
    public static function request(Ur4Command $cmd): string
    {
        if ($cmd->isConfigWrite()) {
            throw new InvalidArgumentException("Perintah tulis {$cmd->name} hanya lewat builder set* yang menjamin save = 0.");
        }

        $payload = match ($cmd) {
            Ur4Command::InventoryMode, Ur4Command::StartInventory => "\x00\x00",
            default => '',
        };

        return self::build($cmd, $payload);
    }

    /** Daya satu antena (dBm, 5..30). Byte pertama payload = save = 0: kembali ke nilai tersimpan saat power-cycle. */
    public static function setPower(int $antenna, float $dbm): string
    {
        if ($antenna < 1 || $antenna > 4 || $dbm < 5 || $dbm > 30) {
            throw new InvalidArgumentException('Antena harus 1..4 dan daya 5..30 dBm.');
        }
        $centi = (int) round($dbm * 100);

        return self::build(Ur4Command::SetPower, "\x00".chr($antenna).pack('nn', $centi, $centi));
    }

    public static function setRegion(int $region): string
    {
        if ($region < 0 || $region > 0xFF) {
            throw new InvalidArgumentException('Kode region tidak sah.');
        }

        return self::build(Ur4Command::SetRegion, "\x00".chr($region));
    }

    /** Mode inventory 0 = EPC, 1 = EPC+TID. Mode 2 (USER) tidak didukung dekoder, jadi tidak ada builder-nya. */
    public static function setInventoryMode(int $mode): string
    {
        if ($mode !== 0 && $mode !== 1) {
            throw new InvalidArgumentException('Mode inventory hanya 0 (EPC) atau 1 (EPC+TID).');
        }

        return self::build(Ur4Command::SetInventoryMode, "\x00".chr($mode)."\x00\x00");
    }

    /** Mask antena aktif (bit 0 = ANT1). */
    public static function setAntennaMask(int $mask): string
    {
        if ($mask < 1 || $mask > 0xFFFF) {
            throw new InvalidArgumentException('Mask antena harus berisi minimal satu antena.');
        }

        return self::build(Ur4Command::SetAntennaMask, "\x00".pack('n', $mask));
    }

    // ─── Penguraian balasan (toleran terhadap byte tambahan firmware) ─────

    /** @return array{major:int,minor:int,patch:int,text:string}|null */
    public static function parseVersion(Ur4Frame $f): ?array
    {
        if ($f->cmd !== Ur4Command::Version->replyCmd() || strlen($f->payload) < 3) {
            return null;
        }
        [$a, $b, $c] = array_values(unpack('C3', $f->payload));

        return ['major' => $a, 'minor' => $b, 'patch' => $c, 'text' => "{$a}.{$b}.{$c}"];
    }

    /**
     * Daya per antena. Satuan centi-dBm; untuk batas keselamatan pakai nilai TERBESAR antara baca dan tulis.
     *
     * @return array<int,array{read_dbm:float,write_dbm:float}>|null
     */
    public static function parsePower(Ur4Frame $f): ?array
    {
        if ($f->cmd !== Ur4Command::Power->replyCmd() || strlen($f->payload) < 6 || (strlen($f->payload) - 1) % 5 !== 0) {
            return null;
        }
        $out = [];
        for ($i = 1; $i + 5 <= strlen($f->payload); $i += 5) {
            $row = unpack('Cant/nread/nwrite', substr($f->payload, $i, 5));
            $out[$row['ant']] = ['read_dbm' => $row['read'] / 100.0, 'write_dbm' => $row['write'] / 100.0];
        }

        return $out;
    }

    public static function parseRegion(Ur4Frame $f): ?int
    {
        if ($f->cmd !== Ur4Command::Region->replyCmd() || strlen($f->payload) < 2) {
            return null;
        }

        return ord($f->payload[1]);
    }

    /** Mask 16-bit (antena aktif untuk inventory, atau antena tersambung); bit 0 = ANT1. */
    public static function parseMask(Ur4Frame $f, Ur4Command $of): ?int
    {
        if (! in_array($of, [Ur4Command::AntennaMask, Ur4Command::AntennaConnection], true)
            || $f->cmd !== $of->replyCmd() || strlen($f->payload) < 2) {
            return null;
        }

        return unpack('n', substr($f->payload, 0, 2))[1];
    }

    /** Mode inventory: baca hanya byte 0..1 (fw 7.40.1 menambah 4 byte nol). */
    public static function parseInventoryMode(Ur4Frame $f): ?int
    {
        if ($f->cmd !== Ur4Command::InventoryMode->replyCmd() || strlen($f->payload) < 2) {
            return null;
        }

        return ord($f->payload[1]);
    }

    /**
     * Return loss per port (dB): 1-2 dB = konektor terbuka, 10-16 dB = antena terpasang.
     *
     * @return array<int,int>|null
     */
    public static function parseReturnLoss(Ur4Frame $f): ?array
    {
        if ($f->cmd !== Ur4Command::ReturnLoss->replyCmd() || strlen($f->payload) < 2) {
            return null;
        }
        $out = [];
        for ($i = 0; $i + 1 < strlen($f->payload); $i += 2) {
            $out[ord($f->payload[$i])] = ord($f->payload[$i + 1]);
        }

        return $out;
    }

    /** Indeks antena (1..16) yang aktif pada mask. @return list<int> */
    public static function antennasInMask(int $mask): array
    {
        $out = [];
        for ($bit = 0; $bit < 16; $bit++) {
            if ($mask & (1 << $bit)) {
                $out[] = $bit + 1;
            }
        }

        return $out;
    }

    // ─── Internal ─────────────────────────────────────────────────────────

    /** Satu-satunya pembangun bingkai; PRIVAT sehingga hanya enum daftar izin yang dapat mencapainya. */
    private static function build(Ur4Command $cmd, string $payload): string
    {
        $body = pack('n', strlen($payload) + 8).chr($cmd->value).$payload;

        return "\xA5\x5A".$body.chr(self::xorOf($body))."\r\n";
    }

    private static function xorOf(string $bytes): int
    {
        $x = 0;
        for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
            $x ^= ord($bytes[$i]);
        }

        return $x;
    }

    /** XOR atas Len..Payload (indeks 2 sampai len-4) dibandingkan byte di indeks len-3. */
    private function xorValid(string $raw): bool
    {
        $len = strlen($raw);

        return self::xorOf(substr($raw, 2, $len - 5)) === ord($raw[$len - 3]);
    }

    private function findHeader(): ?int
    {
        $best = null;
        foreach (self::HEADERS as $h) {
            $p = strpos($this->buffer, $h);
            if ($p !== false && ($best === null || $p < $best)) {
                $best = $p;
            }
        }

        return $best;
    }

    /** Buang $n byte di depan: 00 = heartbeat/idle (tidak dihitung rusak), selain itu sampah. */
    private function discardLeading(int $n): void
    {
        $chunk = substr($this->buffer, 0, $n);
        $garbage = strlen(str_replace("\x00", '', $chunk));
        $this->stats['idle_bytes'] += strlen($chunk) - $garbage;
        if ($garbage > 0) {
            $this->stats['bytes_discarded'] += $garbage;
            $this->stats['resyncs']++;
        }
        $this->buffer = substr($this->buffer, $n);
    }

    /** Bingkai rusak (panjang/ekor/XOR): buang satu byte lalu cari header berikutnya. */
    private function rejectOneByte(): void
    {
        $this->stats['frames_bad']++;
        $this->stats['bytes_discarded']++;
        $this->stats['resyncs']++;
        $this->buffer = substr($this->buffer, 1);
    }
}
