<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Database\Seeders\Core\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PasswordResetTest — alur lupa password via email.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->user = User::where('email', 'lab.sgt@ptimah.co.id')->firstOrFail();
    }

    #[Test]
    public function forgot_password_page_is_accessible(): void
    {
        $this->get('/forgot-password')->assertOk();
    }

    #[Test]
    public function reset_link_can_be_requested_for_registered_email(): void
    {
        Notification::fake();

        $response = $this->post('/forgot-password', ['email' => $this->user->email]);

        $response->assertRedirect();
        $response->assertSessionHas('status');

        Notification::assertSentTo($this->user, ResetPasswordNotification::class);
    }

    #[Test]
    public function unregistered_email_gets_same_generic_response(): void
    {
        Notification::fake();

        $response = $this->post('/forgot-password', ['email' => 'tidak-ada@nowhere.test']);

        $response->assertRedirect();
        $response->assertSessionHas('status');
        Notification::assertNothingSent();
    }

    #[Test]
    public function user_can_reset_password_with_valid_token(): void
    {
        $token = Password::createToken($this->user);

        $response = $this->post('/reset-password', [
            'token'                 => $token,
            'email'                 => $this->user->email,
            'password'              => 'NewSecure123!',
            'password_confirmation' => 'NewSecure123!',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHas('status');

        $this->assertTrue(Hash::check('NewSecure123!', $this->user->fresh()->password));
    }

    #[Test]
    public function reset_token_cannot_be_reused(): void
    {
        $token = Password::createToken($this->user);

        $this->post('/reset-password', [
            'token'                 => $token,
            'email'                 => $this->user->email,
            'password'              => 'FirstPass123!',
            'password_confirmation' => 'FirstPass123!',
        ]);

        $response = $this->post('/reset-password', [
            'token'                 => $token,
            'email'                 => $this->user->email,
            'password'              => 'SecondPass123!',
            'password_confirmation' => 'SecondPass123!',
        ]);

        $response->assertSessionHasErrors('email');
    }

    #[Test]
    public function reset_fails_with_invalid_token(): void
    {
        $response = $this->post('/reset-password', [
            'token'                 => 'invalid-token-value',
            'email'                 => $this->user->email,
            'password'              => 'NewSecure123!',
            'password_confirmation' => 'NewSecure123!',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertFalse(Hash::check('NewSecure123!', $this->user->fresh()->password));
    }

    #[Test]
    public function reset_requires_password_confirmation_to_match(): void
    {
        $token = Password::createToken($this->user);

        $response = $this->post('/reset-password', [
            'token'                 => $token,
            'email'                 => $this->user->email,
            'password'              => 'NewSecure123!',
            'password_confirmation' => 'DifferentPass123!',
        ]);

        $response->assertSessionHasErrors('password');
    }
}
