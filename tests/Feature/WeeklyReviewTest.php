<?php

namespace Tests\Feature;

use App\Models\Goal;
use App\Models\Habit;
use App\Models\PushSubscription;
use App\Models\StudySession;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Reminders;
use App\Services\WeeklySummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class WeeklyReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_weekly_summary_collects_focus_tasks_habits_and_money(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 20:00')); // Rabu
        $user = User::factory()->create(['weekly_target_minutes' => 300]);
        $goal = Goal::factory()->for($user)->create();
        StudySession::factory()->for($goal)->create(['studied_on' => '2026-11-23', 'minutes' => 60]);
        StudySession::factory()->for($goal)->create(['studied_on' => '2026-11-25', 'minutes' => 90]);
        StudySession::factory()->for($goal)->create(['studied_on' => '2026-11-17', 'minutes' => 100]); // minggu lalu
        Task::factory()->for($user)->create(['is_done' => true, 'done_at' => '2026-11-24 10:00']);
        $habit = Habit::factory()->for($user)->create(['created_at' => '2026-11-01']);
        $habit->logs()->create(['logged_on' => '2026-11-23']);
        $habit->logs()->create(['logged_on' => '2026-11-24']);
        Transaction::factory()->for($user)->income()->create(['amount' => 100000, 'occurred_on' => '2026-11-24']);
        Transaction::factory()->for($user)->create(['amount' => 40000, 'occurred_on' => '2026-11-25']);

        $summary = app(WeeklySummary::class)->for($user, now());

        $this->assertTrue($summary['isCurrent']);
        $this->assertSame(150, $summary['focusMinutes']);
        $this->assertSame(100, $summary['previousFocusMinutes']);
        $this->assertSame(50, $summary['targetPercent']);
        $this->assertSame(2, $summary['activeDays']);
        $this->assertSame('2026-11-25', $summary['bestDay']['date']->toDateString());
        $this->assertSame(1, $summary['tasksDone']);
        $this->assertSame(67, $summary['habitRate']); // 2 dari 3 hari terjadwal (Sen–Rab)
        $this->assertSame(100000, $summary['income']);
        $this->assertSame(40000, $summary['expense']);
    }

    public function test_review_can_be_written_and_edited_for_a_week(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 20:00'));
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)
            ->test('pages::review')
            ->assertSet('week', '2026-11-23')
            ->set('rating', 4)
            ->set('went_well', 'Konsisten pagi')
            ->set('next_focus', 'Proposal Bab 1')
            ->call('save')
            ->assertHasNoErrors();

        $review = $user->weeklyReviews()->sole();
        $this->assertSame(4, $review->rating);
        $this->assertSame('2026-11-23', $review->week_start->toDateString());

        $component->set('to_improve', 'Tidur lebih awal')->call('save');
        $this->assertSame('Tidur lebih awal', $user->weeklyReviews()->sole()->to_improve);

        $component->call('shiftWeek', -1)->assertSet('week', '2026-11-16')->assertSet('went_well', '');
        $component->call('shiftWeek', 5)->assertSet('week', '2026-11-23')->assertSet('went_well', 'Konsisten pagi');
    }

    public function test_invalid_rating_is_rejected(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test('pages::review')
            ->set('rating', 9)
            ->call('save')
            ->assertHasErrors('rating');
    }

    public function test_dashboard_and_sunday_reminder_prompt_for_a_missing_review(): void
    {
        $this->travelTo(Carbon::parse('2026-11-29 19:30')); // Minggu
        $user = User::factory()->create();
        PushSubscription::factory()->for($user)->create();

        $this->actingAs($user)->get(route('dashboard'))->assertSee('Waktunya review mingguan');

        $keys = collect(app(Reminders::class)->dueFor($user, now()))->pluck('key');
        $this->assertContains('review:2026-11-23', $keys);

        $user->weeklyReviews()->create(['week_start' => '2026-11-23']);
        $this->assertNotContains('review:2026-11-23', collect(app(Reminders::class)->dueFor($user, now()))->pluck('key'));
        $this->actingAs($user)->get(route('dashboard'))->assertDontSee('Waktunya review mingguan');
    }
}
