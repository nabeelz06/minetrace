<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Halaman publik tidak membocorkan struktur aplikasi (mis. tabel rute Ziggy). */
class PublicExposureTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function halaman_login_tidak_memuat_tabel_rute(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringNotContainsString('ziggy', strtolower($html));
        $this->assertStringNotContainsString('api.v1.company', $html);
        $this->assertStringNotContainsString('super-admin', $html);
    }
}
