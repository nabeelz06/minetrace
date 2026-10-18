<?php

namespace App\Console\Commands;

use App\Models\CctvCamera;
use App\Models\RfidReader;
use App\Services\Hardware\Uhf\EndpointGuard;
use App\Services\Hardware\Uhf\Ur4ExclusiveLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

/**
 * hardware:check — preflight kesiapan integrasi CCTV + RFID di lingkungan ini (lokal atau VPS).
 *
 * Hanya MEMBACA: tidak mengubah data, tidak mengirim scan. Keluar dengan kode 1 bila ada pemeriksaan GAGAL
 * (PERINGATAN tidak menggagalkan). Gunakan --company=KODE untuk membatasi pada satu perusahaan.
 *
 *   php artisan hardware:check
 *   php artisan hardware:check --company=TIMAH
 */
class HardwareCheck extends Command
{
    protected $signature = 'hardware:check {--company= : kode perusahaan (default: semua)}
        {--active : buka TCP ke reader UR4 yang bebas (merebut slot satu-klien; jangan saat gate berjalan)}';

    protected $description = 'Preflight kesiapan integrasi CCTV, reader RFID, dan tag (hanya baca)';

    private int $failures = 0;

    private int $warnings = 0;

    public function handle(): int
    {
        $this->info('MINETRACE — preflight perangkat keras');

        $this->section('Infrastruktur');
        $this->checkDatabase();
        $this->checkQueue();
        $this->checkUrl();

        $this->section('RFID');
        $this->checkRfidConfig();
        $this->checkReaders();

        $this->section('CCTV');
        $this->checkFfmpeg();
        $mediamtxUp = $this->checkMediaMtx();
        $this->checkCameras($mediamtxUp);

        $this->newLine();
        $this->line(sprintf('Ringkasan: %d gagal, %d peringatan', $this->failures, $this->warnings));

        return $this->failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    // ─── Infrastruktur ────────────────────────────────────────────────────

    private function checkDatabase(): void
    {
        try {
            DB::select('select 1');
            $this->ok('Database terhubung');
        } catch (\Throwable $e) {
            $this->bad('Database tidak terhubung: '.$e->getMessage());
        }
    }

    private function checkQueue(): void
    {
        $driver = config('queue.default');
        if ($driver === 'sync') {
            $this->warn_('QUEUE_CONNECTION=sync — snapshot CCTV berjalan di request scan (memperlambat respons ESP32). Pakai redis + worker.');

            return;
        }
        try {
            Cache::store()->put('hardware:check', 1, 5);
            $this->ok("Queue '{$driver}' dan cache dapat diakses (pastikan proses queue:work berjalan)");
        } catch (\Throwable $e) {
            $this->bad('Cache/Redis tidak dapat diakses: '.$e->getMessage());
        }
    }

    private function checkUrl(): void
    {
        $url = (string) config('app.url');
        if (str_starts_with($url, 'https://')) {
            $this->ok("APP_URL {$url} (HTTPS)");
        } elseif (app()->environment('production')) {
            $this->bad("APP_URL {$url} bukan HTTPS — kunci perangkat akan terkirim tanpa enkripsi dan nginx mengalihkan 80→443 (POST ESP32 gagal).");
        } else {
            $this->warn_("APP_URL {$url} bukan HTTPS (wajar untuk lokal)");
        }
    }

    // ─── RFID ─────────────────────────────────────────────────────────────

    private function checkRfidConfig(): void
    {
        if (config('rfid.require_device_key')) {
            $this->ok('RFID_REQUIRE_DEVICE_KEY=true (semua perangkat wajib kunci)');
        } else {
            $this->warn_('RFID_REQUIRE_DEVICE_KEY=false — perangkat anonim/legacy masih diterima. Setelah semua reader terdaftar, set true.');
        }
        if (app()->environment('production') && config('rfid.simulation_enabled')) {
            $this->warn_('RFID_SIMULATION_ENABLED=true di produksi — matikan setelah pelatihan agar data uji tidak tercampur.');
        }
        if (config('rfid.legacy_global_tag_fallback') && app()->environment('production')) {
            $this->warn_('RFID_LEGACY_GLOBAL_FALLBACK=true — perangkat anonim dapat mencari tag lintas perusahaan.');
        }
    }

    private function checkReaders(): void
    {
        $readers = RfidReader::query()
            ->when($this->option('company'), fn ($q, $code) => $q->whereHas('company', fn ($c) => $c->where('code', $code)))
            ->get();

        if ($readers->isEmpty()) {
            $this->warn_('Belum ada reader terdaftar (menu RFID → Perangkat → Tambah). Perangkat berjalan sebagai anonim/legacy.');

            return;
        }

        foreach ($readers as $r) {
            $state = $r->connectionState();
            $line = sprintf('Reader %s (%s, %s) — %s%s', $r->code, $r->stage ?? 'semua tahap', $r->direction, $state,
                $r->last_seen_at ? ', terakhir '.$r->last_seen_at->diffForHumans() : '');
            match ($state) {
                'online' => $this->ok($line),
                'disabled' => $this->line("  - {$line}"),
                default => $this->warn_($line),
            };
            if (($r->last_status['clock_ok'] ?? true) === false) {
                $this->warn_("Reader {$r->code}: jam perangkat menyimpang {$r->last_status['clock_skew']} dtk (cek NTP)");
            }
            if ($r->is_active && in_array($r->device_type, RfidReader::NETWORK_TYPES, true)) {
                $this->checkNetworkReader($r);
            }
        }
    }

    /**
     * Reader gate UR4. PASIF secara bawaan: UR4 hanya melayani satu klien, jadi membuka koneksi uji (nc, fsockopen)
     * ke reader yang sedang dipakai daemon akan MEREBUT aliran dan menghentikan pembacaan gate. Pemeriksaan
     * membaca alamat, kunci eksklusif, dan status yang ditulis daemon. `--active` hanya membuka TCP bila
     * kunci bebas dan tidak ada detak daemon baru-baru ini.
     */
    private function checkNetworkReader(RfidReader $r): void
    {
        $host = trim((string) ($r->connection['host'] ?? ''));
        if ($host === '') {
            $this->bad("Reader {$r->code}: connection.host kosong — isi alamat IP reader UR4 di menu Perangkat.");

            return;
        }
        $port = (int) ($r->connection['port'] ?? config('rfid.uhf.ur4_port', 8888));
        if ($code = EndpointGuard::validate($host, $port)) {
            $this->bad("Reader {$r->code}: alamat {$host}:{$port} ditolak ({$code}) — ".EndpointGuard::message($code));

            return;
        }

        $st = (array) ($r->last_status ?? []);
        $state = $st['link_state'] ?? null;
        $age = $r->last_seen_at ? now()->diffInSeconds($r->last_seen_at, true) : null;
        $holder = Ur4ExclusiveLock::holder($host, $port);

        if ($holder !== null) {
            $this->ok("Reader {$r->code}: {$host}:{$port} dipegang {$holder}".($state ? " (link_state={$state})" : '').' — tidak diuji ulang agar tidak merebut aliran.');
            if ($state && $state !== 'streaming') {
                $this->warn_("Reader {$r->code}: tautan {$state}".(! empty($st['detail']) ? ' — '.$st['detail'] : ''));
            }

            return;
        }

        if ($age !== null && $age < 60) {
            $this->ok("Reader {$r->code}: terlihat {$age} dtk lalu oleh daemon (link_state=".($state ?? '?').').');

            return;
        }

        if (! $this->option('active')) {
            $this->warn_("Reader {$r->code}: daemon `rfid:uhf-gate` belum terlihat untuk {$host}:{$port} (pengecekan pasif; tidak membuka koneksi). Jalankan `php artisan rfid:uhf-probe --reader={$r->code}` untuk uji terkontrol.");

            return;
        }

        // --active: kunci bebas dan tak ada daemon aktif → aman membuka TCP sekejap (kunci dipegang selama uji).
        $owner = 'hardware-check:'.getmypid();
        if (! Ur4ExclusiveLock::acquire($host, $port, $owner, 15)) {
            $this->warn_("Reader {$r->code}: endpoint sedang dipakai proses lain; uji aktif dilewati.");

            return;
        }
        try {
            $sock = @fsockopen($host, $port, $errno, $errstr, 2);
            if ($sock) {
                fclose($sock);
                $this->ok("Reader {$r->code}: TCP {$host}:{$port} terjangkau (uji aktif; reader diberi jeda 2 dtk sebelum dipakai lagi)");
            } else {
                $this->warn_("Reader {$r->code}: TCP {$host}:{$port} tidak terjangkau ({$errstr}) — cek jaringan/IP reader.");
            }
        } finally {
            Ur4ExclusiveLock::release($host, $port, $owner);
        }
    }

    // ─── CCTV ─────────────────────────────────────────────────────────────

    private function checkFfmpeg(): void
    {
        try {
            $p = new Process(['ffmpeg', '-version']);
            $p->setTimeout(5)->run();
            $p->isSuccessful()
                ? $this->ok('ffmpeg: '.strtok($p->getOutput(), "\n"))
                : $this->bad('ffmpeg tidak dapat dijalankan — snapshot CCTV tidak akan tersimpan.');
        } catch (\Throwable $e) {
            $this->bad('ffmpeg tidak ditemukan — snapshot CCTV tidak akan tersimpan.');
        }
    }

    private function checkMediaMtx(): bool
    {
        $api = rtrim((string) config('cctv.mediamtx_api'), '/');
        try {
            $res = Http::timeout(3)->get("{$api}/v3/paths/list");
            if ($res->successful()) {
                $this->ok("MediaMTX API {$api} aktif (".count($res->json('items', [])).' path)');

                return true;
            }
            $this->bad("MediaMTX API {$api} membalas HTTP {$res->status()}");
        } catch (\Throwable $e) {
            $this->bad("MediaMTX API {$api} tidak terjangkau: ".$e->getMessage());
        }

        return false;
    }

    private function checkCameras(bool $mediamtxUp): void
    {
        $cameras = CctvCamera::query()
            ->when($this->option('company'), fn ($q, $code) => $q->whereHas('company', fn ($c) => $c->where('code', $code)))
            ->get();

        if ($cameras->isEmpty()) {
            $this->warn_('Belum ada kamera (menu CCTV → Pengaturan → Tambah Kamera).');

            return;
        }

        $live = [];
        if ($mediamtxUp) {
            try {
                $api = rtrim((string) config('cctv.mediamtx_api'), '/');
                foreach (Http::timeout(3)->get("{$api}/v3/paths/list")->json('items', []) as $p) {
                    $live[$p['name']] = (bool) ($p['ready'] ?? false);
                }
            } catch (\Throwable) {
            }
        }

        foreach ($cameras as $c) {
            if (! $c->is_active) {
                $this->line("  - Kamera {$c->name} nonaktif");

                continue;
            }
            if ($c->isSimulationCamera()) {
                $this->warn_("Kamera {$c->name} [{$c->location_key}] masih SIMULASI — bukan kamera fisik.");

                continue;
            }
            $rtsp = $c->effective_rtsp_url;
            if ($rtsp === '') {
                $this->bad("Kamera {$c->name}: URL RTSP kosong");

                continue;
            }
            $host = parse_url($rtsp, PHP_URL_HOST);
            if ($host && $this->isPrivateHost($host) && app()->environment('production')) {
                $this->warn_("Kamera {$c->name}: RTSP menunjuk IP privat ({$host}) — dari VPS hanya terjangkau lewat VPN/tunnel (lihat docs/INTEGRASI_PERANGKAT.md).");
            }
            $path = $this->pathFromHls($c->effective_hls_url);
            if ($mediamtxUp && $path !== null) {
                match ($live[$path] ?? null) {
                    true => $this->ok("Kamera {$c->name}: stream '{$path}' siap"),
                    false => $this->bad("Kamera {$c->name}: path '{$path}' ada tetapi sumber RTSP tidak terhubung"),
                    default => $this->bad("Kamera {$c->name}: path '{$path}' belum ada di MediaMTX (simpan ulang kamera di Pengaturan)"),
                };
            } elseif ($path === null) {
                $this->bad("Kamera {$c->name}: URL HLS kosong/tidak valid");
            }
        }
    }

    // ─── Util ─────────────────────────────────────────────────────────────

    private function pathFromHls(string $hls): ?string
    {
        $path = trim((string) parse_url($hls, PHP_URL_PATH), '/');
        $path = preg_replace('#^hls/#', '', $path);
        $name = explode('/', $path)[0] ?? '';

        return $name !== '' ? $name : null;
    }

    private function isPrivateHost(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line("<options=bold>{$title}</>");
    }

    private function ok(string $msg): void
    {
        $this->line("  <info>✔</info> {$msg}");
    }

    private function warn_(string $msg): void
    {
        $this->warnings++;
        $this->line("  <comment>▲</comment> {$msg}");
    }

    private function bad(string $msg): void
    {
        $this->failures++;
        $this->line("  <error> ✘ </error> {$msg}");
    }
}
