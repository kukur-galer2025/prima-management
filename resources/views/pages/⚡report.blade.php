<?php

use App\Models\Habit;
use App\Models\JournalEntry;
use App\Models\SavingsDeposit;
use App\Models\StudySession;
use App\Models\Transaction;
use App\Services\BudgetMonitor;
use App\Services\DataExporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('Laporan bulanan')] class extends Component
{
    /** Bulan laporan, format Y-m. */
    #[Url]
    public string $month = '';

    public function mount(): void
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) || $this->period()->gt(today()->startOfMonth())) {
            $this->month = today()->format('Y-m');
        }
    }

    public function period(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $this->month);
    }

    /** Hari terakhir yang dihitung: akhir bulan, atau hari ini untuk bulan berjalan. */
    public function lastDay(): CarbonImmutable
    {
        return $this->period()->endOfMonth()->min(today()->toImmutable())->startOfDay();
    }

    public function shiftMonth(int $delta): void
    {
        $next = $this->period()->addMonthsNoOverflow($delta);

        if ($next->lte(today()->startOfMonth())) {
            $this->month = $next->format('Y-m');
        }
    }

    /** @return array{minutes: int, sessions: int, activeDays: int, goals: Collection} */
    #[Computed]
    public function focus(): array
    {
        $sessions = auth()->user()->studySessions()->with('goal')
            ->whereDate('studied_on', '>=', $this->period())
            ->whereDate('studied_on', '<=', $this->period()->endOfMonth())
            ->get();

        return [
            'minutes' => (int) $sessions->sum('minutes'),
            'sessions' => $sessions->count(),
            'activeDays' => $sessions->map(fn (StudySession $session) => $session->studied_on->toDateString())->unique()->count(),
            'goals' => $sessions->groupBy('goal_id')
                ->map(fn (Collection $items) => ['goal' => $items->first()->goal, 'minutes' => (int) $items->sum('minutes')])
                ->sortByDesc('minutes')->take(6)->values(),
        ];
    }

    /** @return array{done: int, created: int, overdue: int} */
    #[Computed]
    public function tasks(): array
    {
        $user = auth()->user();
        $start = $this->period()->startOfDay();
        $end = $this->period()->endOfMonth();

        return [
            'done' => $user->tasks()->where('is_done', true)->whereBetween('done_at', [$start, $end])->count(),
            'created' => $user->tasks()->whereBetween('created_at', [$start, $end])->count(),
            'overdue' => $user->tasks()->where('is_done', false)->whereDate('due_date', '<', today())->whereDate('due_date', '<=', $end)->count(),
        ];
    }

    /**
     * Persentase hari terjadwal yang dicentang per kebiasaan selama bulan ini.
     *
     * @return Collection<int, array{habit: Habit, done: int, scheduled: int, percent: ?int}>
     */
    #[Computed]
    public function habits(): Collection
    {
        $start = $this->period();
        $end = $this->lastDay();

        return auth()->user()->habits()
            ->with(['logs' => fn ($query) => $query->whereDate('logged_on', '>=', $start)->whereDate('logged_on', '<=', $end)])
            ->orderBy('name')
            ->get()
            ->map(function (Habit $habit) use ($start, $end) {
                $logged = $habit->logs->map(fn ($log) => $log->logged_on->toDateString())->flip();
                $since = $habit->created_at?->copy()->startOfDay();
                $scheduled = 0;
                $done = 0;

                for ($day = $start; $day->lte($end); $day = $day->addDay()) {
                    if ($since && $day->lt($since)) {
                        continue;
                    }

                    if ($habit->isScheduledOn($day)) {
                        $scheduled++;
                        $done += $logged->has($day->toDateString()) ? 1 : 0;
                    }
                }

                return ['habit' => $habit, 'done' => $done, 'scheduled' => $scheduled, 'percent' => $scheduled ? (int) round($done / $scheduled * 100) : null];
            })
            ->filter(fn (array $row) => $row['scheduled'] > 0)
            ->values();
    }

    /** @return array{income: int, expense: int, categories: Collection, budgets: Collection, saved: int} */
    #[Computed]
    public function money(): array
    {
        $user = auth()->user();
        $transactions = $user->transactions()
            ->whereDate('occurred_on', '>=', $this->period())
            ->whereDate('occurred_on', '<=', $this->period()->endOfMonth())
            ->get();

        return [
            'income' => (int) $transactions->where('type', 'income')->sum('amount'),
            'expense' => (int) $transactions->where('type', 'expense')->sum('amount'),
            'categories' => $transactions->where('type', 'expense')->groupBy('category')
                ->map(fn (Collection $items) => (int) $items->sum('amount'))->sortDesc()->take(8),
            'budgets' => app(BudgetMonitor::class)->usage($user, $this->period()),
            'saved' => (int) SavingsDeposit::query()
                ->whereIn('savings_goal_id', $user->savingsGoals()->select('id'))
                ->whereDate('deposited_on', '>=', $this->period())
                ->whereDate('deposited_on', '<=', $this->period()->endOfMonth())
                ->sum('amount'),
        ];
    }

    /** @return array{count: int, average: ?float, best: ?JournalEntry} */
    #[Computed]
    public function journal(): array
    {
        $entries = auth()->user()->journalEntries()
            ->whereDate('entry_on', '>=', $this->period())
            ->whereDate('entry_on', '<=', $this->period()->endOfMonth())
            ->get();

        return [
            'count' => $entries->count(),
            'average' => $entries->isEmpty() ? null : round($entries->avg('mood'), 1),
            'best' => $entries->sortByDesc('mood')->first(fn (JournalEntry $entry) => filled($entry->content)),
        ];
    }

    public function downloadTransactions(): StreamedResponse
    {
        return $this->csv('transaksi', fn ($output) => app(DataExporter::class)->writeTransactionsCsv(auth()->user(), $output, $this->period(), $this->period()->endOfMonth()));
    }

    public function downloadSessions(): StreamedResponse
    {
        return $this->csv('sesi-fokus', fn ($output) => app(DataExporter::class)->writeSessionsCsv(auth()->user(), $output, $this->period(), $this->period()->endOfMonth()));
    }

    private function csv(string $name, Closure $write): StreamedResponse
    {
        return response()->streamDownload(function () use ($write) {
            $output = fopen('php://output', 'w');
            $write($output);
            fclose($output);
        }, "{$name}-{$this->month}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
};
?>

@php
    $focus = $this->focus;
    $tasks = $this->tasks;
    $money = $this->money;
    $journal = $this->journal;
    $maxGoal = max(1, $focus['goals']->max('minutes') ?? 1);
    $maxCategory = max(1, $money['categories']->max() ?? 1);
    $isCurrentMonth = $this->period()->isSameMonth(today());
@endphp

<div class="space-y-5">
    <x-page-header title="Laporan bulanan" subtitle="Ringkasan satu bulan: fokus, tugas, kebiasaan, keuangan, dan mood." icon="report">
        <x-slot:actions>
            <div class="flex items-center gap-0.5 rounded-2xl border border-line bg-surface p-1 print:hidden">
                <button class="rounded-xl p-1.5 text-muted hover:bg-surface-2" wire:click="shiftMonth(-1)" aria-label="Bulan sebelumnya"><x-icon name="chevron-left" class="size-4" /></button>
                <span class="min-w-24 text-center text-sm font-bold">{{ $this->period()->translatedFormat('M Y') }}</span>
                <button class="rounded-xl p-1.5 text-muted hover:bg-surface-2 disabled:opacity-30" wire:click="shiftMonth(1)" @disabled($isCurrentMonth) aria-label="Bulan berikutnya"><x-icon name="chevron-right" class="size-4" /></button>
            </div>
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-1 gap-2 sm:grid-cols-3 print:hidden">
        <button type="button" class="btn btn-primary" onclick="window.print()"><x-icon name="download" class="size-4" /> Cetak / simpan PDF</button>
        <button type="button" class="btn btn-ghost" wire:click="downloadTransactions"><x-icon name="download" class="size-4" /> Transaksi (Excel)</button>
        <button type="button" class="btn btn-ghost" wire:click="downloadSessions"><x-icon name="download" class="size-4" /> Sesi fokus (Excel)</button>
    </div>
    <p class="-mt-2 text-xs text-muted print:hidden">PDF: pilih "Simpan sebagai PDF" di jendela cetak. File Excel berformat CSV dan bisa langsung dibuka di Excel atau Google Sheets.</p>

    <section class="hero">
        <p class="text-sm font-semibold opacity-80">{{ $this->period()->translatedFormat('F Y') }}{{ $isCurrentMonth ? ' (sampai hari ini)' : '' }}</p>
        <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
            @foreach ([
                ['Fokus', StudySession::formatMinutes($focus['minutes'])],
                ['Tugas selesai', $tasks['done']],
                ['Selisih uang', Transaction::formatRupiah($money['income'] - $money['expense'])],
                ['Rata-rata mood', $journal['average'] ? JournalEntry::emojiFor($journal['average']).' '.number_format($journal['average'], 1, ',', '.') : '–'],
            ] as [$label, $value])
                <div class="min-w-0 rounded-2xl p-3" style="background-color: rgb(255 255 255 / 0.12)">
                    <p class="text-xs font-semibold opacity-80">{{ $label }}</p>
                    <p class="truncate text-lg font-extrabold">{{ $value }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Fokus --}}
    <section class="card break-inside-avoid">
        <h2 class="section-title mb-1">Fokus</h2>
        <p class="mb-3 text-sm text-muted">{{ $focus['sessions'] }} sesi · {{ $focus['activeDays'] }} hari aktif</p>
        @forelse ($focus['goals'] as $row)
            <div wire:key="report-goal-{{ $loop->index }}" class="mb-2.5 last:mb-0">
                <div class="mb-1 flex items-center justify-between gap-2 text-sm">
                    <span class="truncate font-semibold">{{ $row['goal']?->label() ?? 'Target terhapus' }}</span>
                    <span class="shrink-0 font-bold">{{ StudySession::formatMinutes($row['minutes']) }}</span>
                </div>
                <x-progress :value="round($row['minutes'] / $maxGoal * 100)" :color="$row['goal']?->color" />
            </div>
        @empty
            <p class="text-sm text-muted">Belum ada sesi fokus di bulan ini.</p>
        @endforelse
    </section>

    {{-- Tugas --}}
    <section class="card break-inside-avoid">
        <h2 class="section-title mb-3">Tugas</h2>
        <div class="grid grid-cols-3 gap-2 text-center">
            @foreach ([['Selesai', $tasks['done']], ['Dibuat', $tasks['created']], ['Terlambat', $tasks['overdue']]] as [$label, $value])
                <div class="rounded-2xl bg-surface-2 p-3">
                    <p class="text-xl font-extrabold {{ $label === 'Terlambat' && $value ? 'text-danger' : '' }}">{{ $value }}</p>
                    <p class="text-xs text-muted">{{ $label }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Kebiasaan --}}
    <section class="card break-inside-avoid">
        <h2 class="section-title mb-3">Kebiasaan</h2>
        @forelse ($this->habits as $row)
            <div wire:key="report-habit-{{ $row['habit']->id }}" class="mb-2.5 last:mb-0">
                <div class="mb-1 flex items-center justify-between gap-2 text-sm">
                    <span class="truncate font-semibold">{{ $row['habit']->label() }}</span>
                    <span class="shrink-0 text-muted"><span class="font-bold text-ink">{{ $row['percent'] }}%</span> · {{ $row['done'] }}/{{ $row['scheduled'] }} hari</span>
                </div>
                <x-progress :value="$row['percent']" :color="$row['habit']->color" />
            </div>
        @empty
            <p class="text-sm text-muted">Tidak ada kebiasaan terjadwal di bulan ini.</p>
        @endforelse
    </section>

    {{-- Keuangan --}}
    <section class="card break-inside-avoid">
        <h2 class="section-title mb-3">Keuangan</h2>
        <div class="mb-4 grid grid-cols-1 gap-2 sm:grid-cols-3">
            <div class="rounded-2xl bg-surface-2 p-3"><p class="text-xs text-muted">Pemasukan</p><p class="font-extrabold text-success">{{ Transaction::formatRupiah($money['income']) }}</p></div>
            <div class="rounded-2xl bg-surface-2 p-3"><p class="text-xs text-muted">Pengeluaran</p><p class="font-extrabold">{{ Transaction::formatRupiah($money['expense']) }}</p></div>
            <div class="rounded-2xl bg-surface-2 p-3"><p class="text-xs text-muted">Disetor ke tabungan</p><p class="font-extrabold">{{ Transaction::formatRupiah($money['saved']) }}</p></div>
        </div>
        <h3 class="mb-2 text-sm font-bold">Pengeluaran per kategori</h3>
        @forelse ($money['categories'] as $category => $total)
            @php $budget = $money['budgets']->firstWhere('key', 'category:'.$category); @endphp
            <div wire:key="report-cat-{{ md5($category) }}" class="mb-2.5 last:mb-0">
                <div class="mb-1 flex items-center justify-between gap-2 text-sm">
                    <span class="truncate font-semibold">{{ $category }}</span>
                    <span class="shrink-0 text-muted">
                        <span @class(['font-bold', 'text-danger' => $budget && $budget['percent'] >= 100, 'text-ink' => ! $budget || $budget['percent'] < 100])>{{ Transaction::formatRupiah($total) }}</span>
                        @if ($budget) / {{ Transaction::formatRupiah($budget['budget']) }} @endif
                    </span>
                </div>
                <div class="h-2.5 overflow-hidden rounded-full bg-surface-2">
                    <div @class(['h-full rounded-full', 'bg-danger' => $budget && $budget['percent'] >= 100, 'bg-expense' => ! $budget || $budget['percent'] < 100])
                        style="width: {{ $budget ? min(100, max(2, $budget['percent'])) : max(2, round($total / $maxCategory * 100)) }}%"></div>
                </div>
            </div>
        @empty
            <p class="text-sm text-muted">Tidak ada pengeluaran di bulan ini.</p>
        @endforelse
    </section>

    {{-- Jurnal --}}
    <section class="card break-inside-avoid">
        <h2 class="section-title mb-1">Mood & jurnal</h2>
        @if ($journal['count'])
            <p class="text-sm text-muted">{{ $journal['count'] }} entri · rata-rata mood <span class="font-bold text-ink">{{ JournalEntry::emojiFor($journal['average']) }} {{ number_format($journal['average'], 1, ',', '.') }}</span></p>
            @if ($journal['best'])
                <blockquote class="mt-3 rounded-2xl bg-surface-2 p-3 text-sm">
                    <span class="text-xs font-bold text-muted">Hari terbaik · {{ $journal['best']->entry_on->translatedFormat('l, d M') }} {{ $journal['best']->moodEmoji() }}</span>
                    <span class="mt-1 block">{{ $journal['best']->content }}</span>
                </blockquote>
            @endif
        @else
            <p class="text-sm text-muted">Belum ada entri jurnal di bulan ini.</p>
        @endif
    </section>

    <p class="hidden text-xs text-muted print:block">Dicetak dari Prima Management · {{ now()->translatedFormat('d M Y H:i') }} · {{ auth()->user()->name }}</p>
</div>
