<?php

namespace App\Services\Hardware\Uhf;

/**
 * EndpointGuard — pembatas alamat reader jaringan (cegah SSRF).
 *
 * Pengguna dengan izin rfid.manage mengisi host:port, lalu daemon (yang berjalan di jaringan internal) menyambung
 * ke sana. Tanpa pembatas, host bisa diarahkan ke loopback, metadata cloud (169.254.169.254), atau layanan internal.
 *
 * Aturan: IPv4 literal saja (tanpa DNS, jadi tidak ada DNS-rebinding); rentang terlarang selalu ditolak
 * (0.0.0.0/8, 169.254.0.0/16, 224.0.0.0/4, 240.0.0.0/4 termasuk broadcast); loopback hanya di lingkungan
 * local/testing atau bila RFID_UHF_ALLOW_LOOPBACK=true (server UR4 tiruan); selain itu harus berada dalam
 * RFID_UHF_ALLOWED_CIDRS (bawaan RFC1918) dan port dalam RFID_UHF_ALLOWED_PORTS (bawaan 8888).
 */
final class EndpointGuard
{
    /** Port server UR4 tiruan; hanya diizinkan bersama loopback. */
    public const FAKE_PORT = 18888;

    private const ALWAYS_FORBIDDEN = ['0.0.0.0/8', '169.254.0.0/16', '224.0.0.0/4', '240.0.0.0/4'];

    private const MESSAGES = [
        'not_ipv4'         => ['Alamat reader harus berupa IPv4 (mis. 192.168.99.202), bukan nama host.', 'The reader address must be an IPv4 address (e.g. 192.168.99.202), not a hostname.'],
        'loopback'         => ['Alamat loopback (127.x) tidak diizinkan di lingkungan ini.', 'Loopback addresses (127.x) are not allowed in this environment.'],
        'forbidden_range'  => ['Alamat ini berada di rentang yang dilarang (link-local, multicast, atau tidak sah).', 'This address is in a forbidden range (link-local, multicast, or invalid).'],
        'not_allowed'      => ['Alamat di luar jaringan privat yang diizinkan. Hubungi administrator untuk menambah RFID_UHF_ALLOWED_CIDRS.', 'The address is outside the allowed private networks. Ask an administrator to extend RFID_UHF_ALLOWED_CIDRS.'],
        'port_not_allowed' => ['Port ini tidak diizinkan untuk reader (bawaan UR4: 8888).', 'This port is not allowed for readers (UR4 default: 8888).'],
        'duplicate'        => ['Alamat dan port ini sudah dipakai reader aktif lain. UR4 hanya melayani satu klien, jadi dua rekaman akan saling merebut.', 'This address and port are already used by another active reader. A UR4 serves a single client, so two records would take the stream from each other.'],
    ];

    /** Kode galat, atau null bila host:port boleh dipakai. */
    public static function validate(string $host, int $port): ?string
    {
        $ip = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        $long = $ip === false ? false : ip2long($ip);
        if ($long === false) {
            return 'not_ipv4';
        }
        $long &= 0xFFFFFFFF;

        $loopback = self::inCidr($long, '127.0.0.0/8');
        if ($loopback) {
            if (! self::loopbackAllowed()) {
                return 'loopback';
            }
        } else {
            foreach (self::ALWAYS_FORBIDDEN as $cidr) {
                if (self::inCidr($long, $cidr)) {
                    return 'forbidden_range';
                }
            }
            if (! self::inAny($long, (array) config('rfid.uhf.allowed_cidrs', []))) {
                return 'not_allowed';
            }
        }

        $ports = array_map('intval', (array) config('rfid.uhf.allowed_ports', [8888]));
        if ($loopback) {
            $ports[] = self::FAKE_PORT;
        }

        return in_array($port, $ports, true) ? null : 'port_not_allowed';
    }

    /** Pesan sesuai bahasa pengguna aktif (SetUserLocale menyetel locale dari users.language). */
    public static function message(string $code): string
    {
        [$id, $en] = self::MESSAGES[$code] ?? self::MESSAGES['not_allowed'];

        return app()->getLocale() === 'en' ? $en : $id;
    }

    public static function loopbackAllowed(): bool
    {
        return (bool) config('rfid.uhf.allow_loopback') || app()->environment(['local', 'testing']);
    }

    private static function inAny(int $long, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if (self::inCidr($long, trim((string) $cidr))) {
                return true;
            }
        }

        return false;
    }

    private static function inCidr(int $long, string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            return false;
        }
        [$net, $bits] = explode('/', $cidr, 2);
        $netLong = ip2long($net);
        if ($netLong === false || ! ctype_digit($bits) || (int) $bits > 32) {
            return false;
        }
        $mask = (int) $bits === 0 ? 0 : (~0 << (32 - (int) $bits)) & 0xFFFFFFFF;

        return (($netLong & 0xFFFFFFFF) & $mask) === ($long & $mask);
    }
}
