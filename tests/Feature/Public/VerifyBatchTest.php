<?php

namespace Tests\Feature\Public;

use App\Models\Batch;
use App\Models\Checkpoint;
use App\Models\Company;
use App\Models\ProductCode;
use App\Services\Batch\QrCodeService;
use Database\Seeders\Core\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Halaman verifikasi QR publik (T1). QR yang sudah tercetak berformat
 * {APP_URL}/verify/batch/{id}?code=…&cs=… dan HARUS tetap terverifikasi.
 */
class VerifyBatchTest extends TestCase
{
    use RefreshDatabase;

    private Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        RateLimiter::clear('verify');

        $company = Company::where('is_regulator', false)->firstOrFail();
        $this->batch = Batch::factory()->create([
            'company_id'      => $company->id,
            'product_code_id' => ProductCode::where('company_id', $company->id)->firstOrFail()->id,
            'current_weight'  => 1234.5,
            'process_stage'   => 'wet_process',
            'status'          => 'in_process',
        ]);
    }

    /** Path+query dari payload yang dihasilkan QrCodeService (persis isi QR tercetak). */
    private function qrPath(?Batch $batch = null): string
    {
        $url = app(QrCodeService::class)->buildPayload($batch ?? $this->batch);

        return parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);
    }

    #[Test]
    public function qr_payload_format_lama_terverifikasi_tanpa_login(): void
    {
        $this->get($this->qrPath())
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/VerifyBatch')
                ->where('state', 'valid')
                ->where('batch.batch_code', $this->batch->batch_code)
                ->where('batch.stage.key', 'wet_process'));
    }

    #[Test]
    public function halaman_publik_tidak_membocorkan_berat_hasil_lab_atau_operator(): void
    {
        $response = $this->get($this->qrPath())->assertOk();
        // Hanya data verifikasi (props bersama seperti daftar rute Ziggy bukan bagian kebijakan ini).
        $props = $response->original->getData()['page']['props'];
        $json  = json_encode(['batch' => $props['batch'], 'timeline' => $props['timeline']]);

        $this->assertStringNotContainsString('1234.5', $json);
        foreach (['weight', 'current_weight', 'created_by', 'recorded_by', 'metadata'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json, "kunci terlarang: {$forbidden}");
        }
    }

    #[Test]
    public function checksum_dimanipulasi_ditolak_404(): void
    {
        $this->get("/verify/batch/{$this->batch->id}?code={$this->batch->batch_code}&cs=000000000000")
            ->assertNotFound()
            ->assertInertia(fn (Assert $page) => $page->where('state', 'invalid'));
    }

    #[Test]
    public function kode_batch_tidak_cocok_dan_batch_tidak_ada_memberi_respons_sama(): void
    {
        $cs = parse_str(parse_url($this->qrPath(), PHP_URL_QUERY), $q) ?: $q['cs'];
        $mismatch = $this->get("/verify/batch/{$this->batch->id}?code=SALAH&cs={$q['cs']}");
        $missing  = $this->get("/verify/batch/999999?code={$this->batch->batch_code}&cs={$q['cs']}");

        $mismatch->assertNotFound();
        $missing->assertNotFound();
        $this->assertSame(
            $mismatch->original->getData()['page']['props']['state'],
            $missing->original->getData()['page']['props']['state'],
        );
    }

    #[Test]
    public function parameter_hilang_menghasilkan_422_ramah(): void
    {
        $this->get("/verify/batch/{$this->batch->id}")
            ->assertStatus(422)
            ->assertInertia(fn (Assert $page) => $page->where('state', 'malformed'));
    }

    #[Test]
    public function scan_simulasi_tidak_masuk_linimasa_publik(): void
    {
        foreach ([['lab_kimia_create', null], ['wet_process_input', 'simulation']] as [$stage, $origin]) {
            Checkpoint::create([
                'batch_id' => $this->batch->id, 'location_id' => $this->batch->current_location_id,
                'checkpoint_stage' => $stage, 'recorded_at' => now(), 'rfid_origin' => $origin,
            ]);
        }

        $this->get($this->qrPath())->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('timeline', 1)
                ->where('timeline.0.stage.key', 'lab_kimia'));
    }

    #[Test]
    public function rate_limit_30_per_menit_per_ip(): void
    {
        $path = $this->qrPath();
        for ($i = 0; $i < 30; $i++) {
            $this->get($path)->assertOk();
        }
        $this->get($path)->assertStatus(429);
    }

    #[Test]
    public function endpoint_api_publik_tidak_lagi_memuat_berat_dan_ber_throttle(): void
    {
        parse_str(parse_url($this->qrPath(), PHP_URL_QUERY), $q);
        $res = $this->getJson("/api/v1/verify/batch/{$this->batch->id}?code={$q['code']}&cs={$q['cs']}")
            ->assertOk();

        $this->assertArrayNotHasKey('current_weight', $res->json('data'));
        $this->assertNotNull($res->headers->get('X-RateLimit-Limit'));
    }
}
