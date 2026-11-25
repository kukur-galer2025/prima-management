<?php

namespace Tests\Feature;

use App\Models\Goal;
use App\Models\PushSubscription;
use App\Models\StudySession;
use App\Models\Task;
use App\Models\User;
use App\Services\Reminders;
use App\Services\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class PlanningInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_goal_pace_compares_needed_daily_focus_with_recent_average(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 10:00'));
        $goal = Goal::factory()->create(['target_hours' => 20, 'deadline' => '2026-12-05', 'status' => 'active']);
        StudySession::factory()->for($goal)->create(['studied_on' => '2026-11-20', 'minutes' => 600]);

        $pace = $goal->pace();
        $this->assertSame(600, $pace['remainingMinutes']);
        $this->assertSame(10, $pace['daysLeft']);
        $this->assertSame(60, $pace['perDay']);
        $this->assertSame(43, $pace['recentPerDay']);
        $this->assertFalse($pace['onTrack']);

        $this->actingAs($goal->user)->get(route('goals.show', $goal))->assertSee('Perlu sedikit dikejar')->assertSee('1j/hari');

        $this->assertNull(Goal::factory()->create(['target_hours' => null, 'deadline' => '2026-12-05'])->pace());
        $this->assertNull(Goal::factory()->create(['target_hours' => 10, 'deadline' => '2026-11-20'])->pace());
    }

    public function test_task_time_requires_a_date_and_is_shown(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::tasks')
            ->set('title', 'Bayar UKT')
            ->set('due_time', '10:00')
            ->call('quickAdd')
            ->assertHasErrors('due_date')
            ->set('due_date', today()->addDay()->toDateString())
            ->set('due_time', '25:99')
            ->call('quickAdd')
            ->assertHasErrors('due_time')
            ->set('due_time', '10:00')
            ->call('quickAdd')
            ->assertHasNoErrors()
            ->assertSee('· 10:00');

        $this->assertSame('10:00', $user->tasks()->sole()->due_time);
    }

    public function test_timed_task_triggers_a_reminder_ten_minutes_before(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 09:52'));
        $keys = WebPush::generateKeys();
        $this->app->instance(WebPush::class, new WebPush($keys['public_key'], $keys['private_key'], 'mailto:test@example.com'));

        $user = User::factory()->create();
        PushSubscription::factory()->for($user)->create();
        $task = Task::factory()->for($user)->create(['title' => 'Bayar UKT', 'due_date' => '2026-11-25', 'due_time' => '10:00']);
        Task::factory()->for($user)->create(['due_date' => '2026-11-25', 'due_time' => '15:00']);

        $taskReminders = collect(app(Reminders::class)->dueFor($user, now()))->filter(fn (array $reminder) => str_starts_with($reminder['key'], 'task:'));

        $this->assertSame(["task:{$task->id}:2026-11-25"], $taskReminders->pluck('key')->values()->all());
        $this->assertSame('⏰ Bayar UKT', $taskReminders->first()['title']);
    }

    public function test_recurring_task_keeps_its_time(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 08:00'));
        $task = Task::factory()->create(['recurrence' => 'daily', 'due_date' => '2026-11-25', 'due_time' => '06:30']);

        $next = $task->toggle();

        $this->assertSame('06:30', $next->due_time);
        $this->assertSame('Kam, 26 Nov · 06:30', $next->dueLabel());
    }

    public function test_onboarding_checklist_tracks_progress_and_can_be_dismissed(): void
    {
        $user = User::factory()->create();
        Goal::factory()->for($user)->create();

        $this->actingAs($user)->get(route('dashboard'))->assertSee('Mulai di sini')->assertSee('(1/7)');

        Livewire::actingAs($user)->test('pages::dashboard')->call('dismissOnboarding');

        $this->assertNotNull($user->fresh()->onboarding_dismissed_at);
        $this->actingAs($user->fresh())->get(route('dashboard'))->assertDontSee('Mulai di sini');
    }
}
