<?php

namespace Tests\Feature\Api;

use App\Jobs\CaptureCctvSnapshotJob;
use App\Jobs\Reports\GenerateMonthlyReportJob;
use App\Models\Batch;
use App\Models\CctvCamera;
use App\Models\Checkpoint;
use App\Models\Company;
use App\Models\ProductCode;
use App\Models\User;
use App\Services\Hardware\RfidDeviceService;
use Database\Seeders\Core\DatabaseSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Queue::fake();
        Cache::flush();
        $this->company   = Company::where('code', 'TIMAH')->firstOrFail();
        $this->productId = ProductCode::where('company_id', $this->company->id)->firstOrFail()->id;
    }

    private function bearer(string $email): array
    {
        $user = User::where('email', $email)->firstOrFail();
        // Guard Sanctum menyimpan pengguna antar request dalam satu tes — reset agar tiap token dinilai sendiri.
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer ' . $user->createToken('uji')->plainTextToken];
    }

    // ─── Peran pada API ber-token ─────────────────────────────────────────

    #[Test]
    public function alerts_bim_hanya_untuk_peran_bim_bukan_operator_perusahaan(): void
    {
        $this->getJson('/api/v1/bim/alerts', $this->bearer('wet.mtk@ptimah.co.id'))->assertStatus(403)->assertJson(['code' => 'forbidden']);
        $this->getJson('/api/v1/bim/alerts', $this->bearer('admin@ptimah.co.id'))->assertStatus(403);
        $this->getJson('/api/v1/bim/alerts', $this->bearer('admin@bim.go.id'))->assertOk();
        $this->getJson('/api/v1/bim/overview', $this->bearer('viewer@bim.go.id'))->assertOk();
    }

    #[Test]
    public function auditor_perusahaan_tidak_bisa_menulis_lewat_api(): void
    {
        $h = $this->bearer('audit@ptimah.co.id');

        $this->getJson('/api/v1/company/info', $h)->assertOk();
        $this->postJson('/api/v1/company/batches', [], $h)->assertStatus(403);
        $batch = Batch::factory()->create(['company_id' => $this->company->id, 'product_code_id' => $this->productId]);
        $this->postJson("/api/v1/company/checkpoints/{$batch->id}", [], $h)->assertStatus(403);
        $this->postJson('/api/v1/company/hardware/weight', [], $h)->assertStatus(403);
    }

    #[Test]
    public function peran_bim_tidak_masuk_endpoint_perusahaan(): void
    {
        $this->getJson('/api/v1/company/info', $this->bearer('admin@bim.go.id'))->assertStatus(403);
    }

    #[Test]
    public function token_sanctum_kedaluwarsa_ditolak(): void
    {
        $h = $this->bearer('admin@ptimah.co.id');
        $this->getJson('/api/v1/auth/me', $h)->assertOk();

        $this->travel(config('sanctum.expiration') + 1)->minutes();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', $h)->assertStatus(401);
    }

    #[Test]
    public function login_api_dibatasi_5_percobaan_per_menit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'admin@ptimah.co.id', 'password' => 'salah'])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'admin@ptimah.co.id', 'password' => 'salah'])->assertStatus(429);
    }

    // ─── IDOR snapshot CCTV ───────────────────────────────────────────────

    #[Test]
    public function snapshot_manual_tidak_bisa_memakai_checkpoint_perusahaan_lain(): void
    {
        $other = Company::factory()->create(['is_regulator' => false, 'code' => 'LAIN']);
        $foreignBatch = Batch::factory()->create(['company_id' => $other->id, 'product_code_id' => $this->productId]);
        $foreignCp = Checkpoint::create([
            'batch_id' => $foreignBatch->id, 'checkpoint_stage' => 'in_transit', 'recorded_at' => now(),
        ]);
        $camera = CctvCamera::where('company_id', $this->company->id)->first()
            ?? CctvCamera::create(['company_id' => $this->company->id, 'name' => 'Cam', 'location_key' => 'wet_process', 'is_active' => true]);
        $user = User::where('email', 'wet.mtk@ptimah.co.id')->firstOrFail();

        $this->actingAs($user)->postJson("/api/v1/company/cctv/cameras/{$camera->id}/snapshot", ['checkpoint_id' => $foreignCp->id])
            ->assertStatus(404);
        Queue::assertNothingPushed();

        $ownBatch = Batch::factory()->create(['company_id' => $this->company->id, 'product_code_id' => $this->productId]);
        $ownCp = Checkpoint::create(['batch_id' => $ownBatch->id, 'checkpoint_stage' => 'in_transit', 'recorded_at' => now()]);
        $this->actingAs($user)->postJson("/api/v1/company/cctv/cameras/{$camera->id}/snapshot", ['checkpoint_id' => $ownCp->id])->assertOk();
        Queue::assertPushed(CaptureCctvSnapshotJob::class);

        $auditor = User::where('email', 'audit@ptimah.co.id')->firstOrFail();
        $this->actingAs($auditor)->postJson("/api/v1/company/cctv/cameras/{$camera->id}/snapshot", ['checkpoint_id' => $ownCp->id])->assertStatus(403);
    }

    #[Test]
    public function super_admin_tanpa_perusahaan_tidak_jatuh_ke_perusahaan_1_di_cctv(): void
    {
        $super = User::where('email', 'superadmin@minetra.id')->firstOrFail();
        $this->actingAs($super)->getJson('/api/v1/company/cctv/recent-snapshots')->assertStatus(403);
    }

    // ─── Scheduler laporan bulanan ────────────────────────────────────────

    #[Test]
    public function scheduler_laporan_bulanan_dapat_dijalankan_dan_men_dispatch_job(): void
    {
        $this->assertTrue(class_exists(GenerateMonthlyReportJob::class));

        $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'monthly-reports');
        $this->assertNotNull($event, 'tugas monthly-reports terdaftar');

        $event->run(app());

        Queue::assertPushed(GenerateMonthlyReportJob::class, fn ($job) => true);
    }

    // ─── Endpoint scan terpadu (reader baru) ──────────────────────────────

    #[Test]
    public function endpoint_scan_terpadu_butuh_kunci_perangkat_dan_mencatat_scan(): void
    {
        [$reader, $key] = app(RfidDeviceService::class)->create($this->company->id, [
            'code' => 'UHF-01', 'name' => 'UHF Gudang', 'stage' => 'warehouse', 'direction' => 'any', 'device_type' => 'uhf_epc_gen2',
        ], null);
        $batch = Batch::factory()->create([
            'company_id' => $this->company->id, 'product_code_id' => $this->productId,
            'process_stage' => 'warehouse', 'status' => 'in_warehouse', 'rfid_tag' => 'E2801160600002',
        ]);

        $this->postJson('/api/v1/hardware/rfid-scan', ['uid' => 'E2801160600002', 'direction' => 'in'])->assertStatus(401);

        $this->postJson('/api/v1/hardware/rfid-scan', ['uid' => 'e2801160600002', 'direction' => 'in', 'event_id' => 'uhf-evt-000001'], ['X-Device-Key' => $key])
            ->assertOk()->assertJson(['success' => true, 'code' => 'scan_recorded', 'data' => ['scan_stage' => 'warehouse']]);

        $this->assertDatabaseHas('checkpoints', [
            'batch_id' => $batch->id, 'rfid_origin' => 'real_uhf', 'reader_id' => $reader->id, 'scan_direction' => 'in',
        ]);

        // Idempoten: event_id sama tidak membuat checkpoint baru.
        $this->postJson('/api/v1/hardware/rfid-scan', ['uid' => 'E2801160600002', 'direction' => 'in', 'event_id' => 'uhf-evt-000001'], ['X-Device-Key' => $key])
            ->assertOk()->assertJson(['code' => 'scan_duplicate']);
        $this->assertSame(1, Checkpoint::where('batch_id', $batch->id)->where('reader_id', $reader->id)->count());
    }

    #[Test]
    public function rute_mati_rfid_assign_dan_simulate_scan_sudah_tidak_ada(): void
    {
        $h = $this->bearer('admin@ptimah.co.id');
        $this->postJson('/api/v1/company/hardware/rfid-assign', [], $h)->assertStatus(404);
        $this->postJson('/api/v1/company/hardware/simulate-scan', [], $h)->assertStatus(404);
    }
}
