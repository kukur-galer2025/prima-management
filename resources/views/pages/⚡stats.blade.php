<?php

use App\Models\StudySession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Statistik')] class extends Component
{
    public const RANGES = ['30d' => '30 hari', '12w' => '12 minggu', '6m' => '6 bulan'];

    #[Url]
    public string $range = '30d';

    public function mount(): void
    {
        if (! array_key_exists($this->range, self::RANGES)) {
            $this->range = '30d';
        }
    }

    public function start(): CarbonImmutable
    {
        $today = today()->toImmutable();

        return match ($this->range) {
            '12w' => $today->startOfWeek(1)->subWeeks(11),
            '6m' => $today->startOfMonth()->subMonths(5),
            default => $today->subDays(29),
        };
    }

    public function dayCount(): int
    {
        return (int) $this->start()->diffInDays(today()) + 1;
    }

    #[Computed]
    public function sessions(): Collection
    {
        return auth()->user()->studySessions()
            ->with('goal')
            ->whereDate('studied_on', '>=', $this->start())
            ->whereDate('studied_on', '<=', today())
            ->get();
    }

    /**
     * Menit fokus per hari (30 hari), per minggu (12 minggu), atau per bulan (6 bulan).
     *
     * @return array<int, array{label: string, tooltip: string, minutes: int, current: bool}>
     */
    #[Computed]
    public function buckets(): array
    {
        $keyOf = match ($this->range) {
            '12w' => fn (CarbonImmutable $date) => $date->startOfWeek(1)->toDateString(),
            '6m' => fn (CarbonImmutable $date) => $date->format('Y-m'),
            default => fn (CarbonImmutable $date) => $date->toDateString(),
        };

        $minutes = $this->sessions
            ->groupBy(fn (StudySession $session) => $keyOf($session->studied_on->toImmutable()))
            ->map(fn (Collection $sessions) => (int) $sessions->sum('minutes'));

        $today = today()->toImmutable();
        $buckets = [];

        for ($cursor = $this->start(), $index = 0; $cursor->lte($today); $index++) {
            [$label, $tooltip, $next] = match ($this->range) {
                '12w' => [$cursor->translatedFormat('j M'), 'Minggu '.$cursor->translatedFormat('d M').' – '.$cursor->addDays(6)->translatedFormat('d M'), $cursor->addWeek()],
                '6m' => [$cursor->translatedFormat('M'), $cursor->translatedFormat('F Y'), $cursor->addMonthNoOverflow()],
                default => [$index % 5 === 0 ? $cursor->format('j') : '', $cursor->translatedFormat('D, d M'), $cursor->addDay()],
            };

            $buckets[] = [
                'label' => $label,
                'tooltip' => $tooltip,
                'minutes' => $minutes->get($keyOf($cursor), 0),
                'current' => $next->gt($today),
            ];

            $cursor = $next;
        }

        return $buckets;
    }

    /** @return array{total: int, count: int, activeDays: int, average: int} */
    #[Computed]
    public function summary(): array
    {
        $total = (int) $this->sessions->sum('minutes');

        return [
            'total' => $total,
            'count' => $this->sessions->count(),
            'activeDays' => $this->sessions->map(fn (StudySession $session) => $session->studied_on->toDateString())->unique()->count(),
            'average' => (int) round($total / max(1, $this->dayCount())),
        ];
    }

    /** @return Collection<int, array{goal: \App\Models\Goal, minutes: int}> */
    #[Computed]
    public function byGoal(): Collection
    {
        return $this->sessions
            ->groupBy('goal_id')
            ->map(fn (Collection $sessions) => ['goal' => $sessions->first()->goal, 'minutes' => (int) $sessions->sum('minutes')])
            ->sortByDesc('minutes')
            ->values();
    }

    /**
     * Heatmap setahun: menit fokus per hari, dibagi 5 tingkat relatif terhadap hari tersibuk.
     *
     * @return array<string, array{level: int, label: string}>
     */
    #[Computed]
    public function focusHeatmap(): array
    {
        $minutes = auth()->user()->studySessions()
            ->whereDate('studied_on', '>=', today()->subDays(364))
            ->get(['studied_on', 'minutes'])
            ->groupBy(fn (StudySession $session) => $session->studied_on->toDateString())
            ->map(fn (Collection $sessions) => (int) $sessions->sum('minutes'));

        $max = max(1, $minutes->max() ?? 1);

        return $minutes->map(fn (int $total, string $date) => [
            'level' => max(1, (int) ceil($total / $max * 4)),
            'label' => CarbonImmutable::parse($date)->translatedFormat('D, d M Y').': '.StudySession::formatMinutes($total).' fokus',
        ])->all();
    }

    /**
     * Heatmap setahun: persentase kebiasaan terjadwal yang dicentang per hari.
     *
     * @return array<string, array{level: int, label: string}>
     */
    #[Computed]
    public function habitHeatmap(): array
    {
        $habits = auth()->user()->habits()->with(['logs' => fn ($query) => $query->whereDate('logged_on', '>=', today()->subDays(364))])->get();

        if ($habits->isEmpty()) {
            return [];
        }

        $logged = $habits->mapWithKeys(fn ($habit) => [$habit->id => $habit->logs->map(fn ($log) => $log->logged_on->toDateString())->flip()]);
        $days = [];

        for ($date = today()->toImmutable()->subDays(364); $date->lte(today()); $date = $date->addDay()) {
            $scheduled = $habits->filter(fn ($habit) => $habit->isScheduledOn($date) && (! $habit->created_at || $date->gte($habit->created_at->copy()->startOfDay())));
            $done = $scheduled->filter(fn ($habit) => $logged[$habit->id]->has($date->toDateString()))->count();

            if ($done > 0) {
                $days[$date->toDateString()] = [
                    'level' => max(1, (int) ceil($done / max(1, $scheduled->count()) * 4)),
                    'label' => $date->translatedFormat('D, d M Y').": {$done} dari {$scheduled->count()} kebiasaan",
                ];
            }
        }

        return $days;
    }

    /**
     * Pemasukan & pengeluaran 6 bulan terakhir.
     *
     * @return array<int, array{label: string, tooltip: string, income: int, expense: int}>
     */
    #[Computed]
    public function financeMonths(): array
    {
        $start = today()->toImmutable()->startOfMonth()->subMonths(5);
        $totals = auth()->user()->transactions()
            ->whereDate('occurred_on', '>=', $start)
            ->get(['type', 'amount', 'occurred_on'])
            ->groupBy(fn ($transaction) => $transaction->occurred_on->format('Y-m'));

        return collect(range(0, 5))->map(function (int $offset) use ($start, $totals) {
            $month = $start->addMonths($offset);
            $items = $totals->get($month->format('Y-m'), collect());

            return [
                'label' => $month->translatedFormat('M'),
                'tooltip' => $month->translatedFormat('F Y'),
                'income' => (int) $items->where('type', 'income')->sum('amount'),
                'expense' => (int) $items->where('type', 'expense')->sum('amount'),
            ];
        })->all();
    }

    #[Computed]
    public function tasksDone(): int
    {
        return auth()->user()->tasks()->where('is_done', true)->where('done_at', '>=', $this->start()->startOfDay())->count();
    }

    #[Computed]
    public function habitRate(): ?int
    {
        $rates = auth()->user()->habits()
            ->with(['logs' => fn ($query) => $query->whereDate('logged_on', '>=', $this->start())])
            ->get()
            ->map(fn ($habit) => $habit->completionRate($habit->logs->map(fn ($log) => $log->logged_on->toDateString()), $this->dayCount()))
            ->filter(fn (?int $rate) => ! is_null($rate));

        return $rates->isEmpty() ? null : (int) round($rates->avg());
    }
};
?>

