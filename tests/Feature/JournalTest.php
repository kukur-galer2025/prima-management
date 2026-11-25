<?php

namespace Tests\Feature;

use App\Models\Goal;
use App\Models\Habit;
use App\Models\JournalEntry;
use App\Models\StudySession;
use App\Models\User;
use App\Services\MoodInsights;
use App\Services\Reminders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class JournalTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_writes_and_updates_todays_entry(): void
    {
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)->test('pages::journal')
            ->call('save')
            ->assertHasErrors('mood')
            ->set('mood', 4)
            ->set('content', 'Presentasi lancar.')
            ->call('save')
            ->assertHasNoErrors();

        $entry = $user->journalEntries()->sole();
        $this->assertSame(4, $entry->mood);
        $this->assertTrue($entry->entry_on->isToday());

        $component->set('mood', 5)->call('save');
        $this->assertSame(5, $user->journalEntries()->sole()->mood);

        $component->assertSee('Presentasi lancar.')->assertSee('🔥 1 hari');
    }

    public function test_past_days_can_be_filled_but_not_future(): void
    {
        $user = User::factory()->create();
        JournalEntry::factory()->for($user)->create(['entry_on' => today()->subDays(2), 'mood' => 2, 'content' => 'Capek']);

        Livewire::actingAs($user)->test('pages::journal')
            ->call('selectDate', today()->subDays(2)->toDateString())
            ->assertSet('mood', 2)
            ->assertSet('content', 'Capek')
            ->set('date', today()->addDay()->toDateString())
            ->set('mood', 3)
            ->call('save')
            ->assertHasErrors('date');

        $this->assertSame(1, $user->journalEntries()->count());
    }

    public function test_entry_can_be_deleted(): void
    {
        $user = User::factory()->create();
        JournalEntry::factory()->for($user)->create(['entry_on' => today()]);

        Livewire::actingAs($user)->test('pages::journal')->call('delete');

        $this->assertSame(0, $user->journalEntries()->count());
    }

    public function test_insights_compare_mood_with_focus_and_habits(): void
    {
        $user = User::factory()->create();
        $goal = Goal::factory()->for($user)->create();
        $habit = Habit::factory()->for($user)->create(['days' => Habit::EVERY_DAY, 'created_at' => today()->subDays(30)]);

        // 4 hari fokus ≥ 1 jam + semua kebiasaan beres → mood 5; 4 hari lain → mood 2.
        foreach (range(1, 8) as $offset) {
            $date = today()->subDays($offset);
            $productive = $offset <= 4;
            JournalEntry::factory()->for($user)->create(['entry_on' => $date, 'mood' => $productive ? 5 : 2]);

            if ($productive) {
                StudySession::factory()->for($goal)->create(['user_id' => $user->id, 'studied_on' => $date, 'minutes' => 90]);
                $habit->logs()->create(['logged_on' => $date]);
            }
        }

        $insights = app(MoodInsights::class)->forUser($user, today()->subDays(29), today());

        $this->assertSame(8, $insights['count']);
        $this->assertSame(3.5, $insights['average']);
        $this->assertSame(['with' => 5.0, 'without' => 2.0, 'withDays' => 4, 'withoutDays' => 4], $insights['focus']);
        $this->assertSame(5.0, $insights['habits']['with']);
        $this->assertSame(2.0, $insights['habits']['without']);

        $this->actingAs($user)->get(route('journal'))->assertOk()->assertSee('Fokus tampaknya membuat harimu lebih baik.');
        $this->actingAs($user)->get(route('stats'))->assertOk()->assertSee('Mood & produktivitas', false);
    }

    public function test_insights_need_enough_days_per_group(): void
    {
        $user = User::factory()->create();
        JournalEntry::factory()->for($user)->create(['entry_on' => today(), 'mood' => 4]);

        $insights = app(MoodInsights::class)->forUser($user, today()->subDays(29), today());

        $this->assertNull($insights['focus']);
        $this->assertNull($insights['habits']);
        $this->assertNull($insights['bestWeekday']);
    }

    public function test_evening_reminder_only_for_journal_users_who_have_not_written_today(): void
    {
        $now = Carbon::parse('2026-11-25 20:45');
        $reminders = app(Reminders::class);

        $newcomer = User::factory()->create();
        $this->assertSame([], collect($reminders->dueFor($newcomer, $now))->where('key', 'journal:2026-11-25')->all());

        $writer = User::factory()->create();
        JournalEntry::factory()->for($writer)->create(['entry_on' => '2026-11-24']);
        $this->assertCount(1, collect($reminders->dueFor($writer, $now))->where('key', 'journal:2026-11-25'));

        JournalEntry::factory()->for($writer)->create(['entry_on' => '2026-11-25']);
        $this->assertCount(0, collect($reminders->dueFor($writer, $now))->where('key', 'journal:2026-11-25'));
    }
}
