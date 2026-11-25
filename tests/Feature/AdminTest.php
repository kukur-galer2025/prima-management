<?php

namespace Tests\Feature;

use App\Models\Goal;
use App\Models\User;
use App\Notifications\AccountInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admins_can_open_the_admin_panel(): void
    {
        $this->actingAs(User::factory()->create())->get(route('admin'))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertDontSee(route('admin'));

        $admin = User::factory()->admin()->create(['name' => 'Bos Prima']);
        User::factory()->create(['name' => 'Sinta', 'email' => 'sinta@example.com']);

        $this->actingAs($admin)->get(route('admin'))->assertOk()->assertSee('Sinta')->assertSee('sinta@example.com');
        $this->actingAs($admin)->get(route('dashboard'))->assertSee(route('admin'));
    }

    public function test_admin_creates_user_with_initial_password(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)
            ->test('pages::admin')
            ->call('openCreate')
            ->set('name', 'Budi')
            ->set('email', 'Budi@Example.com')
            ->set('sendInvitation', false)
            ->set('password', '')
            ->call('save')
            ->assertHasErrors('password')
            ->set('password', 'rahasia123')
            ->call('save')
            ->assertHasNoErrors();

        $budi = User::query()->where('email', 'budi@example.com')->sole();
        $this->assertTrue(Hash::check('rahasia123', $budi->password));
        $this->assertNotNull($budi->email_verified_at);
        $this->assertFalse($budi->is_admin);
        Notification::assertNothingSent();

        $this->post(route('logout'));
        Livewire::test('pages::auth.login')->set('email', 'budi@example.com')->set('password', 'rahasia123')->call('login')->assertRedirect(route('dashboard'));
    }

    public function test_admin_can_invite_user_by_email(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create(['name' => 'Bos Prima']);

        Livewire::actingAs($admin)
            ->test('pages::admin')
            ->call('openCreate')
            ->set('name', 'Citra')
            ->set('email', 'citra@example.com')
            ->set('is_admin', true)
            ->call('save')
            ->assertHasNoErrors();

        $citra = User::query()->where('email', 'citra@example.com')->sole();
        $this->assertTrue($citra->is_admin);
        Notification::assertSentTo($citra, AccountInvitation::class, function (AccountInvitation $notification) use ($citra) {
            $mail = $notification->toMail($citra);

            return $mail->subject === 'Akun Prima Management kamu sudah siap'
                && str_contains($mail->actionUrl, '/reset-sandi/')
                && str_contains(implode(' ', $mail->introLines), 'Bos Prima');
        });
    }

    public function test_admin_validates_duplicate_email(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['email' => 'ada@example.com']);

        Livewire::actingAs($admin)
            ->test('pages::admin')
            ->call('openCreate')
            ->set('name', 'Dobel')
            ->set('email', 'ada@example.com')
            ->set('password', 'rahasia123')
            ->call('save')
            ->assertHasErrors('email');
    }

    public function test_admin_edits_user_and_resets_password(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['name' => 'Lama']);

        Livewire::actingAs($admin)
            ->test('pages::admin')
            ->call('openEdit', $user->id)
            ->assertSet('name', 'Lama')
            ->set('name', 'Baru')
            ->set('password', 'sandibaru123')
            ->set('is_admin', true)
            ->call('save')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertSame('Baru', $user->name);
        $this->assertTrue($user->is_admin);
        $this->assertTrue(Hash::check('sandibaru123', $user->password));
    }

    public function test_disabled_user_cannot_log_in_and_is_kicked_out(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['email' => 'nonaktif@example.com', 'password' => 'rahasia123']);

        Livewire::actingAs($admin)->test('pages::admin')->call('toggleDisabled', $user->id);
        $this->assertTrue($user->fresh()->isDisabled());

        // Sesi yang sedang berjalan langsung dikeluarkan.
        $this->actingAs($user->fresh())->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->get(route('login'))->assertSee('dinonaktifkan');

        Livewire::test('pages::auth.login')
            ->set('email', 'nonaktif@example.com')
            ->set('password', 'rahasia123')
            ->call('login')
            ->assertHasErrors('email');
        $this->assertGuest();

        Livewire::actingAs($admin)->test('pages::admin')->call('toggleDisabled', $user->id);
        $this->assertFalse($user->fresh()->isDisabled());
    }

    public function test_admin_cannot_disable_delete_or_demote_themselves(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test('pages::admin')
            ->call('toggleDisabled', $admin->id)
            ->call('deleteUser', $admin->id)
            ->call('openEdit', $admin->id)
            ->set('is_admin', false)
            ->call('save')
            ->assertHasErrors('is_admin');

        $admin->refresh();
        $this->assertFalse($admin->isDisabled());
        $this->assertTrue($admin->is_admin);
        $this->assertModelExists($admin);
    }

    public function test_admin_deletes_user_with_all_data(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $goal = Goal::factory()->for($user)->create();

        Livewire::actingAs($admin)->test('pages::admin')->call('deleteUser', $user->id);

        $this->assertModelMissing($user);
        $this->assertModelMissing($goal);
    }

    public function test_non_admin_cannot_call_admin_actions(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        Livewire::actingAs($user)->test('pages::admin')->assertForbidden();
        $this->assertModelExists($other);

        // Admin yang diturunkan perannya tidak bisa melanjutkan aksi dari halaman yang masih terbuka.
        $admin = User::factory()->admin()->create();
        $component = Livewire::actingAs($admin)->test('pages::admin');
        $admin->forceFill(['is_admin' => false])->save();

        $component->call('deleteUser', $other->id)->assertForbidden();
        $this->assertModelExists($other);
    }

    public function test_last_admin_cannot_delete_own_account(): void
    {
        $admin = User::factory()->admin()->create(['password' => 'rahasia123']);

        Livewire::actingAs($admin)
            ->test('pages::account')
            ->set('delete_password', 'rahasia123')
            ->call('deleteAccount')
            ->assertHasErrors('delete_password');

        $this->assertModelExists($admin);
    }

    public function test_search_and_status_filter(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['name' => 'Anisa']);
        User::factory()->disabled()->create(['name' => 'Bowo']);

        Livewire::actingAs($admin)->test('pages::admin')
            ->set('search', 'anis')
            ->assertSee('Anisa')
            ->assertDontSee('Bowo')
            ->set('search', '')
            ->set('status', 'disabled')
            ->assertSee('Bowo')
            ->assertDontSee('Anisa');
    }

    public function test_login_is_throttled_per_ip_across_accounts(): void
    {
        foreach (range(1, 20) as $attempt) {
            Livewire::test('pages::auth.login')->set('email', "orang{$attempt}@example.com")->set('password', 'salah')->call('login');
        }

        User::factory()->create(['email' => 'asli@example.com', 'password' => 'rahasia123']);

        Livewire::test('pages::auth.login')
            ->set('email', 'asli@example.com')
            ->set('password', 'rahasia123')
            ->call('login')
            ->assertHasErrors('email');
        $this->assertGuest();
    }

    public function test_active_users_get_last_active_timestamp_and_security_headers(): void
    {
        $user = User::factory()->create(['last_active_at' => null]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertNotNull($user->fresh()->last_active_at);
    }

    public function test_create_user_command_can_make_an_admin(): void
    {
        $this->artisan('app:create-user', ['--admin' => true])
            ->expectsQuestion('Nama', 'Pemilik')
            ->expectsQuestion('Email', 'pemilik@example.com')
            ->expectsQuestion('Kata sandi (min. 8 karakter)', 'rahasia123')
            ->assertSuccessful();

        $this->assertTrue(User::query()->where('email', 'pemilik@example.com')->sole()->is_admin);
    }
}
