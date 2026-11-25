<?php

use App\Models\Habit;
use App\Models\ScheduleSlot;
use App\Models\StudySession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Kalender')] class extends Component
{
    /** Bulan yang ditampilkan, format Y-m. */
    #[Url]
    public string $month = '';

    /** Tanggal yang dipilih, format Y-m-d. */
    #[Url(as: 'tanggal', except: '')]
    public string $selected = '';

    public function mount(): void
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month)) {
            $this->month = today()->format('Y-m');
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->selected)) {
            $this->selected = $this->period()->isSameMonth(today()) ? today()->toDateString() : $this->period()->toDateString();
        }
    }

    public function period(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $this->month);
    }

    public function selectedDate(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->selected)->startOfDay();
    }

    public function shiftMonth(int $delta): void
    {
        $this->month = $this->period()->addMonthsNoOverflow($delta)->format('Y-m');
        $this->selected = $this->period()->isSameMonth(today()) ? today()->toDateString() : $this->period()->toDateString();
    }

    public function goToToday(): void
    {
        $this->month = today()->format('Y-m');
        $this->selected = today()->toDateString();
    }

    public function selectDate(string $date): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->selected = $date;
            $this->month = substr($date, 0, 7);
        }
    }

    public function toggleTask(int $taskId): void
    {
        $task = auth()->user()->tasks()->findOrFail($taskId);
        $next = $task->toggle();

        if ($next) {
            $this->dispatch('toast', message: 'Beres! Muncul lagi '.$next->due_date->translatedFormat('D, d M').' 🔁');
        }
    }

    /**
     * Grid kalender (Senin–Minggu) beserta ringkasan tiap hari.
     *
     * @return array<int, array<int, array{date: CarbonImmutable, inMonth: bool, focus: int, deadlines: Collection, tasks: int, slots: int}>>
     */
    #[Computed]
    public function weeks(): array
    {
        $user = auth()->user();
        $start = $this->period()->startOfWeek(1);
        $end = $this->period()->endOfMonth()->endOfWeek(0)->startOfDay();

        $focus = $user->studySessions()
            ->whereDate('studied_on', '>=', $start)->whereDate('studied_on', '<=', $end)
            ->get(['studied_on', 'minutes'])
            ->groupBy(fn (StudySession $session) => $session->studied_on->toDateString())
            ->map(fn (Collection $sessions) => (int) $sessions->sum('minutes'));

        $deadlines = $user->goals()
            ->where('status', '!=', 'done')
            ->whereDate('deadline', '>=', $start)->whereDate('deadline', '<=', $end)
            ->get()
            ->groupBy(fn ($goal) => $goal->deadline->toDateString());

        $tasks = $user->tasks()
            ->where('is_done', false)
            ->whereDate('due_date', '>=', $start)->whereDate('due_date', '<=', $end)
            ->get(['due_date'])
            ->countBy(fn ($task) => $task->due_date->toDateString());

        $slotsPerDay = $user->scheduleSlots()->get(['day_of_week'])->countBy('day_of_week');

        $weeks = [];
        for ($weekStart = $start; $weekStart->lte($end); $weekStart = $weekStart->addWeek()) {
            $days = [];
            for ($offset = 0; $offset < 7; $offset++) {
                $date = $weekStart->addDays($offset);
                $key = $date->toDateString();
                $days[] = [
                    'date' => $date,
                    'inMonth' => $date->isSameMonth($this->period()),
                    'focus' => $focus->get($key, 0),
                    'deadlines' => $deadlines->get($key, collect()),
                    'tasks' => $tasks->get($key, 0),
                    'slots' => $slotsPerDay->get($date->dayOfWeekIso, 0),
                ];
            }
            $weeks[] = $days;
        }

        return $weeks;
    }

    /**
     * Isi lengkap tanggal yang dipilih.
     *
     * @return array{deadlines: Collection, tasks: Collection, slots: Collection, sessions: Collection, habits: Collection, income: int, expense: int}
     */
    #[Computed]
    public function detail(): array
    {
        $user = auth()->user();
        $date = $this->selectedDate();
        $transactions = $user->transactions()->whereDate('occurred_on', $date)->get(['type', 'amount']);

        return [
            'deadlines' => $user->goals()->whereDate('deadline', $date)->get(),
            'tasks' => $user->tasks()->with('goal')->whereDate('due_date', $date)->orderBy('is_done')->orderByRaw('due_time is null')->orderBy('due_time')->get(),
            'slots' => $user->scheduleSlots()->with('goal')->where('day_of_week', $date->dayOfWeekIso)->orderBy('start_time')->get(),
            'sessions' => $user->studySessions()->with(['goal', 'topic'])->whereDate('studied_on', $date)->get(),
            'habits' => $user->habits()
                ->withExists(['logs as done' => fn ($query) => $query->whereDate('logged_on', $date)])
                ->get()
                ->filter(fn (Habit $habit) => $habit->isScheduledOn($date)),
            'income' => (int) $transactions->where('type', 'income')->sum('amount'),
            'expense' => (int) $transactions->where('type', 'expense')->sum('amount'),
        ];
    }
};
?>

