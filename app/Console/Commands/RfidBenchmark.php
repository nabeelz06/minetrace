<?php

namespace App\Console\Commands;

use App\Models\Batch;
use App\Models\Checkpoint;
use App\Models\Company;
use App\Models\Location;
use App\Models\ProductCode;
use App\Models\RfidReader;
use App\Services\Hardware\DeviceContext;
use App\Services\Hardware\RfidDeviceService;
use App\Services\Hardware\RfidScanService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * rfid:benchmark — uji beban IN-PROCESS jalur scan (RfidScanService → strategi → checkpoint) dan kueri umpan.
 *
 * Mengukur latensi p50/p95/p99, throughput, dan waktu kueri umpan pada tabel besar. TIDAK untuk produksi:
 * menolak berjalan bila APP_ENV=production. Membuat perusahaan "BENCH" + data uji; bersihkan dengan --cleanup.
 * Job CCTV di-fake (tidak ada ffmpeg). Hasil mengukur kode aplikasi + Postgres, BUKAN jaringan/HTTP/PHP-FPM
 * (untuk itu gunakan tests/load/rfid-scan.k6.js).
 *
 *   php artisan rfid:benchmark --readers=20 --tags=2000 --scans=5000
 *   php artisan rfid:benchmark --cleanup
 */
class RfidBenchmark extends Command
{
    protected $signature = 'rfid:benchmark
        {--readers=10 : jumlah reader simulasi}
        {--tags=1000 : jumlah batch bertag}
        {--scans=3000 : jumlah scan yang diukur}
        {--history=0 : tambahkan N checkpoint sintetis untuk menguji kueri umpan pada tabel besar}
        {--cleanup : hapus data BENCH}';

    protected $description = 'Uji beban in-process jalur scan RFID (hanya non-produksi)';

