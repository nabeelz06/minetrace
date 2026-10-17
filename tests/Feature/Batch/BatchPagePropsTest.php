<?php

namespace Tests\Feature\Batch;

use App\Models\Batch;
use App\Models\Company;
use App\Models\Location;
use App\Models\ProductCode;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\Core\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kontrak data halaman Batch (daftar & detail) — pernah rusak diam-diam: Vue membaca `product_code`,
 * `current_location` dan `created_at` ISO, sedangkan server mengirim `product`, `location` dan teks
 * "d/m/Y H:i:s WIB". Akibatnya daftar batch kosong (RangeError) dan detail tanpa produk/lokasi.
 */
class BatchPagePropsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@ptimah.co.id')->firstOrFail();
        $company     = Company::where('code', 'TIMAH')->firstOrFail();
        $product     = ProductCode::where('company_id', $company->id)->where('code', 'MIT')->firstOrFail();
        $location    = Location::where('company_id', $company->id)->firstOrFail();

        $this->batch = Batch::factory()->create([
            'company_id'          => $company->id,
            'product_code_id'     => $product->id,
            'current_location_id' => $location->id,
            'process_stage'       => 'lab_kimia',
            'status'              => 'active',
        ]);
    }

    #[Test]
    public function daftar_batch_mengirim_tanggal_iso_produk_dan_lokasi(): void
    {
        $this->actingAs($this->admin)->get('/company/batches')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Company/Batches/Index')
                ->has('batches.data', fn (Assert $rows) => $rows
                    ->has(0, fn (Assert $row) => $row
                        ->whereType('created_at_iso', 'string')
                        ->where('product.code', 'MIT')
                        ->has('location.name')
                        ->etc())
                    ->etc()));

        $iso = $this->batch->created_at->toIso8601String();
        $this->assertSame($iso, Carbon::parse($iso)->toIso8601String(), 'created_at_iso harus ISO-8601 valid');
    }

    #[Test]
    public function detail_batch_mengirim_produk_lokasi_dan_pembuat(): void
    {
        $this->actingAs($this->admin)->get("/company/batches/{$this->batch->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Company/Batches/Show')
                ->where('batch.product.code', 'MIT')
                ->has('batch.product.category')
                ->has('batch.location.name')
                ->has('batch.created_by_user')
                ->etc());
    }

    #[Test]
    public function teks_tanggal_dari_server_mengikuti_bahasa_pengguna(): void
    {
        $this->admin->forceFill(['language' => 'id'])->save();
        $this->batch->forceFill(['created_at' => '2026-09-30 02:35:10'])->save();

        $this->actingAs($this->admin)->get("/company/batches/{$this->batch->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('batch.created_at_full', fn ($v) => str_contains($v, 'Rabu') && str_contains($v, 'September'))
                ->etc());
    }
}
