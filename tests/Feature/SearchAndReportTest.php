<?php

namespace Tests\Feature;

use App\Models\Debt;
use App\Models\Goal;
use App\Models\Habit;
use App\Models\JournalEntry;
use App\Models\Note;
use App\Models\StudySession;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class SearchAndReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_finds_matches_across_data_types(): void
    {
        $user = User::factory()->create();
        Task::factory()->for($user)->create(['title' => 'Bayar beasiswa semester']);
        Note::factory()->for($user)->create(['title' => 'Syarat', 'body' => 'Dokumen beasiswa: transkrip, KTM']);
        Goal::factory()->for($user)->create(['name' => 'Beasiswa LPDP']);
        Transaction::factory()->for($user)->income()->create(['category' => 'Beasiswa', 'amount' => 2500000, 'occurred_on' => '2026-11-20']);
        JournalEntry::factory()->for($user)->create(['content' => 'Lega, berkas beasiswa terkirim']);
        Debt::factory()->for($user)->create(['person' => 'Andi', 'notes' => 'talangan beasiswa']);
        Habit::factory()->for($user)->create(['name' => 'Olahraga']);

        $component = Livewire::actingAs($user)->test('pages::search')->set('q', 'beasiswa');
        $results = $component->instance()->results;

        $this->assertSame(['Tugas', 'Catatan', 'Target', 'Transaksi', 'Jurnal', 'Utang & piutang'], array_keys($results));
        $component->assertSee('Bayar beasiswa semester')->assertSee('Beasiswa LPDP')->assertSee('Utang ke Andi')->assertDontSee('Olahraga');
        $this->assertStringContainsString('/catatan?open=', $results['Catatan']['items'][0]['url']);
        $this->assertStringContainsString('month=2026-11', $results['Transaksi']['items'][0]['url']);
    }

    public function test_search_never_shows_other_users_data_and_needs_two_letters(): void
    {
        $me = User::factory()->create();
        Task::factory()->for(User::factory()->create())->create(['title' => 'Rahasia orang lain']);

        Livewire::actingAs($me)->test('pages::search')
            ->set('q', 'r')
            ->assertSee('Cari di semua data')
            ->set('q', 'Rahasia')
            ->assertSee('Tidak ditemukan')
            ->assertDontSee('Rahasia orang lain');

        $this->actingAs($me)->get(route('search', ['q' => 'Rahasia']))->assertOk()->assertDontSee('Rahasia orang lain');
    }

    public function test_monthly_report_summarises_the_selected_month(): void
    {
        $this->travelTo(Carbon::parse('2026-11-26 10:00'));
        $user = User::factory()->create();
        $goal = Goal::factory()->for($user)->create(['name' => 'TOEFL']);
        StudySession::factory()->for($goal)->create(['user_id' => $user->id, 'studied_on' => '2026-11-03', 'minutes' => 90]);
        StudySession::factory()->for($goal)->create(['user_id' => $user->id, 'studied_on' => '2026-11-04', 'minutes' => 30]);
        StudySession::factory()->for($goal)->create(['user_id' => $user->id, 'studied_on' => '2026-10-30', 'minutes' => 500]);
        Task::factory()->for($user)->create(['is_done' => true, 'done_at' => '2026-11-10 09:00:00']);
        Transaction::factory()->for($user)->income()->create(['amount' => 3000000, 'occurred_on' => '2026-11-01']);
        Transaction::factory()->for($user)->create(['category' => 'Makan', 'amount' => 450000, 'occurred_on' => '2026-11-15']);
        $user->budgets()->create(['category' => 'Makan', 'amount' => 400000]);
        JournalEntry::factory()->for($user)->create(['entry_on' => '2026-11-20', 'mood' => 5, 'content' => 'Lulus ujian!']);
        JournalEntry::factory()->for($user)->create(['entry_on' => '2026-11-21', 'mood' => 3, 'content' => null]);
        $habit = Habit::factory()->for($user)->create(['name' => 'Baca', 'days' => Habit::EVERY_DAY, 'created_at' => '2026-11-21 08:00:00']);
        foreach (['2026-11-21', '2026-11-22', '2026-11-24'] as $day) {
            $habit->logs()->create(['logged_on' => $day]);
        }

        $component = Livewire::actingAs($user)->test('pages::report', ['month' => '2026-11']);
        $report = $component->instance();

        $this->assertSame(120, $report->focus['minutes']);
        $this->assertSame(2, $report->focus['activeDays']);
        $this->assertSame(1, $report->tasks['done']);
        $this->assertSame(3000000, $report->money['income']);
        $this->assertSame(4.0, $report->journal['average']);
        // Kebiasaan dibuat 21 Nov; dihitung sampai hari ini (26 Nov) = 6 hari terjadwal, 3 dicentang.
        $this->assertSame(['done' => 3, 'scheduled' => 6, 'percent' => 50], collect($report->habits->first())->only(['done', 'scheduled', 'percent'])->all());

        $component->assertSee('Lulus ujian!')->assertSee('Rp 450.000')->assertSee('/ Rp 400.000')->assertSee('Cetak / simpan PDF');
    }

    public function test_report_cannot_go_beyond_current_month_and_csv_downloads_only_that_month(): void
    {
        $this->travelTo(Carbon::parse('2026-11-26 10:00'));
        $user = User::factory()->create();
        Transaction::factory()->for($user)->create(['category' => 'Kos', 'amount' => 750000, 'occurred_on' => '2026-10-05', 'notes' => 'Oktober']);
        Transaction::factory()->for($user)->create(['category' => 'Makan', 'amount' => 20000, 'occurred_on' => '2026-11-05', 'notes' => 'November']);

        Livewire::actingAs($user)->test('pages::report', ['month' => '2027-05'])->assertSet('month', '2026-11')->call('shiftMonth', 1)->assertSet('month', '2026-11');

        $download = Livewire::actingAs($user)->test('pages::report', ['month' => '2026-10'])->call('downloadTransactions');
        $csv = base64_decode($download->effects['download']['content']);

        $this->assertSame('transaksi-2026-10.csv', $download->effects['download']['name']);
        $this->assertStringContainsString('2026-10-05,Pengeluaran,Kos,-750000', $csv);
        $this->assertStringNotContainsString('November', $csv);
    }

    public function test_new_pages_are_reachable_from_navigation(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard'))->assertSee(route('search'))->assertSee(route('report'));
        $this->get(route('report'))->assertOk();
        $this->get(route('search'))->assertOk();
    }
}
