<?php

namespace Tests\Feature;

use App\Models\Goal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DeadlineTimeAndConfirmTest extends TestCase
{
    use RefreshDatabase;

    public function test_goal_deadline_can_have_a_time(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('pages::goals.index')
            ->call('openCreate')
            ->set('name', 'TOEFL ITP')
            ->set('deadline_time', '08:00')
            ->call('save')
            ->assertHasErrors('deadline')
            ->set('deadline', '2026-12-20')
            ->set('deadline_time', '25:00')
            ->call('save')
            ->assertHasErrors('deadline_time')
            ->set('deadline_time', '08:00')
            ->call('save')
            ->assertHasNoErrors();

        $goal = $user->goals()->sole();
        $this->assertSame('08:00', $goal->deadline_time);
        $this->assertSame('20 Des 2026 · 08:00', $goal->deadlineLabel());

        $this->actingAs($user)->get(route('goals.show', $goal))->assertSee('20 Des 2026 · 08:00');

        // Di kalender langganan menjadi acara berjam, bukan seharian.
        $feed = $this->get(route('calendar.feed', ['token' => $user->calendarToken()]))->getContent();
        $this->assertStringContainsString('DTSTART;TZID=Asia/Jakarta:20261220T080000', $feed);
        $this->assertStringContainsString('DTEND;TZID=Asia/Jakarta:20261220T090000', $feed);
    }

    public function test_goal_without_time_stays_an_all_day_deadline(): void
    {
        $user = User::factory()->create();
        $goal = Goal::factory()->for($user)->create(['deadline' => '2026-12-20', 'deadline_time' => null]);

        $this->assertSame('20 Des 2026', $goal->deadlineLabel());
        $feed = $this->get(route('calendar.feed', ['token' => $user->calendarToken()]))->getContent();
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20261220', $feed);
    }

    public function test_task_due_time_field_is_clearly_labelled(): void
    {
        $this->actingAs(User::factory()->create())->get(route('tasks'))
            ->assertOk()
            ->assertSee('Jam tenggat', false)
            ->assertSee('date-placeholder', false);
    }

    public function test_app_uses_styled_confirm_dialog_instead_of_browser_default(): void
    {
        $this->actingAs(User::factory()->create())->get(route('dashboard'))
            ->assertSee('prima-confirm', false)
            ->assertSee("Livewire.hook('directive.init'", false);
    }
}
