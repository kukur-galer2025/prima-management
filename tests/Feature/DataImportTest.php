<?php

namespace Tests\Feature;

use App\Models\Goal;
use App\Models\Habit;
use App\Models\Note;
use App\Models\StudySession;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DataImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class DataImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_exported_data_can_be_restored_into_another_account(): void
    {
        $source = User::factory()->create(['weekly_target_minutes' => 600]);
        $goal = Goal::factory()->for($source)->create(['name' => 'TOEFL', 'deadline' => '2027-03-01']);
        $topic = $goal->topics()->create(['name' => 'Listening', 'sort_order' => 0]);
        StudySession::factory()->for($goal)->create(['topic_id' => $topic->id, 'studied_on' => '2026-11-20', 'minutes' => 45, 'score' => 520]);
        Task::factory()->for($source)->create(['goal_id' => $goal->id, 'title' => 'Daftar tes', 'due_date' => '2026-12-01', 'due_time' => '09:00']);
        Habit::factory()->for($source)->create(['name' => 'Baca'])->logs()->create(['logged_on' => '2026-11-24']);
        Transaction::factory()->for($source)->create(['amount' => 25000, 'occurred_on' => '2026-11-24']);
        $source->budgets()->create(['category' => 'Makan', 'amount' => 1000000]);
        $source->weeklyReviews()->create(['week_start' => '2026-11-16', 'rating' => 4, 'next_focus' => 'Reading']);
        Note::factory()->for($source)->create(['goal_id' => $goal->id, 'title' => 'Tips']);
        $trashed = Goal::factory()->for($source)->create(['name' => 'Lama']);
        $trashed->archive();

        $export = Livewire::actingAs($source)->test('pages::account')->call('exportJson');
        $json = base64_decode($export->effects['download']['content']);
        $file = UploadedFile::fake()->createWithContent('backup.json', $json);

        $target = User::factory()->create();
        Livewire::actingAs($target)
            ->test('pages::account')
            ->set('importFile', $file)
            ->call('importData')
            ->assertHasNoErrors()
            ->assertSee('Berhasil dipulihkan');

        $restoredGoal = $target->goals()->where('name', 'TOEFL')->sole();
        $this->assertSame('2027-03-01', $restoredGoal->deadline->toDateString());
        $this->assertSame(1, $target->goals()->onlyTrashed()->count());

        $session = $target->studySessions()->sole();
        $this->assertSame($restoredGoal->id, $session->goal_id);
        $this->assertSame('Listening', $session->topic->name);
        $this->assertSame('2026-11-20', $session->studied_on->toDateString());
        $this->assertSame('520.00', $session->score);

        $task = $target->tasks()->sole();
        $this->assertSame($restoredGoal->id, $task->goal_id);
        $this->assertSame('09:00', $task->due_time);
        $this->assertSame('2026-12-01', $task->due_date->toDateString());

        $this->assertSame('2026-11-24', $target->habits()->sole()->logs()->sole()->logged_on->toDateString());
        $this->assertSame(25000, $target->transactions()->sole()->amount);
        $this->assertSame(1000000, $target->budgets()->sole()->amount);
        $this->assertSame('Reading', $target->weeklyReviews()->sole()->next_focus);
        $this->assertSame($restoredGoal->id, $target->notes()->sole()->goal_id);
        $this->assertSame(600, $target->fresh()->weekly_target_minutes);

        // Data sumber tidak tersentuh.
        $this->assertSame(1, $source->studySessions()->count());
    }

    public function test_replace_mode_wipes_existing_data_first(): void
    {
        $user = User::factory()->create();
        Goal::factory()->for($user)->create(['name' => 'Lama']);
        Task::factory()->for($user)->create();

        $counts = app(DataImporter::class)->import($user, ['goals' => [['id' => 9, 'name' => 'Baru']], 'tasks' => []], replace: true);

        $this->assertSame(1, $counts['goals']);
        $this->assertSame(['Baru'], $user->goals()->withTrashed()->pluck('name')->all());
        $this->assertSame(0, $user->tasks()->count());
    }

    public function test_unrelated_or_broken_files_are_rejected(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::account')
            ->set('importFile', UploadedFile::fake()->createWithContent('lain.json', json_encode(['halo' => 'dunia'])))
            ->call('importData')
            ->assertHasErrors('importFile')
            ->set('importFile', UploadedFile::fake()->createWithContent('rusak.json', '{tidak valid'))
            ->call('importData')
            ->assertHasErrors('importFile');

        $this->assertSame(0, $user->goals()->count());
    }
}
