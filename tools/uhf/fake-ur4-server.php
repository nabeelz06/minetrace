<?php

/**
 * Server UR4 tiruan untuk simulasi lokal (tanpa perangkat). Hanya bind ke loopback.
 *
 *   php tools/uhf/fake-ur4-server.php [--port=18888] [--epc=E20000200914016323809025,...] [--antenna=1] [--rssi=-55]
 *                                     [--interval=0.3] [--scenario=normal|silent|doa] [--power=15]
 *
 * Meniru perilaku UR4 nyata: SATU klien (koneksi baru merebut aliran, soket lama ditutup), ack STOP,
 * jawaban konfigurasi, lalu aliran tag setelah START. Skenario:
 *   normal  jawab semua, kirim tag tiap --interval detik selama inventory berjalan
 *   silent  tidak pernah mengirim ack STOP (handshake_timeout)
 *   doa     koneksi PERTAMA ditutup seketika (mati-sejak-lahir), berikutnya normal
 * Daftarkan reader uji dengan host 127.0.0.1 port <port> (RFID_UHF_ALLOW_LOOPBACK=true atau APP_ENV=local;
 * port harus ada di RFID_UHF_ALLOWED_PORTS).
 */

require __DIR__.'/../../vendor/autoload.php';

use Tests\Support\FakeUr4Peer;
use Tests\Support\Ur4Frames;

$o = getopt('', ['port::', 'epc::', 'antenna::', 'rssi::', 'interval::', 'scenario::', 'power::']);
$port = (int) ($o['port'] ?? 18888);
$epcs = array_values(array_filter(explode(',', (string) ($o['epc'] ?? 'E20000200914016323809025'))));
$antenna = (int) ($o['antenna'] ?? 1);
$rssi = (float) ($o['rssi'] ?? -55);
$interval = (float) ($o['interval'] ?? 0.3);
$scenario = (string) ($o['scenario'] ?? 'normal');
$power = (float) ($o['power'] ?? 15);

$server = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
if (! $server) {
    fwrite(STDERR, "Tidak bisa bind 127.0.0.1:{$port}: {$errstr}\n");
    exit(1);
}
stream_set_blocking($server, false);
fwrite(STDOUT, "fake-ur4 siap di 127.0.0.1:{$port} (skenario {$scenario}). Ctrl+C untuk berhenti.\n");

$peer = null;
$connections = 0;
$next = 0.0;
$i = 0;
$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    foreach ([SIGINT, SIGTERM] as $sig) {
        pcntl_signal($sig, function () use (&$running) {
            $running = false;
        });
    }
}

while ($running) {
    $read = [$server];
    if ($peer) {
        $read[] = $peer->stream;
    }
    $w = $e = null;
    @stream_select($read, $w, $e, 0, 50_000);

    if ($c = @stream_socket_accept($server, 0)) {
        $connections++;
        if ($peer) {
            $peer->close();                // koneksi baru merebut satu-satunya slot
            fwrite(STDOUT, "klien baru merebut slot\n");
        }
        if ($scenario === 'doa' && $connections === 1) {
            fclose($c);
            $peer = null;
            fwrite(STDOUT, "koneksi #1 ditutup (doa)\n");
        } else {
            $peer = new FakeUr4Peer($c, ackStop: $scenario !== 'silent', powerDbm: $power, antennaMask: 0b0011, antennaConn: 0b0011);
            fwrite(STDOUT, "klien tersambung (#{$connections})\n");
        }
    }

    if ($peer) {
        $peer->service();
        if (feof($peer->stream)) {
            $peer->close();
            $peer = null;
            fwrite(STDOUT, "klien putus\n");
        } elseif ($peer->sawCommand(0x82) && microtime(true) >= $next) {
            $epc = $epcs[$i++ % count($epcs)];
            $peer->send(Ur4Frames::tag($epc, $antenna, $rssi));
            $next = microtime(true) + $interval;
        }
    }
}
fwrite(STDOUT, "berhenti.\n");
