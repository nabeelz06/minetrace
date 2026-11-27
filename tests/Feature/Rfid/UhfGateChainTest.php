<?php

namespace Tests\Feature\Rfid;

use App\Models\Batch;
use App\Models\Company;
use App\Models\ProductCode;
use App\Models\RfidAuthorizationMode;
use App\Models\RfidReadLog;
use App\Models\RfidReader;
use App\Models\User;
use App\Services\Hardware\HardwareEventService;
use App\Services\Hardware\RfidAuthorizationService;
use App\Services\Hardware\RfidDeviceService;
use App\Services\Hardware\RfidReadLogService;
use App\Services\Hardware\RfidRegistrationService;
use App\Services\Hardware\RfidScanService;
use App\Services\Hardware\Uhf\CachePresenceFilter;
use App\Services\Hardware\Uhf\UhfGate;
use Database\Seeders\Core\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeUr4Peer;
use Tests\Support\Ur4Frames;
use Tests\TestCase;

/**
 * Alur nyata di lapangan (konfigurasi VPS produksi): 4 reader UR4 berarah "any" TANPA pemetaan antena, satu per tahap,
 * semua mode otorisasi masih "Catat saja". Membuktikan (A) kondisi itu hanya mencatat dan tak pernah menghasilkan
 * Scan Keluar, (B) setelah mode "terima otomatis" diatur rantai Receiving → Wet → Dry → Gudang berpindah per tap, dan
 * (C) jalur MON Gudang → Lab LTJ memakai tombol web "Kirim ke Lab LTJ" (Gudang tidak punya tahap berikutnya di RFID).
 */
class UhfGateChainTest extends TestCase
{
    use RefreshDatabase;

    private const EPC = 'E28068940000502FBF241526';   // ZIR (uji)

    private const EPC_2 = 'E28068940000502FBF21B127';   // MON (uji)

    private Company $company;

    private User $admin;

    private float $t = 5000.0;

    /** @var array<string,FakeUr4Peer> host:port → reader palsu */
    private array $peers = [];

    /** @var list<array{0:string,1:string}> */
    private array $out = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Queue::fake();
        Cache::flush();
        putenv('RFID_API_TOKEN=');
        config(['rfid.uhf.reconnect_pause' => 0.1]);
        set_error_handler(fn (int $no, string $str) => str_contains($str, 'Broken pipe'));

