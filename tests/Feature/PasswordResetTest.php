<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_link_is_emailed_in_indonesian(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->get(route('password.request'))->assertOk()->assertSee('Lupa kata sandi?');

        Livewire::test('pages::auth.forgot-password')
            ->set('email', $user->email)
            ->call('sendLink')
            ->assertSet('sent', true)
            ->assertSee('Cek kotak masukmu');

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $mail = $notification->toMail($user);

            return $mail->subject === 'Atur ulang kata sandi Prima Management'
                && str_contains($mail->actionUrl, '/reset-sandi/'.$notification->token);
        });
    }

    public function test_unknown_email_gets_the_same_response_without_sending(): void
    {
        Notification::fake();

        Livewire::test('pages::auth.forgot-password')
            ->set('email', 'tidak-ada@example.com')
            ->call('sendLink')
            ->assertSet('sent', true);

        Notification::assertNothingSent();
    }

    public function test_password_can_be_reset_with_a_valid_token_only(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))->assertOk()->assertSee('Kata sandi baru');

        Livewire::test('pages::auth.reset-password', ['token' => 'token-salah'])
            ->set('email', $user->email)
            ->set('password', 'barubaru123')
            ->set('password_confirmation', 'barubaru123')
            ->call('resetPassword')
            ->assertHasErrors('email');

        Livewire::test('pages::auth.reset-password', ['token' => $token])
            ->set('email', $user->email)
            ->set('password', 'barubaru123')
            ->set('password_confirmation', 'barubaru123')
            ->call('resetPassword')
            ->assertHasNoErrors()
            ->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('barubaru123', $user->fresh()->password));
    }
}