@php
    $weeks = $this->weeks;
    $maxFocus = max(60, collect($weeks)->flatten(1)->max('focus'));
    $detail = $this->detail;
    $selectedDate = $this->selectedDate();
@endphp

<div class="space-y-5">
    <x-page-header title="Kalender" subtitle="Deadline, tugas, jadwal, dan fokus dalam satu bulan." icon="calendar-grid">
        <x-slot:actions>
            <div class="flex items-center gap-0.5 rounded-2xl border border-line bg-surface p-1">
                <button class="rounded-xl p-1.5 text-muted hover:bg-surface-2" wire:click="shiftMonth(-1)" aria-label="Bulan sebelumnya"><x-icon name="chevron-left" class="size-4" /></button>
                <button class="min-w-24 rounded-xl px-1 text-center text-sm font-bold hover:bg-surface-2" wire:click="goToToday" title="Kembali ke hari ini">{{ $this->period()->translatedFormat('M Y') }}</button>
                <button class="rounded-xl p-1.5 text-muted hover:bg-surface-2" wire:click="shiftMonth(1)" aria-label="Bulan berikutnya"><x-icon name="chevron-right" class="size-4" /></button>
            </div>
        </x-slot:actions>
    </x-page-header>

    <section class="card p-3 sm:p-4">
        <div class="mb-2 grid grid-cols-7 text-center text-[11px] font-bold tracking-wide text-muted uppercase">
            @foreach (ScheduleSlot::DAYS as $dayName)
                <span>{{ mb_substr($dayName, 0, 3) }}</span>
            @endforeach
        </div>
        <div class="grid grid-cols-7 gap-1">
            @foreach ($weeks as $week)
                @foreach ($week as $day)
                    @php
                        $isSelected = $day['date']->isSameDay($selectedDate);
                        $intensity = $day['focus'] ? max(12, (int) round($day['focus'] / $maxFocus * 45)) : 0;
                    @endphp
                    <button type="button" wire:key="day-{{ $day['date']->toDateString() }}" wire:click="selectDate('{{ $day['date']->toDateString() }}')"
                        aria-label="{{ $day['date']->translatedFormat('l, d F') }}" aria-pressed="{{ $isSelected ? 'true' : 'false' }}"
                        @class([
                            'relative flex aspect-square min-h-12 flex-col items-center justify-start gap-0.5 rounded-2xl p-1 text-sm transition sm:aspect-[4/3]',
                            'opacity-35' => !$day['inMonth'],
                            'ring-2 ring-brand' => $isSelected,
                            'hover:bg-surface-2' => !$isSelected,
                        ])
                        @if ($intensity) style="background-color: color-mix(in oklab, var(--brand) {{ $intensity }}%, transparent)" @endif>
                        <span @class([
                            'grid size-7 place-items-center rounded-full text-xs font-bold',
                            'bg-linear-to-br from-brand to-brand-2 text-on-brand' => $day['date']->isToday(),
                        ])>{{ $day['date']->day }}</span>
                        <span class="flex flex-wrap items-center justify-center gap-0.5">
                            @foreach ($day['deadlines']->take(2) as $goal)
                                <span class="size-1.5 rounded-full ring-1 ring-surface" style="background-color: {{ $goal->color }}" title="Deadline {{ $goal->name }}"></span>
                            @endforeach
                            @if ($day['tasks'])
                                <span class="rounded-full bg-ink/80 px-1 text-[9px] leading-3 font-bold text-bg">{{ $day['tasks'] }}</span>
                            @endif
                        </span>
                    </button>
                @endforeach
            @endforeach
        </div>
        <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
            <span class="flex items-center gap-1.5"><span class="size-3 rounded" style="background-color: color-mix(in oklab, var(--brand) 35%, transparent)"></span> Waktu fokus</span>
            <span class="flex items-center gap-1.5"><span class="size-1.5 rounded-full bg-brand"></span> Deadline target</span>
            <span class="flex items-center gap-1.5"><span class="rounded-full bg-ink/80 px-1 text-[9px] font-bold text-bg">2</span> Tugas belum selesai</span>
        </div>
    </section>

    {{-- Detail tanggal terpilih --}}
    <section class="space-y-3">
        <div class="flex items-end justify-between gap-2">
            <div>
                @php $offset = (int) today()->diffInDays($selectedDate, false); @endphp
                <p class="text-xs font-bold tracking-wider text-brand uppercase">
                    {{ match (true) { $offset === 0 => 'Hari ini', $offset === 1 => 'Besok', $offset === -1 => 'Kemarin', $offset > 1 => $offset.' hari lagi', default => abs($offset).' hari lalu' } }}
                </p>
                <h2 class="text-xl font-extrabold tracking-tight">{{ $selectedDate->translatedFormat('l, d F Y') }}</h2>
            </div>
            @if ($selectedDate->lte(today()))
                <a href="{{ route('study') }}" wire:navigate class="link">+ Catat fokus</a>
            @endif
        </div>

        @foreach ($detail['deadlines'] as $goal)
            <a href="{{ route('goals.show', $goal) }}" wire:navigate wire:key="detail-goal-{{ $goal->id }}" class="card card-hover flex items-center gap-3 py-3" style="border-color: {{ $goal->color }}">
                <span class="icon-tile" style="background-color: color-mix(in oklab, {{ $goal->color }} 18%, transparent); color: {{ $goal->color }}"><x-icon name="flag" /></span>
                <span><span class="block text-xs font-bold text-muted">Deadline target</span><span class="block font-bold">{{ $goal->label() }}</span></span>
            </a>
        @endforeach

        <div class="grid gap-3 sm:grid-cols-2">
            <div class="card">
                <h3 class="mb-2 flex items-center gap-2 text-sm font-bold"><x-icon name="check" class="size-4 text-brand" /> Tugas</h3>
                @forelse ($detail['tasks'] as $task)
                    <label wire:key="detail-task-{{ $task->id }}" class="flex cursor-pointer items-center gap-2 py-1.5">
                        <input type="checkbox" class="size-4 shrink-0 accent-brand" wire:click="toggleTask({{ $task->id }})" @checked($task->is_done)>
                        <span @class(['min-w-0 flex-1 truncate text-sm', 'text-muted line-through' => $task->is_done])>{{ $task->due_time ? $task->due_time.' · ' : '' }}{{ $task->title }}</span>
                    </label>
                @empty
                    <p class="text-sm text-muted">Tidak ada tugas.</p>
                @endforelse
            </div>

            <div class="card">
                <h3 class="mb-2 flex items-center gap-2 text-sm font-bold"><x-icon name="calendar" class="size-4 text-brand" /> Jadwal fokus</h3>
                @forelse ($detail['slots'] as $slot)
                    <div wire:key="detail-slot-{{ $slot->id }}" class="flex items-center gap-2 py-1.5 text-sm">
                        <span class="h-5 w-1 rounded-full" style="background-color: {{ $slot->goal->color }}"></span>
                        <span class="font-semibold tabular-nums">{{ $slot->start_time }}–{{ $slot->end_time }}</span>
                        <span class="truncate text-muted">{{ $slot->goal->label() }}</span>
                    </div>
                @empty
                    <p class="text-sm text-muted">Tidak ada jadwal rutin.</p>
                @endforelse
            </div>

            <div class="card">
                <h3 class="mb-2 flex items-center gap-2 text-sm font-bold"><x-icon name="timer" class="size-4 text-brand" /> Sesi fokus <span class="ml-auto text-muted">{{ StudySession::formatMinutes((int) $detail['sessions']->sum('minutes')) }}</span></h3>
                @forelse ($detail['sessions'] as $session)
                    <div wire:key="detail-session-{{ $session->id }}" class="flex items-center gap-2 py-1.5 text-sm">
                        <span class="size-2 rounded-full" style="background-color: {{ $session->goal->color }}"></span>
                        <span class="min-w-0 flex-1 truncate">{{ $session->goal->label() }}{{ $session->topic ? ' · '.$session->topic->name : '' }}</span>
                        <span class="font-semibold">{{ StudySession::formatMinutes($session->minutes) }}</span>
                    </div>
                @empty
                    <p class="text-sm text-muted">Belum ada sesi.</p>
                @endforelse
            </div>

            <div class="card">
                <h3 class="mb-2 flex items-center gap-2 text-sm font-bold"><x-icon name="repeat" class="size-4 text-brand" /> Kebiasaan</h3>
                @forelse ($detail['habits'] as $habit)
                    <div wire:key="detail-habit-{{ $habit->id }}" class="flex items-center gap-2 py-1 text-sm">
                        <span @class(['font-bold', 'text-success' => $habit->done, 'text-muted' => !$habit->done])>{{ $habit->done ? '✓' : '○' }}</span>
                        <span class="truncate">{{ $habit->label() }}</span>
                    </div>
                @empty
                    <p class="text-sm text-muted">Tidak ada kebiasaan terjadwal.</p>
                @endforelse
                @if ($detail['income'] || $detail['expense'])
                    <p class="mt-3 border-t border-line pt-2 text-xs text-muted">
                        💰 Masuk <span class="font-bold text-success">{{ \App\Models\Transaction::formatRupiah($detail['income']) }}</span>
                        · Keluar <span class="font-bold text-ink">{{ \App\Models\Transaction::formatRupiah($detail['expense']) }}</span>
                    </p>
                @endif
            </div>
        </div>
    </section>
</div>
