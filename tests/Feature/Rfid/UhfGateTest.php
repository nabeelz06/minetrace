<?php

namespace Tests\Feature\Rfid;

use App\Models\Batch;
use App\Models\Checkpoint;
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

    private const EPC_2 = 'E20000200914016323809026';

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
        $this->step($gate, 0.5);   // jendela kumpul registrasi (400 ms) berakhir → keputusan diambil

        $this->assertSame(self::EPC, $batch->fresh()->rfid_tag, 'tap pertama di reader sesi mengikat tag, bukan scan in/out');
        $log = RfidReadLog::where('epc', self::EPC)->firstOrFail();
        $this->assertSame(['register', 'accepted', 'real_uhf'], [$log->action, $log->result, $log->origin]);
        $this->assertSame($batch->id, $log->batch_id);

        $snap = Checkpoint::where('batch_id', $batch->id)->where('rfid_scan_code', self::EPC)->firstOrFail()->checkpoint_data['batch_snapshot'];
        $this->assertSame(['wet_process', 'in_process'], [$snap['process_stage'], $snap['status']], 'posisi awal dicatat sebagai baseline reset uji');
        $gate->shutdown();
    }

    /** @return array{0:RfidReader,1:Batch,2:UhfGate} reader UR4 satu antena + batch tanpa tag + sesi registrasi menunggu reader itu. */
    private function registrationSetup(): array
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

        return [$reader, $batch, $gate];
    }

    #[Test]
    public function registrasi_dengan_dua_tag_sama_kuat_ditolak_dan_tidak_mengikat_tag_tetangga(): void
    {
        [, $batch, $gate] = $this->registrationSetup();
        $peer = $this->peers['192.168.99.202:8888'];

        $peer->send(Ur4Frames::tag(self::EPC, 1, -50.0));
        $peer->send(Ur4Frames::tag(self::EPC_2, 1, -52.0));   // selisih 2 dB < ambang dominasi 6 dB
        $this->step($gate, 0.1);
        $this->step($gate, 0.6);

        $this->assertNull($batch->fresh()->rfid_tag, 'tidak boleh menebak: dua tag setara → tidak ada yang diikat');

        $session = collect(app(RfidRegistrationService::class)->activeForCompany($this->company->id))->firstWhere('batch_id', $batch->id);
        $this->assertNotNull($session, 'sesi tetap hidup agar operator bisa mencoba lagi');
        $this->assertSame('multiple_tags', $session['last_attempt']['code']);
        $this->assertSame(2, $session['last_attempt']['count']);

        $log = RfidReadLog::where('action', 'register')->firstOrFail();
        $this->assertSame(['rejected', 'multiple_tags'], [$log->result, $log->result_code]);
        $gate->shutdown();
    }

    #[Test]
    public function registrasi_dengan_dua_tag_mengikat_tag_yang_jelas_terkuat(): void
    {
        [, $batch, $gate] = $this->registrationSetup();
        $peer = $this->peers['192.168.99.202:8888'];

        $peer->send(Ur4Frames::tag(self::EPC_2, 1, -72.0));   // tetangga jauh
        $peer->send(Ur4Frames::tag(self::EPC, 1, -45.0));     // tag di tangan operator, unggul 27 dB
        $this->step($gate, 0.1);
        $this->step($gate, 0.6);

        $this->assertSame(self::EPC, $batch->fresh()->rfid_tag);
        $gate->shutdown();
    }

    #[Test]
    public function tag_yang_baru_didaftarkan_tidak_berubah_menjadi_scan_hantu_selama_masih_di_medan_antena(): void
    {
        [, $batch, $gate] = $this->registrationSetup();
        $peer = $this->peers['192.168.99.202:8888'];

        for ($i = 0; $i < 4; $i++) {
            $peer->send(Ur4Frames::tag(self::EPC, 1, -50.0));
            $this->step($gate, 0.2);
        }
        $this->assertSame(self::EPC, $batch->fresh()->rfid_tag);

        for ($i = 0; $i < 5; $i++) {   // tag masih ditahan di depan reader setelah registrasi
            $peer->send(Ur4Frames::tag(self::EPC, 1, -50.0));
            $this->step($gate, 0.2);
        }

        $this->assertSame(0, RfidReadLog::where('epc', self::EPC)->whereIn('action', ['in', 'out'])->count(), 'pembacaan lanjutan tidak menjadi scan IN/OUT');
        $this->assertSame(1, RfidReadLog::where('epc', self::EPC)->where('action', 'register')->count());
        $gate->shutdown();
    }

    #[Test]
    public function jendela_registrasi_nol_mengikat_tag_pertama_seketika_perilaku_lama(): void
    {
        config(['rfid.uhf.registration_window_ms' => 0]);
        [, $batch, $gate] = $this->registrationSetup();

        $this->peers['192.168.99.202:8888']->send(Ur4Frames::tag(self::EPC, 1, -50.0));
        $this->step($gate, 0.1);

        $this->assertSame(self::EPC, $batch->fresh()->rfid_tag);
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

    // ─── Serah-terima lewat reader: tap → mode otorisasi → posisi batch (skenario uji lab) ───

    /** Batch yang tagnya sudah terdaftar di $stage (status $status), siap dibaca reader. */
    private function taggedBatch(string $stage, string $status = 'in_process'): Batch
    {
        $product = ProductCode::where('company_id', $this->company->id)->firstOrFail();

        return Batch::factory()->create([
            'company_id' => $this->company->id, 'product_code_id' => $product->id,
            'process_stage' => $stage, 'status' => $status, 'rfid_tag' => self::EPC,
        ]);
    }

    /** Tag meninggalkan medan antena lalu datang lagi: kehadiran + dedupe 3 dtk dilupakan (di produksi: jeda ≥ presence_seconds). */
    private function tagLeavesAndReturns(RfidReader $reader, string $direction, string $stage): void
    {
        CachePresenceFilter::forget("{$reader->id}:".self::EPC);
        Cache::forget('rfid:dd:'.self::EPC.":{$direction}:{$stage}");
    }

    /** @return array{0:RfidReader,1:UhfGate} reader UR4 satu antena "Scan Masuk saja" di Wet Process, sudah streaming. */
    private function wetEntryGate(): array
    {
        $reader = $this->reader('WET-GATE-IN', extra: ['direction' => 'in', 'connection' => [
            'host' => '192.168.99.202', 'port' => 8888, 'antennas' => ['1' => 'in'],
        ]]);
        $gate = $this->gate();
        $this->step($gate);
        $this->streaming($gate, $reader->id);

        return [$reader, $gate];
    }

    #[Test]
    public function tap_di_reader_tanpa_mode_otorisasi_hanya_tercatat_dan_batch_tetap_di_receiving(): void
    {
        $batch = $this->taggedBatch('receiving');
        [, $gate] = $this->wetEntryGate();

        $this->peers['192.168.99.202:8888']->send(Ur4Frames::tag(self::EPC, 1, -50.0));
        $this->step($gate, 0.1);

        $log = RfidReadLog::where('epc', self::EPC)->firstOrFail();
        $this->assertSame(['in', 'accepted', 'real_uhf'], [$log->action, $log->result, $log->origin], 'pembacaan DITERIMA sebagai audit');
        $this->assertSame(['audit_only', 'audit'], [$log->meta['mode'] ?? null, $log->meta['transition'] ?? null], 'log baca menyebut mode yang dipakai gate + "hanya tercatat"');
        $fresh = $batch->fresh();
        $this->assertSame(['receiving', 'in_process'], [$fresh->process_stage, $fresh->status], 'mode bawaan "Catat saja": posisi batch tidak berubah');
        $this->assertSame(0, Checkpoint::where('batch_id', $batch->id)->where('checkpoint_data->action', 'rfid_auto_receive')->count());
        $gate->shutdown();
    }

    #[Test]
    public function mode_diubah_dari_ui_saat_gate_sudah_berjalan_berlaku_pada_tap_berikutnya_tanpa_restart(): void
    {
        $batch = $this->taggedBatch('receiving');
        [$reader, $gate] = $this->wetEntryGate();
        $peer = $this->peers['192.168.99.202:8888'];

        // Tap pertama (mode bawaan) — gate menyimpan "audit_only" di cache mode.
        $peer->send(Ur4Frames::tag(self::EPC, 1, -50.0));
        $this->step($gate, 0.1);
        $this->assertSame('receiving', $batch->fresh()->process_stage);

        // Administrator memilih "Scan Masuk = terima otomatis" untuk Wet Process (jalur yang sama dengan UI).
        app(RfidAuthorizationService::class)->setMode($this->company->id, 'wet_process', 'scan_to_receive', $this->admin->id);

        $this->tagLeavesAndReturns($reader, 'in', 'wet_process');
        $peer->send(Ur4Frames::tag(self::EPC, 1, -50.0));
        $this->step($gate, 0.1);

        $fresh = $batch->fresh();
        $this->assertSame(['wet_process', 'in_process'], [$fresh->process_stage, $fresh->status], 'tap berikutnya MEMINDAHKAN batch ke tahap reader');
        $this->assertSame(1, Checkpoint::where('batch_id', $batch->id)->where('checkpoint_data->action', 'rfid_auto_receive')->count());
        $latest = RfidReadLog::where('epc', self::EPC)->orderByDesc('id')->firstOrFail();
        $this->assertSame(['scan_to_receive', 'moved'], [$latest->meta['mode'] ?? null, $latest->meta['transition'] ?? null], 'log tap kedua menyebut mode baru yang dipakai gate');
        $gate->shutdown();
    }

    #[Test]
    public function reader_arah_masuk_tidak_pernah_menghasilkan_scan_keluar_dan_batch_tidak_terkirim(): void
    {
        $batch = $this->taggedBatch('wet_process');
        app(RfidAuthorizationService::class)->setMode($this->company->id, 'wet_process', 'scan_to_dispatch', $this->admin->id);
        [$reader, $gate] = $this->wetEntryGate();
        $peer = $this->peers['192.168.99.202:8888'];

        for ($i = 0; $i < 2; $i++) {
            $peer->send(Ur4Frames::tag(self::EPC, 1, -50.0));
            $this->step($gate, 0.1);
            $this->tagLeavesAndReturns($reader, 'in', 'wet_process');
        }

        $this->assertSame(['in'], RfidReadLog::where('epc', self::EPC)->pluck('action')->unique()->values()->all(), 'reader "Scan Masuk saja": semua pembacaan berarah Masuk');
        $this->assertSame('in_process', $batch->fresh()->status, 'tanpa Scan Keluar tidak ada pengiriman (mode dispatch hanya bereaksi pada Scan Keluar)');
        $gate->shutdown();
    }

    #[Test]
    public function reader_dua_arah_dengan_pemetaan_antena_membaca_scan_keluar_dan_mengirim_batch(): void
    {
        $batch = $this->taggedBatch('wet_process');
        app(RfidAuthorizationService::class)->setMode($this->company->id, 'wet_process', 'scan_to_dispatch', $this->admin->id);
        $reader = $this->reader('WET-GATE-2WAY');   // arah "Dua arah": antena 1 = Masuk, antena 2 = Keluar
        $gate = $this->gate();
        $this->step($gate);
        $this->streaming($gate, $reader->id);

        $this->peers['192.168.99.202:8888']->send(Ur4Frames::tag(self::EPC, 2, -48.0));   // tag melewati sisi antena KELUAR
        $this->step($gate, 0.1);
        $this->step($gate, 1.5);                                                          // jendela arbitrasi antena (1,2 dtk) berakhir

        $log = RfidReadLog::where('epc', self::EPC)->firstOrFail();
        $this->assertSame(['out', 'accepted', 2], [$log->action, $log->result, $log->antenna]);
        $this->assertSame(['scan_to_dispatch', 'moved'], [$log->meta['mode'] ?? null, $log->meta['transition'] ?? null]);
        $fresh = $batch->fresh();
        $this->assertSame(['wet_process', 'in_transit'], [$fresh->process_stage, $fresh->status], 'Scan Keluar mengirim batch (tetap di tahap asal, status dalam pengiriman)');
        $gate->shutdown();
    }

    #[Test]
    public function transisi_yang_ditolak_guard_tercatat_di_log_beserta_alasannya_dan_batch_tidak_berubah(): void
    {
        // Batch masih di Gudang, tetapi reader Wet Process (mode terima otomatis) membacanya: guard serah-terima menolak.
        $batch = $this->taggedBatch('warehouse');
        app(RfidAuthorizationService::class)->setMode($this->company->id, 'wet_process', 'scan_to_receive', $this->admin->id);
        [, $gate] = $this->wetEntryGate();

        $this->peers['192.168.99.202:8888']->send(Ur4Frames::tag(self::EPC, 1, -50.0));
        $this->step($gate, 0.1);

        $log = RfidReadLog::where('epc', self::EPC)->firstOrFail();
        $this->assertSame('accepted', $log->result, 'pembacaan tetap tercatat');
        $this->assertSame(['scan_to_receive', 'blocked'], [$log->meta['mode'] ?? null, $log->meta['transition'] ?? null]);
        $this->assertNotEmpty($log->meta['reason'] ?? null, 'alasan guard disimpan agar UI dapat menjelaskannya');
        $fresh = $batch->fresh();
        $this->assertSame(['warehouse', 'in_process'], [$fresh->process_stage, $fresh->status], 'batch tidak berpindah');

        $presented = app(RfidReadLogService::class)->present($log);
        $this->assertSame(['scan_to_receive', 'blocked', $log->meta['reason']], [$presented['mode'], $presented['transition'], $presented['transition_reason']]);
        $gate->shutdown();
    }

    #[Test]
    public function batch_yang_sudah_di_tahap_reader_dicatat_sebagai_sudah_selesai_bukan_galat(): void
    {
        $this->taggedBatch('wet_process');
        app(RfidAuthorizationService::class)->setMode($this->company->id, 'wet_process', 'scan_to_receive', $this->admin->id);
        [, $gate] = $this->wetEntryGate();

        $this->peers['192.168.99.202:8888']->send(Ur4Frames::tag(self::EPC, 1, -50.0));
        $this->step($gate, 0.1);

        $log = RfidReadLog::where('epc', self::EPC)->firstOrFail();
        $this->assertSame(['scan_to_receive', 'already_done'], [$log->meta['mode'] ?? null, $log->meta['transition'] ?? null]);
        $gate->shutdown();
    }

    #[Test]
    public function mode_cache_mengikuti_ttl_yang_dikonfigurasi_dan_nol_berarti_tanpa_cache(): void
    {
        $svc = app(RfidAuthorizationService::class);
        $cid = $this->company->id;

        config(['rfid.mode_cache_seconds' => 60]);
        $this->assertSame('audit_only', $svc->getMode($cid, 'wet_process'));
        // Perubahan LANGSUNG di DB (proses lain / cache tak terhapus) tidak terlihat selama TTL, tetapi terlihat setelahnya —
        // batas keterlambatan mode di gate adalah TTL, bukan 1 jam.
        RfidAuthorizationMode::create(['company_id' => $cid, 'stage' => 'wet_process', 'mode' => 'scan_full', 'updated_by' => $this->admin->id]);
        $this->assertSame('audit_only', $svc->getMode($cid, 'wet_process'));
        $this->travel(61)->seconds();
        $this->assertSame('scan_full', $svc->getMode($cid, 'wet_process'));

        // 0 = tanpa cache: perubahan di DB langsung terlihat.
        config(['rfid.mode_cache_seconds' => 0]);
        Cache::forget('rfid_auth_mode_'.$cid.'_wet_process');
        $this->assertSame('scan_full', $svc->getMode($cid, 'wet_process'));
        RfidAuthorizationMode::where('company_id', $cid)->where('stage', 'wet_process')->update(['mode' => 'scan_to_receive']);
        $this->assertSame('scan_to_receive', $svc->getMode($cid, 'wet_process'));
    }
}
