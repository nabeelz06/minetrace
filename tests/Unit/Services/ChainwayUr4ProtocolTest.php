<?php

namespace Tests\Unit\Services;

use App\Services\Hardware\Uhf\ChainwayUr4Protocol;
use App\Services\Hardware\Uhf\UhfPresenceFilter;
use App\Services\Hardware\Uhf\Ur4Command;
use App\Services\Hardware\Uhf\Ur4Frame;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Parser/builder protokol UR4 (murni, tanpa DB/soket).
 *
 * Semua vektor datang dari tests/Fixtures/uhf/ur4/golden.json yang DIBANGKITKAN skrip Python independen
 * (tools/uhf/make-golden-vectors.py), BUKAN dari kelas yang diuji, supaya bug simetris builder+parser tidak lolos.
 */
class ChainwayUr4ProtocolTest extends TestCase
{
    private static function golden(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../../Fixtures/uhf/ur4/golden.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    private static function bin(string $hex): string
    {
        return hex2bin(str_replace(' ', '', $hex));
    }

    private static function entry(string $section, string $name): array
    {
        foreach (self::golden()[$section] as $e) {
            if ($e['name'] === $name) {
                return $e;
            }
        }
        self::fail("Vektor {$section}/{$name} tidak ada");
    }

    // ─── Builder: daftar izin ─────────────────────────────────────────────

    public static function requestProvider(): array
    {
        return [
            'versi' => ['versi_0x02', fn () => ChainwayUr4Protocol::request(Ur4Command::Version)],
            'daya' => ['daya_0x12', fn () => ChainwayUr4Protocol::request(Ur4Command::Power)],
            'region' => ['region_0x2E', fn () => ChainwayUr4Protocol::request(Ur4Command::Region)],
            'mask' => ['mask_antena_0x2A', fn () => ChainwayUr4Protocol::request(Ur4Command::AntennaMask)],
            'sambungan' => ['sambungan_antena_0x4E', fn () => ChainwayUr4Protocol::request(Ur4Command::AntennaConnection)],
            'mode' => ['mode_inventory_0x72', fn () => ChainwayUr4Protocol::request(Ur4Command::InventoryMode)],
            'return loss' => ['return_loss_0x26', fn () => ChainwayUr4Protocol::request(Ur4Command::ReturnLoss)],
            'start 00 00' => ['start_0x82_0000', fn () => ChainwayUr4Protocol::request(Ur4Command::StartInventory)],
            'stop' => ['stop_0x8C', fn () => ChainwayUr4Protocol::request(Ur4Command::StopInventory)],
            'tulis daya' => ['tulis_daya_ant1_15dbm_save0', fn () => ChainwayUr4Protocol::setPower(1, 15)],
            'tulis region' => ['tulis_region_0x3F_save0', fn () => ChainwayUr4Protocol::setRegion(0x3F)],
            'tulis mode 0' => ['tulis_mode_inventory_0_save0', fn () => ChainwayUr4Protocol::setInventoryMode(0)],
            'tulis mode 1' => ['tulis_mode_inventory_1_save0', fn () => ChainwayUr4Protocol::setInventoryMode(1)],
            'tulis mask' => ['tulis_mask_ant1_save0', fn () => ChainwayUr4Protocol::setAntennaMask(1)],
        ];
    }

    #[Test]
    #[DataProvider('requestProvider')]
    public function builder_menghasilkan_byte_yang_sama_dengan_vektor_independen(string $name, \Closure $build): void
    {
        $this->assertSame(strtoupper(self::entry('requests', $name)['hex']), strtoupper(bin2hex($build())), $name);
    }

    #[Test]
    public function konstanta_start_stop_sama_dengan_builder_dan_start_baku_bukan_2710(): void
    {
        $this->assertSame(ChainwayUr4Protocol::START_INVENTORY, ChainwayUr4Protocol::request(Ur4Command::StartInventory));
        $this->assertSame(ChainwayUr4Protocol::STOP_INVENTORY, ChainwayUr4Protocol::request(Ur4Command::StopInventory));
        // Payload 27 10 hanya kebiasaan klien npm pihak ketiga; tidak boleh lagi dibangun.
        $this->assertNotSame(strtoupper(self::entry('requests', 'start_2710_klien_npm')['hex']), strtoupper(bin2hex(ChainwayUr4Protocol::START_INVENTORY)));
    }

    #[Test]
    public function hanya_byte_daftar_izin_yang_bisa_dibangun_menelusuri_00_sampai_ff(): void
    {
        $allowed = [0x02, 0x12, 0x2E, 0x2A, 0x4E, 0x72, 0x82, 0x8C, 0x26, 0x10, 0x2C, 0x70, 0x28];
        $fromEnum = [];
        for ($b = 0x00; $b <= 0xFF; $b++) {
            if (Ur4Command::tryFrom($b) !== null) {
                $fromEnum[] = $b;
            }
        }
        sort($allowed);
        $this->assertSame($allowed, $fromEnum, 'Enum daftar izin harus persis sama dengan daftar yang disetujui.');

        // Setiap builder publik hanya menghasilkan Cmd dari daftar izin.
        $built = [];
        foreach (Ur4Command::cases() as $c) {
            if (! $c->isConfigWrite()) {
                $built[] = ChainwayUr4Protocol::request($c);
            }
        }
        $built[] = ChainwayUr4Protocol::setPower(2, 20);
        $built[] = ChainwayUr4Protocol::setRegion(1);
        $built[] = ChainwayUr4Protocol::setInventoryMode(1);
        $built[] = ChainwayUr4Protocol::setAntennaMask(3);
        foreach ($built as $frame) {
            $this->assertContains(ord($frame[4]), $allowed);
        }

        // Dan tidak ada metode publik statis yang menerima byte Cmd mentah.
        foreach ((new \ReflectionClass(ChainwayUr4Protocol::class))->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_STATIC) as $m) {
            foreach ($m->getParameters() as $p) {
                $type = (string) $p->getType();
                $this->assertFalse($type === 'int' && in_array($p->getName(), ['cmd', 'command', 'opcode'], true), "{$m->getName()}() menerima Cmd mentah");
            }
        }
    }

