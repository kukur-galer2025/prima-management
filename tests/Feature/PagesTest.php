<?php

namespace Tests\Feature;

use App\Models\Debt;
use App\Models\Goal;
use App\Models\Habit;
use App\Models\JournalEntry;
use App\Models\Note;
use App\Models\SavingsGoal;
use App\Models\ScheduleSlot;
use App\Models\StudySession;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\StarterGoalsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_renders_with_seeded_data(): void
    {
        $user = User::factory()->create();
        (new StarterGoalsSeeder)->run($user);

        $goal = $user->goals()->first();
        StudySession::factory()->for($goal)->create(['user_id' => $user->id, 'score' => 520]);
        ScheduleSlot::factory()->for($goal)->create(['user_id' => $user->id, 'day_of_week' => today()->dayOfWeekIso]);
        Task::factory()->for($user)->create(['goal_id' => $goal->id, 'due_date' => today()->subDay()]);

        $this->actingAs($user);

        $this->get(route('dashboard'))->assertOk()->assertSee('TOEFL')->assertSee('Terlambat');
        $this->get(route('goals.index'))->assertOk()->assertSee('Proposal Riset S2');
        $this->get(route('goals.show', $goal))->assertOk()->assertSee('Listening Comprehension');
        $this->get(route('study'))->assertOk()->assertSee('Stopwatch');
        $this->get(route('schedule'))->assertOk()->assertSee(ScheduleSlot::DAYS[today()->dayOfWeekIso]);
        $this->get(route('tasks'))->assertOk()->assertSee('Terlambat');
    }

    public function test_pages_render_for_a_brand_new_user_without_data(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (['dashboard', 'goals.index', 'study', 'schedule', 'tasks', 'habits', 'journal', 'finance', 'savings', 'debts', 'notes', 'stats', 'account', 'menu', 'calendar', 'review', 'groups'] as $route) {
            $this->get(route($route))->assertOk();
        }
    }

    public function test_new_pages_render_with_data_for_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $wallet = Wallet::factory()->for($admin)->create(['name' => 'Jago']);
        SavingsGoal::factory()->for($admin)->create(['name' => 'Dana darurat', 'deadline' => today()->addMonths(3)]);
        Debt::factory()->for($admin)->create(['person' => 'Rina', 'wallet_id' => $wallet->id, 'due_on' => today()]);
        JournalEntry::factory()->for($admin)->create(['content' => 'Hari yang tenang']);

        $this->actingAs($admin);

        $this->get(route('finance'))->assertOk()->assertSee('Jago');
        $this->get(route('savings'))->assertOk()->assertSee('Dana darurat')->assertSee('/bulan');
        $this->get(route('debts'))->assertOk()->assertSee('Rina')->assertSee('Jatuh tempo hari ini');
        $this->get(route('journal'))->assertOk()->assertSee('Hari yang tenang');
        $this->get(route('admin'))->assertOk()->assertSee($admin->email);
        $this->get(route('tasks', ['view' => 'board']))->assertOk()->assertSee('Dikerjakan');
        $this->get(route('menu'))->assertOk()->assertSee('Tabungan')->assertSee('Admin');
    }

    public function test_guest_pages_render_with_theme_switcher(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Lupa kata sandi?')->assertSee('prima.theme', false);
        $this->get(route('password.request'))->assertOk();
    }

    public function test_redesigned_pages_render_heatmaps_and_charts_with_data(): void
    {
        $user = User::factory()->create();
        $goal = Goal::factory()->for($user)->create();
        StudySession::factory()->for($goal)->create(['minutes' => 50, 'studied_on' => today()->subDays(10)]);
        Habit::factory()->for($user)->create()->logs()->create(['logged_on' => today()->subDay()]);
        Transaction::factory()->for($user)->income()->create(['amount' => 2000000, 'occurred_on' => today()]);

        $this->actingAs($user);

        $this->get(route('stats'))->assertOk()
            ->assertSee('Fokus setahun terakhir')
            ->assertSee('Kebiasaan setahun terakhir')
            ->assertSee('Keuangan 6 bulan')
            ->assertSee(today()->subDays(10)->translatedFormat('D, d M Y').': 50m fokus');

        $this->get(route('dashboard'))->assertOk()->assertSee('Mulai fokus');
    }

    public function test_new_module_pages_render_with_data(): void
    {
        $user = User::factory()->create();
        (new StarterGoalsSeeder)->run($user);
        $goal = $user->goals()->first();
        StudySession::factory()->for($goal)->create(['minutes' => 75, 'studied_on' => today()->subDays(3)]);
        Habit::factory()->for($user)->create(['name' => 'Olahraga pagi']);
        Transaction::factory()->for($user)->create(['category' => 'Transport', 'occurred_on' => today()]);
        Note::factory()->for($user)->create(['title' => 'Daftar beasiswa']);

        $this->actingAs($user);

        $this->get(route('habits'))->assertOk()->assertSee('Olahraga pagi');
        $this->get(route('finance'))->assertOk()->assertSee('Transport');
        $this->get(route('notes'))->assertOk()->assertSee('Daftar beasiswa');
        foreach (['30d', '12w', '6m'] as $range) {
            $this->get(route('stats', ['range' => $range]))->assertOk()->assertSee('1j 15m')->assertSee($goal->name);
        }
        $this->get(route('account'))->assertOk()->assertSee('Notifikasi pengingat');
    }

    public function test_user_cannot_open_another_users_goal(): void
    {
        $otherGoal = Goal::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('goals.show', $otherGoal))
            ->assertNotFound();
    }

    public function test_logout_ends_the_session(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