    public function handle(RfidScanService $scans, RfidDeviceService $devices): int
    {
        if (app()->environment('production')) {
            $this->error('Ditolak: jangan jalankan benchmark di produksi.');
            return self::FAILURE;
        }

        if ($this->option('cleanup')) {
            return $this->cleanup();
        }

        Queue::fake();
        config(['rfid.dedupe_seconds' => 0]);   // ukur jalur penuh, bukan cache dedupe

        $company = Company::withTrashed()->firstOrCreate(['code' => 'BENCH'], ['name' => 'Benchmark', 'is_active' => true, 'is_regulator' => false]);
        if (method_exists($company, 'trashed') && $company->trashed()) {
            $company->restore();
        }
        $product = ProductCode::where('company_id', $company->id)->first()
            ?? ProductCode::create(['company_id' => $company->id, 'code' => 'BNC', 'name' => 'Benchmark', 'category' => 'concentrate', 'is_hazardous' => false]);
        $location = Location::where('company_id', $company->id)->first()
            ?? Location::create(['company_id' => $company->id, 'code' => 'BNC-WET', 'name' => 'Bench Wet', 'location_type' => 'wet_process', 'is_active' => true]);

        $nReaders = (int) $this->option('readers');
        $nTags    = (int) $this->option('tags');
        $nScans   = (int) $this->option('scans');

        $this->info("Menyiapkan {$nReaders} reader dan {$nTags} batch bertag…");
        $readers = [];
        for ($i = 1; $i <= $nReaders; $i++) {
            $code = sprintf('BNC-%02d', $i);
            $readers[] = RfidReader::where('company_id', $company->id)->where('code', $code)->first()
                ?? $devices->create($company->id, ['code' => $code, 'name' => "Bench {$i}", 'stage' => 'wet_process', 'direction' => 'any'], null)[0];
        }

        $existing = Batch::where('company_id', $company->id)->whereNotNull('rfid_tag')->count();
        $rows = [];
        for ($i = $existing; $i < $nTags; $i++) {
            $rows[] = [
                'company_id' => $company->id, 'batch_code' => sprintf('BNC-%07d', $i), 'product_code_id' => $product->id,
                'initial_weight' => 1000, 'current_weight' => 1000, 'process_stage' => 'wet_process', 'status' => 'in_process',
                'current_location_id' => $location->id, 'has_discrepancy' => false, 'export_status' => 'not_applicable',
                'rfid_tag' => sprintf('BNC%08X', $i), 'metadata' => '[]', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
            ];
            if (count($rows) === 1000) { DB::table('batches')->insert($rows); $rows = []; }
        }
        if ($rows) DB::table('batches')->insert($rows);

        if (($extra = (int) $this->option('history')) > 0) {
            $this->info("Menambah {$extra} checkpoint sintetis…");
            $batchId = Batch::where('company_id', $company->id)->value('id');
            for ($done = 0; $done < $extra; $done += 5000) {
                $chunk = [];
                for ($k = 0; $k < min(5000, $extra - $done); $k++) {
                    $chunk[] = ['company_id' => $company->id, 'batch_id' => $batchId, 'checkpoint_stage' => 'wet_process_input',
                        'rfid_scan_code' => 'BNCHIST', 'scan_stage' => 'wet_process', 'scan_direction' => 'in', 'rfid_origin' => 'real_esp32',
                        'recorded_at' => now()->subSeconds($done + $k), 'created_at' => now(), 'updated_at' => now()];
                }
                DB::table('checkpoints')->insert($chunk);
            }
        }

        $this->info("Mengukur {$nScans} scan…");
        $lat = [];
        $ok = 0;
        $t0 = microtime(true);
        for ($i = 0; $i < $nScans; $i++) {
            $reader = $readers[$i % $nReaders];
            $uid    = sprintf('BNC%08X', random_int(0, $nTags - 1));
            $s = hrtime(true);
            [$result] = $scans->scan(new DeviceContext($company->id, $reader, 'device_key'), $i % 2 ? 'out' : 'in', $uid, 'wet_process', ['origin' => 'real_esp32']);
            $lat[] = (hrtime(true) - $s) / 1e6;
            $ok += $result['status'] === 'success' ? 1 : 0;
        }
        $total = microtime(true) - $t0;

        sort($lat);
        $pct = fn (float $p) => $lat[(int) min(count($lat) - 1, floor($p * count($lat)))];

        $this->newLine();
        $this->table(['Metrik', 'Nilai'], [
            ['scan sukses / total', "{$ok} / {$nScans}"],
            ['throughput (satu proses)', round($nScans / $total, 1) . ' scan/dtk'],
            ['latensi p50', round($pct(0.50), 2) . ' ms'],
            ['latensi p95', round($pct(0.95), 2) . ' ms'],
            ['latensi p99', round($pct(0.99), 2) . ' ms'],
            ['latensi maks', round(end($lat), 2) . ' ms'],
        ]);

        // Kueri umpan (tabel besar): 30 terbaru per perusahaan
        $feed = [];
        for ($i = 0; $i < 20; $i++) {
            $s = hrtime(true);
            Checkpoint::where('company_id', $company->id)->whereNotNull('rfid_scan_code')->orderByDesc('id')->limit(30)->get();
            $feed[] = (hrtime(true) - $s) / 1e6;
        }
        sort($feed);
        $rowsTotal = Checkpoint::where('company_id', $company->id)->count();
        $this->info(sprintf('Kueri umpan (30 terbaru, %s baris checkpoint BENCH): median %.2f ms, maks %.2f ms', number_format($rowsTotal), $feed[10], end($feed)));

        $explain = DB::select("EXPLAIN SELECT * FROM checkpoints WHERE company_id = ? AND rfid_scan_code IS NOT NULL ORDER BY id DESC LIMIT 30", [$company->id]);
        $this->line('Rencana kueri: ' . trim(((array) $explain[0])['QUERY PLAN']));
        $this->warn('Bersihkan: php artisan rfid:benchmark --cleanup');

        return self::SUCCESS;
    }

    private function cleanup(): int
    {
        $company = Company::withTrashed()->where('code', 'BENCH')->first();
        if (! $company) {
            $this->info('Tidak ada data BENCH.');
            return self::SUCCESS;
        }
        DB::transaction(function () use ($company) {
            DB::table('checkpoints')->where('company_id', $company->id)->delete();
            DB::table('rfid_tag_events')->where('company_id', $company->id)->delete();
            DB::table('rfid_readers')->where('company_id', $company->id)->delete();
            DB::table('batches')->where('company_id', $company->id)->delete();
            DB::table('locations')->where('company_id', $company->id)->delete();
            DB::table('product_codes')->where('company_id', $company->id)->delete();
            $company->forceDelete();
        });
        $this->info('Data BENCH dihapus.');

        return self::SUCCESS;
    }
}
