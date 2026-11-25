<?php

use App\Models\StudySession;
use App\Models\Transaction;
use App\Models\WeeklyReview;
use App\Services\WeeklySummary;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Review mingguan')] class extends Component
{
    /** Senin dari minggu yang dibuka, format Y-m-d. */
    #[Url(as: 'minggu', except: '')]
    public string $week = '';

    public int|string|null $rating = null;

    public string $went_well = '';

    public string $to_improve = '';

    public string $next_focus = '';

    public function mount(): void
    {
        $this->week = $this->weekStart()->toDateString();
        $this->loadReview();
    }

    public function weekStart(): CarbonImmutable
    {
        $current = CarbonImmutable::today()->startOfWeek(1);

        try {
            $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->week) ? CarbonImmutable::parse($this->week)->startOfWeek(1) : $current;
        } catch (\Throwable) {
            $start = $current;
        }

        return $start->gt($current) ? $current : $start;
    }

    public function shiftWeek(int $delta): void
    {
        $this->week = $this->weekStart()->addWeeks($delta)->toDateString();
        $this->week = $this->weekStart()->toDateString();
        $this->loadReview();
    }

    public function openWeek(string $week): void
    {
        $this->week = $week;
        $this->week = $this->weekStart()->toDateString();
        $this->loadReview();
    }

    #[Computed]
    public function summary(): array
    {
        return app(WeeklySummary::class)->for(auth()->user(), $this->weekStart());
    }

    #[Computed]
    public function pastReviews(): Collection
    {
        return auth()->user()->weeklyReviews()->latest('week_start')->limit(8)->get();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'rating' => ['nullable', 'integer', Rule::in(array_keys(WeeklyReview::RATINGS))],
            'went_well' => ['nullable', 'string', 'max:2000'],
            'to_improve' => ['nullable', 'string', 'max:2000'],
            'next_focus' => ['nullable', 'string', 'max:2000'],
        ], [], ['went_well' => 'yang berjalan baik', 'to_improve' => 'yang perlu diperbaiki', 'next_focus' => 'fokus minggu depan']);

        $attributes = [
            'rating' => filled($validated['rating']) ? (int) $validated['rating'] : null,
            'went_well' => $validated['went_well'] ?: null,
            'to_improve' => $validated['to_improve'] ?: null,
            'next_focus' => $validated['next_focus'] ?: null,
        ];

        $review = auth()->user()->weeklyReviews()->whereDate('week_start', $this->weekStart())->first();

        $review
            ? $review->update($attributes)
            : auth()->user()->weeklyReviews()->create(['week_start' => $this->weekStart()->toDateString()] + $attributes);

        unset($this->pastReviews);
        $this->dispatch('toast', message: 'Review minggu ini tersimpan ✨');
    }

    private function loadReview(): void
    {
        $this->resetValidation();
        $review = auth()->user()->weeklyReviews()->whereDate('week_start', $this->weekStart())->first();

        $this->rating = $review?->rating;
        $this->went_well = (string) $review?->went_well;
        $this->to_improve = (string) $review?->to_improve;
        $this->next_focus = (string) $review?->next_focus;
    }
};
?>

@php
    $summary = $this->summary;
    $delta = $summary['focusMinutes'] - $summary['previousFocusMinutes'];
    $isCurrentWeek = $summary['isCurrent'];
@endphp

