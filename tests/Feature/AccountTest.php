<?php

namespace Tests\Feature;

use App\Models\Goal;
use App\Models\PushSubscription;
use App\Models\StudySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Livewire;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_name_can_be_updated_but_email_is_locked_for_the_user(): void
    {
        $user = User::factory()->create(['email' => 'tetap@example.com']);

        Livewire::actingAs($user)
            ->test('pages::account')
            ->assertSee('hanya bisa diubah oleh admin')
            ->set('name', 'Prima Dzaky')
            ->call('saveProfile')
            ->assertHasNoErrors();

        $this->assertSame('Prima Dzaky', $user->fresh()->name);
        $this->assertSame('tetap@example.com', $user->fresh()->email);

        // Mengirim isian email lewat request yang dimanipulasi tetap tidak bisa mengubahnya.
        $this->expectException(PublicPropertyNotFoundException::class);
        Livewire::actingAs($user)->test('pages::account')->set('email', 'baru@example.com');
    }

    public function test_password_change_requires_the_current_password(): void
    {
        $user = User::factory()->create(['password' => 'lama12345']);

        Livewire::actingAs($user)
            ->test('pages::account')
            ->set('current_password', 'salah')
            ->set('password', 'baru12345')
            ->set('password_confirmation', 'baru12345')
            ->call('savePassword')
            ->assertHasErrors('current_password')
            ->set('current_password', 'lama12345')
            ->call('savePassword')
            ->assertHasNoErrors()
            ->assertSet('current_password', '');

        $this->assertTrue(Hash::check('baru12345', $user->fresh()->password));
    }

    public function test_weekly_target_and_budget_are_saved_and_shown(): void
    {
        $goal = Goal::factory()->create();
        StudySession::factory()->for($goal)->create(['minutes' => 90]);

        Livewire::actingAs($goal->user)
            ->test('pages::account')
            ->set('weekly_target_hours', '7.5')
            ->set('monthly_budget', '2.500.000')
            ->call('savePlanning')
            ->assertHasNoErrors();

        $user = $goal->user->fresh();
        $this->assertSame(450, $user->weekly_target_minutes);
        $this->assertSame(2500000, $user->monthly_budget);

        $this->actingAs($user)->get(route('dashboard'))->assertSee('Fokus minggu ini')->assertSee('7j 30m');
    }

    public function test_push_subscription_is_saved_moved_between_accounts_and_removed(): void
    {
        $endpoint = 'https://fcm.googleapis.com/fcm/send/abc123';
        $previousOwner = PushSubscription::factory()->create(['endpoint' => $endpoint]);
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)
            ->test('pages::account')
            ->call('savePushSubscription', ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'pub', 'auth' => 'secret']])
            ->assertHasNoErrors();

        $this->assertModelMissing($previousOwner);
        $this->assertDatabaseHas('push_subscriptions', ['user_id' => $user->id, 'endpoint' => $endpoint, 'auth_token' => 'secret']);

        $component->call('deletePushSubscription', $endpoint);
        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_push_subscription_must_use_https(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test('pages::account')
            ->call('savePushSubscription', ['endpoint' => 'http://evil.test/push'])
            ->assertHasErrors('endpoint');

        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_user_can_export_all_data_and_sessions(): void
    {
        $goal = Goal::factory()->create(['name' => 'TOEFL']);
        StudySession::factory()->for($goal)->create(['minutes' => 45, 'score' => 510]);

        $component = Livewire::actingAs($goal->user)->test('pages::account');

        $component->call('exportJson')->assertFileDownloaded('prima-management-'.now()->format('Ymd').'.json');
        $component->call('exportSessionsCsv')->assertFileDownloaded('sesi-fokus-'.now()->format('Ymd').'.csv');
    }

    public function test_menu_page_links_to_all_modules_and_logout(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('menu'))
            ->assertOk()
            ->assertSee(['Jadwal', 'Kebiasaan', 'Keuangan', 'Catatan', 'Statistik', 'Akun', 'Keluar']);
    }
}
