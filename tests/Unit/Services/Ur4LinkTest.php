<?php

namespace Tests\Unit\Services;

use App\Services\Hardware\Uhf\ChainwayUr4Protocol;
use App\Services\Hardware\Uhf\Ur4Command;
use App\Services\Hardware\Uhf\Ur4Link;
use App\Services\Hardware\Uhf\Ur4LinkConfig;
use App\Services\Hardware\Uhf\Ur4LinkState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeUr4Peer;
use Tests\Support\Ur4Frames;

/**
 * Mesin status Ur4Link terhadap "reader" palsu di ujung lain pasangan soket. Tanpa sleep: jam disuntikkan.
 */
class Ur4LinkTest extends TestCase
{
    private const EPC = 'E20000200914016323809025';

    private float $t = 1000.0;

    /** @var list<array> */
    private array $tags = [];

    /** @var list<FakeUr4Peer> */
    private array $peers = [];

    private array $peerOpts = [];

    private int $connects = 0;

    private bool $refuse = false;

    /** @var (callable(FakeUr4Peer):void)|null */
    private $onConnect = null;

    private Ur4Link $link;

    protected function setUp(): void
    {
        parent::setUp();
        // Menulis ke soket yang sudah ditutup lawan memang menghasilkan "Broken pipe" (sudah di-@ di kode produksi dan
        // menjadi write_failed); PHPUnit tetap melaporkannya sebagai notice, jadi ditekan di sini.
        set_error_handler(fn (int $no, string $str) => str_contains($str, 'Broken pipe'));
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        parent::tearDown();
    }

