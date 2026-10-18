<?php

namespace App\Services\Hardware\Uhf;

/**
 * Identitas tag kanonik (SATU definisi untuk semua jalur: gate UR4, reader desktop, registrasi, scan).
 *
 *   UID = huruf besar heksadesimal dari byte EPC SAJA (tanpa PC, tanpa CRC, tanpa TID),
 *         tanpa spasi/titik dua/strip/titik, panjang genap, 4..50 karakter (batches.rfid_tag = string(50) UNIQUE).
 *
 * Pembaca mana pun yang menghasilkan string berbeda untuk tag fisik yang sama akan membuat tag "asing" di gate.
 * Padanan JavaScript: resources/js/lib/uhf/identity.js (diuji dengan vektor yang sama).
 */
final class UhfTagIdentity
{
    public const MIN_HEX = 4;

    public const MAX_HEX = 50;

    /** Dari byte EPC mentah. Null bila kosong atau lebih dari 25 byte (50 karakter). */
    public static function fromBytes(string $epc): ?string
    {
        if ($epc === '' || strlen($epc) * 2 > self::MAX_HEX) {
            return null;
        }

        return self::canonical(bin2hex($epc));
    }

    /** Dari string apa pun (mis. ketikan manual/hasil pembaca). Null bila bukan heksadesimal sah. */
    public static function canonical(string $uid): ?string
    {
        $uid = strtoupper(str_replace([' ', ':', '-', '.'], '', trim($uid)));

        if ($uid === '' || strlen($uid) % 2 !== 0 || ! ctype_xdigit($uid)) {
            return null;
        }
        if (strlen($uid) < self::MIN_HEX || strlen($uid) > self::MAX_HEX) {
            return null;
        }

        return $uid;
    }
}
