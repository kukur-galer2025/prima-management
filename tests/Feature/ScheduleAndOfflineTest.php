<?php

namespace Tests\Feature;

use App\Models\Goal;
use App\Models\ScheduleSlot;
use App\Models\StudySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class ScheduleAndOfflineTest extends TestCase
{
    use RefreshDatabase;

    public function test_overlapping_schedule_slot_is_rejected(): void
    {
        $goal = Goal::factory()->create(['name' => 'TOEFL']);
        ScheduleSlot::factory()->for($goal)->create(['day_of_week' => 2, 'start_time' => '19:00', 'end_time' => '20:30']);

        $component = Livewire::actingAs($goal->user)
            ->test('pages::schedule')
            ->call('openCreate', 1)
            ->set('days', [1, 2])
            ->set('start_time', '20:00')
            ->set('end_time', '21:00')
            ->call('save')
            ->assertHasErrors('start_time');

        $this->assertStringContainsString('TOEFL', $component->errors()->first('start_time'));
        $this->assertDatabaseCount('schedule_slots', 1);

        $component->set('start_time', '20:30')->call('save')->assertHasNoErrors();
        $this->assertDatabaseCount('schedule_slots', 3);
    }

    public function test_editing_a_slot_does_not_clash_with_itself(): void
    {
        $slot = ScheduleSlot::factory()->create(['day_of_week' => 3, 'start_time' => '19:00', 'end_time' => '20:00']);

        Livewire::actingAs($slot->user)
            ->test('pages::schedule')
            ->call('openEdit', $slot->id)
            ->set('end_time', '20:30')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('20:30', $slot->fresh()->end_time);
    }

    public function test_weekly_adherence_compares_schedule_with_logged_focus(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 21:00')); // Rabu

        $goal = Goal::factory()->create();
        $other = Goal::factory()->for($goal->user)->create();
        foreach ([1, 2, 3] as $day) {
            ScheduleSlot::factory()->for($goal)->create(['day_of_week' => $day, 'start_time' => '19:00', 'end_time' => '20:00']);
        }
        ScheduleSlot::factory()->for($goal)->create(['day_of_week' => 3, 'start_time' => '22:00', 'end_time' => '23:00']); // belum lewat
        ScheduleSlot::factory()->for($goal)->create(['day_of_week' => 4, 'start_time' => '19:00', 'end_time' => '20:00']); // besok

        StudySession::factory()->for($goal)->create(['studied_on' => '2026-11-23', 'minutes' => 120]); // Senin, lebih dari jadwal
        StudySession::factory()->for($goal)->create(['studied_on' => '2026-11-24', 'minutes' => 30]);  // Selasa, setengah
        StudySession::factory()->for($other)->create(['studied_on' => '2026-11-25', 'minutes' => 60]); // Rabu, target lain

        $adherence = ScheduleSlot::weeklyAdherence($goal->user);

        $this->assertSame(180, $adherence['planned']);
        $this->assertSame(90, $adherence['done']);
        $this->assertSame(50, $adherence['percent']);
        $this->assertSame([1 => 'done', 2 => 'partial', 3 => 'missed'], $adherence['days']);

        $this->actingAs($goal->user)->get(route('schedule'))->assertSee('Jadwal terlaksana minggu ini')->assertSee('50%');
    }

    public function test_offline_sessions_sync_once_even_when_sent_twice(): void
    {
        $goal = Goal::factory()->create();
        $payload = ['sessions' => [[
            'client_uuid' => (string) Str::uuid(),
            'goal_id' => $goal->id,
            'topic_id' => null,
            'studied_on' => today()->toDateString(),
            'minutes' => 50,
            'score' => '88',
            'notes' => 'Dicatat saat offline',
        ]]];

        $this->actingAs($goal->user)->postJson(route('study.sync'), $payload)
            ->assertOk()
            ->assertJsonPath('synced.0', $payload['sessions'][0]['client_uuid']);
        $this->actingAs($goal->user)->postJson(route('study.sync'), $payload)->assertOk();

        $session = $goal->sessions()->sole();
        $this->assertSame(50, $session->minutes);
        $this->assertSame('88.00', $session->score);
    }

    public function test_offline_sync_rejects_another_users_goal_and_future_dates(): void
    {
        $otherGoal = Goal::factory()->create();

        $this->actingAs(User::factory()->create())
            ->postJson(route('study.sync'), ['sessions' => [[
                'client_uuid' => (string) Str::uuid(),
                'goal_id' => $otherGoal->id,
                'studied_on' => today()->addDay()->toDateString(),
                'minutes' => 30,
            ]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sessions.0.goal_id', 'sessions.0.studied_on']);

        $this->assertDatabaseCount('study_sessions', 0);
    }

    public function test_offline_sync_ignores_topic_from_a_different_goal(): void
    {
        $goal = Goal::factory()->create();
        $foreignTopic = Goal::factory()->create()->topics()->create(['name' => 'Asing']);

        $this->actingAs($goal->user)->postJson(route('study.sync'), ['sessions' => [[
            'client_uuid' => (string) Str::uuid(),
            'goal_id' => $goal->id,
            'topic_id' => $foreignTopic->id,
            'studied_on' => today()->toDateString(),
            'minutes' => 20,
        ]]])->assertOk();

        $this->assertNull($goal->sessions()->sole()->topic_id);
    }

    public function test_old_study_url_redirects_to_focus_page(): void
    {
        $this->actingAs(User::factory()->create())->get('/belajar')->assertRedirect('/fokus');
    }
}
