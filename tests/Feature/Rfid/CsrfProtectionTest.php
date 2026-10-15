<?php

namespace Tests\Feature\Rfid;

use App\Models\Batch;
use App\Models\ProductCode;
use App\Models\User;
use Database\Seeders\Core\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * T7 — rute UI bersesi di bawah /api/v1/company/* WAJIB menegakkan CSRF.
 * Laravel mematikan CSRF saat env=testing, jadi env dinyalakan 'local' selama request.
 */
class CsrfProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    #[Test]
    public function mutasi_ui_tanpa_token_csrf_ditolak_419_dan_dengan_token_diterima(): void
    {
        $user  = User::where('email', 'wet.mtk@ptimah.co.id')->firstOrFail();
        $batch = Batch::factory()->create([
            'company_id' => $user->company_id,
            'product_code_id' => ProductCode::where('company_id', $user->company_id)->firstOrFail()->id,
            'rfid_tag' => null,
        ]);

        $this->app['env'] = 'local';   // aktifkan penegakan CSRF

        $this->actingAs($user)->postJson('/api/v1/company/rfid/registration', ['batch_id' => $batch->id])->assertStatus(419);

        $this->withSession(['_token' => 'tok-uji'])->actingAs($user)
            ->postJson('/api/v1/company/rfid/registration', ['batch_id' => $batch->id], ['X-CSRF-TOKEN' => 'tok-uji'])
            ->assertStatus(201);
    }

    #[Test]
    public function endpoint_perangkat_tidak_terkena_csrf(): void
    {
        $this->app['env'] = 'local';

        // Tanpa sesi/token: harus sampai ke logika perangkat (422 tag tidak dikenal), bukan 419.
        $this->postJson('/api/rfid/login', ['uid' => 'FFFF0001'], ['X-Company-Code' => 'TIMAH'])
            ->assertStatus(422)->assertJson(['message' => 'Tag tidak dikenal']);
    }
}
