<?php

namespace Tests\Feature\Rfid;

use App\Jobs\CaptureCctvSnapshotJob;
use App\Models\Batch;
use App\Models\Company;
use App\Models\ProductCode;
use App\Models\RfidReader;
use App\Models\RfidReadLog;
use App\Models\User;
use App\Services\Hardware\DeviceContext;
use App\Services\Hardware\RfidDeviceService;
use App\Services\Hardware\RfidScanService;
use Database\Seeders\Core\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reader UHF (desktop R2000 di Receiving, gate Chainway UR4) + log setiap pembacaan + capture CCTV per log.
 */
class UhfReadLogTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private User $wet;

    private int $productId;

    private const BASE = '/api/v1/company/rfid';

    private const EPC = 'E20000200914016323809025';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Queue::fake();
        Cache::flush();
        putenv('RFID_API_TOKEN=');
        config(['rfid.read_log.capture_on_reject' => true, 'rfid.read_log.capture_on_register' => true]);

        $this->company = Company::where('code', 'TIMAH')->firstOrFail();
        $this->admin = User::where('email', 'admin@ptimah.co.id')->firstOrFail();
        $this->wet = User::where('email', 'wet.mtk@ptimah.co.id')->firstOrFail();
        $this->productId = ProductCode::where('company_id', $this->company->id)->firstOrFail()->id;
    }

    private function batch(array $attrs = []): Batch
    {
        return Batch::factory()->create($attrs + [
            'company_id' => $this->company->id, 'product_code_id' => $this->productId,
            'process_stage' => 'wet_process', 'status' => 'in_process', 'rfid_tag' => null,
        ]);
    }

    private function gateReader(array $attrs = []): RfidReader
    {
        [$reader] = app(RfidDeviceService::class)->create($this->company->id, $attrs + [
            'code' => 'WET-GATE-01', 'name' => 'Gate Wet', 'stage' => 'wet_process', 'direction' => 'any',
            'device_type' => 'uhf_chainway_ur4',
            'connection' => ['host' => '192.168.99.202', 'port' => 8888, 'antennas' => ['1' => 'in', '2' => 'out']],
        ], $this->admin->id);

        return $reader;
    }

    // ─── Registrasi desktop (Receiving) ───────────────────────────────────

    #[Test]
    public function registrasi_reader_desktop_tercatat_real_uhf_dengan_log_dan_capture(): void
    {
        $batch = $this->batch(['process_stage' => 'receiving']);

        $this->actingAs($this->wet)->postJson(self::BASE.'/registration', [
            'batch_id' => $batch->id, 'uid' => self::EPC, 'source' => 'uhf_desktop', 'rssi' => -48, 'antenna' => 1,
        ])->assertOk()->assertJson(['code' => 'tag_registered']);

        $this->assertSame(self::EPC, $batch->fresh()->rfid_tag);
        $this->assertDatabaseHas('checkpoints', ['batch_id' => $batch->id, 'rfid_scan_code' => self::EPC, 'rfid_origin' => 'real_uhf']);

        $log = RfidReadLog::firstOrFail();
        $this->assertSame(['register', 'accepted', 'real_uhf', -48, 'receiving'], [$log->action, $log->result, $log->origin, $log->rssi, $log->stage]);
        $this->assertNotNull($log->checkpoint_id);
        $this->assertSame('pending', $log->snapshot_status);

        Queue::assertPushed(CaptureCctvSnapshotJob::class, 1);
    }

    #[Test]
    public function registrasi_gagal_tag_dipakai_tetap_tercatat_sebagai_ditolak(): void
    {
        $this->batch(['rfid_tag' => self::EPC]);
        $batch = $this->batch();

        $this->actingAs($this->wet)->postJson(self::BASE.'/registration', [
            'batch_id' => $batch->id, 'uid' => self::EPC, 'source' => 'uhf_desktop',
        ])->assertStatus(422);

        $this->assertDatabaseHas('rfid_read_logs', ['epc' => self::EPC, 'action' => 'register', 'result' => 'rejected', 'result_code' => 'tag_in_use']);
    }

    // ─── Gate UR4 (jalur yang dipakai daemon rfid:uhf-gate) ───────────────

    #[Test]
    public function arah_gate_mengikuti_pemetaan_antena(): void
    {
        $reader = $this->gateReader();

        $this->assertSame('in', $reader->directionForAntenna(1));
        $this->assertSame('out', $reader->directionForAntenna(2));
        $this->assertSame('in', $reader->directionForAntenna(3));   // tanpa pemetaan, reader 'any' → IN
    }

    #[Test]
    public function scan_gate_diterima_mencatat_log_dan_satu_capture_tertaut(): void
    {
        $reader = $this->gateReader();
        $batch = $this->batch(['rfid_tag' => self::EPC]);

        [$result, $dup] = app(RfidScanService::class)->scan(
            new DeviceContext($this->company->id, $reader, 'device_key'), 'in', self::EPC, $reader->stage,
            ['origin' => 'real_uhf', 'antenna' => 1, 'rssi' => -60, 'tid' => 'E2003412013101000E5439AF']
        );

        $this->assertSame('success', $result['status']);
        $this->assertFalse($dup);

        $log = RfidReadLog::firstOrFail();
        $this->assertSame(['in', 'accepted', $batch->id, $reader->id, 1, -60], [$log->action, $log->result, $log->batch_id, $log->reader_id, $log->antenna, $log->rssi]);
        $this->assertNotNull($log->checkpoint_id);

        Queue::assertPushed(CaptureCctvSnapshotJob::class, 1);
    }

    #[Test]
    public function tag_asing_di_gate_ditolak_tercatat_dan_tetap_di_capture(): void
    {
        $reader = $this->gateReader();

        [$result] = app(RfidScanService::class)->scan(
            new DeviceContext($this->company->id, $reader, 'device_key'), 'in', 'E200AAAABBBBCCCCDDDD0001', null, ['origin' => 'real_uhf']
        );

        $this->assertSame('failed', $result['status']);
        $this->assertDatabaseHas('rfid_read_logs', [
            'epc' => 'E200AAAABBBBCCCCDDDD0001', 'result' => 'rejected', 'result_code' => 'tag_unknown',
            'snapshot_status' => 'pending', 'batch_id' => null,
        ]);
        Queue::assertPushed(CaptureCctvSnapshotJob::class, 1);
    }

    #[Test]
    public function capture_tag_ditolak_bisa_dimatikan(): void
    {
        config(['rfid.read_log.capture_on_reject' => false]);
        $reader = $this->gateReader();

        app(RfidScanService::class)->scan(new DeviceContext($this->company->id, $reader, 'device_key'), 'in', 'E200AAAA00000001', null, ['origin' => 'real_uhf']);

        $this->assertDatabaseHas('rfid_read_logs', ['epc' => 'E200AAAA00000001', 'result' => 'rejected', 'snapshot_status' => 'none']);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function tag_asing_berulang_hanya_satu_capture_per_cooldown(): void
    {
        $reader = $this->gateReader();
        $ctx = new DeviceContext($this->company->id, $reader, 'device_key');

        foreach ([1, 2, 3] as $i) {
            app(RfidScanService::class)->scan($ctx, 'in', 'E200AAAA00000009', null, ['origin' => 'real_uhf', 'event_id' => "x-{$i}"]);
        }

        $this->assertSame(3, RfidReadLog::where('epc', 'E200AAAA00000009')->count(), 'semua pembacaan tetap tercatat');
        Queue::assertPushed(CaptureCctvSnapshotJob::class, 1);
    }

    #[Test]
    public function pembacaan_duplikat_tercatat_tanpa_capture_kedua(): void
    {
        $reader = $this->gateReader();
        $this->batch(['rfid_tag' => self::EPC]);
        $ctx = new DeviceContext($this->company->id, $reader, 'device_key');

        app(RfidScanService::class)->scan($ctx, 'in', self::EPC, null, ['origin' => 'real_uhf']);
        [, $dup] = app(RfidScanService::class)->scan($ctx, 'in', self::EPC, null, ['origin' => 'real_uhf']);

        $this->assertTrue($dup);
        $this->assertSame(['accepted', 'duplicate'], RfidReadLog::orderBy('id')->pluck('result')->all());
        Queue::assertPushed(CaptureCctvSnapshotJob::class, 1);
    }

    // ─── Registri reader UR4 ──────────────────────────────────────────────

    #[Test]
    public function reader_ur4_wajib_host_dan_antena_tidak_boleh_bertentangan(): void
    {
        $base = ['code' => 'GATE-01', 'name' => 'Gate', 'stage' => 'warehouse', 'device_type' => 'uhf_chainway_ur4'];

        $this->actingAs($this->admin)->postJson(self::BASE.'/readers', $base + ['direction' => 'in'])
            ->assertStatus(422)->assertJsonValidationErrors('connection.host');

        $this->actingAs($this->admin)->postJson(self::BASE.'/readers', $base + [
            'direction' => 'in', 'connection' => ['host' => '10.0.0.5', 'antennas' => ['2' => 'out']],
        ])->assertStatus(422)->assertJsonValidationErrors('connection.antennas');

        $this->actingAs($this->admin)->postJson(self::BASE.'/readers', $base + [
            'direction' => 'any', 'connection' => ['host' => '10.0.0.5', 'port' => 8888, 'antennas' => ['1' => 'in', '2' => 'out']],
        ])->assertStatus(201)->assertJsonPath('data.reader.connection.host', '10.0.0.5');
    }

    // ─── Endpoint log ─────────────────────────────────────────────────────

    #[Test]
    public function endpoint_log_terbatas_pada_perusahaan_pengguna(): void
    {
        $reader = $this->gateReader();
        app(RfidScanService::class)->scan(new DeviceContext($this->company->id, $reader, 'device_key'), 'in', 'E200AAAA00000002', null, ['origin' => 'real_uhf']);

        $other = Company::factory()->create(['is_regulator' => false, 'code' => 'LAIN']);
        RfidReadLog::create(['company_id' => $other->id, 'epc' => 'FFFF0000', 'action' => 'in', 'result' => 'rejected']);

        $res = $this->actingAs($this->wet)->getJson(self::BASE.'/read-logs')->assertOk();
        $this->assertSame(['E200AAAA00000002'], array_column($res->json('data'), 'epc'));

        $this->actingAs($this->wet)->getJson(self::BASE.'/read-logs?result=accepted')->assertOk()->assertJsonCount(0, 'data');
    }
}
