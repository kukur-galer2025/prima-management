<?php

use App\Models\Habit;
use App\Models\JournalEntry;
use App\Models\SavingsGoal;
use App\Models\ScheduleSlot;
use App\Models\StudySession;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\BudgetMonitor;
use App\Services\QuickTaskParser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Beranda')] class extends Component
{
    public string $quickTask = '';

    /** @return array{title: string, due_date: ?string, due_time: ?string, priority: ?int, recurrence: ?string, goal_id: ?int, goal_label: ?string, detected: bool}|null */
    #[Computed]
    public function quickPreview(): ?array
    {
        if (blank($this->quickTask)) {
            return null;
        }

        return app(QuickTaskParser::class)->parse($this->quickTask, auth()->user()->goals()->where('status', '!=', 'done')->get(), now());
    }

    public function addQuickTask(): void
    {
        $this->validate(['quickTask' => ['required', 'string', 'max:300']], [], ['quickTask' => 'tugas']);

        $parsed = $this->quickPreview;
        $task = auth()->user()->tasks()->create([
            'title' => Str::limit($parsed['title'], 200, ''),
            'due_date' => $parsed['due_date'],
            'due_time' => $parsed['due_time'],
            'priority' => $parsed['priority'] ?? 2,
            'recurrence' => $parsed['recurrence'],
            'goal_id' => $parsed['goal_id'],
        ]);

        $this->reset('quickTask');
        unset($this->upcomingTasks, $this->tasksDueToday);
        $this->dispatch('toast', message: 'Ditambahkan: '.$task->title.($task->due_date ? ' · '.$task->dueLabel('D, d M') : ''));
    }

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
            ['label' => 'Tambah dompet', 'hint' => 'Tunai, rekening, atau e-wallet + saldo awal', 'route' => 'finance', 'done' => $user->wallets()->exists()],
            ['label' => 'Tentukan target mingguan', 'hint' => 'Berapa jam fokus per minggu', 'route' => 'account', 'done' => (bool) $user->weekly_target_minutes],
            ['label' => 'Aktifkan pengingat', 'hint' => 'Notifikasi di HP atau sambungkan Telegram', 'route' => 'account', 'done' => $user->hasTelegram() || $user->pushSubscriptions()->exists()],
        ];
    }

    /**
     * Ringkasan uang untuk Beranda: saldo dompet, anggaran, utang/piutang yang segera jatuh tempo, dan tabungan.
     *
     * @return array{hasData: bool, wallets: int, balance: int, expense: int, budgets: Collection, debts: Collection, savings: Collection}
     */
    #[Computed]
    public function money(): array
    {
        $user = auth()->user();
        $balances = Wallet::balancesFor($user);

        $debts = $user->debts()
            ->whereNull('settled_at')
            ->whereNotNull('due_on')
            ->whereDate('due_on', '<=', today()->addDays(7))
            ->withSum('payments', 'amount')
            ->orderBy('due_on')
            ->limit(3)
            ->get();

        $savings = $user->savingsGoals()
            ->withSum('deposits', 'amount')
            ->orderByRaw('deadline is null')
            ->orderBy('deadline')
            ->get()
            ->reject(fn (SavingsGoal $goal) => $goal->isReached())
            ->take(2)
            ->values();

        $budgets = app(BudgetMonitor::class)->usage($user);

        return [
            'hasData' => $balances->isNotEmpty() || $budgets->isNotEmpty() || $debts->isNotEmpty() || $savings->isNotEmpty(),
            'wallets' => $balances->count(),
            'balance' => (int) $balances->sum(),
            'expense' => (int) $user->transactions()->where('type', 'expense')->whereDate('occurred_on', '>=', today()->startOfMonth())->sum('amount'),
            'budgets' => $budgets,
            'debts' => $debts,
            'savings' => $savings,
        ];
    }

    #[Computed]
    public function journalToday(): ?JournalEntry
    {
        return auth()->user()->journalEntries()->whereDate('entry_on', today())->first();
    }

    public function logMood(int $mood): void
    {
        if ($mood < 1 || $mood > 5) {
            return;
        }

        $entry = $this->journalToday;
        $entry ? $entry->update(['mood' => $mood]) : auth()->user()->journalEntries()->create(['entry_on' => today()->toDateString(), 'mood' => $mood]);

        unset($this->journalToday);
        $this->dispatch('toast', message: 'Mood hari ini tercatat '.JournalEntry::MOODS[$mood]['emoji']);
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
            ->withCount(['items', 'items as items_done_count' => fn ($query) => $query->where('is_done', true)])
            ->where('is_done', false)
            // Yang sedang dikerjakan selalu di atas.
            ->orderByRaw('started_at is null')
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
    $money = $this->money;
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

    {{-- Tambah tugas cepat dengan bahasa sehari-hari --}}
    <form wire:submit="addQuickTask" class="card p-3">
        <div class="flex gap-2">
            <label class="relative min-w-0 flex-1">
                <x-icon name="bolt" class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-brand" />
                <input wire:model.live.debounce.400ms="quickTask" class="input pl-10" placeholder="Tambah cepat: kirim CV jumat jam 9 !penting" aria-label="Tambah tugas cepat" autocomplete="off">
            </label>
            <button class="btn btn-primary shrink-0 px-3.5" aria-label="Tambahkan tugas"><x-icon name="plus" class="size-5" /></button>
        </div>
        @error('quickTask') <p class="error px-1">{{ $message }}</p> @enderror
        @if (($preview = $this->quickPreview) && $preview['detected'])
            <div class="mt-2 flex flex-wrap items-center gap-1.5 px-1 text-xs" aria-live="polite">
                <span class="chip bg-surface-2 text-ink">“{{ $preview['title'] }}”</span>
                @if ($preview['due_date'])
                    <span class="chip bg-brand-soft text-brand-ink">📅 {{ Carbon::parse($preview['due_date'])->translatedFormat('D, d M') }}{{ $preview['due_time'] ? ' · '.$preview['due_time'] : '' }}</span>
                @endif
                @if ($preview['priority'])
                    <span class="chip bg-danger/10 text-danger">{{ $preview['priority'] === 1 ? '❗ Penting' : '↓ Rendah' }}</span>
                @endif
                @if ($preview['recurrence'])
                    <span class="chip bg-surface-2 text-ink">🔁 {{ Task::RECURRENCES[$preview['recurrence']] }}</span>
                @endif
                @if ($preview['goal_label'])
                    <span class="chip bg-surface-2 text-ink">🎯 {{ $preview['goal_label'] }}</span>
                @endif
            </div>
        @endif
    </form>

    {{-- Panduan awal --}}
    @if ($showOnboarding)
        <section class="card border-brand/30">
            <div class="mb-3 flex items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-bold tracking-wider text-brand uppercase">Mulai di sini</p>
                    @if ($onboardingDone === 0)
                        <h2 class="section-title">Selamat datang di Prima Management! 🎉</h2>
                        <p class="mt-0.5 text-sm text-muted">Akunmu sudah siap. Ikuti langkah singkat ini supaya Beranda langsung berguna. Semua bisa diubah kapan saja.</p>
                    @else
                        <h2 class="section-title">Siapkan Prima Management-mu ({{ $onboardingDone }}/{{ count($onboarding) }})</h2>
                    @endif
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

    {{-- Peringatan anggaran (≥ 80%) --}}
    @foreach ($money['budgets']->filter(fn ($row) => BudgetMonitor::levelFor($row['percent'])) as $row)
        <a href="{{ route('finance') }}" wire:navigate wire:key="budget-alert-{{ md5($row['key']) }}" @class([
            'card card-hover flex items-center gap-3 py-3',
            'border-danger/40 bg-danger/5' => $row['percent'] >= 100,
            'border-warning/40 bg-warning/5' => $row['percent'] < 100,
        ])>
            <span class="text-2xl">{{ $row['percent'] >= 100 ? '🚨' : '⚠️' }}</span>
            <span class="min-w-0 flex-1">
                <span class="block text-sm font-bold">{{ $row['label'] }} {{ $row['percent'] >= 100 ? 'terlampaui' : 'hampir habis' }} · {{ $row['percent'] }}%</span>
                <span class="block text-xs text-muted">{{ Transaction::formatRupiah($row['spent']) }} dari {{ Transaction::formatRupiah($row['budget']) }} bulan ini</span>
            </span>
            <x-icon name="chevron-right" class="size-4 text-muted" />
        </a>
    @endforeach

    {{-- Mood hari ini (sore/malam, bila jurnal belum diisi) --}}
    @if (! $this->journalToday && now()->hour >= 15)
        <section class="card flex flex-wrap items-center gap-3">
            <div class="min-w-0 flex-1 basis-48">
                <h2 class="section-title">Bagaimana harimu?</h2>
                <p class="text-xs text-muted">Satu ketukan untuk jurnal mood. <a href="{{ route('journal') }}" wire:navigate class="link">Tulis catatan →</a></p>
            </div>
            <div class="flex gap-1">
                @foreach (JournalEntry::MOODS as $value => $meta)
                    <button wire:click="logMood({{ $value }})" class="grid size-11 place-items-center rounded-2xl bg-surface-2 text-2xl transition hover:scale-110 hover:bg-brand-soft" title="{{ $meta['label'] }}" aria-label="Mood {{ $meta['label'] }}">{{ $meta['emoji'] }}</button>
                @endforeach
            </div>
        </section>
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

    {{-- Uang --}}
    @if ($money['hasData'])
        <section>
            <div class="mb-2 flex items-center justify-between">
                <h2 class="section-title">Uang</h2>
                <a href="{{ route('finance') }}" wire:navigate class="link">Keuangan</a>
            </div>
            <div class="grid gap-2 sm:grid-cols-2">
                <a href="{{ route('finance') }}" wire:navigate class="card card-hover block py-3">
                    @if ($money['wallets'])
                        <p class="text-xs font-semibold text-muted">Saldo {{ $money['wallets'] }} dompet</p>
                        <p @class(['text-xl font-extrabold', 'text-danger' => $money['balance'] < 0])>{{ Transaction::formatRupiah($money['balance']) }}</p>
                    @else
                        <p class="text-xs font-semibold text-muted">Belum ada dompet</p>
                        <p class="text-sm font-bold text-brand">Tambah dompet →</p>
                    @endif
                    @php $total = $money['budgets']->firstWhere('key', 'total'); @endphp
                    <p class="mt-1 text-xs text-muted">Keluar bulan ini {{ Transaction::formatRupiah($money['expense']) }}{{ $total ? ' · '.$total['percent'].'% anggaran' : '' }}</p>
                    @if ($total)
                        <x-progress class="mt-2" :value="$total['percent']" :color="$total['percent'] >= 100 ? 'var(--danger)' : null" />
                    @endif
                </a>

                @foreach ($money['debts'] as $debt)
                    <a href="{{ route('debts', ['tab' => $debt->direction]) }}" wire:navigate wire:key="dash-debt-{{ $debt->id }}" @class(['card card-hover flex items-center gap-3 py-3', 'border-danger/40' => $debt->isOverdue()])>
                        <span class="text-2xl">{{ $debt->isPayable() ? '🤝' : '📒' }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-bold">{{ $debt->isPayable() ? 'Bayar ke' : 'Tagih' }} {{ $debt->person }} · {{ Transaction::formatRupiah($debt->remainingAmount()) }}</span>
                            <span @class(['block text-xs', 'font-semibold text-danger' => $debt->isOverdue(), 'text-muted' => ! $debt->isOverdue()])>
                                {{ $debt->isOverdue() ? 'Lewat tempo '.$debt->due_on->translatedFormat('d M') : ($debt->due_on->isToday() ? 'Jatuh tempo hari ini' : 'Jatuh tempo '.$debt->due_on->translatedFormat('D, d M')) }}
                            </span>
                        </span>
                    </a>
                @endforeach

                @foreach ($money['savings'] as $saving)
                    <a href="{{ route('savings') }}" wire:navigate wire:key="dash-saving-{{ $saving->id }}" class="card card-hover block py-3">
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <p class="truncate text-sm font-bold">{{ $saving->emoji ?: '💰' }} {{ $saving->name }}</p>
                            <span class="text-sm font-extrabold text-brand">{{ $saving->percent() }}%</span>
                        </div>
                        <x-progress :value="$saving->percent()" />
                        <p class="mt-1.5 text-xs text-muted">
                            {{ Transaction::formatRupiah($saving->savedAmount()) }} / {{ Transaction::formatRupiah($saving->target_amount) }}
                            @if ($monthly = $saving->monthlyNeeded()) · {{ Transaction::formatRupiah($monthly) }}/bln @endif
                        </p>
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
                            @if ($task->started_at)
                                <span class="font-semibold text-brand">● Dikerjakan</span>
                            @endif
                            @if ($task->items_count)
                                <span @class(['font-semibold', 'text-success' => $task->items_done_count === $task->items_count])>☑ {{ $task->items_done_count }}/{{ $task->items_count }}</span>
                            @endif
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
