<?php

namespace Tests\Feature\Rfid;

use App\Models\Company;
use App\Models\RfidReadLog;
use App\Models\RfidReader;
use App\Models\User;
use App\Services\Hardware\HardwareEventService;
use App\Services\Hardware\RfidDeviceService;
use App\Services\Hardware\RfidReadLogService;
use App\Services\Hardware\RfidRegistrationService;
use App\Services\Hardware\RfidScanService;
use App\Services\Hardware\Uhf\UhfGate;
use App\Services\Hardware\Uhf\Ur4UdpLink;
use Database\Seeders\Core\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Ur4Frames;
use Tests\TestCase;

/** Reader UR4 mode push UDP lewat UhfGate: tanpa koneksi keluar, jalur scan yang sama dengan TCP. */
class UhfGateUdpTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/company/rfid';

    private const EPC = 'E28068940000502FBF283924';

    private Company $company;

    private User $admin;

    private float $t = 5000.0;

    /** @var list<array{0:string,1:int}> */
    private array $connects = [];

    /** @var list<int> */
    private array $binds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Queue::fake();
        Cache::flush();
        putenv('RFID_API_TOKEN=');
        config(['rfid.uhf.udp_allowed_cidrs' => ['140.213.69.213/32']]);

        $this->company = Company::where('code', 'TIMAH')->firstOrFail();
        $this->admin = User::where('email', 'admin@ptimah.co.id')->firstOrFail();
    }

    private function udpReader(string $code, int $port): RfidReader
    {
        [$reader] = app(RfidDeviceService::class)->create($this->company->id, [
            'code' => $code, 'name' => $code, 'stage' => 'wet_process', 'direction' => 'in',
            'device_type' => 'uhf_chainway_ur4',
            'connection' => ['host' => '192.168.0.31', 'port' => 8888, 'mode' => 'udp_push', 'udp_port' => $port],
        ], $this->admin->id);

        return $reader;
    }

    private function gate(): UhfGate
    {
        return new UhfGate(
            app(RfidScanService::class),
            app(HardwareEventService::class),
            fn () => null,
            fn () => $this->t,
            function (string $host, int $port) {
                $this->connects[] = [$host, $port];

                return false;
            },
            owner: 'gate:test',
            connectCheck: fn () => true,
            registrations: app(RfidRegistrationService::class),
            readLogs: app(RfidReadLogService::class),
            udpBinder: function (string $bind, int $port) {
                $this->binds[] = $port;

                return fopen('php://memory', 'r');
            },
        );
    }

    private function step(UhfGate $gate, float $dt = 0.05): void
    {
        $this->t += $dt;
        $gate->reloadIfNeeded();
        $gate->tick();
    }

    #[Test]
    public function datagram_menjadi_scan_real_uhf_tanpa_menyambung_ke_reader(): void
    {
        $reader = $this->udpReader('WET-GATE-01', 9101);
        $gate = $this->gate();
        $this->step($gate);

        $link = $gate->link($reader->id);
        $this->assertInstanceOf(Ur4UdpLink::class, $link);
        $this->assertSame([9101], $this->binds);
        $this->assertSame([], $this->connects, 'mode UDP tidak pernah menyambung ke IP LAN reader');

        for ($i = 0; $i < 5; $i++) {
            $link->receive(Ur4Frames::tag(self::EPC, 1, -50.0), '140.213.69.213:13148');
            $this->step($gate, 0.1);
        }

        $logs = RfidReadLog::where('epc', self::EPC)->get();
        $this->assertCount(1, $logs, 'push berulang satu tag = satu peristiwa (filter kehadiran)');
        $this->assertSame('real_uhf', $logs[0]->origin);
        $this->assertSame(-50, $logs[0]->rssi);

        $this->t += UhfGate::HEARTBEAT_EVERY;
        $gate->heartbeatIfDue();
        $fresh = $reader->fresh();
        $this->assertSame('streaming', $fresh->last_status['link_state']);
        $this->assertSame('140.213.69.213', $fresh->last_ip);
        $this->assertNotNull($fresh->last_seen_at);
        $gate->shutdown();
    }

    #[Test]
    public function datagram_dari_sumber_asing_tidak_menjadi_scan(): void
    {
        $reader = $this->udpReader('WET-GATE-01', 9101);
        $gate = $this->gate();
        $this->step($gate);

        $gate->link($reader->id)->receive(Ur4Frames::tag(self::EPC), '203.0.113.7:5000');
        $this->step($gate, 0.1);

        $this->assertSame(0, RfidReadLog::where('epc', self::EPC)->count());
        $gate->shutdown();
    }

    #[Test]
    public function berganti_mode_menutup_gate_lama_dan_membuka_yang_baru(): void
    {
        $reader = $this->udpReader('WET-GATE-01', 9101);
        $gate = $this->gate();
        $this->step($gate);
        $this->assertInstanceOf(Ur4UdpLink::class, $gate->link($reader->id));

        $reader->forceFill(['connection' => ['host' => '192.168.0.31', 'port' => 8888]])->save();
        Cache::increment(RfidReader::REVISION_KEY);
        $this->step($gate, UhfGate::REV_CHECK_EVERY + 0.1);
        $this->step($gate);

        $this->assertNotInstanceOf(Ur4UdpLink::class, $gate->link($reader->id));
        $this->assertContains(['192.168.0.31', 8888], $this->connects);
        $gate->shutdown();
    }

    // ─── Validasi registri reader ─────────────────────────────────────────

    private function payload(array $over = []): array
    {
        return array_replace_recursive([
            'code' => 'GATE-UDP', 'name' => 'Gate', 'stage' => 'warehouse', 'device_type' => 'uhf_chainway_ur4',
            'direction' => 'in', 'connection' => ['host' => '192.168.99.202', 'port' => 8888, 'mode' => 'udp_push', 'udp_port' => 9101],
        ], $over);
    }

    #[Test]
    public function api_menyimpan_mode_udp_dan_menolak_port_ganda_atau_kosong(): void
    {
        $this->actingAs($this->admin)->postJson(self::BASE.'/readers', $this->payload())->assertStatus(201);
        $saved = RfidReader::where('code', 'GATE-UDP')->firstOrFail()->connectionConfig();
        $this->assertSame('udp_push', $saved['mode']);
        $this->assertSame(9101, (int) $saved['udp_port']);

        $this->actingAs($this->admin)
            ->postJson(self::BASE.'/readers', $this->payload(['code' => 'GATE-UDP2', 'connection' => ['host' => '192.168.99.203']]))
            ->assertStatus(422)->assertJsonValidationErrors('connection.udp_port');

        $this->actingAs($this->admin)
            ->postJson(self::BASE.'/readers', $this->payload(['code' => 'GATE-UDP3', 'connection' => ['host' => '192.168.99.204', 'udp_port' => null]]))
            ->assertStatus(422)->assertJsonValidationErrors('connection.udp_port');

        $this->actingAs($this->admin)
            ->postJson(self::BASE.'/readers', $this->payload(['code' => 'GATE-UDP4', 'connection' => ['host' => '192.168.99.205', 'udp_port' => 80]]))
            ->assertStatus(422)->assertJsonValidationErrors('connection.udp_port');
    }
}
