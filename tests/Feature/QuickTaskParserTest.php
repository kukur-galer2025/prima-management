<?php

namespace Tests\Feature;

use App\Models\Goal;
use App\Models\User;
use App\Services\QuickTaskParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QuickTaskParserTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Rabu, 25 November 2026 pukul 08.00.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function sentences(): array
    {
        return [
            'besok + jam + penting' => ['Bayar UKT besok jam 10 !penting', ['title' => 'Bayar UKT', 'due_date' => '2026-11-26', 'due_time' => '10:00', 'priority' => 1]],
            'jam malam' => ['Telepon ibu jam 7 malam', ['title' => 'Telepon ibu', 'due_date' => '2026-11-25', 'due_time' => '19:00']],
            'jam sudah lewat → besok' => ['Olahraga jam 6 pagi', ['due_date' => '2026-11-26', 'due_time' => '06:00']],
            'format 19.30' => ['Rapat 19.30', ['title' => 'Rapat', 'due_date' => '2026-11-25', 'due_time' => '19:30']],
            'lusa' => ['Kumpulkan berkas lusa', ['title' => 'Kumpulkan berkas', 'due_date' => '2026-11-27']],
            'nama hari' => ['Kirim CV jumat', ['title' => 'Kirim CV', 'due_date' => '2026-11-27']],
            'hari ini (rabu) → rabu depan' => ['Bimbingan rabu', ['due_date' => '2026-12-02']],
            'senin depan (dari Rabu)' => ['Mulai bab 2 senin depan', ['title' => 'Mulai bab 2', 'due_date' => '2026-11-30']],
            'tanggal + bulan' => ['Tes TOEFL 5 des jam 8', ['title' => 'Tes TOEFL', 'due_date' => '2026-12-05', 'due_time' => '08:00']],
            'tanggal lewat → tahun depan' => ['Perpanjang SIM 3 januari', ['due_date' => '2027-01-03']],
            'tgl saja' => ['Bayar kos tgl 1', ['title' => 'Bayar kos', 'due_date' => '2026-12-01']],
            'garis miring' => ['Daftar ulang 30/11', ['due_date' => '2026-11-30']],
            'N hari lagi' => ['Revisi 3 hari lagi', ['title' => 'Revisi', 'due_date' => '2026-11-28']],
            'tiap minggu' => ['Review kosakata tiap minggu', ['title' => 'Review kosakata', 'recurrence' => 'weekly', 'due_date' => null]],
            'tiap hari minggu' => ['Cuci motor tiap hari minggu', ['title' => 'Cuci motor', 'recurrence' => 'weekly', 'due_date' => '2026-11-29']],
            'tiap senin' => ['Laporan tiap senin jam 9', ['title' => 'Laporan', 'recurrence' => 'weekly', 'due_date' => '2026-11-30', 'due_time' => '09:00']],
            'prioritas rendah' => ['Rapikan meja !rendah', ['title' => 'Rapikan meja', 'priority' => 3]],
            'uang tidak dikira jam' => ['Bayar 50.000 ke Andi', ['title' => 'Bayar 50.000 ke Andi', 'due_time' => null, 'detected' => false]],
            '"minggu ini" bukan hari Minggu' => ['Laporan minggu ini', ['title' => 'Laporan minggu ini', 'due_date' => null]],
            'tanggal mustahil diabaikan' => ['Acara 31/2', ['title' => 'Acara 31/2', 'due_date' => null]],
            'tanpa token' => ['Beli buku', ['title' => 'Beli buku', 'detected' => false]],
        ];
    }

    /** @param  array<string, mixed>  $expected */
    #[DataProvider('sentences')]
    public function test_it_understands_indonesian_sentences(string $sentence, array $expected): void
    {
        $result = (new QuickTaskParser)->parse($sentence, collect(), Carbon::parse('2026-11-25 08:00'));

        foreach ($expected as $key => $value) {
            $this->assertSame($value, $result[$key], "Kunci '{$key}' untuk: {$sentence}");
        }
    }

    public function test_hashtag_links_to_a_goal_by_partial_name(): void
    {
        $user = User::factory()->create();
        $toefl = Goal::factory()->for($user)->create(['name' => 'TOEFL ITP', 'icon' => null]);
        Goal::factory()->for($user)->create(['name' => 'Proposal S2']);

        $result = (new QuickTaskParser)->parse('Latihan listening #toefl besok', $user->goals, Carbon::parse('2026-11-25 08:00'));
        $this->assertSame($toefl->id, $result['goal_id']);
        $this->assertSame('Latihan listening', $result['title']);

        $unknown = (new QuickTaskParser)->parse('Belajar #fisika', $user->goals, Carbon::parse('2026-11-25 08:00'));
        $this->assertNull($unknown['goal_id']);
        $this->assertSame('Belajar fisika', $unknown['title']);
    }

    public function test_tasks_page_and_dashboard_create_tasks_from_a_sentence(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 08:00'));
        $user = User::factory()->create();
        $goal = Goal::factory()->for($user)->create(['name' => 'SKD', 'icon' => null]);

        Livewire::actingAs($user)
            ->test('pages::tasks')
            ->set('title', 'Latihan TIU besok jam 7 malam !penting #skd')
            ->assertSee('Terbaca:')
            ->call('quickAdd')
            ->assertHasNoErrors();

        $task = $user->tasks()->sole();
        $this->assertSame('Latihan TIU', $task->title);
        $this->assertSame('2026-11-26', $task->due_date->toDateString());
        $this->assertSame('19:00', $task->due_time);
        $this->assertSame(1, $task->priority);
        $this->assertSame($goal->id, $task->goal_id);

        Livewire::actingAs($user)
            ->test('pages::dashboard')
            ->set('quickTask', 'Bayar kos tgl 1 tiap bulan')
            ->call('addQuickTask')
            ->assertHasNoErrors()
            ->assertSet('quickTask', '');

        $kos = $user->tasks()->where('title', 'Bayar kos')->sole();
        $this->assertSame('monthly', $kos->recurrence);
        $this->assertSame('2026-12-01', $kos->due_date->toDateString());
    }
}
