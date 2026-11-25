<?php

namespace Tests\Unit\Services;

use App\Services\Hardware\Uhf\UhfGate;
use App\Services\Hardware\Uhf\Ur4InboundListener;
use App\Services\Hardware\Uhf\Ur4Link;
use App\Services\Hardware\Uhf\Ur4LinkConfig;
use App\Services\Hardware\Uhf\Ur4LinkState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeUr4Peer;
use Tests\Support\Ur4Frames;

/**
 * Mode TCP Client: reader yang menyambung ke server. Reader palsu menyambung lewat TCP loopback sungguhan.
 */
class Ur4InboundListenerTest extends TestCase
{
    private const EPC = 'E20000200914016323809025';

    private float $t = 1000.0;

    private array $tags = [];

    private ?Ur4InboundListener $listener = null;

    protected function setUp(): void
    {
        parent::setUp();
        set_error_handler(fn (int $no, string $str) => str_contains($str, 'Broken pipe') || str_contains($str, 'forcibly closed'));
    }

    protected function tearDown(): void
    {
        $this->listener?->close();
        restore_error_handler();
        parent::tearDown();
    }

    private static function freePort(): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
        fclose($s);

        return $port;
    }

    /** Reader palsu yang menyambung keluar ke listener (seperti UR4 mode TCP Client). */
    private function dialIn(int $port): FakeUr4Peer
    {
        $c = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 2);
        $this->assertNotFalse($c, $errstr);

        return new FakeUr4Peer($c);
    }

    private function link(int $port): Ur4Link
    {
        return new Ur4Link(
            UhfGate::INBOUND_HOST,
            $port,
            new Ur4LinkConfig,
            $this->listener->connector($port),
            fn () => $this->t,
            function (array $tag) {
                $this->tags[] = $tag;
            },
            jitter: fn () => 1.0,
            inbound: true,
        );
    }

    private function step(Ur4Link $link, ?FakeUr4Peer $peer, float $dt = 0.05): void
    {
        $this->t += $dt;
        $link->tick();
        if ($link->wantsWrite()) {
            $link->onWritable();
        }
        for ($i = 0; $i < 3; $i++) {
            usleep(2000);
            $peer?->service();
            usleep(2000);
            $link->onReadable();
        }
    }

    #[Test]
    public function tanpa_reader_status_menunggu_tanpa_menghitung_reconnect(): void
    {
        $port = self::freePort();
        $this->listener = new Ur4InboundListener('127.0.0.1');
        $link = $this->link($port);

        for ($i = 0; $i < 60; $i++) {
            $this->step($link, null);
        }

        $this->assertSame('awaiting_reader', $link->uiState());
        $this->assertSame(0, $link->status()['counters']['reconnects']);
    }

    #[Test]
    public function reader_yang_menyambung_masuk_dihandshake_lalu_tag_diteruskan(): void
    {
        $port = self::freePort();
        $this->listener = new Ur4InboundListener('127.0.0.1');
        $link = $this->link($port);

        $this->step($link, null);   // membuka soket dengar
        $peer = $this->dialIn($port);

        for ($i = 0; $i < 200 && $link->state() !== Ur4LinkState::Streaming; $i++) {
            $this->step($link, $peer);
        }
        $this->assertSame(Ur4LinkState::Streaming, $link->state(), json_encode($link->status()));
        $this->assertSame('127.0.0.1', $this->listener->peer($port));
        $peer->service();

        $peer->send(Ur4Frames::tag(self::EPC, antenna: 1, dbm: -50.0));
        $this->step($link, $peer);
        $this->assertCount(1, $this->tags);
        $this->assertSame(self::EPC, $this->tags[0]['epc']);
    }

    #[Test]
    public function sumber_di_luar_cidr_ditolak(): void
    {
        $port = self::freePort();
        $this->listener = new Ur4InboundListener('127.0.0.1', ['10.0.0.0/8']);
        $this->assertFalse($this->listener->accept($port));   // membuka soket dengar
        $this->dialIn($port);
        usleep(20000);

        $this->assertFalse($this->listener->accept($port));
    }

    #[Test]
    public function cidr_dicocokkan_dengan_benar(): void
    {
        $this->assertTrue(Ur4InboundListener::inCidr('114.10.139.154', '114.10.0.0/16'));
        $this->assertFalse(Ur4InboundListener::inCidr('114.11.139.154', '114.10.0.0/16'));
        $this->assertTrue(Ur4InboundListener::inCidr('10.1.2.3', '10.0.0.0/8'));
        $this->assertTrue(Ur4InboundListener::inCidr('1.2.3.4', '1.2.3.4'));
        $this->assertFalse(Ur4InboundListener::inCidr('1.2.3.5', '1.2.3.4/32'));
        $this->assertFalse(Ur4InboundListener::inCidr('::1', '10.0.0.0/8'));
    }
}

