<?php

namespace Tests\Feature;

use App\Models\Goal;
use App\Models\Note;
use App\Models\ScheduleSlot;
use App\Models\StudySession;
use App\Models\Task;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GoalManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_a_goal_with_initial_topics(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::goals.index')
            ->call('openCreate')
            ->set('name', 'TOEFL ITP 550')
            ->set('target_hours', '')
            ->set('deadline', '2027-03-01')
            ->set('initialTopics', "Listening\n- Structure\n\nReading")
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $goal = $user->goals()->sole();
        $this->assertSame('TOEFL ITP 550', $goal->name);
        $this->assertNull($goal->target_hours);
        $this->assertSame(['Listening', 'Structure', 'Reading'], $goal->topics->pluck('name')->all());
    }

    public function test_goal_name_is_required(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test('pages::goals.index')
            ->call('openCreate')
            ->set('name', '')
            ->call('save')
            ->assertHasErrors('name');
    }

    public function test_user_can_edit_and_delete_own_goal(): void
    {
        $goal = Goal::factory()->create(['name' => 'Lama']);

        $component = Livewire::actingAs($goal->user)
            ->test('pages::goals.index')
            ->call('openEdit', $goal->id)
            ->set('name', 'Baru')
            ->set('goalStatus', 'paused')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Baru', $goal->fresh()->name);
        $this->assertSame('paused', $goal->fresh()->status);

        $component->call('delete', $goal->id);
        $this->assertSoftDeleted($goal);
    }

    public function test_deleting_a_goal_keeps_its_focus_history_and_drops_its_schedule(): void
    {
        $goal = Goal::factory()->create();
        $session = StudySession::factory()->for($goal)->create(['minutes' => 90]);
        $slot = ScheduleSlot::factory()->for($goal)->create(['user_id' => $goal->user_id]);
        $task = Task::factory()->for($goal->user)->create(['goal_id' => $goal->id]);

        Livewire::actingAs($goal->user)->test('pages::goals.index')->call('delete', $goal->id);

        $this->assertSoftDeleted($goal);
        $this->assertModelExists($session);
        $this->assertModelExists($task);
        $this->assertModelMissing($slot);
        $this->assertSame($goal->name, $session->fresh()->goal->name);
        $this->assertSame(90, (int) $goal->user->studySessions()->sum('minutes'));

        $this->actingAs($goal->user)->get(route('study'))->assertOk()->assertSee($goal->name);
    }

    public function test_trashed_goal_can_be_restored_or_deleted_permanently(): void
    {
        $goal = Goal::factory()->create();
        $other = Goal::factory()->for($goal->user)->create();
        StudySession::factory()->for($other)->create();
        $goal->archive();
        $other->archive();

        $component = Livewire::actingAs($goal->user)
            ->test('pages::goals.index')
            ->set('status', 'trashed')
            ->assertSee($goal->name);

        $this->assertSame(2, $component->instance()->counts['trashed']);

        $component->call('restore', $goal->id)->call('forceDelete', $other->id);

        $this->assertNotSoftDeleted($goal);
        $this->assertModelMissing($other);
        $this->assertDatabaseCount('study_sessions', 0);
    }

    public function test_trashed_goal_cannot_receive_new_sessions(): void
    {
        $goal = Goal::factory()->create();
        $goal->archive();

        Livewire::actingAs($goal->user)
            ->test('pages::study')
            ->set('goal_id', $goal->id)
            ->set('minutes', 30)
            ->call('save')
            ->assertHasErrors('goal_id');
    }

    public function test_overdue_goal_is_flagged_on_list_and_dashboard(): void
    {
        $goal = Goal::factory()->create(['name' => 'SKD', 'deadline' => today()->subDays(3), 'status' => 'active']);

        $this->assertTrue($goal->isOverdue());
        $this->assertFalse(Goal::factory()->done()->create(['deadline' => today()->subDay()])->isOverdue());

        $this->actingAs($goal->user);
        $this->get(route('goals.index'))->assertSee('Lewat 3 hari');
        $this->get(route('dashboard'))->assertSee('Deadline terlewat');
    }

    public function test_goal_page_shows_score_trend_and_related_notes(): void
    {
        $goal = Goal::factory()->create();
        StudySession::factory()->for($goal)->create(['studied_on' => today()->subDays(2), 'score' => 480]);
        StudySession::factory()->for($goal)->create(['studied_on' => today(), 'score' => 520.5]);
        Note::factory()->for($goal->user)->create(['goal_id' => $goal->id, 'title' => 'Rumus cepat']);

        $this->actingAs($goal->user)
            ->get(route('goals.show', $goal))
            ->assertOk()
            ->assertSee('Tren skor')
            ->assertSee('520.5')
            ->assertSee('+40.5 dari awal')
            ->assertSee('Rumus cepat');
    }

    public function test_user_cannot_edit_another_users_goal(): void
    {
        $otherGoal = Goal::factory()->create();

        Livewire::actingAs(User::factory()->create())
            ->test('pages::goals.index')
            ->call('openEdit', $otherGoal->id)
            ->assertNotFound();
    }

    public function test_topics_can_be_added_toggled_reordered_and_removed(): void
    {
        $goal = Goal::factory()->create();
        $first = Topic::factory()->for($goal)->create(['name' => 'A', 'sort_order' => 0]);
        Topic::factory()->for($goal)->create(['name' => 'B', 'sort_order' => 1]);

        $component = Livewire::actingAs($goal->user)
            ->test('pages::goals.show', ['goal' => $goal])
            ->set('newTopic', 'C')
            ->call('addTopic')
            ->assertHasNoErrors()
            ->call('toggleTopic', $first->id)
            ->call('moveTopic', $first->id, 1);

        $this->assertSame(['B', 'A', 'C'], $goal->topics()->pluck('name')->all());
        $this->assertTrue($first->fresh()->is_done);

        $component->call('deleteTopic', $first->id);
        $this->assertModelMissing($first);
    }

    public function test_progress_uses_topics_then_falls_back_to_hours(): void
    {
        $goal = Goal::factory()->create(['target_hours' => 10]);
        StudySession::factory()->for($goal)->create(['minutes' => 300]);

        $this->assertSame(50, $goal->progress());

        Topic::factory()->for($goal)->done()->create();
        Topic::factory()->for($goal)->count(3)->create();

        $this->assertSame(25, Goal::query()->withStats()->find($goal->id)->progress());
    }
}
