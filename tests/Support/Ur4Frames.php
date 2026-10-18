<?php

namespace Tests\Support;

/**
 * Pembangun bingkai UR4 untuk TES: sengaja ditulis ulang di sini (bukan memanggil ChainwayUr4Protocol)
 * agar bug simetris builder+parser pada kode produksi tidak lolos.
 */
final class Ur4Frames
{
    public static function build(int $cmd, string $payload = '', string $head = "\xA5\x5A"): string
    {
        $body = pack('n', strlen($payload) + 8).chr($cmd).$payload;
        $x = 0;
        foreach (str_split($body) as $b) {
            $x ^= ord($b);
        }

        return $head.$body.chr($x)."\r\n";
    }

    /** Rekaman tag 0x83: PC(2) EPC [TID] RSSI(2) ANT. */
    public static function tag(string $epcHex, int $antenna = 1, float $dbm = -55.0, ?string $tidHex = null): string
    {
        $epc = hex2bin($epcHex);
        $pc = chr(((int) (strlen($epc) / 2)) << 3)."\x00";
        $payload = $pc.$epc.($tidHex ? hex2bin($tidHex) : '').pack('n', ((int) round($dbm * 10)) & 0xFFFF).chr($antenna);

        return self::build(0x83, $payload);
    }
}
