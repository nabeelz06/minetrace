<?php

namespace Tests\Feature\Process;

use App\Models\Batch;
use App\Models\Company;
use App\Models\ProductCode;
use App\Models\User;
use App\Services\Hardware\RfidAuthorizationService;
use Database\Seeders\Core\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Serah-terima antar tahap — skenario (b):
 *   forward/dispatch: batch TETAP di tahap asal, status in_transit
 *   receive         : batch PINDAH ke tahap penerima
 * Web dan RFID memakai StageTransitionService yang sama (guard identik).
 */
class StageTransitionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private int $productId;
    private User $wet;
    private User $dry;
    private User $warehouse;
    private User $ltj;
    private User $auditor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Queue::fake();
        Cache::flush();
        putenv('RFID_API_TOKEN=');

        $this->company   = Company::where('code', 'TIMAH')->firstOrFail();
        $this->productId = ProductCode::where('company_id', $this->company->id)->firstOrFail()->id;
        $this->wet       = User::where('email', 'wet.mtk@ptimah.co.id')->firstOrFail();
        $this->dry       = User::where('email', 'dry.mtk@ptimah.co.id')->firstOrFail();
        $this->warehouse = User::where('email', 'warehouse.tju@ptimah.co.id')->firstOrFail();
        $this->ltj       = User::where('email', 'lab.ltj@ptimah.co.id')->firstOrFail();
        $this->auditor   = User::where('email', 'audit@ptimah.co.id')->firstOrFail();
    }

    private function batch(string $stage, string $status = 'in_process', array $attrs = []): Batch
    {
        return Batch::factory()->create($attrs + [
            'company_id' => $this->company->id, 'product_code_id' => $this->productId,
            'process_stage' => $stage, 'status' => $status, 'initial_weight' => 1000, 'current_weight' => 1000,
            'has_discrepancy' => false, 'rfid_tag' => null,
        ]);
    }

    private function mon(): int
    {
        return ProductCode::firstOrCreate(
            ['company_id' => $this->company->id, 'code' => 'MON'],
            ['name' => 'Monasit', 'is_hazardous' => false] + ProductCode::where('company_id', $this->company->id)->first()->only([])
        )->id;
    }

    private function forward(User $u, string $stage, Batch $b, float $weight = 950): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($u)->post("/company/process/{$stage}/forward-batch", ['batch_id' => $b->id, 'weight_recorded' => $weight]);
    }

    private function receive(User $u, string $stage, Batch $b): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($u)->post("/company/process/{$stage}/receive-batch", ['batch_id' => $b->id]);
    }

    #[Test]
    public function forward_menandai_in_transit_dan_batch_tetap_di_tahap_asal(): void
    {
        $b = $this->batch('wet_process');

        $this->forward($this->wet, 'wet_process', $b, 940)->assertSessionHas('success');

        $b->refresh();
        $this->assertSame('wet_process', $b->process_stage);
        $this->assertSame('in_transit', $b->status);
        $this->assertEquals(940, $b->current_weight);
        $this->assertDatabaseHas('checkpoints', ['batch_id' => $b->id, 'checkpoint_stage' => 'wet_process_output', 'company_id' => $this->company->id]);
    }

    #[Test]
    public function forward_ganda_ditolak(): void
    {
        $b = $this->batch('wet_process');
        $this->forward($this->wet, 'wet_process', $b);

        $this->forward($this->wet, 'wet_process', $b)->assertSessionHas('error');
        $this->assertSame(1, $b->checkpoints()->where('checkpoint_stage', 'wet_process_output')->count());
    }

    #[Test]
    public function receive_setelah_forward_memindahkan_tahap(): void
    {
        $b = $this->batch('wet_process');
        $this->forward($this->wet, 'wet_process', $b);

        $this->receive($this->dry, 'dry_process', $b)->assertSessionHas('success');

        $b->refresh();
        $this->assertSame('dry_process', $b->process_stage);
        $this->assertSame('in_process', $b->status);
        $this->assertDatabaseHas('checkpoints', ['batch_id' => $b->id, 'checkpoint_stage' => 'dry_process_input']);
    }

    #[Test]
    public function receive_langsung_tanpa_forward_tetap_diizinkan_seperti_sebelumnya(): void
    {
        $b = $this->batch('wet_process');
        $this->receive($this->dry, 'dry_process', $b)->assertSessionHas('success');
        $this->assertSame('dry_process', $b->fresh()->process_stage);
    }

    #[Test]
    public function receive_dari_tahap_yang_salah_atau_tahap_awal_ditolak(): void
    {
        $b = $this->batch('receiving');
        $this->receive($this->dry, 'dry_process', $b)->assertSessionHas('error');
        $this->assertSame('receiving', $b->fresh()->process_stage);

        $lab = $this->batch('lab_kimia', 'active');
        $labOp = User::where('email', 'lab.sgt@ptimah.co.id')->firstOrFail();
        $this->receive($labOp, 'lab_kimia', $lab)->assertSessionHas('error');   // tahap awal: tak bisa "diterima"
        $this->assertSame('lab_kimia', $lab->fresh()->process_stage);
    }

    #[Test]
    public function receive_dobel_tidak_menggandakan_checkpoint(): void
    {
        $b = $this->batch('wet_process');
        $this->receive($this->dry, 'dry_process', $b)->assertSessionHas('success');
        $this->receive($this->dry, 'dry_process', $b)->assertSessionHas('error');   // sudah di dry_process

        $this->assertSame(1, $b->checkpoints()->where('checkpoint_stage', 'dry_process_input')->count());
    }

    #[Test]
    public function peran_per_tahap_ditegakkan(): void
    {
        $b = $this->batch('wet_process');
        $this->receive($this->wet, 'dry_process', $b)->assertForbidden();         // operator wet bukan penerima dry
        $this->forward($this->dry, 'wet_process', $b)->assertForbidden();
        $this->receive($this->auditor, 'dry_process', $b)->assertForbidden();
        $this->assertSame('wet_process', $b->fresh()->process_stage);
    }

    #[Test]
    public function jalur_kembali_project_plant_ke_warehouse_berfungsi_dan_status_in_warehouse(): void
    {
        $mon = $this->batch('project_plant', 'in_process', ['product_code_id' => $this->mon()]);

        // Belum dikirim: tidak boleh diterima warehouse (hanya yang in_transit dari project_plant).
        $this->receive($this->warehouse, 'warehouse', $mon)->assertSessionHas('error');

        $this->forward($this->ltj, 'project_plant', $mon, 990)->assertSessionHas('success');
        $this->assertSame('project_plant', $mon->fresh()->process_stage);
        $this->assertSame('in_transit', $mon->fresh()->status);

        $this->receive($this->warehouse, 'warehouse', $mon)->assertSessionHas('success');
        $mon->refresh();
        $this->assertSame('warehouse', $mon->process_stage);
        $this->assertSame('in_warehouse', $mon->status);   // sebelumnya salah: in_process
    }

    #[Test]
    public function halaman_tahap_menampilkan_incoming_termasuk_kembalian_project_plant(): void
    {
        $returning = $this->batch('project_plant', 'in_transit', ['product_code_id' => $this->mon()]);
        $fromDry   = $this->batch('dry_process');
        $ignored   = $this->batch('project_plant', 'in_process', ['product_code_id' => $this->mon()]);

        $this->actingAs($this->warehouse)->get('/company/process/warehouse')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('incoming_batches', 2)
                ->where('incoming_batches', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all()
                    === collect([$returning->id, $fromDry->id])->sort()->values()->all()));
    }

    // ─── RFID memakai layanan transisi yang sama ──────────────────────────

    #[Test]
    public function serah_terima_lengkap_lewat_rfid_dispatch_lalu_receive(): void
    {
        $auth = app(RfidAuthorizationService::class);
        $auth->setMode($this->company->id, 'wet_process', 'scan_to_dispatch', $this->wet->id);
        $auth->setMode($this->company->id, 'dry_process', 'scan_to_receive', $this->dry->id);

        $b = $this->batch('wet_process', 'in_process', ['rfid_tag' => 'AABB0001', 'has_discrepancy' => true, 'current_weight' => 1000]);
        $h = ['X-Company-Code' => 'TIMAH'];

        // Scan OUT di wet_process (dispatch): batch tetap di wet, in_transit, berat/diskrepansi tidak disentuh.
        $out = $this->postJson('/api/rfid/logout', ['uid' => 'AABB0001', 'stage' => 'wet_process'], $h)->assertOk();
        $out->assertJson(['transition_attempted' => true, 'transition_success' => true]);
        $b->refresh();
        $this->assertSame('wet_process', $b->process_stage);
        $this->assertSame('in_transit', $b->status);
        $this->assertTrue((bool) $b->has_discrepancy);
        $this->assertEquals(1000, $b->current_weight);

        // Scan OUT ulang (di luar dedupe): ditolak karena sudah dalam pengiriman, audit tetap tercatat.
        $this->travel(10)->seconds();
        $again = $this->postJson('/api/rfid/logout', ['uid' => 'AABB0001', 'stage' => 'wet_process'], $h)->assertOk();
        $again->assertJson(['transition_success' => false]);
        $this->assertStringContainsString('sudah dalam pengiriman', $again->json('transition_reason'));

        // Scan IN di dry_process (receive): batch pindah, in_process.
        $in = $this->postJson('/api/rfid/login', ['uid' => 'AABB0001', 'stage' => 'dry_process'], $h)->assertOk();
        $in->assertJson(['transition_attempted' => true, 'transition_success' => true]);
        $b->refresh();
        $this->assertSame('dry_process', $b->process_stage);
        $this->assertSame('in_process', $b->status);
        $this->assertDatabaseHas('checkpoints', ['batch_id' => $b->id, 'checkpoint_stage' => 'dry_process_input', 'rfid_scan_code' => 'AABB0001']);
    }

    #[Test]
    public function rfid_dispatch_di_dry_process_ditolak_butuh_transformasi(): void
    {
        app(RfidAuthorizationService::class)->setMode($this->company->id, 'dry_process', 'scan_to_dispatch', $this->dry->id);
        $b = $this->batch('dry_process', 'in_process', ['rfid_tag' => 'AABB0002']);

        $res = $this->postJson('/api/rfid/logout', ['uid' => 'AABB0002', 'stage' => 'dry_process'], ['X-Company-Code' => 'TIMAH'])->assertOk();
        $res->assertJson(['transition_success' => false]);
        $this->assertStringContainsString('transformasi', $res->json('transition_reason'));
        $this->assertSame('in_process', $b->fresh()->status);
    }
}