    #[Test]
    public function perintah_terlarang_tidak_punya_builder(): void
    {
        $forbidden = [0x1C, 0xC0, 0xC1, 0xC2, 0xC3, 0xC4, 0xC5, 0xC6, 0x74, 0x68, 0xA1, 0x24, 0x86, 0x88, 0x8A, 0x93, 0x95, 0x8E, 0x9F, 0x97, 0x99, 0x9B, 0x9D, 0xA3];
        foreach ($forbidden as $b) {
            $this->assertNull(Ur4Command::tryFrom($b), sprintf('0x%02X tidak boleh ada di daftar izin', $b));
        }
    }

    #[Test]
    public function request_menolak_perintah_tulis_dan_builder_tulis_selalu_save_nol(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ChainwayUr4Protocol::request(Ur4Command::SetPower);
    }

    #[Test]
    public function builder_tulis_selalu_save_nol_dan_memvalidasi_rentang(): void
    {
        $this->assertSame("\x00", ChainwayUr4Protocol::setPower(1, 15)[5], 'byte pertama payload daya = save = 0');
        $this->assertSame("\x00", ChainwayUr4Protocol::setRegion(0x3F)[5]);
        $this->assertSame("\x00", ChainwayUr4Protocol::setInventoryMode(0)[5]);
        $this->assertSame("\x00", ChainwayUr4Protocol::setAntennaMask(1)[5]);

        foreach ([[fn () => ChainwayUr4Protocol::setPower(0, 15)], [fn () => ChainwayUr4Protocol::setPower(1, 31)],
            [fn () => ChainwayUr4Protocol::setPower(1, 4)], [fn () => ChainwayUr4Protocol::setInventoryMode(2)],
            [fn () => ChainwayUr4Protocol::setAntennaMask(0)]] as [$call]) {
            try {
                $call();
                $this->fail('Seharusnya melempar InvalidArgumentException');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ─── Dekode tag ───────────────────────────────────────────────────────

    public static function tagProvider(): array
    {
        $out = [];
        foreach (self::golden()['tags'] as $t) {
            $out[$t['name']] = [$t];
        }

        return $out;
    }

    #[Test]
    #[DataProvider('tagProvider')]
    public function dekode_tag_sesuai_vektor(array $t): void
    {
        $p = new ChainwayUr4Protocol;
        $tags = $p->feed(self::bin($t['hex']));
        $e = $t['expect'];

        if ($e['result'] === 'tag') {
            $this->assertCount(1, $tags, $t['name']);
            $this->assertSame($e['epc'], $tags[0]['epc']);
            $this->assertSame($e['tid'], $tags[0]['tid']);
            $this->assertSame($e['rssi'], $tags[0]['rssi']);
            $this->assertSame($e['antenna'], $tags[0]['antenna']);
        } else {
            $this->assertSame([], $tags, "{$t['name']} tidak boleh menghasilkan tag (tidak menebak)");
            $this->assertSame(1, $p->stats()[$e['result'] === 'epc_too_long' ? 'epc_too_long' : 'unparseable']);
        }
    }

    // ─── Aliran ───────────────────────────────────────────────────────────

    #[Test]
    public function xor_rusak_ditolak_dan_dihitung(): void
    {
        $e = self::entry('streams', 'X1_xor_rusak');
        $p = new ChainwayUr4Protocol;
        $this->assertSame([], $p->feed(self::bin($e['hex'])));
        $this->assertGreaterThanOrEqual(1, $p->stats()['frames_bad']);
        $this->assertSame(0, $p->stats()['frames_ok']);
    }

    #[Test]
    public function xor_dapat_dimatikan_lewat_konfigurasi(): void
    {
        $e = self::entry('streams', 'X1_xor_rusak');
        $this->assertCount(1, (new ChainwayUr4Protocol(verifyXor: false))->feed(self::bin($e['hex'])));
    }

    #[Test]
    public function byte_heartbeat_nol_ditoleransi_tanpa_dihitung_rusak(): void
    {
        $p = new ChainwayUr4Protocol;
        $tags = $p->feed(self::bin(self::entry('streams', 'X2_heartbeat_00_di_antara')['hex']));

        $this->assertCount(2, $tags);
        $this->assertSame(['E20000200914016323809025', '523742303030303539353537'], array_column($tags, 'epc'));
        $this->assertSame(0, $p->stats()['frames_bad']);
        $this->assertSame(1, $p->stats()['idle_bytes']);
    }

    #[Test]
    public function satu_byte_per_satu_dan_sampah_di_depan_tetap_terbaca(): void
    {
        $one = self::bin(self::entry('streams', 'F1_satu_byte_per_satu')['hex']);
        $p = new ChainwayUr4Protocol;
        $tags = [];
        foreach (str_split($one, 1) as $b) {
            array_push($tags, ...$p->feed($b));
        }
        $this->assertCount(1, $tags);

        $junk = (new ChainwayUr4Protocol);
        $this->assertCount(1, $junk->feed(self::bin(self::entry('streams', 'sampah_di_depan')['hex'])));
        $this->assertGreaterThan(0, $junk->stats()['bytes_discarded']);
    }

    #[Test]
    public function payload_yang_memuat_0d0a_tidak_memotong_bingkai(): void
    {
        $frames = (new ChainwayUr4Protocol)->feedFrames(self::bin(self::entry('streams', 'payload_memuat_0D0A')['hex']));
        $this->assertCount(1, $frames);
        $this->assertStringContainsString("\r\n", $frames[0]->payload);
    }

    // ─── Balasan: bingkai non-tag tidak boleh hilang ──────────────────────

    private function reply(string $name): Ur4Frame
    {
        $frames = (new ChainwayUr4Protocol)->feedFrames(self::bin(self::entry('replies', $name)['hex']));
        $this->assertCount(1, $frames, $name);

        return $frames[0];
    }

    #[Test]
    public function bingkai_non_tag_terlihat_oleh_pemanggil_dan_feed_tetap_hanya_tag(): void
    {
        $ack = $this->reply('ack_stop');
        $this->assertSame(ChainwayUr4Protocol::CMD_STOP_ACK, $ack->cmd);
        $this->assertSame("\x01", $ack->payload);

        $this->assertSame([], (new ChainwayUr4Protocol)->feed(self::bin(self::entry('replies', 'ack_stop')['hex'])));
    }

    #[Test]
    public function parser_balasan_konfigurasi(): void
    {
        $this->assertSame('7.40.1', ChainwayUr4Protocol::parseVersion($this->reply('versi_7_40_1'))['text']);

        $power = ChainwayUr4Protocol::parsePower($this->reply('daya_4_antena_30dbm'));
        $this->assertSame([1, 2, 3, 4], array_keys($power));
        $this->assertSame(30.0, $power[3]['read_dbm']);
        $this->assertSame(30.0, $power[4]['write_dbm']);

        $this->assertSame(0x3F, ChainwayUr4Protocol::parseRegion($this->reply('region_indonesia_0x3F')));
        $this->assertSame(1, ChainwayUr4Protocol::parseMask($this->reply('mask_ant1'), Ur4Command::AntennaMask));
        $this->assertSame(1, ChainwayUr4Protocol::parseMask($this->reply('sambungan_ant1'), Ur4Command::AntennaConnection));
        $this->assertSame([1], ChainwayUr4Protocol::antennasInMask(1));
        $this->assertSame([1, 3, 4], ChainwayUr4Protocol::antennasInMask(0b1101));

        // fw 7.40.1 menambah 4 byte nol di balasan mode inventory: dibaca hanya byte 0..1
        $this->assertSame(0, ChainwayUr4Protocol::parseInventoryMode($this->reply('mode_inventory_0_fw7401')));
        $this->assertSame(1, ChainwayUr4Protocol::parseInventoryMode($this->reply('mode_inventory_1')));

        $loss = ChainwayUr4Protocol::parseReturnLoss($this->reply('return_loss_ant1_16db'));
        $this->assertSame(16, $loss[1]);
    }

    #[Test]
    public function parser_menolak_balasan_dengan_cmd_salah(): void
    {
        $this->assertNull(ChainwayUr4Protocol::parseVersion($this->reply('ack_stop')));
        $this->assertNull(ChainwayUr4Protocol::parseRegion($this->reply('versi_7_40_1')));
        $this->assertNull(ChainwayUr4Protocol::parseMask($this->reply('mask_ant1'), Ur4Command::Region));
    }

    // ─── Filter kehadiran (tidak berubah) ─────────────────────────────────

    #[Test]
    public function filter_kehadiran_satu_peristiwa_per_kehadiran(): void
    {
        $f = new UhfPresenceFilter(10);

        $this->assertTrue($f->shouldProcess('A|in', 100.0));
        $this->assertFalse($f->shouldProcess('A|in', 105.0));   // masih di medan antena
        $this->assertFalse($f->shouldProcess('A|in', 114.0));   // jendela bergeser (terakhir 105)
        $this->assertTrue($f->shouldProcess('B|in', 114.0));
        $this->assertTrue($f->shouldProcess('A|in', 130.0));    // hilang ≥10 dtk lalu muncul lagi

        $f->prune(150.0);
        $this->assertSame(0, $f->count());
    }
}
