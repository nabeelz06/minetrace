<?php

namespace Tests\Feature\Rfid;

use App\Models\Company;
use App\Models\User;
use App\Services\Hardware\Uhf\EndpointGuard;
use Database\Seeders\Core\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** EndpointGuard (cegah SSRF lewat host reader) + keunikan host:port reader UR4. */
class EndpointGuardTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/company/rfid';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('email', 'admin@ptimah.co.id')->firstOrFail();
        Company::where('code', 'TIMAH')->firstOrFail();
    }

    /** Simulasikan produksi: loopback tidak diizinkan kecuali RFID_UHF_ALLOW_LOOPBACK=true. */
    private function asProduction(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['rfid.uhf.allow_loopback' => false]);
    }

    public static function productionCases(): array
    {
        return [
            'privat 192.168'            => ['192.168.99.202', 8888, null],
            'privat 10.x'               => ['10.1.2.3', 8888, null],
            'privat 172.16-31'          => ['172.20.0.9', 8888, null],
            'loopback ditolak'          => ['127.0.0.1', 8888, 'loopback'],
            'loopback lain ditolak'     => ['127.5.5.5', 8888, 'loopback'],
            'metadata cloud ditolak'    => ['169.254.169.254', 80, 'forbidden_range'],
            'link-local ditolak'        => ['169.254.10.10', 8888, 'forbidden_range'],
            '0.0.0.0 ditolak'           => ['0.0.0.0', 8888, 'forbidden_range'],
            'multicast ditolak'         => ['224.0.0.1', 8888, 'forbidden_range'],
            'broadcast ditolak'         => ['255.255.255.255', 8888, 'forbidden_range'],
            'IP publik ditolak'         => ['8.8.8.8', 8888, 'not_allowed'],
            'luar 172.16/12 ditolak'    => ['172.32.0.1', 8888, 'not_allowed'],
            'nama host ditolak'         => ['reader.local', 8888, 'not_ipv4'],
            'localhost ditolak'         => ['localhost', 8888, 'not_ipv4'],
            'IPv6 ditolak'              => ['::1', 8888, 'not_ipv4'],
            'desimal ditolak'           => ['3232235777', 8888, 'not_ipv4'],
            'port 22 ditolak'           => ['192.168.99.202', 22, 'port_not_allowed'],
            'port 6379 ditolak'         => ['10.0.0.5', 6379, 'port_not_allowed'],
            'port tiruan hanya loopback' => ['192.168.99.202', 18888, 'port_not_allowed'],
        ];
    }

    #[Test]
    #[DataProvider('productionCases')]
    public function aturan_di_produksi(string $host, int $port, ?string $expected): void
    {
        $this->asProduction();

        $this->assertSame($expected, EndpointGuard::validate($host, $port), "{$host}:{$port}");
    }

    #[Test]
    public function loopback_dan_port_tiruan_diizinkan_di_lokal_atau_dengan_flag(): void
    {
        $this->assertNull(EndpointGuard::validate('127.0.0.1', 18888), 'env testing = lokal');

        $this->asProduction();
        $this->assertSame('loopback', EndpointGuard::validate('127.0.0.1', 18888));
        config(['rfid.uhf.allow_loopback' => true]);
        $this->assertNull(EndpointGuard::validate('127.0.0.1', 18888));
        $this->assertNull(EndpointGuard::validate('127.0.0.1', 8888));
    }

    #[Test]
    public function cidr_dan_port_dapat_dikonfigurasi(): void
    {
        $this->asProduction();
        config(['rfid.uhf.allowed_cidrs' => ['100.64.0.0/10'], 'rfid.uhf.allowed_ports' => [8888, 9000]]);

        $this->assertNull(EndpointGuard::validate('100.100.1.1', 9000));
        $this->assertSame('not_allowed', EndpointGuard::validate('192.168.99.202', 8888));
    }

    #[Test]
    public function pesan_mengikuti_bahasa_pengguna(): void
    {
        app()->setLocale('id');
        $this->assertStringContainsString('IPv4', EndpointGuard::message('not_ipv4'));
        $idText = EndpointGuard::message('loopback');

        app()->setLocale('en');
        $this->assertNotSame($idText, EndpointGuard::message('loopback'));
        $this->assertStringContainsString('allowed', EndpointGuard::message('loopback'));
    }

    // ─── Lewat API registri reader ────────────────────────────────────────

    private function payload(array $over = []): array
    {
        return array_replace_recursive([
            'code' => 'GATE-01', 'name' => 'Gate', 'stage' => 'warehouse', 'device_type' => 'uhf_chainway_ur4',
            'direction' => 'in', 'connection' => ['host' => '192.168.99.202', 'port' => 8888],
        ], $over);
    }

    #[Test]
    public function api_menolak_host_berbahaya_dengan_pesan_pada_connection_host(): void
    {
        // Loopback diizinkan di env testing (tercakup tes aturan); yang lain ditolak di lingkungan mana pun.
        // Tidak memakai asProduction(): mengubah env menyalakan CSRF dan membuat permintaan JSON ini 419.
        foreach (['169.254.169.254', 'localhost', '8.8.8.8', '0.0.0.0'] as $i => $host) {
            $this->actingAs($this->admin)
                ->postJson(self::BASE . '/readers', $this->payload(['code' => "G-{$i}", 'connection' => ['host' => $host]]))
                ->assertStatus(422)->assertJsonValidationErrors('connection.host');
        }
    }

    #[Test]
    public function api_menolak_port_tidak_diizinkan(): void
    {
        $this->actingAs($this->admin)
            ->postJson(self::BASE . '/readers', $this->payload(['connection' => ['port' => 6379]]))
            ->assertStatus(422)->assertJsonValidationErrors('connection.host');
    }

    #[Test]
    public function dua_reader_aktif_tidak_boleh_berbagi_host_port_tetapi_nonaktif_boleh(): void
    {
        $this->actingAs($this->admin)->postJson(self::BASE . '/readers', $this->payload())->assertStatus(201);

        // Reader aktif kedua ke host:port yang sama ditolak (UR4 hanya melayani satu klien).
        $this->actingAs($this->admin)->postJson(self::BASE . '/readers', $this->payload(['code' => 'GATE-02']))
            ->assertStatus(422)->assertJsonValidationErrors('connection.host');

        // Port berbeda atau host berbeda: boleh.
        $this->actingAs($this->admin)->postJson(self::BASE . '/readers', $this->payload(['code' => 'GATE-03', 'connection' => ['host' => '192.168.99.203']]))
            ->assertStatus(201);
    }

    #[Test]
    public function update_memvalidasi_endpoint_efektif_dan_mengaktifkan_kembali_dicek_keunikan(): void
    {
        $first = $this->actingAs($this->admin)->postJson(self::BASE . '/readers', $this->payload())->json('data.reader.id');
        $second = $this->actingAs($this->admin)->postJson(self::BASE . '/readers', $this->payload(['code' => 'GATE-02', 'connection' => ['host' => '192.168.99.210']]))->json('data.reader.id');

        // Mengubah host reader kedua ke host reader pertama ditolak.
        $this->actingAs($this->admin)->patchJson(self::BASE . "/readers/{$second}", ['connection' => ['host' => '192.168.99.202']])
            ->assertStatus(422)->assertJsonValidationErrors('connection.host');

        // Mengubah hanya nama (tanpa menyentuh koneksi) tidak memicu cek keunikan terhadap dirinya sendiri.
        $this->actingAs($this->admin)->patchJson(self::BASE . "/readers/{$first}", ['name' => 'Gate Utama'])->assertOk();

        // Nonaktifkan pertama, lalu reader kedua boleh memakai alamatnya; mengaktifkan kembali pertama sekarang bentrok.
        $this->actingAs($this->admin)->patchJson(self::BASE . "/readers/{$first}", ['is_active' => false])->assertOk();
        $this->actingAs($this->admin)->patchJson(self::BASE . "/readers/{$second}", ['connection' => ['host' => '192.168.99.202']])->assertOk();
        $this->actingAs($this->admin)->patchJson(self::BASE . "/readers/{$first}", ['is_active' => true])
            ->assertStatus(422)->assertJsonValidationErrors('connection.host');
    }
}
