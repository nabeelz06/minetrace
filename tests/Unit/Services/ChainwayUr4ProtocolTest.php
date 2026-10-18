<?php

namespace Tests\Unit\Services;

use App\Services\Hardware\Uhf\ChainwayUr4Protocol;
use App\Services\Hardware\Uhf\UhfPresenceFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Parser bingkai TCP Chainway UR4 + filter kehadiran UHF (murni, tanpa DB/soket). */
class ChainwayUr4ProtocolTest extends TestCase
{
    private const EPC = 'E20000200914016323809025';
    private const TID = 'E2003412013101000E5439AF';

    /** Bingkai laporan tag (cmd 0x83) dengan Data = [PC] EPC [TID] RSSI ANT. */
    private function tagFrame(bool $withPc = true, bool $withTid = true, int $rssiTenths = -553, int $ant = 2): string
    {
        $data = ($withPc ? pack('n', 0x3000) : '') . hex2bin(self::EPC)
            . ($withTid ? hex2bin(self::TID) : '')
            . pack('n', $rssiTenths & 0xFFFF) . chr($ant);

        return ChainwayUr4Protocol::frame(ChainwayUr4Protocol::CMD_TAG_REPORT, $data);
    }

    #[Test]
    public function perintah_start_dan_stop_sama_dengan_pustaka_referensi(): void
    {
        $this->assertSame('a55a000a822710bf0d0a', bin2hex(ChainwayUr4Protocol::START_INVENTORY));
        $this->assertSame('a55a00088c840d0a', bin2hex(ChainwayUr4Protocol::STOP_INVENTORY));
        // Builder menghasilkan bingkai start & stop yang identik (checksum XOR terverifikasi).
        $this->assertSame(ChainwayUr4Protocol::START_INVENTORY, ChainwayUr4Protocol::frame(0x82, "\x27\x10"));
        $this->assertSame(ChainwayUr4Protocol::STOP_INVENTORY, ChainwayUr4Protocol::frame(0x8C));
    }

    #[Test]
    public function mengurai_tag_dengan_pc_dan_tid(): void
    {
        $tags = (new ChainwayUr4Protocol())->feed($this->tagFrame());

        $this->assertCount(1, $tags);
        $this->assertSame(self::EPC, $tags[0]['epc']);
        $this->assertSame(self::TID, $tags[0]['tid']);
        $this->assertSame(-55, $tags[0]['rssi']);
        $this->assertSame(2, $tags[0]['antenna']);
    }

    #[Test]
    public function tanpa_tid_dan_tata_letak_pustaka_tanpa_pc(): void
    {
        $noTid = (new ChainwayUr4Protocol())->feed($this->tagFrame(withTid: false));
        $this->assertSame(self::EPC, $noTid[0]['epc']);
        $this->assertNull($noTid[0]['tid']);

        $libLayout = (new ChainwayUr4Protocol())->feed($this->tagFrame(withPc: false));
        $this->assertSame(self::EPC, $libLayout[0]['epc']);
        $this->assertSame(self::TID, $libLayout[0]['tid']);
    }

    #[Test]
    public function aliran_terpotong_sampah_dan_bingkai_ganda(): void
    {
        $p      = new ChainwayUr4Protocol();
        $stream = "\x00\xFF\xA5" . $this->tagFrame(ant: 1) . $this->tagFrame(ant: 3);

        $tags = [];
        foreach (str_split($stream, 7) as $chunk) {
            array_push($tags, ...$p->feed($chunk));
        }

        $this->assertCount(2, $tags);
        $this->assertSame([1, 3], array_column($tags, 'antenna'));
    }

    #[Test]
    public function bingkai_selain_laporan_tag_diabaikan(): void
    {
        $ack = ChainwayUr4Protocol::frame(0x82, "\x01");
        $this->assertSame([], (new ChainwayUr4Protocol())->feed($ack));
    }

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