@php
    $summary = $this->summary;
    $buckets = $this->buckets;
    $maxBucket = max(60, max(array_column($buckets, 'minutes')));
    $unit = ['30d' => 'hari', '12w' => 'minggu', '6m' => 'bulan'][$range];
@endphp

<div class="space-y-5">
    <x-page-header title="Statistik" subtitle="Gambaran fokus, tugas, kebiasaan, dan keuanganmu." icon="chart" />

    <div class="flex gap-2">
        @foreach ($this::RANGES as $key => $label)
            <button wire:click="$set('range', '{{ $key }}')" @class([
                'chip px-3.5 py-1.5 text-sm',
                'bg-brand text-on-brand' => $range === $key,
                'bg-surface-2 text-muted' => $range !== $key,
            ])>{{ $label }}</button>
        @endforeach
    </div>

    <section class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="card">
            <p class="text-xs font-semibold text-muted">Total fokus</p>
            <p class="mt-1 text-xl font-bold">{{ StudySession::formatMinutes($summary['total']) }}</p>
        </div>
        <div class="card">
            <p class="text-xs font-semibold text-muted">Rata-rata / hari</p>
            <p class="mt-1 text-xl font-bold">{{ StudySession::formatMinutes($summary['average']) }}</p>
        </div>
        <div class="card">
            <p class="text-xs font-semibold text-muted">Hari aktif</p>
            <p class="mt-1 text-xl font-bold">{{ $summary['activeDays'] }}<span class="text-sm font-semibold text-muted"> / {{ $this->dayCount() }}</span></p>
        </div>
        <div class="card">
            <p class="text-xs font-semibold text-muted">Sesi</p>
            <p class="mt-1 text-xl font-bold">{{ $summary['count'] }}</p>
        </div>
    </section>

    <section class="card" x-data="{ readout: null }" x-on:mouseleave="readout = null">
        <div class="mb-3 flex items-start justify-between gap-3">
            <h2 class="font-bold">Waktu fokus per {{ $unit }}</h2>
            <p class="text-right text-xs font-semibold text-muted" x-text="readout ?? 'Arahkan / ketuk batang'">Arahkan / ketuk batang</p>
        </div>
        <div @class(['flex h-40 items-end', 'gap-0.5' => $range === '30d', 'gap-2' => $range !== '30d'])>
            @foreach ($buckets as $index => $bucket)
                @php $readout = $bucket['tooltip'].': '.StudySession::formatMinutes($bucket['minutes']); @endphp
                <button type="button" wire:key="bucket-{{ $range }}-{{ $index }}"
                    class="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1 outline-none"
                    x-on:mouseenter="readout = @js($readout)" x-on:focus="readout = @js($readout)" x-on:click="readout = @js($readout)"
                    aria-label="{{ $readout }}">
                    <div @class([
                        'w-full max-w-10 rounded-t transition-all',
                        'bg-brand' => $bucket['current'],
                        'bg-brand/40 hover:bg-brand/60' => !$bucket['current'],
                    ]) style="height: {{ $bucket['minutes'] ? max(3, round($bucket['minutes'] / $maxBucket * 100)) : 1 }}%"></div>
                    <span class="h-4 text-[10px] whitespace-nowrap text-muted">{{ $bucket['label'] }}</span>
                </button>
            @endforeach
        </div>
    </section>

    <section class="card">
        <h2 class="mb-3 font-bold">Per target</h2>
        @forelse ($this->byGoal as $row)
            <div wire:key="by-goal-{{ $row['goal']->id }}" class="mb-3 last:mb-0">
                <div class="mb-1 flex items-center justify-between gap-2 text-sm">
                    <span class="flex min-w-0 items-center gap-2">
                        <span class="size-2.5 shrink-0 rounded-full" style="background-color: {{ $row['goal']->color }}"></span>
                        <span class="truncate font-medium">{{ $row['goal']->label() }}</span>
                        @if ($row['goal']->trashed())
                            <span class="chip bg-surface-2 text-muted">terhapus</span>
                        @endif
                    </span>
                    <span class="shrink-0 text-muted">
                        <span class="font-semibold text-ink">{{ StudySession::formatMinutes($row['minutes']) }}</span>
                        · {{ round($row['minutes'] / max(1, $summary['total']) * 100) }}%
                    </span>
                </div>
                <x-progress :value="round($row['minutes'] / max(1, $this->byGoal->first()['minutes']) * 100)" :color="$row['goal']->color" />
            </div>
        @empty
            <p class="text-sm text-muted">Belum ada sesi fokus di rentang ini.</p>
        @endforelse
    </section>

    {{-- Heatmap setahun --}}
    <section class="card">
        <h2 class="section-title mb-3">Fokus setahun terakhir</h2>
        <x-heatmap :days="$this->focusHeatmap" :from="today()->subDays(364)" :to="today()" empty-label="tidak ada sesi fokus" />
    </section>

    @if ($this->habitHeatmap !== [] || auth()->user()->habits()->exists())
        <section class="card">
            <h2 class="section-title mb-3">Kebiasaan setahun terakhir</h2>
            <x-heatmap :days="$this->habitHeatmap" :from="today()->subDays(364)" :to="today()" color="var(--chart-income)" empty-label="belum ada kebiasaan dicentang" />
        </section>
    @endif

    {{-- Keuangan 6 bulan: 2 seri, warna lolos validasi buta warna, legenda + label teks --}}
    @php
        $months = $this->financeMonths;
        $maxMoney = max(1, ...array_map(fn ($month) => max($month['income'], $month['expense']), $months));
    @endphp
    <section class="card" x-data="{ readout: null }" x-on:mouseleave="readout = null">
        <div class="mb-3 flex flex-wrap items-start justify-between gap-2">
            <div>
                <h2 class="section-title">Keuangan 6 bulan</h2>
                <div class="mt-1 flex items-center gap-3 text-xs font-semibold text-muted">
                    <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-income"></span> Pemasukan</span>
                    <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-expense"></span> Pengeluaran</span>
                </div>
            </div>
            <p class="text-right text-xs font-semibold text-muted" x-text="readout ?? 'Arahkan / ketuk bulan'">Arahkan / ketuk bulan</p>
        </div>
        <div class="flex h-40 items-end gap-3">
            @foreach ($months as $index => $month)
                @php $readout = $month['tooltip'].': masuk '.\App\Models\Transaction::formatRupiah($month['income']).' · keluar '.\App\Models\Transaction::formatRupiah($month['expense']); @endphp
                <button type="button" wire:key="money-{{ $index }}" class="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1 outline-none"
                    x-on:mouseenter="readout = @js($readout)" x-on:focus="readout = @js($readout)" x-on:click="readout = @js($readout)" aria-label="{{ $readout }}">
                    <div class="flex h-full w-full max-w-14 items-end justify-center gap-0.5">
                        <div class="w-1/2 rounded-t-md bg-income" style="height: {{ $month['income'] ? max(2, round($month['income'] / $maxMoney * 100)) : 1 }}%"></div>
                        <div class="w-1/2 rounded-t-md bg-expense" style="height: {{ $month['expense'] ? max(2, round($month['expense'] / $maxMoney * 100)) : 1 }}%"></div>
                    </div>
                    <span class="h-4 text-[10px] font-semibold text-muted">{{ $month['label'] }}</span>
                </button>
            @endforeach
        </div>
    </section>

    <section class="grid grid-cols-2 gap-3">
        <a href="{{ route('tasks', ['filter' => 'done']) }}" wire:navigate class="card block">
            <p class="text-xs font-semibold text-muted">Tugas selesai</p>
            <p class="mt-1 text-xl font-bold">{{ $this->tasksDone }}</p>
        </a>
        <a href="{{ route('habits') }}" wire:navigate class="card block">
            <p class="text-xs font-semibold text-muted">Konsistensi kebiasaan</p>
            <p class="mt-1 text-xl font-bold">{{ is_null($this->habitRate) ? '–' : $this->habitRate.'%' }}</p>
        </a>
    </section>
</div>
