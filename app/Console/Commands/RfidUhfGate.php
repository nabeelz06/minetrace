<?php

namespace App\Console\Commands;

use App\Services\Hardware\HardwareEventService;
use App\Services\Hardware\RfidReadLogService;
use App\Services\Hardware\RfidRegistrationService;
use App\Services\Hardware\RfidScanService;
use App\Services\Hardware\Uhf\UhfGate;
use App\Services\Hardware\Uhf\Ur4Link;
use Illuminate\Console\Command;

/**
 * rfid:uhf-gate — daemon pembaca gate UHF Chainway UR4 (TCP) untuk Wet Process, Wet→Dry, dan Gudang.
 *
 * Satu proses melayani SEMUA reader jenis `uhf_chainway_ur4` yang aktif (atau yang dipilih dengan --reader).
 * Seluruh logika ada di UhfGate (orkestrasi) dan Ur4Link (satu koneksi: handshake, konfigurasi, inventory,
 * liveness, deteksi perebutan aliran). Command ini hanya memegang loop, sinyal, dan keluaran.
 *
 * Hal yang PERLU diketahui operator:
 *   - UR4 melayani SATU klien. Selama daemon berjalan JANGAN membuka koneksi lain ke reader (nc, telnet,
 *     UHFAPP.exe, rfid:uhf-probe --force). Alat buatan kita menghormati kunci eksklusif; yang lain tidak.
 *   - Reader baru/diubah dari panel web berlaku dalam ≤ 3 detik; daftar juga dimuat ulang tiap 60 detik.
 *   - Status jujur ada di panel Perangkat (link_state, penghitung, peringatan), bukan sekadar "terhubung".
 *
 *   php artisan rfid:uhf-gate                       # semua reader UR4 aktif
 *   php artisan rfid:uhf-gate --reader=WET-GATE-01  # reader tertentu (boleh diulang)
 *
 * Jalankan di mesin yang SATU JARINGAN dengan reader (supervisor/systemd/docker, restart: always).
 */
class RfidUhfGate extends Command
{
    protected $signature = 'rfid:uhf-gate
        {--reader=* : kode reader (default: semua reader uhf_chainway_ur4 aktif)}
        {--max-runtime=0 : berhenti setelah N detik (0 = tanpa batas; untuk supervisor/uji)}';

    protected $description = 'Daemon gate UHF Chainway UR4: baca tag via TCP lalu catat scan + log + capture CCTV';

    private bool $running = true;

    public function handle(RfidScanService $scans, HardwareEventService $events, RfidRegistrationService $registrations, RfidReadLogService $readLogs): int
    {
        $this->trapSignals();

        $gate = new UhfGate(
            $scans,
            $events,
            function (string $level, string $message) {
                match ($level) {
                    'warning' => $this->warn($message),
                    'error' => $this->error($message),
                    'info' => $this->info($message),
                    default => $this->line($message),
                };
            },
            fn () => microtime(true),
            Ur4Link::tcpConnector(),
            (array) $this->option('reader'),
            registrations: $registrations,
            readLogs: $readLogs,
        );

        $maxRuntime = (int) $this->option('max-runtime');
        $startedAt = microtime(true);

        $this->info('rfid:uhf-gate mulai. Ctrl+C untuk berhenti.');

        while ($this->running) {
            $gate->reloadIfNeeded();
            $gate->tick();
            $gate->poll();
            $gate->heartbeatIfDue();
            $gate->refreshLocksIfDue();

            if ($maxRuntime > 0 && microtime(true) - $startedAt >= $maxRuntime) {
                break;
            }
            if ($gate->count() === 0) {
                usleep(250_000);   // belum ada reader aktif: tunggu terukur (bukan loop sibuk)
            }
        }

        $gate->shutdown();
        $this->info('rfid:uhf-gate berhenti.');

        return self::SUCCESS;
    }

    private function trapSignals(): void
    {
        if (! function_exists('pcntl_async_signals')) {
            $this->warn('Ekstensi pcntl tidak ada: Ctrl+C memutus soket tanpa mengirim STOP (aman: pemindaian berakhir saat klien menutup koneksi).');

            return;
        }
        pcntl_async_signals(true);
        foreach ([SIGINT, SIGTERM] as $signal) {
            pcntl_signal($signal, function () {
                $this->running = false;
            });
        }
    }
}
