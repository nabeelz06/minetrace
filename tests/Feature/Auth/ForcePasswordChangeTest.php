<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\Core\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** T14 — force_password_change kini DITEGAKKAN (sebelumnya hanya flag tanpa efek). */
class ForcePasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->user = User::where('email', 'wet.mtk@ptimah.co.id')->firstOrFail();
        $this->user->update(['password' => Hash::make('Lama#1234'), 'force_password_change' => true]);
    }

    #[Test]
    public function pengguna_wajib_ganti_dialihkan_dari_halaman_lain(): void
    {
        $this->actingAs($this->user)->get('/company/dashboard')->assertRedirect('/account/password');
        $this->actingAs($this->user)->get('/company/hardware/rfid')->assertRedirect('/account/password');
        $this->actingAs($this->user)->getJson('/api/v1/company/rfid/overview')->assertStatus(403)->assertJson(['code' => 'password_change_required']);
    }

    #[Test]
    public function halaman_ganti_password_dan_logout_tetap_bisa_diakses(): void
    {
        $this->actingAs($this->user)->get('/account/password')->assertOk();
        $this->actingAs($this->user)->post('/logout')->assertRedirect();
    }

    #[Test]
    public function ganti_password_menurunkan_flag_dan_membuka_akses(): void
    {
        $this->actingAs($this->user)->put('/account/password', [
            'current_password' => 'Lama#1234', 'password' => 'Baru#98765', 'password_confirmation' => 'Baru#98765',
        ])->assertRedirect();

        $this->user->refresh();
        $this->assertFalse($this->user->force_password_change);
        $this->assertTrue(Hash::check('Baru#98765', $this->user->password));
        $this->actingAs($this->user)->get('/company/dashboard')->assertOk();
    }

    #[Test]
    public function password_lama_salah_atau_lemah_atau_sama_ditolak(): void
    {
        $base = ['password_confirmation' => 'Baru#98765'];
        $this->actingAs($this->user)->put('/account/password', ['current_password' => 'salah', 'password' => 'Baru#98765'] + $base)->assertSessionHasErrors('current_password');
        $this->actingAs($this->user)->put('/account/password', ['current_password' => 'Lama#1234', 'password' => 'lemah', 'password_confirmation' => 'lemah'])->assertSessionHasErrors('password');
        $this->actingAs($this->user)->put('/account/password', ['current_password' => 'Lama#1234', 'password' => 'Lama#1234', 'password_confirmation' => 'Lama#1234'])->assertSessionHasErrors('password');
        $this->assertTrue($this->user->fresh()->force_password_change);
    }

    #[Test]
    public function login_api_diblokir_selama_wajib_ganti_password(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => $this->user->email, 'password' => 'Lama#1234'])
            ->assertStatus(403)->assertJson(['code' => 'password_change_required']);
        $this->assertSame(0, $this->user->tokens()->count());
    }

    #[Test]
    public function perintah_artisan_menandai_pengguna_dan_dry_run_tidak_mengubah(): void
    {
        User::query()->update(['force_password_change' => false]);

        Artisan::call('users:require-password-change', ['--role' => ['operator_wet'], '--dry-run' => true]);
        $this->assertSame(0, User::where('force_password_change', true)->count());

        Artisan::call('users:require-password-change', ['--role' => ['operator_wet']]);
        $flagged = User::where('force_password_change', true)->get();
        $this->assertGreaterThan(0, $flagged->count());
        $this->assertTrue($flagged->every(fn ($u) => $u->hasRole('operator_wet')));

        $this->assertSame(1, Artisan::call('users:require-password-change'));   // tanpa target = gagal
    }
}
