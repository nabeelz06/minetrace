<?php

namespace Tests\Feature\Rfid;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HardwareCheckTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function preflight_berjalan_dan_melaporkan_mediamtx_aktif(): void
    {
        Http::fake(['*' => Http::response(['items' => [['name' => 'cam_gate1', 'ready' => true]]], 200)]);

        $this->artisan('hardware:check')
            ->expectsOutputToContain('Database terhubung')
            ->expectsOutputToContain('MediaMTX API')
            ->expectsOutputToContain('Ringkasan:');
    }

    #[Test]
    public function preflight_gagal_bila_mediamtx_tidak_terjangkau(): void
    {
        Http::fake(['*' => Http::response('x', 500)]);

        $this->artisan('hardware:check')->assertFailed();
    }
}
