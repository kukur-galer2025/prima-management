<?php

namespace Tests\Feature;

use App\Models\Goal;
use App\Models\ScheduleSlot;
use App\Models\StudySession;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StudyPlanningTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_log_a_study_session(): void
    {
        $goal = Goal::factory()->create();

        Livewire::actingAs($goal->user)
            ->test('pages::study')
            ->assertSet('goal_id', $goal->id)
            ->set('minutes', '45')
            ->set('score', '87.5')
            ->set('topic_id', '')
            ->call('save')
            ->assertHasNoErrors();

        $session = $goal->sessions()->sole();
        $this->assertSame(45, $session->minutes);
        $this->assertSame('87.50', $session->score);
        $this->assertTrue($session->studied_on->isToday());
    }

    public function test_session_cannot_be_logged_to_another_users_goal_or_in_the_future(): void
    {
        $user = User::factory()->create();
        $otherGoal = Goal::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::study')
            ->set('goal_id', $otherGoal->id)
            ->set('minutes', 30)
            ->set('studied_on', today()->addDay()->toDateString())
            ->call('save')
            ->assertHasErrors(['goal_id', 'studied_on']);

        $this->assertDatabaseCount('study_sessions', 0);
    }

    public function test_dashboard_counts_the_study_streak(): void
    {
        $goal = Goal::factory()->create();
        foreach ([1, 2, 3] as $daysAgo) {
            StudySession::factory()->for($goal)->create(['studied_on' => today()->subDays($daysAgo), 'minutes' => 30]);
        }
        StudySession::factory()->for($goal)->create(['studied_on' => today()->subDays(5), 'minutes' => 60]);

        $stats = Livewire::actingAs($goal->user)->test('pages::dashboard')->instance()->stats;

        $this->assertSame(3, $stats['streak']);
        $this->assertSame(150, $stats['week']);
    }

    public function test_one_schedule_slot_can_be_created_for_several_days(): void
    {
        $goal = Goal::factory()->create();

        Livewire::actingAs($goal->user)
            ->test('pages::schedule')
            ->call('openCreate', 1)
            ->call('toggleDay', 3)
            ->call('toggleDay', 5)
            ->set('start_time', '19:00')
            ->set('end_time', '20:30')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([1, 3, 5], $goal->slots()->orderBy('day_of_week')->pluck('day_of_week')->all());
        $this->assertSame(90, ScheduleSlot::first()->durationMinutes());
    }

    public function test_schedule_end_time_must_be_after_start_time(): void
    {
        $goal = Goal::factory()->create();

        Livewire::actingAs($goal->user)
            ->test('pages::schedule')
            ->call('openCreate')
            ->set('start_time', '20:00')
            ->set('end_time', '19:00')
            ->call('save')
            ->assertHasErrors('end_time');
    }

    public function test_tasks_can_be_added_completed_and_grouped(): void
    {
        $user = User::factory()->create();
        Task::factory()->for($user)->create(['title' => 'Telat', 'due_date' => today()->subDays(2)]);

        $component = Livewire::actingAs($user)
            ->test('pages::tasks')
            ->set('title', 'Minta surat rekomendasi')
            ->set('due_date', today()->toDateString())
            ->call('quickAdd')
            ->assertHasNoErrors();

        $task = $user->tasks()->where('title', 'Minta surat rekomendasi')->sole();
        $this->assertSame(['Terlambat', 'Hari ini'], array_keys($component->instance()->groups));

        $component->call('toggle', $task->id);
        $this->assertTrue($task->fresh()->is_done);
        $this->assertNotNull($task->fresh()->done_at);
    }
}
