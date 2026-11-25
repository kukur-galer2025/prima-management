<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class KanbanAndChecklistTest extends TestCase
{
    use RefreshDatabase;

    public function test_board_groups_tasks_into_columns(): void
    {
        $user = User::factory()->create();
        Task::factory()->for($user)->create(['title' => 'Belum mulai']);
        Task::factory()->for($user)->create(['title' => 'Sedang jalan', 'started_at' => now()]);
        Task::factory()->for($user)->done()->create(['title' => 'Sudah beres']);
        Task::factory()->for($user)->create(['title' => 'Lama beres', 'is_done' => true, 'done_at' => now()->subDays(30)]);

        $board = Livewire::actingAs($user)->test('pages::tasks', ['view' => 'board'])
            ->set('view', 'board')
            ->assertSee('Dikerjakan')
            ->instance()->board;

        $this->assertSame(['Belum mulai'], $board['todo']->pluck('title')->all());
        $this->assertSame(['Sedang jalan'], $board['doing']->pluck('title')->all());
        $this->assertSame(['Sudah beres'], $board['done']->pluck('title')->all());
    }

    public function test_dragging_moves_between_columns_and_reorders(): void
    {
        $user = User::factory()->create();
        $first = Task::factory()->for($user)->create(['title' => 'Pertama']);
        $second = Task::factory()->for($user)->create(['title' => 'Kedua']);
        $third = Task::factory()->for($user)->create(['title' => 'Ketiga']);

        $component = Livewire::actingAs($user)->test('pages::tasks')->set('view', 'board');

        $component->call('moveTask', (string) $second->id, 0, 'doing');
        $this->assertSame('doing', $second->fresh()->column());
        $this->assertNotNull($second->fresh()->started_at);

        // Urutkan ulang kolom "Belum": Ketiga di atas Pertama.
        $component->call('moveTask', $third->id, 0, 'todo');
        $this->assertSame(['Ketiga', 'Pertama'], $component->instance()->board['todo']->pluck('title')->all());

        $component->call('moveTask', $second->id, 0, 'done')->assertDispatched('toast', message: 'Satu beres! 🎉');
        $this->assertTrue($second->fresh()->is_done);

        // Kembali ke "Belum" membuka lagi tugasnya dan menghapus status dikerjakan.
        $component->call('moveTask', $second->id, 1, 'todo');
        $second->refresh();
        $this->assertFalse($second->is_done);
        $this->assertNull($second->started_at);
        $this->assertSame(['Ketiga', 'Kedua', 'Pertama'], $component->instance()->board['todo']->pluck('title')->all());
    }

    public function test_moving_recurring_task_to_done_creates_next_occurrence(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->for($user)->create(['title' => 'Bayar kos', 'recurrence' => 'monthly', 'due_date' => today()]);
        $task->items()->create(['title' => 'Transfer', 'is_done' => true]);

        Livewire::actingAs($user)->test('pages::tasks')->call('moveTask', $task->id, 0, 'done');

        $next = $user->tasks()->where('is_done', false)->sole();
        $this->assertTrue($next->due_date->isSameDay(today()->addMonthNoOverflow()));
        $this->assertSame(['Transfer'], $next->items->pluck('title')->all());
        $this->assertFalse($next->items->first()->is_done, 'Checklist disalin dalam keadaan belum dicentang');
    }

    public function test_cannot_move_other_users_task(): void
    {
        $task = Task::factory()->create();

        Livewire::actingAs(User::factory()->create())->test('pages::tasks')->call('moveTask', $task->id, 0, 'done')->assertNotFound();

        $this->assertFalse($task->fresh()->is_done);
    }

    public function test_checklist_items_can_be_added_toggled_and_removed(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->for($user)->create(['title' => 'Daftar ulang']);

        $component = Livewire::actingAs($user)->test('pages::tasks')
            ->call('openEdit', $task->id)
            ->set('newItem', '')
            ->call('addItem')
            ->assertHasErrors('newItem')
            ->set('newItem', 'Fotokopi KTM')
            ->call('addItem')
            ->set('newItem', 'Bayar UKT')
            ->call('addItem')
            ->assertHasNoErrors()
            ->assertSee('Fotokopi KTM');

        $items = $task->items()->get();
        $this->assertSame(['Fotokopi KTM', 'Bayar UKT'], $items->pluck('title')->all());

        $component->call('toggleItem', $items[0]->id)->call('deleteItem', $items[1]->id);

        $this->assertTrue($items[0]->fresh()->is_done);
        $this->assertModelMissing($items[1]);

        $component->call('closeForm')->assertSee('☑ 1/1');
    }

    public function test_checklist_items_of_other_users_are_protected(): void
    {
        $task = Task::factory()->create();
        $item = $task->items()->create(['title' => 'Rahasia']);

        Livewire::actingAs(User::factory()->create())->test('pages::tasks')->call('deleteItem', $item->id)->assertNotFound();

        $this->assertModelExists($item);
    }

    public function test_status_can_be_changed_from_the_edit_sheet(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->for($user)->create();

        Livewire::actingAs($user)->test('pages::tasks')
            ->call('openEdit', $task->id)
            ->assertSet('column', 'todo')
            ->set('column', 'doing')
            ->call('update')
            ->assertHasNoErrors();

        $this->assertSame('doing', $task->fresh()->column());
    }
}