    private function make(?Ur4LinkConfig $cfg = null): void
    {
        $this->link = new Ur4Link(
            '192.168.99.202',
            8888,
            $cfg ?? new Ur4LinkConfig,
            function () {
                if ($this->refuse) {
                    return false;
                }
                [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                stream_set_blocking($a, false);
                $peer = new FakeUr4Peer($b, ...$this->peerOpts);
                $this->peers[] = $peer;
                $this->connects++;
                if ($this->onConnect) {
                    ($this->onConnect)($peer);
                }

                return $a;
            },
            fn () => $this->t,
            function (array $tag) {
                $this->tags[] = $tag;
            },
            connectCheck: fn () => true,
            jitter: fn () => 1.0,
        );
    }

    private function peer(): FakeUr4Peer
    {
        return end($this->peers);
    }

    /** Satu putaran: waktu maju, tick, kesiapan tulis, layani reader palsu, baca. */
    private function step(float $dt = 0.05): void
    {
        $this->t += $dt;
        $this->link->tick();
        if ($this->link->wantsWrite()) {
            $this->link->onWritable();
        }
        for ($i = 0; $i < 3; $i++) {   // permintaan -> jawaban -> permintaan berikutnya
            if ($this->peers) {
                $this->peer()->service();
            }
            $this->link->onReadable();
        }
    }

    private function advance(float $seconds, float $dt = 0.05): void
    {
        $end = $this->t + $seconds;
        while ($this->t < $end) {
            $this->step($dt);
        }
    }

    private function runUntil(callable $cond, float $max = 10.0): void
    {
        $end = $this->t + $max;
        while ($this->t < $end) {
            if ($cond()) {
                return;
            }
            $this->step();
        }
        $this->assertTrue($cond(), 'Kondisi tidak tercapai dalam '.$max.' dtk. Status: '.json_encode($this->link->status()));
    }

    private function streaming(): void
    {
        $this->runUntil(fn () => $this->link->state() === Ur4LinkState::Streaming);
        $this->peer()->service();   // START dikirim tepat saat masuk Streaming; biarkan reader palsu membacanya
    }

    private function counter(string $name): int
    {
        return $this->link->status()['counters'][$name] ?? 0;
    }

    // ─── Jalur normal ─────────────────────────────────────────────────────

    #[Test]
    public function jalur_normal_handshake_konfigurasi_lalu_inventory_dan_tag_diteruskan(): void
    {
        $this->make();
        $this->streaming();

        // Urutan PERMINTAAN: STOP, lalu konfigurasi baca-saja satu per satu, START terakhir. Tanpa perintah lain.
        $this->assertSame([0x8C, 0x02, 0x12, 0x2E, 0x2A, 0x4E, 0x72, 0x82], $this->peer()->commands);

        $dev = $this->link->status()['device'];
        $this->assertSame('7.40.1', $dev['firmware']);
        $this->assertSame(0x3F, $dev['region']);
        $this->assertSame(15.0, $dev['power_dbm_max']);
        $this->assertSame(0, $dev['inventory_mode']);
        $this->assertSame('streaming', $this->link->uiState());

        $this->peer()->send(Ur4Frames::tag(self::EPC, antenna: 2, dbm: -55.3));
        $this->step();
        $this->assertCount(1, $this->tags);
        $this->assertSame(self::EPC, $this->tags[0]['epc']);
        $this->assertSame(2, $this->tags[0]['antenna']);
        $this->assertSame(-55, $this->tags[0]['rssi']);
    }

    #[Test]
    public function tag_yang_datang_sebelum_ack_handshake_dibuang_dan_tidak_diteruskan(): void
    {
        $this->onConnect = fn (FakeUr4Peer $p) => $p->send(Ur4Frames::tag(self::EPC).Ur4Frames::tag(self::EPC));
        $this->make();
        $this->streaming();

        $this->assertSame([], $this->tags);
        $this->assertSame(2, $this->counter('handshake_dropped'));
    }

    #[Test]
    public function ack_tak_diminta_di_awal_koneksi_ditoleransi_dan_hening_300ms_diperlukan(): void
    {
        // Firmware mengirim ack STOP sendiri ±0,2 dtk setelah koneksi baru; ack kedua dari STOP kita menyusul.
        $this->onConnect = fn (FakeUr4Peer $p) => $p->send(Ur4Frames::build(0x8D, "\x01"));
        $this->make();
        $this->streaming();

        $this->assertTrue($this->peer()->sawCommand(0x82));
        $this->assertSame([], $this->tags);
    }

    // ─── Mati sejak lahir ────────────────────────────────────────────────

    #[Test]
    public function koneksi_mati_sejak_lahir_dideteksi_dan_disambung_ulang_setelah_jeda(): void
    {
        $this->peerOpts = ['ackStop' => false];
        $this->make(new Ur4LinkConfig(reconnectPause: 2.0));

        $this->runUntil(fn () => $this->counter('dead_on_arrival') === 1, 5.0);
        $this->assertSame(Ur4LinkState::Backoff, $this->link->state());
        $this->assertSame(1, $this->connects);

        // Jeda sambung ulang >= 2 dtk (data sumber: 0/20 mati sejak lahir setelah 2 dtk).
        $before = $this->t;
        $this->runUntil(fn () => $this->connects === 2, 5.0);
        $this->assertGreaterThanOrEqual(1.95, $this->t - $before);

        // Reader yang sehat pada percobaan berikutnya: tautan pulih dan hitungan beruntun di-reset.
        $this->peerOpts = ['ackStop' => true];
        $this->runUntil(fn () => $this->link->state() === Ur4LinkState::Streaming, 15.0);
        $this->assertSame(Ur4LinkState::Streaming, $this->link->state());
    }

    // ─── Keselamatan RF dan konfigurasi ──────────────────────────────────

    #[Test]
    public function daya_melebihi_batas_menolak_memulai_inventory_dan_tidak_pernah_mengirim_start(): void
    {
        $this->peerOpts = ['powerDbm' => 30.0];
        $this->make(new Ur4LinkConfig(maxPowerDbm: 15.0));

        $this->runUntil(fn () => $this->link->state() === Ur4LinkState::ConfigMismatch);

        $this->assertSame('config_mismatch', $this->link->uiState());
        $this->assertSame('power_exceeds_limit', $this->link->status()['last_error']);
        $this->assertFalse($this->peer()->sawCommand(0x82), 'START TIDAK boleh terkirim saat daya melebihi batas');
        $this->assertNull($this->link->wantsRead());
    }

    #[Test]
    public function setelah_daya_diturunkan_coba_ulang_otomatis_berhasil(): void
    {
        $this->peerOpts = ['powerDbm' => 30.0];
        $this->make(new Ur4LinkConfig(maxPowerDbm: 15.0, mismatchRetry: 30.0));
        $this->runUntil(fn () => $this->link->state() === Ur4LinkState::ConfigMismatch);

        $this->peerOpts = ['powerDbm' => 15.0];
        $this->runUntil(fn () => $this->link->state() === Ur4LinkState::Streaming, 40.0);
        $this->assertSame(2, $this->connects);
    }

    #[Test]
    public function batas_daya_diset_tetapi_daya_tak_terbaca_gagal_aman(): void
    {
        // Antena aktif 3 tetapi tabel daya reader palsu memuat 1..4: ganti topeng ke antena 5 yang tidak ada di tabel.
        $this->peerOpts = ['antennaMask' => 0b10000];
        $this->make(new Ur4LinkConfig(maxPowerDbm: 15.0));

        $this->runUntil(fn () => $this->link->state() === Ur4LinkState::ConfigMismatch);
        $this->assertSame('power_unknown', $this->link->status()['last_error']);
        $this->assertFalse($this->peer()->sawCommand(0x82));
    }

    #[Test]
    public function mode_inventory_tak_didukung_atau_tanpa_antena_aktif_ditolak(): void
    {
        $this->peerOpts = ['inventoryMode' => 2];
        $this->make();
        $this->runUntil(fn () => $this->link->state() === Ur4LinkState::ConfigMismatch);
        $this->assertSame('inventory_mode_unsupported', $this->link->status()['last_error']);
        $this->assertFalse($this->peer()->sawCommand(0x82));

        $this->peers = [];
        $this->peerOpts = ['antennaMask' => 0];
        $this->make();
        $this->runUntil(fn () => $this->link->state() === Ur4LinkState::ConfigMismatch);
        $this->assertSame('no_active_antenna', $this->link->status()['last_error']);
    }

    #[Test]
    public function tidak_ada_antena_terpetakan_yang_aktif_ditolak(): void
    {
        $this->peerOpts = ['antennaMask' => 0b0001];                     // hanya ANT1 aktif
        $this->make(new Ur4LinkConfig(antennaMap: [2 => 'in', 3 => 'out']));

        $this->runUntil(fn () => $this->link->state() === Ur4LinkState::ConfigMismatch);
        $this->assertSame('no_mapped_antenna', $this->link->status()['last_error']);
    }

    #[Test]
    public function daya_di_atas_ambang_peringatan_hanya_menandai_peringatan(): void
    {
        $this->peerOpts = ['powerDbm' => 28.0];
        $this->make(new Ur4LinkConfig(warnPowerDbm: 26.0));
        $this->streaming();

        $this->assertContains('power_above_warn', $this->link->status()['warnings']);
    }

    #[Test]
    public function antena_tak_tersambung_menurut_0x4e_hanya_peringatan_bukan_kegagalan(): void
    {
        // Makna 0x4E di unit nyata belum terbukti: tidak boleh menggagalkan tautan.
        $this->peerOpts = ['antennaConn' => 0];
        $this->make();
        $this->streaming();

        $this->assertContains('antenna_maybe_disconnected', $this->link->status()['warnings']);
    }

    #[Test]
    public function reader_yang_tak_menjawab_konfigurasi_gagal_dengan_config_timeout(): void
    {
        $this->peerOpts = ['answerConfig' => false];
        $this->make(new Ur4LinkConfig(configTimeout: 1.0));

        $this->runUntil(fn () => $this->link->status()['last_error'] === 'config_timeout', 8.0);
        $this->assertFalse($this->peer()->sawCommand(0x82));
    }

    // ─── Liveness, perebutan, re-arm ─────────────────────────────────────

    #[Test]
    public function heartbeat_nol_dikirim_saat_hening_dan_tidak_saat_data_mengalir(): void
    {
        $this->make();
        $this->streaming();
        $base = $this->peer()->heartbeats;

        $this->advance(4.5);
        $this->assertSame($base, $this->peer()->heartbeats, 'belum 5 dtk hening');
        $this->advance(1.0);
        $this->assertSame($base + 1, $this->peer()->heartbeats, 'heartbeat pertama setelah 5 dtk hening');
        $this->advance(3.1);
        $this->assertSame($base + 2, $this->peer()->heartbeats, 'lalu tiap 3 dtk');

        // Data mengalir -> tidak ada heartbeat tambahan.
        $now = $this->peer()->heartbeats;
        for ($i = 0; $i < 12; $i++) {
            $this->peer()->send(Ur4Frames::tag(self::EPC));
            $this->advance(1.0);
        }
        $this->assertSame($now, $this->peer()->heartbeats);
    }

    #[Test]
    public function dua_reset_pada_tautan_sehat_dalam_jendela_menjadi_contested_dan_jeda_panjang(): void
    {
        $this->make(new Ur4LinkConfig(contestedResets: 2, contestedWindow: 120, contestedBackoff: 60));
        $this->streaming();

        $this->peer()->close();                         // reset pertama
        $this->runUntil(fn () => $this->link->state() === Ur4LinkState::Backoff, 2.0);
        $this->streaming();
        $this->assertSame(2, $this->connects);

        $this->peer()->close();                         // reset kedua dalam jendela
        $this->runUntil(fn () => $this->link->state() === Ur4LinkState::Contested, 2.0);
        $this->assertSame('contested', $this->link->uiState());
        $this->assertSame(1, $this->counter('contested'));

        // 59 dtk: TIDAK merebut kembali.
        $this->advance(59.0);
        $this->assertSame(2, $this->connects);

        // Setelah jeda: menyambung lagi.
        $this->advance(2.0);
        $this->assertSame(3, $this->connects);
    }

    #[Test]
    public function reset_di_luar_jendela_tidak_dianggap_direbut(): void
    {
        $this->make(new Ur4LinkConfig(contestedResets: 2, contestedWindow: 120));
        $this->streaming();
        $this->peer()->close();
        $this->runUntil(fn () => $this->link->state() === Ur4LinkState::Backoff, 2.0);
        $this->streaming();

        $this->advance(130.0);                              // jauh di luar jendela 120 dtk
        $this->peer()->close();
        $this->runUntil(fn () => $this->link->state() === Ur4LinkState::Backoff, 2.0);

        $this->assertSame(0, $this->counter('contested'));
    }

    #[Test]
    public function ack_stop_tak_diminta_saat_streaming_memicu_start_ulang_lalu_stalled(): void
    {
        $this->make(new Ur4LinkConfig(rearmPerMinute: 3));
        $this->streaming();

        for ($i = 1; $i <= 3; $i++) {
            $this->peer()->send(Ur4Frames::build(0x8D, "\x01"));
            $this->step();
            $this->assertSame($i, $this->counter('rearms'));
            $this->assertSame(Ur4LinkState::Streaming, $this->link->state());
        }
        $starts = count(array_filter($this->peer()->commands, fn ($c) => $c === 0x82));
        $this->assertSame(4, $starts, 'START awal + 3 re-arm');

        $this->peer()->send(Ur4Frames::build(0x8D, "\x01"));
        $this->step();
        $this->assertSame(Ur4LinkState::Stalled, $this->link->state());
        $this->assertSame('stalled', $this->link->uiState());
    }

    // ─── Koneksi gagal ───────────────────────────────────────────────────

    #[Test]
    public function tak_terjangkau_dilaporkan_dan_backoff_naik_eksponensial_dengan_dasar_jeda(): void
    {
        $this->refuse = true;
        $this->make(new Ur4LinkConfig(reconnectPause: 2.0, reconnectMax: 30));

        $delays = [];
        for ($i = 0; $i < 5; $i++) {
            $this->runUntil(fn () => $this->link->state() === Ur4LinkState::Backoff && $this->link->nextWake() > 0, 40.0);
            $delays[] = round($this->link->nextWake(), 1);
            $this->advance($this->link->nextWake() + 0.1);
        }

        $this->assertSame('unreachable', $this->link->uiState());
        $this->assertSame('connect_failed', $this->link->status()['last_error']);
        // Dasar jeda 2 dtk, lalu 2^n; diukur satu langkah (0,05) setelah masuk Backoff, jadi toleransi 0,2.
        foreach ([2.0, 2.0, 4.0, 8.0, 16.0] as $i => $expected) {
            $this->assertEqualsWithDelta($expected, $delays[$i], 0.2, "jeda ke-{$i}");
        }
    }

    #[Test]
    public function sambungan_async_yang_tak_selesai_kena_connect_timeout(): void
    {
        $this->make(new Ur4LinkConfig(connectTimeout: 3.0));
        $this->t += 0.01;
        $this->link->tick();                             // membuka soket -> Connecting
        $this->assertSame(Ur4LinkState::Connecting, $this->link->state());

        $this->t += 3.1;
        $this->link->tick();                             // tanpa onWritable: tak pernah tersambung
        $this->assertSame('connect_timeout', $this->link->status()['last_error']);
        $this->assertSame('unreachable', $this->link->uiState());
    }

    // ─── Bingkai rusak, stop ─────────────────────────────────────────────

    #[Test]
    public function tag_dengan_xor_rusak_tidak_diteruskan_dan_dihitung(): void
    {
        $this->make();
        $this->streaming();

        $bad = Ur4Frames::tag(self::EPC);
        $bad[strlen($bad) - 3] = chr(ord($bad[strlen($bad) - 3]) ^ 0xFF);
        $this->peer()->send($bad);
        $this->step();

        $this->assertSame([], $this->tags);
        $this->assertGreaterThanOrEqual(1, $this->counter('frames_bad'));
        $this->assertSame(Ur4LinkState::Streaming, $this->link->state(), 'bingkai rusak tidak memutus tautan');
    }

    #[Test]
    public function tag_tak_terurai_dihitung_dan_tidak_ditebak(): void
    {
        $this->make();
        $this->streaming();

        // Rekaman mode fase+frekuensi (V4): sisa 20 byte -> unparseable.
        $this->peer()->send(Ur4Frames::build(0x83, "\x34\x00".hex2bin('523742303030303539353537').hex2bin('E2801170200041FF40AC0B9F').hex2bin('00510D3A54').pack('n', 0xFDB1)."\x01"));
        $this->step();

        $this->assertSame([], $this->tags);
        $this->assertSame(1, $this->counter('unparseable'));
    }

    #[Test]
    public function stop_mengirim_stop_menutup_soket_dan_tidak_menyambung_ulang(): void
    {
        $this->make();
        $this->streaming();
        $peer = $this->peer();

        $this->link->stop();
        $peer->service();

        $this->assertSame(0x8C, end($peer->commands));
        $this->advance(120.0);
        $this->assertSame(1, $this->connects);
        $this->assertNull($this->link->wantsRead());
    }

    #[Test]
    public function wantsread_dan_wantswrite_mengikuti_state(): void
    {
        $this->make();
        $this->assertNull($this->link->wantsRead());
        $this->assertNull($this->link->wantsWrite());

        $this->t += 0.01;
        $this->link->tick();
        $this->assertNotNull($this->link->wantsWrite(), 'Connecting menunggu siap tulis');
        $this->assertNull($this->link->wantsRead());

        $this->streaming();
        $this->assertNull($this->link->wantsWrite());
        $this->assertNotNull($this->link->wantsRead());
    }

    // ─── State Ready (alat diagnostik) ───────────────────────────────────

    private function ready(): void
    {
        $this->make(new Ur4LinkConfig(startInventory: false));
        $this->runUntil(fn () => $this->link->state() === Ur4LinkState::Ready);
        $this->peer()->service();
    }

    #[Test]
    public function tanpa_start_inventory_berhenti_di_ready_dan_tidak_pernah_memancar(): void
    {
        $this->ready();

        $this->assertSame('ready', $this->link->uiState());
        $this->assertSame([0x8C, 0x02, 0x12, 0x2E, 0x2A, 0x4E, 0x72], $this->peer()->commands);
        $this->assertFalse($this->peer()->sawCommand(0x82), 'START tidak boleh terkirim di Ready');

        // Dari Ready inventory baru dimulai atas permintaan eksplisit.
        $this->assertTrue($this->link->startInventory());
        $this->peer()->service();
        $this->assertTrue($this->peer()->sawCommand(0x82));
        $this->assertSame(Ur4LinkState::Streaming, $this->link->state());
    }

    #[Test]
    public function start_inventory_tetap_menghormati_batas_daya_karena_konfigurasi_dinilai_dulu(): void
    {
        $this->peerOpts = ['powerDbm' => 30.0];
        $this->make(new Ur4LinkConfig(startInventory: false, maxPowerDbm: 15.0));
        $this->runUntil(fn () => $this->link->state() === Ur4LinkState::ConfigMismatch);

        $this->assertFalse($this->link->startInventory(), 'dari ConfigMismatch tidak ada jalan ke inventory');
        $this->assertFalse($this->peer()->sawCommand(0x82));
    }

    #[Test]
    public function return_loss_hanya_diukur_atas_permintaan_eksplisit_di_ready(): void
    {
        $this->make();
        $this->streaming();
        $this->assertFalse($this->link->requestReturnLoss(), 'tidak di Streaming');

        $this->peers = [];
        $this->ready();
        $this->assertFalse($this->peer()->sawCommand(0x26));
        $this->assertTrue($this->link->requestReturnLoss());
        $this->step();
        $this->assertTrue($this->peer()->sawCommand(0x26));
        $this->assertSame(16, $this->link->status()['device']['return_loss'][1]);
    }

    #[Test]
    public function tulis_konfigurasi_hanya_di_ready_dengan_save_nol_dan_menunggu_ack(): void
    {
        $this->ready();

        $frame = ChainwayUr4Protocol::setPower(1, 15);
        $this->assertTrue($this->link->applyWrite(Ur4Command::SetPower, $frame));
        $this->step();
        $this->assertTrue($this->link->writeResult());
        $this->assertSame([strtoupper(bin2hex($frame))], $this->peer()->writes);
    }

    #[Test]
    public function applywrite_menolak_save_bukan_nol_cmd_tak_cocok_dan_state_selain_ready(): void
    {
        $this->ready();

        // Bingkai daya dengan byte save = 02 (simpan permanen) harus ditolak walau cmd benar.
        $save2 = "\xA5\x5A\x00\x0E\x10\x02\x01\x05\xDC\x05\xDC\x1D\x0D\x0A";
        $this->assertFalse($this->link->applyWrite(Ur4Command::SetPower, $save2));
        // Cmd tak cocok dengan bingkai.
        $this->assertFalse($this->link->applyWrite(Ur4Command::SetRegion, ChainwayUr4Protocol::setPower(1, 15)));
        // Perintah baca-saja bukan perintah tulis.
        $this->assertFalse($this->link->applyWrite(Ur4Command::Power, ChainwayUr4Protocol::request(Ur4Command::Power)));
        $this->step();
        $this->assertSame([], $this->peer()->writes);

        $this->peers = [];
        $this->make();
        $this->streaming();
        $this->assertFalse($this->link->applyWrite(Ur4Command::SetPower, ChainwayUr4Protocol::setPower(1, 15)), 'tidak boleh saat Streaming');
    }

    #[Test]
    public function tap_merekam_byte_rx_dan_tx(): void
    {
        $log = [];
        $this->link = new Ur4Link('192.168.99.202', 8888, new Ur4LinkConfig(startInventory: false),
            function () {
                [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                stream_set_blocking($a, false);
                $this->peers[] = new FakeUr4Peer($b);

                return $a;
            }, fn () => $this->t, fn () => null, connectCheck: fn () => true, jitter: fn () => 1.0,
            tap: function (string $dir, string $bytes) use (&$log) {
                $log[] = [$dir, strtoupper(bin2hex($bytes))];
            });
        $this->runUntil(fn () => $this->link->state() === Ur4LinkState::Ready);

        $this->assertSame(['tx', 'A55A00088C840D0A'], $log[0]);
        $this->assertContains('rx', array_column($log, 0));
    }
}
