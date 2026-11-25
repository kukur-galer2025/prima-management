<?php

namespace Tests\Feature;

use App\Models\Goal;
use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_write_a_note_linked_to_a_goal(): void
    {
        $goal = Goal::factory()->create();

        Livewire::actingAs($goal->user)
            ->withQueryParams(['goal' => $goal->id])
            ->test('pages::notes')
            ->call('openCreate')
            ->assertSet('goal_id', $goal->id)
            ->set('title', 'Tips Reading')
            ->set('body', "Baca pertanyaan dulu.\nCari kata kunci.")
            ->set('is_pinned', true)
            ->call('save')
            ->assertHasNoErrors();

        $note = $goal->notes()->sole();
        $this->assertTrue($note->is_pinned);
        $this->assertSame($goal->user_id, $note->user_id);
    }

    public function test_note_cannot_be_linked_to_another_users_goal(): void
    {
        $otherGoal = Goal::factory()->create();

        Livewire::actingAs(User::factory()->create())
            ->test('pages::notes')
            ->call('openCreate')
            ->set('title', 'X')
            ->set('goal_id', $otherGoal->id)
            ->call('save')
            ->assertHasErrors('goal_id');
    }

    public function test_notes_are_searchable_and_pinned_first(): void
    {
        $user = User::factory()->create();
        Note::factory()->for($user)->create(['title' => 'Daftar kampus', 'body' => 'UI, ITB']);
        Note::factory()->for($user)->pinned()->create(['title' => 'Strategi SKD', 'body' => 'Fokus TIU']);
        Note::factory()->create(['title' => 'Catatan orang lain']);

        $component = Livewire::actingAs($user)->test('pages::notes');

        $this->assertSame(['Strategi SKD', 'Daftar kampus'], $component->instance()->notes->pluck('title')->all());

        $component->set('search', 'ITB')->assertSee('Daftar kampus')->assertDontSee('Strategi SKD')->assertDontSee('orang lain');
    }

    public function test_user_cannot_open_another_users_note(): void
    {
        $note = Note::factory()->create();

        Livewire::actingAs(User::factory()->create())
            ->test('pages::notes')
            ->call('openEdit', $note->id)
            ->assertNotFound();
    }
}