<div class="space-y-5">
    <x-page-header title="Review mingguan" subtitle="Lihat apa yang terjadi, lalu rencanakan minggu depan." icon="sparkle" />

    {{-- Navigasi minggu --}}
    <div class="flex items-center justify-between gap-2 rounded-3xl border border-line bg-surface p-1.5 shadow-soft">
        <button class="btn btn-ghost px-3 py-2" wire:click="shiftWeek(-1)" aria-label="Minggu sebelumnya"><x-icon name="chevron-left" class="size-4" /></button>
        <div class="text-center">
            <p class="text-sm font-extrabold">{{ $summary['start']->translatedFormat('d M') }} – {{ $summary['end']->translatedFormat('d M Y') }}</p>
            <p class="text-xs text-muted">{{ $isCurrentWeek ? 'Minggu ini' : 'Minggu ke-'.$summary['start']->isoWeek() }}</p>
        </div>
        <button class="btn btn-ghost px-3 py-2" wire:click="shiftWeek(1)" @disabled($isCurrentWeek) aria-label="Minggu berikutnya"><x-icon name="chevron-right" class="size-4" /></button>
    </div>

    {{-- Ringkasan otomatis --}}
    <section class="hero">
        <p class="text-sm font-semibold opacity-80">Total fokus</p>
        <div class="flex flex-wrap items-end gap-x-3 gap-y-1">
            <p class="text-4xl font-extrabold tracking-tight">{{ StudySession::formatMinutes($summary['focusMinutes']) }}</p>
            <p class="pb-1 text-sm font-bold opacity-90">
                {{ $delta >= 0 ? '▲' : '▼' }} {{ StudySession::formatMinutes(abs($delta)) }} vs minggu lalu
            </p>
        </div>
        <p class="mt-2 text-sm opacity-90">
            {{ $summary['sessionsCount'] }} sesi · {{ $summary['activeDays'] }} hari aktif
            @if ($summary['bestDay'])
                · hari terbaik {{ $summary['bestDay']['date']->translatedFormat('l') }} ({{ StudySession::formatMinutes($summary['bestDay']['minutes']) }})
            @endif
        </p>
        @if ($summary['target'])
            <div class="mt-4">
                <div class="mb-1 flex justify-between text-xs font-bold opacity-90">
                    <span>Target mingguan {{ StudySession::formatMinutes($summary['target']) }}</span>
                    <span>{{ $summary['targetPercent'] }}%</span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-white/20">
                    <div class="h-full rounded-full bg-white" style="width: {{ min(100, $summary['targetPercent']) }}%"></div>
                </div>
            </div>
        @endif
    </section>

    <section class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="card">
            <p class="text-xs font-semibold text-muted">Jadwal terlaksana</p>
            <p class="text-xl font-extrabold">{{ is_null($summary['adherence']['percent']) ? '–' : $summary['adherence']['percent'].'%' }}</p>
        </div>
        <div class="card">
            <p class="text-xs font-semibold text-muted">Tugas selesai</p>
            <p class="text-xl font-extrabold">{{ $summary['tasksDone'] }}</p>
            @if ($summary['tasksOverdue'])
                <p class="text-xs font-semibold text-danger">{{ $summary['tasksOverdue'] }} terlambat</p>
            @endif
        </div>
        <div class="card">
            <p class="text-xs font-semibold text-muted">Kebiasaan</p>
            <p class="text-xl font-extrabold">{{ is_null($summary['habitRate']) ? '–' : $summary['habitRate'].'%' }}</p>
        </div>
        <div class="card">
            <p class="text-xs font-semibold text-muted">Keuangan</p>
            <p class="truncate text-sm font-extrabold text-success">+{{ Transaction::formatRupiah($summary['income']) }}</p>
            <p class="truncate text-sm font-extrabold">−{{ Transaction::formatRupiah($summary['expense']) }}</p>
        </div>
    </section>

    @if ($summary['byGoal']->isNotEmpty())
        <section class="card">
            <h2 class="section-title mb-3">Fokus per target</h2>
            @foreach ($summary['byGoal'] as $row)
                <div wire:key="review-goal-{{ $row['goal']->id }}" class="mb-3 last:mb-0">
                    <div class="mb-1 flex items-center justify-between gap-2 text-sm">
                        <span class="flex min-w-0 items-center gap-2"><span class="size-2.5 shrink-0 rounded-full" style="background-color: {{ $row['goal']->color }}"></span><span class="truncate font-semibold">{{ $row['goal']->label() }}</span></span>
                        <span class="shrink-0 font-bold">{{ StudySession::formatMinutes($row['minutes']) }}</span>
                    </div>
                    <x-progress :value="round($row['minutes'] / max(1, $summary['byGoal']->first()['minutes']) * 100)" :color="$row['goal']->color" />
                </div>
            @endforeach
        </section>
    @endif

    {{-- Refleksi --}}
    <section class="card">
        <h2 class="section-title">Refleksi</h2>
        <p class="mb-4 text-sm text-muted">Tiga pertanyaan singkat. Cukup 2–3 menit.</p>
        <form wire:submit="save" class="space-y-4">
            <div>
                <span class="label">Bagaimana minggu ini secara keseluruhan?</span>
                <div class="grid grid-cols-5 gap-2">
                    @foreach (WeeklyReview::RATINGS as $value => $emoji)
                        <button type="button" wire:click="$set('rating', {{ $value }})" aria-label="Nilai {{ $value }} dari 5" aria-pressed="{{ (int) $rating === $value ? 'true' : 'false' }}"
                            @class([
                                'rounded-2xl py-2.5 text-2xl transition active:scale-95',
                                'bg-brand-soft ring-2 ring-brand' => (int) $rating === $value,
                                'bg-surface-2 opacity-70 grayscale hover:opacity-100 hover:grayscale-0' => (int) $rating !== $value,
                            ])>{{ $emoji }}</button>
                    @endforeach
                </div>
            </div>
            <div>
                <label class="label" for="went_well">✅ Apa yang berjalan baik?</label>
                <textarea id="went_well" wire:model="went_well" rows="3" class="input" placeholder="mis. Konsisten latihan Listening 4 hari"></textarea>
                @error('went_well') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="to_improve">🔧 Apa yang perlu diperbaiki?</label>
                <textarea id="to_improve" wire:model="to_improve" rows="3" class="input" placeholder="mis. Sering menunda sesi malam"></textarea>
                @error('to_improve') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="next_focus">🎯 Fokus utama minggu depan</label>
                <textarea id="next_focus" wire:model="next_focus" rows="2" class="input" placeholder="mis. Selesaikan draft Bab 1 proposal"></textarea>
                @error('next_focus') <p class="error">{{ $message }}</p> @enderror
            </div>
            <button class="btn btn-primary w-full" wire:loading.attr="disabled" wire:target="save">Simpan review</button>
        </form>
    </section>

    @if ($this->pastReviews->isNotEmpty())
        <section>
            <h2 class="section-title mb-2">Review sebelumnya</h2>
            <div class="card divide-y divide-line p-0">
                @foreach ($this->pastReviews as $review)
                    <button type="button" wire:key="past-{{ $review->id }}" wire:click="openWeek('{{ $review->week_start->toDateString() }}')" class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-surface-2/60">
                        <span class="text-2xl">{{ WeeklyReview::RATINGS[$review->rating] ?? '📝' }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-bold">{{ $review->week_start->translatedFormat('d M') }} – {{ $review->week_start->copy()->addDays(6)->translatedFormat('d M Y') }}</span>
                            <span class="block truncate text-xs text-muted">{{ $review->next_focus ?: ($review->went_well ?: 'Tanpa catatan') }}</span>
                        </span>
                        <x-icon name="chevron-right" class="size-4 text-muted" />
                    </button>
                @endforeach
            </div>
        </section>
    @endif
</div>
