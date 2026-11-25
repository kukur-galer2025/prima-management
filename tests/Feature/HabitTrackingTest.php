<?php

namespace Tests\Feature;

use App\Models\Habit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class HabitTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_a_habit_for_specific_days(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::habits')
            ->call('openCreate')
            ->set('name', 'Baca 20 halaman')
            ->call('setDays', [1, 2, 3, 4, 5])
            ->call('save')
            ->assertHasNoErrors();

        $habit = $user->habits()->sole();
        $this->assertSame([1, 2, 3, 4, 5], $habit->days);
        $this->assertSame('Hari kerja', $habit->scheduleLabel());
    }

    public function test_habit_needs_at_least_one_day(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test('pages::habits')
            ->call('openCreate')
            ->set('name', 'Lari')
            ->set('days', [])
            ->call('save')
            ->assertHasErrors('days');
    }

    public function test_days_can_be_checked_and_unchecked_but_not_in_the_future(): void
    {
        $habit = Habit::factory()->create();
        $component = Livewire::actingAs($habit->user)->test('pages::habits');

        $component->call('toggleDay', $habit->id, today()->toDateString());
        $this->assertSame(1, $habit->logs()->count());

        $component->call('toggleDay', $habit->id, today()->toDateString());
        $this->assertSame(0, $habit->logs()->count());

        $component->call('toggleDay', $habit->id, today()->addDay()->toDateString());
        $this->assertSame(0, $habit->logs()->count());
    }

    public function test_user_cannot_touch_another_users_habit(): void
    {
        $habit = Habit::factory()->create();

        Livewire::actingAs(User::factory()->create())
            ->test('pages::habits')
            ->call('toggleDay', $habit->id, today()->toDateString())
            ->assertNotFound();
    }

    public function test_streak_skips_unscheduled_days_and_waits_for_today(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 12:00')); // Rabu
        $habit = Habit::factory()->on([1, 2, 3, 4, 5])->create(['created_at' => '2026-11-01']);

        // Rabu (hari ini) belum dicentang; Selasa, Senin, Jumat lalu dicentang. Sabtu-Minggu tidak dijadwalkan.
        $logged = collect(['2026-11-24', '2026-11-23', '2026-11-20']);
        $this->assertSame(3, $habit->streak($logged));

        // Kamis 19 Nov terlewat sehingga streak berhenti di sana.
        $this->assertSame(4, $habit->streak($logged->concat(['2026-11-25'])));

        // Dalam 7 hari terakhir (19–25 Nov) ada 5 hari kerja; hari ini belum dicentang jadi belum dihitung.
        $this->assertSame(75, $habit->completionRate($logged, 7));
    }

    public function test_dashboard_lists_and_toggles_todays_habits(): void
    {
        $habit = Habit::factory()->create(['name' => 'Hafal kosakata', 'icon' => null]);

        $this->actingAs($habit->user)->get(route('dashboard'))->assertSee('Kebiasaan hari ini')->assertSee('Hafal kosakata');

        Livewire::actingAs($habit->user)->test('pages::dashboard')->call('toggleHabit', $habit->id);
        $this->assertTrue($habit->logs()->whereDate('logged_on', today())->exists());
    }
}
