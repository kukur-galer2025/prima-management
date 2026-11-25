<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class TaskManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_can_be_created_with_priority_and_recurrence(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::tasks')
            ->set('title', 'Review kosakata')
            ->set('priority', '1')
            ->set('recurrence', 'daily')
            ->call('quickAdd')
            ->assertHasNoErrors();

        $task = $user->tasks()->sole();
        $this->assertSame(1, $task->priority);
        $this->assertSame('daily', $task->recurrence);
    }

    public function test_invalid_recurrence_is_rejected(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test('pages::tasks')
            ->set('title', 'X')
            ->set('recurrence', 'hourly')
            ->call('quickAdd')
            ->assertHasErrors('recurrence');
    }

    public function test_completing_a_recurring_task_schedules_the_next_one_only_once(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 08:00'));
        $task = Task::factory()->create(['title' => 'Olahraga', 'recurrence' => 'daily', 'due_date' => '2026-11-25', 'priority' => 1]);

        $component = Livewire::actingAs($task->user)->test('pages::tasks');
        $component->call('toggle', $task->id)->call('toggle', $task->id)->call('toggle', $task->id);

        $open = $task->user->tasks()->where('is_done', false)->get();
        $this->assertCount(1, $open);
        $this->assertSame('2026-11-26', $open->first()->due_date->toDateString());
        $this->assertSame(1, $open->first()->priority);
        $this->assertSame('daily', $open->first()->recurrence);
    }

    public function test_overdue_weekly_task_jumps_to_the_next_future_date(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 08:00'));
        $task = Task::factory()->create(['recurrence' => 'weekly', 'due_date' => '2026-11-15']);

        $this->assertSame('2026-11-29', $task->nextDueDate()->toDateString());
        $this->assertNull(Task::factory()->create(['recurrence' => null])->nextDueDate());
    }

    public function test_tasks_can_be_searched_by_title_or_notes(): void
    {
        $user = User::factory()->create();
        Task::factory()->for($user)->create(['title' => 'Kirim CV ke dosen', 'notes' => null]);
        Task::factory()->for($user)->create(['title' => 'Bayar UKT', 'notes' => 'lewat bank kampus']);
        Task::factory()->create(['title' => 'Kirim CV milik orang lain']);

        Livewire::actingAs($user)
            ->test('pages::tasks')
            ->set('search', 'kirim')
            ->assertSee('Kirim CV ke dosen')
            ->assertDontSee('Bayar UKT')
            ->assertDontSee('milik orang lain')
            ->set('search', 'kampus')
            ->assertSee('Bayar UKT');
    }
}