        $this->company = Company::where('code', 'TIMAH')->firstOrFail();
        $this->admin = User::where('email', 'admin@ptimah.co.id')->firstOrFail();
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        parent::tearDown();
    }

    private function reader(string $code, string $host = '192.168.99.202', array $extra = []): RfidReader
    {
        [$reader] = app(RfidDeviceService::class)->create($this->company->id, $extra + [
            'code' => $code, 'name' => $code, 'stage' => 'wet_process', 'direction' => 'any',
            'device_type' => 'uhf_chainway_ur4',
            'connection' => ['host' => $host, 'port' => 8888, 'antennas' => ['1' => 'in', '2' => 'out']],
        ], $this->admin->id);

        return $reader;
    }

    private function gate(): UhfGate
    {
        return new UhfGate(
            app(RfidScanService::class),
            app(HardwareEventService::class),
            function (string $level, string $msg) {
                $this->out[] = [$level, $msg];
            },
            fn () => $this->t,
            function (string $host, int $port) {
                [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                stream_set_blocking($a, false);
                $this->peers["{$host}:{$port}"] = new FakeUr4Peer($b, antennaMask: 0b0011, antennaConn: 0b0011);

                return $a;
            },
            owner: 'gate:test',
            connectCheck: fn () => true,
            registrations: app(RfidRegistrationService::class),
            readLogs: app(RfidReadLogService::class),
        );
    }

    private function step(UhfGate $gate, float $dt = 0.05): void
    {
        $this->t += $dt;
        $gate->reloadIfNeeded();
        $gate->tick();
        foreach ($gate->readerIds() as $id) {
            $link = $gate->link($id);
            if ($link->wantsWrite()) {
                $link->onWritable();
            }
        }
        for ($i = 0; $i < 3; $i++) {
            foreach ($gate->readerIds() as $id) {
                $host = RfidReader::find($id)->connectionConfig()['host'];
                if (isset($this->peers["{$host}:8888"])) {
                    $this->peers["{$host}:8888"]->service();
                }
                $gate->link($id)->onReadable();
            }
        }
    }

    private function streaming(UhfGate $gate, int $id): void
    {
        for ($i = 0; $i < 400 && $gate->link($id)?->uiState() !== 'streaming'; $i++) {
            $this->step($gate);
        }
        $this->assertSame('streaming', $gate->link($id)->uiState());
    }

    private const HOSTS = ['wet_process' => '192.168.99.31', 'dry_process' => '192.168.99.32', 'warehouse' => '192.168.99.33', 'project_plant' => '192.168.99.34'];

    /** Persis data VPS: 4 reader, arah "any", connection hanya host/port (TANPA pemetaan antena), semua mode audit_only. */
    private function vpsSetup(): array
    {
        $readers = [];
        foreach (self::HOSTS as $stage => $host) {
            [$r] = app(RfidDeviceService::class)->create($this->company->id, [
                'code' => 'R-' . $stage, 'name' => $stage, 'stage' => $stage, 'direction' => 'any',
                'device_type' => 'uhf_chainway_ur4', 'connection' => ['host' => $host, 'port' => 8888],
            ], $this->admin->id);
            $readers[$stage] = $r;
        }
        foreach (['dry_process', 'project_plant', 'receiving', 'warehouse', 'wet_process'] as $s) {
            RfidAuthorizationMode::updateOrCreate(['company_id' => $this->company->id, 'stage' => $s], ['mode' => 'audit_only']);
        }
        Cache::flush();
        $gate = $this->gate();
        $this->step($gate);
        foreach ($readers as $r) {
            $this->streaming($gate, $r->id);
        }

        return [$readers, $gate];
    }

    private function tap(UhfGate $gate, string $stage, string $epc, int $antenna = 1): RfidReadLog
    {
        $this->peers[self::HOSTS[$stage] . ':8888']->send(Ur4Frames::tag($epc, $antenna, -50.0));
        $this->step($gate, 0.1);
        $this->step($gate, 1.5);

        return RfidReadLog::where('epc', $epc)->orderByDesc('id')->firstOrFail();
    }

    private function batchAt(string $code, string $stage, string $status, string $epc): Batch
    {
        $product = ProductCode::where('company_id', $this->company->id)->where('code', $code)->firstOrFail();

        return Batch::factory()->create([
            'company_id' => $this->company->id, 'product_code_id' => $product->id,
            'process_stage' => $stage, 'status' => $status, 'rfid_tag' => $epc,
        ]);
    }

    #[Test]
    public function kondisi_vps_semua_mode_catat_saja_hanya_mencatat_dan_reader_any_tanpa_antena_tak_pernah_keluar(): void
    {
        $batch = $this->batchAt('ZIR', 'receiving', 'in_process', self::EPC);
        [, $gate] = $this->vpsSetup();

        $log = $this->tap($gate, 'wet_process', self::EPC);
        $this->assertSame(['in', 'accepted', 'audit_only', 'audit'], [$log->action, $log->result, $log->meta['mode'] ?? null, $log->meta['transition'] ?? null]);
        $this->assertSame('receiving', $batch->fresh()->process_stage, 'persis keluhan: status batch tidak berubah');

        // Tag lewat sisi "antena 2" pada reader "any" tanpa peta: tetap Masuk (tidak ada Keluar).
        $log2 = $this->tap($gate, 'dry_process', self::EPC, 2);
        $this->assertSame('in', $log2->action, 'reader "any" tanpa pemetaan antena: selalu Masuk');

        $resp = $this->actingAs($this->admin)->getJson('/api/v1/company/rfid/readers')->assertOk();
        $dirs = collect($resp->json('data'))->mapWithKeys(fn ($r) => [$r['code'] => $r['directions']])->all();
        foreach ($dirs as $d) {
            $this->assertSame(['in'], $d);
        }
        $gate->shutdown();
    }

    #[Test]
    public function setelah_mode_terima_otomatis_diatur_rantai_receiving_wet_dry_gudang_berpindah_per_tap_dengan_tag_baru(): void
    {
        $batch = $this->batchAt('ZIR', 'receiving', 'in_process', self::EPC);
        [, $gate] = $this->vpsSetup();
        $svc = app(RfidAuthorizationService::class);
        foreach (['wet_process', 'dry_process', 'warehouse'] as $s) {
            $svc->setMode($this->company->id, $s, 'scan_to_receive', $this->admin->id);   // jalur yang sama dengan UI
        }

        // Penghimpunan: Wet/Dry melepas tag saat menerima; drum keluar dengan UID baru (di sini lewat PoolService,
        // karena reader "any" tanpa pemetaan antena hanya menghasilkan Masuk).
        $pools = app(\App\Services\Process\PoolService::class);

        $log = $this->tap($gate, 'wet_process', self::EPC);
        $this->assertSame('moved', $log->meta['transition'] ?? null);
        $this->assertSame(['wet_process', 'in_process', null], [$batch->fresh()->process_stage, $batch->fresh()->status, $batch->fresh()->rfid_tag]);

        $wet = $pools->createOutputDrum($this->company->id, 'wet_process', self::EPC_2, $this->admin->id)['batch'];
        $pools->recordDrumWeight($wet, 900, null, $this->admin->id);

        $log = $this->tap($gate, 'dry_process', self::EPC_2);
        $this->assertSame('moved', $log->meta['transition'] ?? null);
        $this->assertSame(['dry_process', 'in_process'], [$wet->fresh()->process_stage, $wet->fresh()->status]);

        $dryUid = 'E28068940000502FBF2415AA';
        $dry = $pools->createOutputDrum($this->company->id, 'dry_process', $dryUid, $this->admin->id)['batch'];
        $zir = ProductCode::where('company_id', $this->company->id)->where('code', 'ZIR')->firstOrFail();
        $pools->recordDrumWeight($dry, 400, $zir->id, $this->admin->id);
        // Himpunan Dry wajib mengeluarkan 3 drum produk (MON, ZIR, ILM) sebelum "Selesai himpunan".
        foreach (['MON' => 'E28068940000502FBF2415AB', 'ILM' => 'E28068940000502FBF2415AC'] as $code => $uid) {
            $product = ProductCode::where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
            $extra = $pools->createOutputDrum($this->company->id, 'dry_process', $uid, $this->admin->id)['batch'];
            $pools->recordDrumWeight($extra, 300, $product->id, $this->admin->id);
        }
        $pools->closeDryPool($dry->pool, $this->admin->id);

        $log = $this->tap($gate, 'warehouse', $dryUid);
        $this->assertSame('moved', $log->meta['transition'] ?? null);
        $this->assertSame(['warehouse', 'in_warehouse'], [$dry->fresh()->process_stage, $dry->fresh()->status]);
        $this->assertSame(['pooled', 'pooled'], [$batch->fresh()->status, $wet->fresh()->status]);
        $gate->shutdown();
    }

    #[Test]
    public function jalur_mon_gudang_ke_lab_ltj_ditolak_sampai_dikirim_lewat_tombol_web_lalu_berpindah_saat_tap(): void
    {
        $batch = $this->batchAt('MON', 'warehouse', 'in_warehouse', self::EPC_2);
        [$readers, $gate] = $this->vpsSetup();
        $svc = app(RfidAuthorizationService::class);
        $svc->setMode($this->company->id, 'warehouse', 'scan_full', $this->admin->id);
        $svc->setMode($this->company->id, 'project_plant', 'scan_to_receive', $this->admin->id);

        // 1) Tap di Lab LTJ sebelum dikirim: guard menolak, alasan tercatat, batch tetap.
        $log = $this->tap($gate, 'project_plant', self::EPC_2);
        $this->assertSame('blocked', $log->meta['transition'] ?? null);
        $this->assertSame(['warehouse', 'in_warehouse'], [$batch->fresh()->process_stage, $batch->fresh()->status]);

        // 2) Pengiriman Gudang → Lab LTJ memakai alur khusus di web (tombol "Kirim ke Lab LTJ"); RFID Scan Keluar di Gudang tidak dipakai.
        $this->actingAs($this->admin)->post(route('company.process.warehouse.send_ltj'), ['batch_id' => $batch->id])->assertRedirect();
        $b = $batch->fresh();
        $this->assertSame(['warehouse', 'in_transit'], [$b->process_stage, $b->status]);

        // 3) Tap di Lab LTJ sesudah dikirim: berpindah (tag sempat keluar dari medan antena: kehadiran + dedupe dilupakan).
        CachePresenceFilter::forget($readers['project_plant']->id . ':' . self::EPC_2);
        Cache::forget('rfid:dd:' . self::EPC_2 . ':in:project_plant');
        $log = $this->tap($gate, 'project_plant', self::EPC_2);
        $b = $batch->fresh();
        $this->assertSame(['project_plant', 'in_process'], [$b->process_stage, $b->status]);
        $gate->shutdown();
    }
}
