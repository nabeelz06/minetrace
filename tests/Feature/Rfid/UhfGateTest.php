<?php

namespace Tests\Feature\Rfid;

use App\Models\Batch;
use App\Models\Company;
use App\Models\ProductCode;
use App\Models\RfidReader;
use App\Models\RfidReadLog;
use App\Models\User;
use App\Services\Hardware\HardwareEventService;
use App\Services\Hardware\RfidDeviceService;
use App\Services\Hardware\RfidReadLogService;
use App\Services\Hardware\RfidRegistrationService;
use App\Services\Hardware\RfidScanService;
use App\Services\Hardware\Uhf\UhfGate;
use App\Services\Hardware\Uhf\Ur4ExclusiveLock;
use Database\Seeders\Core\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeUr4Peer;
use Tests\Support\Ur4Frames;
use Tests\TestCase;

/**
 * Orkestrator UhfGate terhadap reader palsu (pasangan soket), DB sungguhan, jam disuntikkan.
 */
class UhfGateTest extends TestCase
{
    use RefreshDatabase;

    private const EPC = 'E20000200914016323809025';

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

    #[Test]
    public function tag_terbaca_menjadi_satu_scan_dengan_log_real_uhf_dan_kehadiran_menahan_duplikat(): void
    {
        $reader = $this->reader('WET-GATE-01', extra: ['direction' => 'in', 'connection' => [
            'host' => '192.168.99.202', 'port' => 8888, 'antennas' => ['1' => 'in'],
        ]]);
        $gate = $this->gate();
        $this->step($gate);
        $this->streaming($gate, $reader->id);

        $peer = $this->peers['192.168.99.202:8888'];
        for ($i = 0; $i < 5; $i++) {
            $peer->send(Ur4Frames::tag(self::EPC, 1, -50.0));
            $this->step($gate, 0.1);
        }

        $logs = RfidReadLog::where('epc', self::EPC)->get();
        $this->assertCount(1, $logs, 'lima pembacaan beruntun satu tag = satu peristiwa');
        $this->assertSame('real_uhf', $logs[0]->origin);
        $this->assertSame(1, $logs[0]->antenna);
        $this->assertSame(-50, $logs[0]->rssi);
        $gate->shutdown();
    }

    #[Test]
    public function pakai_reader_tap_pertama_di_gate_ur4_mengikat_tag_ke_sesi_registrasi_aktif(): void
    {
        $reader = $this->reader('WET-GATE-REG', extra: ['direction' => 'in', 'connection' => [
            'host' => '192.168.99.202', 'port' => 8888, 'antennas' => ['1' => 'in'],
        ]]);
        $product = ProductCode::where('company_id', $this->company->id)->firstOrFail();
        $batch = Batch::factory()->create([
            'company_id' => $this->company->id, 'product_code_id' => $product->id,
            'process_stage' => 'wet_process', 'status' => 'in_process', 'rfid_tag' => null,
        ]);
        app(RfidRegistrationService::class)->start($this->admin, $batch, $reader);

        $gate = $this->gate();
        $this->step($gate);
        $this->streaming($gate, $reader->id);

        $peer = $this->peers['192.168.99.202:8888'];
        for ($i = 0; $i < 3; $i++) {
            $peer->send(Ur4Frames::tag(self::EPC, 1, -50.0));
            $this->step($gate, 0.1);
        }

        $this->assertSame(self::EPC, $batch->fresh()->rfid_tag, 'tap pertama di reader sesi mengikat tag, bukan scan in/out');
        $log = RfidReadLog::where('epc', self::EPC)->firstOrFail();
        $this->assertSame(['register', 'accepted', 'real_uhf'], [$log->action, $log->result, $log->origin]);
        $this->assertSame($batch->id, $log->batch_id);
        $gate->shutdown();
    }

    #[Test]
    public function reader_status_jujur_dan_last_seen_hanya_saat_streaming(): void
    {
        $reader = $this->reader('WET-GATE-02');
        $gate = $this->gate();
        $this->step($gate);
        $this->assertNull($reader->fresh()->last_seen_at);

        $this->streaming($gate, $reader->id);
        $this->t += 25;
        $gate->heartbeatIfDue();

        $fresh = $reader->fresh();
        $this->assertNotNull($fresh->last_seen_at);
        $this->assertSame('streaming', $fresh->last_status['link_state']);
        $this->assertSame('rfid:uhf-gate', $fresh->last_status['daemon']);
        $gate->shutdown();
    }

    #[Test]
    public function endpoint_ditolak_dan_host_ganda_dilewati_dengan_status_jelas(): void
    {
        $ok = $this->reader('GATE-A');
        $dupe = $this->reader('GATE-B', '192.168.99.203');
        // Paksa duplikat/terlarang langsung di DB (validasi request sudah mencegahnya di UI).
        $dupe->forceFill(['connection' => ['host' => '192.168.99.202', 'port' => 8888]])->save();
        $bad = $this->reader('GATE-C', '192.168.99.204');
        $bad->forceFill(['connection' => ['host' => '169.254.169.254', 'port' => 8888]])->save();

        $gate = $this->gate();
        $gate->reload();

        $this->assertSame([$ok->id], $gate->readerIds());
        $this->assertSame('config_mismatch', $dupe->fresh()->last_status['link_state']);
        $this->assertSame('endpoint_duplicate', $dupe->fresh()->last_status['last_error']);
        $this->assertSame('endpoint_forbidden_range', $bad->fresh()->last_status['last_error']);
        $gate->shutdown();
    }

    #[Test]
    public function kunci_eksklusif_dipegang_selama_hidup_dan_dilepas_saat_berhenti(): void
    {
        $reader = $this->reader('GATE-LOCK');
        $gate = $this->gate();
        $gate->reload();

        $this->assertSame('gate:test', Ur4ExclusiveLock::holder('192.168.99.202', 8888));
        $gate->shutdown();
        $this->assertNull(Ur4ExclusiveLock::holder('192.168.99.202', 8888));
        $this->assertSame([], $gate->readerIds());
        $this->assertNotNull($reader->fresh());
    }

    #[Test]
    public function endpoint_yang_dipegang_proses_lain_ditandai_contested_dan_tidak_dibuka(): void
    {
        $reader = $this->reader('GATE-BUSY');
        Ur4ExclusiveLock::acquire('192.168.99.202', 8888, 'probe:other', 30);

        $gate = $this->gate();
        $gate->reload();

        $this->assertSame([], $gate->readerIds());
        $this->assertSame('contested', $reader->fresh()->last_status['link_state']);
    }

    #[Test]
    public function perubahan_reader_dari_ui_memicu_muat_ulang_lewat_revisi_cache(): void
    {
        $gate = $this->gate();
        $gate->reloadIfNeeded();
        $this->assertSame(0, $gate->count());

        $reader = $this->reader('GATE-NEW');
        $this->t += UhfGate::REV_CHECK_EVERY + 0.1;
        $gate->reloadIfNeeded();

        $this->assertSame([$reader->id], $gate->readerIds(), 'reader baru terpakai tanpa menunggu 60 dtk');
        $gate->shutdown();
    }
}
