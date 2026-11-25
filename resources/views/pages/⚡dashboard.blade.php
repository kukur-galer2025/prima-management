<?php

use App\Models\Habit;
use App\Models\ScheduleSlot;
use App\Models\StudySession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Beranda')] class extends Component
{
    public function toggleTask(int $taskId): void
    {
        $task = auth()->user()->tasks()->findOrFail($taskId);
        $next = $task->toggle();

        $this->dispatch('toast', message: match (true) {
            $next !== null => 'Beres! Muncul lagi '.$next->due_date->translatedFormat('D, d M').' 🔁',
            $task->is_done => 'Tugas selesai 🎉',
            default => 'Tugas dibuka lagi',
        });
    }

    public function toggleHabit(int $habitId): void
    {
        $habit = auth()->user()->habits()->findOrFail($habitId);
        $log = $habit->logs()->whereDate('logged_on', today())->first();

        if ($log) {
            $log->delete();
        } else {
            $habit->logs()->create(['logged_on' => today()->toDateString()]);
            $this->dispatch('toast', message: 'Kebiasaan tercentang ✅');
        }
    }

    public function dismissOnboarding(): void
    {
        auth()->user()->forceFill(['onboarding_dismissed_at' => now()])->save();
    }

    /**
     * Langkah awal untuk pengguna baru; tiap langkah tercentang otomatis dari datanya.
     *
     * @return array<int, array{label: string, hint: string, route: string, done: bool}>
     */
    #[Computed]
    public function onboarding(): array
    {
        $user = auth()->user();

        return [
            ['label' => 'Buat target pertama', 'hint' => 'mis. TOEFL 550 atau Proposal S2', 'route' => 'goals.index', 'done' => $user->goals()->exists()],
            ['label' => 'Atur jadwal fokus mingguan', 'hint' => 'Pola rutin kapan kamu mengerjakannya', 'route' => 'schedule', 'done' => $user->scheduleSlots()->exists()],
            ['label' => 'Catat sesi fokus pertama', 'hint' => 'Pakai stopwatch atau Pomodoro', 'route' => 'study', 'done' => $user->studySessions()->exists()],
            ['label' => 'Tambah satu kebiasaan', 'hint' => 'Kecil saja, yang penting konsisten', 'route' => 'habits', 'done' => $user->habits()->exists()],
            ['label' => 'Tentukan target mingguan', 'hint' => 'Berapa jam fokus per minggu', 'route' => 'account', 'done' => (bool) $user->weekly_target_minutes],
            ['label' => 'Aktifkan notifikasi', 'hint' => 'Pengingat jadwal & ringkasan pagi', 'route' => 'account', 'done' => $user->pushSubscriptions()->exists()],
        ];
    }

    #[Computed]
    public function goals(): Collection
    {
        return auth()->user()->goals()
            ->where('status', 'active')
            ->withStats()
            ->orderBy('priority')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function todaySlots(): Collection
    {
        return auth()->user()->scheduleSlots()
            ->with('goal')
            ->where('day_of_week', today()->dayOfWeekIso)
            ->orderBy('start_time')
            ->get();
    }

    #[Computed]
    public function habitsToday(): Collection
    {
        return auth()->user()->habits()
            ->withExists(['logs as done_today' => fn ($query) => $query->whereDate('logged_on', today())])
            ->orderBy('name')
            ->get()
            ->filter(fn (Habit $habit) => $habit->isScheduledOn(today()))
            ->values();
    }

    /**
     * @return array{today: int, week: int, streak: int, days: array<int, array{label: string, date: string, minutes: int, isToday: bool}>}
     */
    #[Computed]
    public function stats(): array
    {
        $minutesByDate = auth()->user()->studySessions()
            ->where('studied_on', '>=', today()->subDays(400))
            ->get(['studied_on', 'minutes'])
            ->groupBy(fn (StudySession $session) => $session->studied_on->toDateString())
            ->map(fn (Collection $sessions) => (int) $sessions->sum('minutes'));

        $days = [];
        foreach (range(6, 0) as $offset) {
            $date = today()->subDays($offset);
            $days[] = [
                'label' => $date->translatedFormat('D'),
                'date' => $date->translatedFormat('d M'),
                'minutes' => $minutesByDate->get($date->toDateString(), 0),
                'isToday' => $offset === 0,
            ];
        }

        $streak = 0;
        $cursor = today();
        if (! $minutesByDate->has($cursor->toDateString())) {
            $cursor = $cursor->subDay();
        }
        while ($minutesByDate->has($cursor->toDateString())) {
            $streak++;
            $cursor = $cursor->subDay();
        }

        return [
            'today' => $minutesByDate->get(today()->toDateString(), 0),
            'week' => array_sum(array_column($days, 'minutes')),
            'streak' => $streak,
            'days' => $days,
        ];
    }

    /** @return array{minutes: int, target: ?int, percent: ?int, adherence: ?int} */
    #[Computed]
    public function weekly(): array
    {
        $user = auth()->user();
        $minutes = $user->focusMinutesThisWeek();
        $target = $user->weekly_target_minutes;

        return [
            'minutes' => $minutes,
            'target' => $target,
            'percent' => $target ? (int) min(100, round($minutes / $target * 100)) : null,
            'adherence' => ScheduleSlot::weeklyAdherence($user)['percent'],
        ];
    }

    /** Target yang deadline-nya sudah lewat ditampilkan paling atas, lalu yang terdekat. */
    #[Computed]
    public function upcomingDeadlines(): Collection
    {
        return auth()->user()->goals()
            ->whereIn('status', ['active', 'paused'])
            ->whereNotNull('deadline')
            ->orderBy('deadline')
            ->limit(4)
            ->get();
    }

    #[Computed]
    public function upcomingTasks(): Collection
    {
        return auth()->user()->tasks()
            ->with('goal')
            ->where('is_done', false)
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->orderByRaw('due_time is null')
            ->orderBy('due_time')
            ->orderBy('priority')
            ->limit(6)
            ->get();
    }

    #[Computed]
    public function tasksDueToday(): int
    {
        return auth()->user()->tasks()->where('is_done', false)->whereDate('due_date', '<=', today())->count();
    }

    /** Ajak menulis review mulai Jumat bila minggu ini belum direview. */
    #[Computed]
    public function reviewDue(): bool
    {
        return today()->dayOfWeekIso >= 5
            && ! auth()->user()->weeklyReviews()->whereDate('week_start', today()->startOfWeek(1))->exists();
    }

    public function greeting(): string
    {
        $hour = Carbon::now()->hour;

        return match (true) {
            $hour < 11 => 'Selamat pagi',
            $hour < 15 => 'Selamat siang',
            $hour < 19 => 'Selamat sore',
            default => 'Selamat malam',
        };
    }
};
?>

@php
    $stats = $this->stats;
    $weekly = $this->weekly;
    $maxDay = max(60, max(array_column($stats['days'], 'minutes')));
    $onboarding = $this->onboarding;
    $onboardingDone = collect($onboarding)->where('done', true)->count();
    $showOnboarding = ! auth()->user()->onboarding_dismissed_at && $onboardingDone < count($onboarding);
    $habitsDone = $this->habitsToday->where('done_today', true)->count();
@endphp

<div class="space-y-6">
    {{-- Hero --}}
    <section class="hero">
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="text-sm font-semibold opacity-80">{{ today()->translatedFormat('l, d F Y') }}</p>
                <h1 class="mt-1 text-2xl font-extrabold tracking-tight sm:text-3xl">{{ $this->greeting() }}, {{ str(auth()->user()->name)->before(' ') }} 👋</h1>
                <p class="mt-2 text-sm font-medium opacity-90">
                    @if ($stats['today'] > 0)
                        Hari ini kamu sudah fokus <span class="font-extrabold">{{ StudySession::formatMinutes($stats['today']) }}</span>. Lanjutkan!
                    @else
                        Belum ada sesi fokus hari ini. Mulai dari 25 menit saja.
                    @endif
                </p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <a href="{{ route('study') }}" wire:navigate class="btn bg-white text-brand-ink shadow-lg hover:bg-white/90 dark:text-[#3b1d8f]">
                        <x-icon name="play" class="size-4" /> Mulai fokus
                    </a>
                    <span class="chip bg-white/15 px-3 py-2 text-sm backdrop-blur">
                        <x-icon name="flame" class="size-4" /> Streak {{ $stats['streak'] }} hari
                    </span>
                </div>
            </div>
            <x-ring :value="$weekly['percent'] ?? 0" :size="92" :stroke="9" color="white" track="rgb(255 255 255 / 0.2)" class="hidden sm:inline-grid">
                <span class="block text-lg font-extrabold">{{ $weekly['percent'] ?? 0 }}%</span>
                <span class="block text-[10px] font-semibold opacity-80">target<br>minggu</span>
            </x-ring>
        </div>
    </section>

    {{-- Panduan awal --}}
    @if ($showOnboarding)
        <section class="card border-brand/30">
            <div class="mb-3 flex items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-bold tracking-wider text-brand uppercase">Mulai di sini</p>
                    <h2 class="section-title">Siapkan Prima Management-mu ({{ $onboardingDone }}/{{ count($onboarding) }})</h2>
                </div>
                <button class="rounded-xl p-1.5 text-muted hover:bg-surface-2" wire:click="dismissOnboarding" aria-label="Sembunyikan panduan"><x-icon name="x" class="size-4" /></button>
            </div>
            <x-progress :value="round($onboardingDone / count($onboarding) * 100)" class="mb-3" />
            <div class="grid gap-2 sm:grid-cols-2">
                @foreach ($onboarding as $index => $step)
                    <a href="{{ route($step['route']) }}" wire:navigate wire:key="onboarding-{{ $index }}" @class([
                        'flex items-center gap-3 rounded-2xl border p-3 transition',
                        'border-transparent bg-surface-2/60 opacity-70' => $step['done'],
                        'border-line hover:border-brand/40 hover:bg-brand-soft/40' => !$step['done'],
                    ])>
                        <span @class([
                            'grid size-7 shrink-0 place-items-center rounded-full text-xs font-extrabold',
                            'bg-success text-white' => $step['done'],
                            'bg-brand-soft text-brand-ink' => !$step['done'],
                        ])>{{ $step['done'] ? '✓' : $index + 1 }}</span>
                        <span class="min-w-0">
                            <span @class(['block text-sm font-bold', 'line-through' => $step['done']])>{{ $step['label'] }}</span>
                            <span class="block truncate text-xs text-muted">{{ $step['hint'] }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Ajakan review mingguan --}}
    @if ($this->reviewDue)
        <a href="{{ route('review') }}" wire:navigate class="card card-hover flex items-center gap-4 border-brand/30 bg-brand-soft/50">
            <span class="icon-tile size-12 bg-linear-to-br from-brand to-brand-2 text-on-brand"><x-icon name="sparkle" /></span>
            <span class="min-w-0 flex-1">
                <span class="block font-bold">Waktunya review mingguan</span>
                <span class="block text-sm text-muted">Lihat ringkasan minggu ini dan tulis refleksi singkat.</span>
            </span>
            <x-icon name="chevron-right" class="size-5 text-muted" />
        </a>
    @endif

    {{-- Ringkasan --}}
    <section class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="card">
            <span class="icon-tile mb-3 size-9"><x-icon name="timer" class="size-4" /></span>
            <p class="text-xs font-semibold text-muted">Fokus minggu ini</p>
            <p class="text-xl font-extrabold">{{ StudySession::formatMinutes($weekly['minutes']) }}</p>
            @if ($weekly['target'])
                <p class="text-xs text-muted">dari {{ StudySession::formatMinutes($weekly['target']) }}</p>
            @else
                <a href="{{ route('account') }}" wire:navigate class="text-xs font-bold text-brand">Atur target →</a>
            @endif
        </div>
        <a href="{{ route('schedule') }}" wire:navigate class="card card-hover block">
            <span class="icon-tile mb-3 size-9"><x-icon name="calendar" class="size-4" /></span>
            <p class="text-xs font-semibold text-muted">Jadwal terlaksana</p>
            <p class="text-xl font-extrabold">{{ is_null($weekly['adherence']) ? '–' : $weekly['adherence'].'%' }}</p>
            <p class="text-xs text-muted">minggu ini</p>
        </a>
        <a href="{{ route('tasks') }}" wire:navigate class="card card-hover block">
            <span class="icon-tile mb-3 size-9"><x-icon name="check" class="size-4" /></span>
            <p class="text-xs font-semibold text-muted">Tugas hari ini</p>
            <p class="text-xl font-extrabold">{{ $this->tasksDueToday }}</p>
            <p class="text-xs text-muted">termasuk yang terlambat</p>
        </a>
        <a href="{{ route('habits') }}" wire:navigate class="card card-hover block">
            <span class="icon-tile mb-3 size-9"><x-icon name="repeat" class="size-4" /></span>
            <p class="text-xs font-semibold text-muted">Kebiasaan</p>
            <p class="text-xl font-extrabold">{{ $habitsDone }}/{{ $this->habitsToday->count() }}</p>
            <p class="text-xs text-muted">hari ini</p>
        </a>
    </section>

    {{-- Grafik 7 hari --}}
    <section class="card">
        <div class="mb-4 flex items-center justify-between">
            <div>
                <h2 class="section-title">Waktu fokus 7 hari</h2>
                <p class="text-xs text-muted">Total {{ StudySession::formatMinutes($stats['week']) }}</p>
            </div>
            <a href="{{ route('stats') }}" wire:navigate class="link">Statistik →</a>
        </div>
        <div class="flex h-36 items-end gap-2">
            @foreach ($stats['days'] as $day)
                <div class="flex h-full flex-1 flex-col items-center justify-end gap-1" title="{{ $day['date'] }}: {{ StudySession::formatMinutes($day['minutes']) }}">
                    <span class="text-[10px] font-bold text-muted">{{ $day['minutes'] ? StudySession::formatMinutes($day['minutes']) : '' }}</span>
                    <div @class([
                        'w-full max-w-10 rounded-t-xl transition-all',
                        'bg-linear-to-t from-brand to-brand-2' => $day['isToday'],
                        'bg-brand/30' => !$day['isToday'],
                    ]) style="height: {{ $day['minutes'] ? max(4, round($day['minutes'] / $maxDay * 100)) : 2 }}%"></div>
                    <span @class(['text-xs', 'font-extrabold text-ink' => $day['isToday'], 'text-muted' => !$day['isToday']])>{{ $day['label'] }}</span>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Kebiasaan hari ini --}}
    @if ($this->habitsToday->isNotEmpty())
        <section>
            <div class="mb-2 flex items-center justify-between">
                <h2 class="section-title">Kebiasaan hari ini <span class="text-sm font-semibold text-muted">{{ $habitsDone }}/{{ $this->habitsToday->count() }}</span></h2>
                <a href="{{ route('habits') }}" wire:navigate class="link">Semua</a>
            </div>
            <div class="flex flex-wrap gap-2">
                @foreach ($this->habitsToday as $habit)
                    <button wire:key="habit-{{ $habit->id }}" wire:click="toggleHabit({{ $habit->id }})" @class([
                        'chip rounded-2xl px-3.5 py-2 text-sm ring-1 transition active:scale-95',
                        'text-white shadow-md ring-transparent' => $habit->done_today,
                        'bg-surface text-ink ring-line hover:ring-brand/40' => !$habit->done_today,
                    ]) @if ($habit->done_today) style="background-color: {{ $habit->color }}" @endif aria-pressed="{{ $habit->done_today ? 'true' : 'false' }}">
                        {{ $habit->done_today ? '✓' : '○' }} {{ $habit->label() }}
                    </button>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Jadwal hari ini --}}
    <section>
        <div class="mb-2 flex items-center justify-between">
            <h2 class="section-title">Jadwal hari ini</h2>
            <a href="{{ route('schedule') }}" wire:navigate class="link">Atur</a>
        </div>
        @forelse ($this->todaySlots as $slot)
            <div wire:key="slot-{{ $slot->id }}" class="card mb-2 flex items-center gap-3 py-3">
                <div class="h-11 w-1.5 rounded-full" style="background-color: {{ $slot->goal->color }}"></div>
                <div class="min-w-0 flex-1">
                    <p class="truncate font-bold">{{ $slot->goal->label() }}</p>
                    <p class="text-sm text-muted">{{ $slot->start_time }}–{{ $slot->end_time }}{{ $slot->notes ? ' · '.$slot->notes : '' }}</p>
                </div>
                <a href="{{ route('study', ['goal' => $slot->goal_id]) }}" wire:navigate class="btn btn-ghost px-3 py-2">
                    <x-icon name="play" class="size-4" /> Mulai
                </a>
            </div>
        @empty
            <div class="card text-sm text-muted">Tidak ada jadwal hari ini. <a href="{{ route('schedule') }}" wire:navigate class="link">Buat pola fokus mingguan →</a></div>
        @endforelse
    </section>

    {{-- Deadline --}}
    @if ($this->upcomingDeadlines->isNotEmpty())
        <section>
            <h2 class="section-title mb-2">Hitung mundur</h2>
            <div class="grid gap-2 sm:grid-cols-2">
                @foreach ($this->upcomingDeadlines as $goal)
                    <a href="{{ route('goals.show', $goal) }}" wire:navigate wire:key="dl-{{ $goal->id }}" @class(['card card-hover flex items-center gap-3 py-3', 'border-danger/40' => $goal->isOverdue()])>
                        @if ($goal->isOverdue())
                            <p class="text-sm font-bold text-danger">Lewat<br><span class="text-2xl font-extrabold">{{ abs($goal->daysLeft()) }}</span><span class="text-sm"> hari</span></p>
                        @else
                            <p class="text-2xl font-extrabold" style="color: {{ $goal->color }}">{{ $goal->daysLeft() }}<span class="text-sm font-semibold text-muted"> hari</span></p>
                        @endif
                        <div class="min-w-0">
                            <p class="truncate text-sm font-bold">{{ $goal->label() }}</p>
                            <p @class(['text-xs', 'text-danger' => $goal->isOverdue(), 'text-muted' => !$goal->isOverdue()])>
                                {{ $goal->isOverdue() ? 'Deadline terlewat · perbarui tanggal atau tandai selesai' : $goal->deadline->translatedFormat('d M Y') }}
                            </p>
                        </div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Progres target --}}
    <section>
        <div class="mb-2 flex items-center justify-between">
            <h2 class="section-title">Progres target</h2>
            <a href="{{ route('goals.index') }}" wire:navigate class="link">Semua</a>
        </div>
        <div class="grid gap-2 sm:grid-cols-2">
            @forelse ($this->goals as $goal)
                @php $pace = $goal->pace(); @endphp
                <a href="{{ route('goals.show', $goal) }}" wire:navigate wire:key="goal-{{ $goal->id }}" class="card card-hover block py-3">
                    <div class="mb-2 flex items-center justify-between gap-2">
                        <p class="truncate font-bold">{{ $goal->label() }}</p>
                        <span class="text-sm font-extrabold" style="color: {{ $goal->color }}">{{ $goal->progress() }}%</span>
                    </div>
                    <x-progress :value="$goal->progress()" :color="$goal->color" />
                    <p class="mt-2 text-xs text-muted">
                        {{ $goal->hoursLogged() }}{{ $goal->target_hours ? ' / '.$goal->target_hours : '' }} jam
                        · {{ $goal->topics_done_count }}/{{ $goal->topics_count }} langkah
                    </p>
                    @if ($pace)
                        <p @class(['mt-1 text-xs font-bold', 'text-success' => $pace['onTrack'], 'text-warning' => !$pace['onTrack']])>
                            {{ $pace['onTrack'] ? '✓ Sesuai jalur' : '⚠ Perlu dikejar' }} · butuh {{ StudySession::formatMinutes($pace['perDay']) }}/hari
                        </p>
                    @endif
                </a>
            @empty
                <x-empty-state emoji="🎯" title="Belum ada target aktif" class="sm:col-span-2">
                    <a href="{{ route('goals.index') }}" wire:navigate class="link">Tambah target pertamamu →</a>
                </x-empty-state>
            @endforelse
        </div>
    </section>

    {{-- Tugas --}}
    <section>
        <div class="mb-2 flex items-center justify-between">
            <h2 class="section-title">Tugas terdekat</h2>
            <a href="{{ route('tasks') }}" wire:navigate class="link">Semua</a>
        </div>
        <div class="card divide-y divide-line p-0">
            @forelse ($this->upcomingTasks as $task)
                <label wire:key="task-{{ $task->id }}" class="flex cursor-pointer items-center gap-3 px-4 py-3 hover:bg-surface-2/50">
                    <input type="checkbox" class="size-5 shrink-0 accent-brand" wire:click="toggleTask({{ $task->id }})" @checked($task->is_done)>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold">{{ $task->priority === 1 ? '❗ ' : '' }}{{ $task->title }}</span>
                        <span class="text-xs text-muted">
                            {{ $task->goal?->label() }}
                            @if ($task->due_date)
                                <span @class(['font-semibold text-danger' => $task->isOverdue()])>
                                    {{ $task->goal ? '·' : '' }} {{ $task->isOverdue() ? 'Terlambat · ' : '' }}{{ $task->dueLabel('d M') }}
                                </span>
                            @endif
                        </span>
                    </span>
                </label>
            @empty
                <p class="px-4 py-4 text-sm text-muted">Tidak ada tugas yang menunggu. 🎉</p>
            @endforelse
        </div>
    </section>
</div>
