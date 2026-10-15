<?php

namespace Tests\Feature\Rfid;

use App\Jobs\CaptureCctvSnapshotJob;
use App\Models\Batch;
use App\Models\Company;
use App\Models\ProductCode;
use App\Services\Hardware\RfidService;
use Database\Seeders\Core\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CONTRACT TEST perangkat legacy (ESP32 fisik; firmware TIDAK ada di repo — L3).
 * Mengunci bentuk respons /api/rfid/register|login|logout dan /api/v1/hardware/rfid/esp-config.
 * Perubahan hanya boleh ADITIF: kunci yang ada tidak boleh hilang/berubah arti.
 */
class LegacyDeviceContractTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Queue::fake();
        Cache::flush();
        putenv('RFID_API_TOKEN=');
        putenv('RFID_DEFAULT_COMPANY_CODE=');

        $this->company = Company::where('is_regulator', false)->firstOrFail();
        $this->batch   = Batch::factory()->create([
            'company_id'      => $this->company->id,
            'product_code_id' => ProductCode::where('company_id', $this->company->id)->firstOrFail()->id,
            'process_stage'   => 'wet_process',
            'status'          => 'in_process',
            'rfid_tag'        => null,
        ]);
    }

    private function headers(): array
    {
        return ['X-Company-Code' => $this->company->code];
    }

    private function tagBatch(string $uid = 'A1B2C3D4'): void
    {
        $this->batch->update(['rfid_tag' => $uid]);
    }

    #[Test]
    public function register_sukses_menjaga_bentuk_respons_lcd(): void
    {
        app(RfidService::class)->setPendingBatch($this->batch->id, $this->company->id, 'Operator Uji');

        $res = $this->postJson('/api/rfid/register', ['uid' => 'a1:b2:c3:d4'], $this->headers())->assertOk();

        $this->assertEqualsCanonicalizing(
            ['status', 'name', 'message', 'detail', 'batch_id', 'batch_code'],
            array_keys($res->json())
        );
        $res->assertJson(['status' => 'success', 'message' => 'Tag terdaftar!', 'batch_id' => $this->batch->id]);
        $this->assertSame('A1B2C3D4', $this->batch->fresh()->rfid_tag);
        $res->assertHeader('Access-Control-Allow-Origin', '*');
    }

    #[Test]
    public function register_tanpa_sesi_422_dengan_teks_lcd_tetap(): void
    {
        $this->postJson('/api/rfid/register', ['uid' => 'A1B2C3D4'], $this->headers())
            ->assertStatus(422)
            ->assertExactJson([
                'status'  => 'failed',
                'name'    => 'Tidak Ada Sesi',
                'message' => 'Tidak ada sesi',
                'detail'  => 'Mulai sesi registrasi dari panel web MINETRACE terlebih dahulu.',
            ]);
    }

    #[Test]
    public function register_tag_sudah_dipakai_422(): void
    {
        $this->tagBatch();   // UID sudah dipakai batch ini
        $other = Batch::factory()->create([
            'company_id' => $this->company->id, 'product_code_id' => $this->batch->product_code_id, 'rfid_tag' => null,
        ]);
        app(RfidService::class)->setPendingBatch($other->id, $this->company->id, 'Operator Uji');

        $this->postJson('/api/rfid/register', ['uid' => 'A1B2C3D4'], $this->headers())
            ->assertStatus(422)
            ->assertJson(['status' => 'failed', 'message' => 'Tag sudah dipakai']);
    }

    #[Test]
    public function scan_in_audit_only_menjaga_kunci_respons(): void
    {
        $this->tagBatch();

        $res = $this->postJson('/api/rfid/login', ['uid' => 'A1B2C3D4', 'stage' => 'wet_process'], $this->headers())->assertOk();

        $this->assertEqualsCanonicalizing([
            'status', 'name', 'message', 'detail', 'batch_id', 'batch_code', 'company_id', 'product',
            'weight_kg', 'scan_stage', 'stage_label', 'timestamp', 'manual_mode', 'action_required',
        ], array_keys($res->json()));
        $res->assertJson(['status' => 'success', 'message' => 'Scan IN OK', 'scan_stage' => 'wet_process', 'manual_mode' => true]);

        $this->assertDatabaseHas('checkpoints', [
            'batch_id' => $this->batch->id, 'checkpoint_stage' => 'wet_process_input',
            'rfid_scan_code' => 'A1B2C3D4', 'rfid_origin' => 'real_esp32',
        ]);
        Queue::assertPushed(CaptureCctvSnapshotJob::class, 1);
    }

    #[Test]
    public function scan_out_audit_only_menjaga_kunci_respons(): void
    {
        $this->tagBatch();

        $res = $this->postJson('/api/rfid/logout', ['uid' => 'A1B2C3D4', 'stage' => 'wet_process'], $this->headers())->assertOk();

        $res->assertJson(['status' => 'success', 'message' => 'Scan OUT OK', 'manual_mode' => true]);
        $this->assertArrayHasKey('action_required', $res->json());
        $this->assertDatabaseHas('checkpoints', ['batch_id' => $this->batch->id, 'checkpoint_stage' => 'wet_process_output']);
    }

    #[Test]
    public function header_x_requested_with_menandai_simulasi(): void
    {
        $this->tagBatch();

        $this->postJson('/api/rfid/login', ['uid' => 'A1B2C3D4', 'stage' => 'wet_process'],
            $this->headers() + ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();

        $this->assertDatabaseHas('checkpoints', ['rfid_scan_code' => 'A1B2C3D4', 'rfid_origin' => 'simulation']);
    }

    #[Test]
    public function tag_tidak_dikenal_422_dengan_teks_lcd_tetap(): void
    {
        $this->postJson('/api/rfid/login', ['uid' => 'FFFFFFFF'], $this->headers())
            ->assertStatus(422)
            ->assertJson(['status' => 'failed', 'name' => 'Tag Asing', 'message' => 'Tag tidak dikenal']);
    }

    #[Test]
    public function uid_kosong_atau_pendek_422(): void
    {
        $this->postJson('/api/rfid/login', ['uid' => ''], $this->headers())
            ->assertStatus(422)->assertExactJson(['status' => 'failed', 'name' => 'Error', 'message' => 'UID tidak valid atau kosong']);
        $this->postJson('/api/rfid/logout', ['uid' => 'AB'], $this->headers())->assertStatus(422);
    }

    #[Test]
    public function token_salah_401_bila_token_dikonfigurasi(): void
    {
        putenv('RFID_API_TOKEN=rahasia-uji');
        try {
            $this->postJson('/api/rfid/login', ['uid' => 'A1B2C3D4'], $this->headers())
                ->assertStatus(401)->assertExactJson(['status' => 'failed', 'name' => 'Error', 'message' => 'Token tidak valid']);
            $this->tagBatch();
            $this->postJson('/api/rfid/login', ['uid' => 'A1B2C3D4'], $this->headers() + ['X-Rfid-Token' => 'rahasia-uji'])->assertOk();
        } finally {
            putenv('RFID_API_TOKEN=');
        }
    }

    #[Test]
    public function esp_config_menjaga_bentuk(): void
    {
        $res = $this->getJson('/api/v1/hardware/rfid/esp-config', $this->headers())->assertOk();

        $this->assertTrue($res->json('success'));
        $data = $res->json('data');
        foreach (['api_base_url', 'endpoint_register', 'endpoint_scan_in', 'endpoint_scan_out', 'api_token',
                  'company_id', 'company_code', 'company_header', 'timeout_ms', 'mode', 'firmware_note'] as $key) {
            $this->assertArrayHasKey($key, $data, "kunci esp-config hilang: {$key}");
        }
        $this->assertSame('/api/rfid/register', $data['endpoint_register']);
        $this->assertSame('/api/rfid/login', $data['endpoint_scan_in']);
        $this->assertSame('/api/rfid/logout', $data['endpoint_scan_out']);
        $this->assertSame('X-Company-Code', $data['company_header']);
        $this->assertSame($this->company->code, $data['company_code']);
    }
}
