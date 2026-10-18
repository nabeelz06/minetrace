<?php

namespace App\Services\Hardware\Uhf;

/**
 * Protokol TCP Chainway UR4 (port default 8888) — diturunkan dari
 * https://github.com/ready2tag/chainway-rfid (src/module/tcpUhf.ts).
 *
 * Bingkai (dua arah):
 *
 *     Head(2)  Len(2, big-endian = panjang SELURUH bingkai)  Cmd(1)  Data…  XOR(1)  0D 0A
 *
 *   Head = A5 5A (perintah dan respons).
 *   XOR  = XOR semua byte dari Len s.d. akhir Data.
 *   Contoh terverifikasi: start inventory  A5 5A 00 0A 82 27 10 BF 0D 0A  (00^0A^82^27^10 = BF)
 *                         stop inventory   A5 5A 00 08 8C 84 0D 0A        (00^08^8C = 84)
 *   Pustaka referensi mengirim stop dengan header C8 8C — sisa obfuskasinya, bukan header protokol;
 *   UR4 mengabaikan bingkai itu sehingga inventory tak pernah berhenti. Parser tetap menerima C8 8C.
 *
 * Laporan tag = Cmd 0x83, Data = [PC(2)] EPC(n) [TID(12)] RSSI(2) ANT(1).
 * Pustaka referensi menganggap Data = EPC + TID(12) + RSSI(2) + ANT(1). Di sini PC dilepas bila
 * panjangnya konsisten dengan jumlah word EPC di PC (PC >> 11), dan TID dianggap opsional —
 * sehingga perangkat dengan maupun tanpa mode TID terbaca benar.
 *
 * Kelas ini murni (tanpa I/O) agar dapat diuji; soket dikelola perintah rfid:uhf-gate.
 */
final class ChainwayUr4Protocol
{
    public const START_INVENTORY = "\xA5\x5A\x00\x0A\x82\x27\x10\xBF\x0D\x0A";
    public const STOP_INVENTORY  = "\xA5\x5A\x00\x08\x8C\x84\x0D\x0A";

    public const CMD_TAG_REPORT = 0x83;

    private const HEADERS   = ["\xA5\x5A", "\xC8\x8C"];
    private const MIN_FRAME = 8;
    private const MAX_FRAME = 1024;
    private const TID_LEN   = 12;

    private string $buffer = '';

    /**
     * Masukkan potongan byte dari soket; kembalikan tag yang lengkap terbaca.
     *
     * @return list<array{epc:string, tid:?string, rssi:?int, antenna:?int}>
     */
    public function feed(string $bytes): array
    {
        $this->buffer .= $bytes;
        $tags = [];

        while (true) {
            $start = $this->findHeader();
            if ($start === null) {
                // Simpan byte terakhir: bisa jadi separuh header yang terpotong.
                $this->buffer = substr($this->buffer, -1);
                break;
            }
            if ($start > 0) {
                $this->buffer = substr($this->buffer, $start);
            }
            if (strlen($this->buffer) < 4) {
                break;
            }

            $len = unpack('n', substr($this->buffer, 2, 2))[1];
            if ($len < self::MIN_FRAME || $len > self::MAX_FRAME) {
                $this->buffer = substr($this->buffer, 1);   // header palsu — sinkron ulang
                continue;
            }
            if (strlen($this->buffer) < $len) {
                break;                                       // tunggu sisa bingkai
            }

            $frame = substr($this->buffer, 0, $len);
            if (substr($frame, -2) !== "\r\n") {
                $this->buffer = substr($this->buffer, 1);
                continue;
            }
            $this->buffer = substr($this->buffer, $len);

            if (ord($frame[4]) === self::CMD_TAG_REPORT && ($tag = self::decodeTag(substr($frame, 5, $len - 8)))) {
                $tags[] = $tag;
            }
        }

        if (strlen($this->buffer) > self::MAX_FRAME * 4) {
            $this->buffer = '';   // pengaman: aliran sampah tanpa header valid
        }

        return $tags;
    }

    /** @return array{epc:string, tid:?string, rssi:?int, antenna:?int}|null */
    public static function decodeTag(string $data): ?array
    {
        $n = strlen($data);
        if ($n < 5) {
            return null;
        }

        $epc = $tid = null;
        $pc     = unpack('n', substr($data, 0, 2))[1];
        $epcLen = ($pc >> 11) * 2;
        $rest   = $n - 2 - $epcLen;

        if ($epcLen > 0 && ($rest === 3 || $rest === 3 + self::TID_LEN)) {
            $epc = substr($data, 2, $epcLen);
            $tid = $rest === 3 + self::TID_LEN ? substr($data, 2 + $epcLen, self::TID_LEN) : null;
        } elseif ($n >= 3 + self::TID_LEN + 2) {
            // Tata letak pustaka referensi: EPC + TID(12) + RSSI(2) + ANT(1)
            $epc = substr($data, 0, $n - 3 - self::TID_LEN);
            $tid = substr($data, $n - 3 - self::TID_LEN, self::TID_LEN);
        } else {
            $epc = substr($data, 0, $n - 3);
        }

        $raw  = unpack('n', substr($data, $n - 3, 2))[1];
        $raw  = $raw >= 0x8000 ? $raw - 0x10000 : $raw;   // int16 bertanda, satuan 0,1 dBm
        $rssi = $raw / 10;

        return [
            'epc'     => strtoupper(bin2hex($epc)),
            'tid'     => $tid !== null ? strtoupper(bin2hex($tid)) : null,
            'rssi'    => ($rssi <= 0 && $rssi >= -120) ? (int) round($rssi) : null,
            'antenna' => ord($data[$n - 1]),
        ];
    }

    /** Bangun bingkai perintah (A5 5A …) — untuk perintah tambahan di masa depan. */
    public static function frame(int $cmd, string $data = ''): string
    {
        $len  = strlen($data) + 8;
        $body = pack('n', $len) . chr($cmd) . $data;
        $xor  = 0;
        foreach (str_split($body) as $byte) {
            $xor ^= ord($byte);
        }

        return "\xA5\x5A" . $body . chr($xor) . "\r\n";
    }

    private function findHeader(): ?int
    {
        $positions = [];
        foreach (self::HEADERS as $h) {
            $p = strpos($this->buffer, $h);
            if ($p !== false) {
                $positions[] = $p;
            }
        }

        return $positions ? min($positions) : null;
    }
}
