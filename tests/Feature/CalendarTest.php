<?php

namespace Tests\Feature;

use App\Models\Goal;
use App\Models\ScheduleSlot;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class CalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_ical_feed_contains_deadlines_tasks_and_weekly_slots(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 10:00'));
        $goal = Goal::factory()->create(['name' => 'TOEFL, ITP; 550', 'deadline' => '2027-03-01', 'description' => null]);
        Task::factory()->for($goal->user)->create(['title' => 'Bayar UKT', 'due_date' => '2026-11-27', 'due_time' => '10:00']);
        Task::factory()->for($goal->user)->create(['title' => 'Kirim berkas', 'due_date' => '2026-11-28', 'due_time' => null]);
        Task::factory()->for($goal->user)->done()->create(['title' => 'Sudah selesai', 'due_date' => '2026-11-28']);
        ScheduleSlot::factory()->for($goal)->create(['day_of_week' => 2, 'start_time' => '19:00', 'end_time' => '20:30']);

        $token = $goal->user->calendarToken();
        $response = $this->get(route('calendar.feed', ['token' => $token]))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/calendar; charset=utf-8');

        $ics = $response->getContent();
        $this->assertStringContainsString("BEGIN:VCALENDAR\r\n", $ics);
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20270301', $ics);
        $this->assertStringContainsString('SUMMARY:🎯 Deadline: TOEFL\, ITP\; 550', $ics);
        $this->assertStringContainsString('DTSTART;TZID=Asia/Jakarta:20261127T100000', $ics);
        $this->assertStringContainsString('DTEND;TZID=Asia/Jakarta:20261127T103000', $ics);
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20261128', $ics);
        $this->assertStringContainsString('DTSTART;TZID=Asia/Jakarta:20261124T190000', $ics);
        $this->assertStringContainsString('RRULE:FREQ=WEEKLY;BYDAY=TU', $ics);
        $this->assertStringNotContainsString('Sudah selesai', $ics);
        $this->assertSame(substr_count($ics, 'BEGIN:VEVENT'), substr_count($ics, 'END:VEVENT'));

        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), "Baris iCal terlalu panjang: {$line}");
        }
    }

    public function test_feed_rejects_unknown_tokens_and_regenerating_revokes_the_old_link(): void
    {
        $user = User::factory()->create();
        $oldToken = $user->calendarToken();

        Livewire::actingAs($user)->test('pages::account')->call('createCalendarLink', true);
        $newToken = $user->fresh()->calendar_token;

        $this->assertNotSame($oldToken, $newToken);
        $this->get(route('calendar.feed', ['token' => $oldToken]))->assertNotFound();
        $this->get(route('calendar.feed', ['token' => $newToken]))->assertOk();
        $this->get('/kalender/feed/pendek.ics')->assertNotFound();
    }

    public function test_calendar_page_shows_month_and_selected_day_detail(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 10:00'));
        $goal = Goal::factory()->create(['name' => 'SKD', 'icon' => null, 'deadline' => '2026-11-28']);
        $task = Task::factory()->for($goal->user)->create(['title' => 'Latihan TIU', 'due_date' => '2026-11-28', 'due_time' => '08:00']);

        $component = Livewire::actingAs($goal->user)
            ->test('pages::calendar')
            ->assertSet('month', '2026-11')
            ->assertSee('November')
            ->call('selectDate', '2026-11-28')
            ->assertSee('Deadline target')
            ->assertSee('08:00 · Latihan TIU')
            ->assertSee('3 hari lagi')
            ->call('toggleTask', $task->id);

        $this->assertTrue($task->fresh()->is_done);

        $component->call('shiftMonth', 1)->assertSet('month', '2026-12')->assertSet('selected', '2026-12-01');
    }

    public function test_calendar_cannot_toggle_another_users_task(): void
    {
        $task = Task::factory()->create();

        Livewire::actingAs(User::factory()->create())
            ->test('pages::calendar')
            ->call('toggleTask', $task->id)
            ->assertNotFound();
    }
}
