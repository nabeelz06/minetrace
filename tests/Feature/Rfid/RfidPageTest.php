<?php

namespace Tests\Feature\Rfid;

use App\Models\Batch;
use App\Models\Company;
use App\Models\ProductCode;
use App\Models\User;
use Database\Seeders\Core\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Halaman RFID baru (satu halaman, dua presentasi), pencarian batch, dan integrasi detail batch. */
class RfidPageTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Cache::flush();
        $this->company   = Company::where('code', 'TIMAH')->firstOrFail();
        $this->productId = ProductCode::where('company_id', $this->company->id)->firstOrFail()->id;
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function batch(array $a = []): Batch
    {
        return Batch::factory()->create($a + ['company_id' => $this->company->id, 'product_code_id' => $this->productId, 'rfid_tag' => null, 'status' => 'in_process']);
    }

    #[Test]
    public function halaman_rfid_memuat_komponen_baru_dengan_izin_per_peran(): void
    {
        $this->actingAs($this->user('admin@ptimah.co.id'))->get('/company/hardware/rfid')
            ->assertOk()->assertInertia(fn (Assert $p) => $p
                ->component('Company/Rfid/Index')
                ->where('field', false)
                ->where('rfid.can.operate', true)->where('rfid.can.manage', true)
                ->where('rfid.company.code', 'TIMAH')
                ->has('rfid.stages', 5)->has('rfid.authorization_modes')->where('rfid.simulation_enabled', true));

        $this->actingAs($this->user('wet.mtk@ptimah.co.id'))->get('/company/hardware/rfid')
            ->assertInertia(fn (Assert $p) => $p->where('rfid.can.operate', true)->where('rfid.can.manage', false));

        $this->actingAs($this->user('audit@ptimah.co.id'))->get('/company/hardware/rfid')
            ->assertInertia(fn (Assert $p) => $p->where('rfid.can.operate', false)->where('rfid.can.manage', false));
    }

    #[Test]
    public function mode_lapangan_memakai_komponen_sama_dan_tidak_ada_redirect_user_agent(): void
    {
        $iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148';

        // Operator di ponsel TIDAK lagi dialihkan paksa; URL penuh tetap melayani halaman penuh.
        $this->actingAs($this->user('wet.mtk@ptimah.co.id'))->withHeader('User-Agent', $iphone)
            ->get('/company/hardware/rfid')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Company/Rfid/Index')->where('field', false));

        $this->actingAs($this->user('wet.mtk@ptimah.co.id'))->get('/company/hardware/rfid/field')
            ->assertOk()->assertInertia(fn (Assert $p) => $p->component('Company/Rfid/Index')->where('field', true));
    }

    #[Test]
    public function parameter_prefill_diteruskan_ke_halaman(): void
    {
        $b = $this->batch();
        $this->actingAs($this->user('wet.mtk@ptimah.co.id'))->get("/company/hardware/rfid?register_batch={$b->id}&uid=ABCD1234")
            ->assertInertia(fn (Assert $p) => $p->where('initial.register_batch', $b->id)->where('initial.uid', 'ABCD1234'));
    }

    #[Test]
    public function pencarian_batch_dibatasi_perusahaan_filter_tag_dan_cari_kode(): void
    {
        $untagged = $this->batch();
        $tagged   = $this->batch(['rfid_tag' => 'AABB1122']);
        $other    = Company::factory()->create(['is_regulator' => false, 'code' => 'LAIN']);
        $foreign  = $this->batch(['company_id' => $other->id]);
        $u = $this->user('wet.mtk@ptimah.co.id');

        $ids = fn ($res) => collect($res->json('data'))->pluck('id')->all();

        $r = $this->actingAs($u)->getJson('/api/v1/company/rfid/batch-search?tag=untagged')->assertOk();
        $this->assertContains($untagged->id, $ids($r));
        $this->assertNotContains($tagged->id, $ids($r));
        $this->assertNotContains($foreign->id, $ids($r), 'batch perusahaan lain tidak boleh muncul');

        $r = $this->actingAs($u)->getJson('/api/v1/company/rfid/batch-search?tag=tagged&q=AABB')->assertOk();
        $this->assertSame([$tagged->id], $ids($r));

        $r = $this->actingAs($u)->getJson('/api/v1/company/rfid/batch-search?tag=all&id=' . $untagged->id)->assertOk();
        $this->assertSame([$untagged->id], $ids($r));

        $r = $this->actingAs($u)->getJson('/api/v1/company/rfid/batch-search?q=' . urlencode($untagged->batch_code))->assertOk();
        $this->assertContains($untagged->id, $ids($r));
    }

    #[Test]
    public function pencarian_aman_dari_karakter_wildcard(): void
    {
        $this->batch();
        // '%' dan '_' diperlakukan literal, bukan wildcard → tidak mencocokkan semua batch
        $res = $this->actingAs($this->user('wet.mtk@ptimah.co.id'))->getJson('/api/v1/company/rfid/batch-search?tag=all&q=' . urlencode('%'))->assertOk();
        $this->assertCount(0, $res->json('data'));
    }

    #[Test]
    public function umpan_scan_menyertakan_pembacaan_ditolak_untuk_perusahaan_sendiri(): void
    {
        $this->postJson('/api/rfid/login', ['uid' => 'DEAD9999'], ['X-Company-Code' => 'TIMAH'])->assertStatus(422);

        $res = $this->actingAs($this->user('wet.mtk@ptimah.co.id'))->getJson('/api/v1/company/rfid/scans?include_rejects=1')->assertOk();
        $rejects = $res->json('rejects');
        $this->assertCount(1, $rejects);
        $this->assertSame('DEAD9999', $rejects[0]['uid']);
        $this->assertSame('tag_unknown', $rejects[0]['code']);
    }

    #[Test]
    public function create_batch_tidak_lagi_menerima_atau_menyimpan_rfid_tag(): void
    {
        $user = $this->user('lab.sgt@ptimah.co.id');
        $loc  = \App\Models\Location::where('company_id', $this->company->id)->where('location_type', 'lab_kimia')->firstOrFail();

        $this->actingAs($user)->post('/company/batches', [
            'product_code_id' => $this->productId, 'location_id' => $loc->id, 'initial_weight' => 500, 'rfid_tag' => 'AABBCCDD11',
        ])->assertRedirect();

        $this->assertNull(Batch::latest('id')->first()->rfid_tag);
    }

    #[Test]
    public function detail_batch_membawa_flag_izin_rfid(): void
    {
        $b = $this->batch();
        $this->actingAs($this->user('wet.mtk@ptimah.co.id'))->get("/company/batches/{$b->id}")
            ->assertOk()->assertInertia(fn (Assert $p) => $p->where('can.rfid_operate', true));
        $this->actingAs($this->user('audit@ptimah.co.id'))->get("/company/batches/{$b->id}")
            ->assertInertia(fn (Assert $p) => $p->where('can.rfid_operate', false));
    }
}
