<?php

namespace Tests\Unit\Services;

use App\Services\Hardware\Uhf\Ur4UdpLink;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\Ur4Frames;

/** Penerima push UDP UR4: urai bingkai 0x83 dari datagram, filter sumber, status jujur. */
class Ur4UdpLinkTest extends TestCase
{
    private const EPC = 'E28068940000502FBF283924';

    private float $t = 1000.0;

    /** @var list<array> */
    private array $tags = [];

    private function link(array $cidrs = [], ?\Closure $binder = null, int $port = 0): Ur4UdpLink
    {
        return new Ur4UdpLink(
            $port,
            '127.0.0.1',
            $cidrs,
            120.0,
            fn () => $this->t,
            function (array $tag) {
                $this->tags[] = $tag;
            },
            binder: $binder ?? fn () => fopen('php://memory', 'r'),
        );
    }

    #[Test]
    public function datagram_dari_reader_menjadi_tag(): void
    {
        $link = $this->link();
        $link->tick();

        // Datagram nyata yang ditangkap dari WET-GATE-01 pada 2026-11-27.
        $link->receive(hex2bin('a55a0019833000e28068940000502fbf283924fd5c01610d0a'), '140.213.69.213:13148');

        $this->assertCount(1, $this->tags);
        $this->assertSame(self::EPC, $this->tags[0]['epc']);
        $this->assertSame(1, $this->tags[0]['antenna']);
        $this->assertSame(-68, $this->tags[0]['rssi']);
        $this->assertSame('140.213.69.213', $link->peer());
        $this->assertSame('streaming', $link->uiState());
    }

    #[Test]
    public function sumber_di_luar_cidr_ditolak(): void
    {
        $link = $this->link(['140.213.69.213/32']);
        $link->tick();

        $link->receive(Ur4Frames::tag(self::EPC), '203.0.113.9:4000');
        $this->assertSame([], $this->tags);
        $this->assertSame(1, $link->status()['counters']['rejected_source']);
        $this->assertSame('listening', $link->uiState(), 'datagram ditolak tidak membuat reader tampak online');

        $link->receive(Ur4Frames::tag(self::EPC), '140.213.69.213:4000');
        $this->assertCount(1, $this->tags);
    }

    #[Test]
    public function sampah_dan_bingkai_bukan_tag_diabaikan(): void
    {
        $link = $this->link();
        $link->tick();

        $link->receive('hello', '10.0.0.1:1');
        $link->receive(Ur4Frames::build(0x8D), '10.0.0.1:1');

        $this->assertSame([], $this->tags);
        $c = $link->status()['counters'];
        $this->assertSame(1, $c['bad_datagrams']);
        $this->assertSame(1, $c['non_tag_frames']);
    }

    #[Test]
    public function status_menua_menjadi_listening_tanpa_datagram(): void
    {
        $link = $this->link();
        $link->tick();
        $this->assertSame('listening', $link->uiState());

        $link->receive(Ur4Frames::tag(self::EPC), '10.0.0.1:1');
        $this->assertSame('streaming', $link->uiState());

        $this->t += 121;
        $this->assertSame('listening', $link->uiState());
        $this->assertStringContainsString('tidak ada datagram', $link->status()['detail']);
    }

    #[Test]
    public function gagal_bind_dicoba_ulang_terukur(): void
    {
        $calls = 0;
        $link = $this->link(binder: function () use (&$calls) {
            $calls++;

            return $calls >= 2 ? fopen('php://memory', 'r') : null;
        });

        $link->tick();
        $this->assertSame('config_mismatch', $link->uiState());
        $this->assertSame('bind_failed', $link->status()['last_error']);

        $link->tick();
        $this->assertSame(1, $calls, 'tidak mencoba ulang sebelum jeda');

        $this->t += 5;
        $link->tick();
        $this->assertSame(2, $calls);
        $this->assertSame('listening', $link->uiState());
    }

    #[Test]
    public function soket_udp_sungguhan_menerima_datagram(): void
    {
        $probe = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
        $port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $link = $this->link(['127.0.0.1/32'], Ur4UdpLink::udpBinder(), $port);
        $link->tick();
        $this->assertNotNull($link->wantsRead());

        $client = stream_socket_client("udp://127.0.0.1:{$port}");
        fwrite($client, Ur4Frames::tag(self::EPC, 2, -61.0));
        fwrite($client, Ur4Frames::tag(self::EPC, 2, -60.0));
        fclose($client);

        for ($i = 0; $i < 20 && count($this->tags) < 2; $i++) {
            $r = [$link->wantsRead()];
            $w = $e = null;
            if (stream_select($r, $w, $e, 0, 50_000)) {
                $link->onReadable();
            }
        }
        $link->stop();

        $this->assertCount(2, $this->tags);
        $this->assertSame(2, $this->tags[0]['antenna']);
        $this->assertSame('127.0.0.1', $link->peer());
    }
}
