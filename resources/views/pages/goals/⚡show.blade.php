<?php

use App\Models\Goal;
use App\Models\ScheduleSlot;
use App\Models\StudySession;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public Goal $goal;

    public string $newTopic = '';

    public string $newTask = '';

    public ?string $newTaskDue = null;

    public function mount(Goal $goal): void
    {
        abort_unless($goal->user_id === auth()->id(), 404);

        $this->goal = $goal;
    }

    public function render()
    {
        return $this->view()->title($this->goal->name);
    }

    #[Computed]
    public function topics(): Collection
    {
        return $this->goal->topics()->get();
    }

    #[Computed]
    public function tasks(): Collection
    {
        return $this->goal->tasks()->orderBy('is_done')->orderByRaw('due_date is null')->orderBy('due_date')->get();
    }

    #[Computed]
    public function sessions(): Collection
    {
        return $this->goal->sessions()->with('topic')->latest('studied_on')->latest('id')->limit(10)->get();
    }

    #[Computed]
    public function scheduleSlots(): Collection
    {
        return $this->goal->slots()->orderBy('day_of_week')->orderBy('start_time')->get();
    }

    #[Computed]
    public function goalNotes(): Collection
    {
        return $this->goal->notes()->orderByDesc('is_pinned')->latest('updated_at')->limit(5)->get();
    }

    /**
     * Titik grafik tren skor (20 skor terakhir), sudah diskalakan ke persen tinggi/lebar area grafik.
     *
     * @return array{latest: string, change: ?float, high: string, low: string, highY: float, lowY: float, points: array<int, array{x: float, y: float, score: string, date: string}>}|array{}
     */
    #[Computed]
    public function scoreTrend(): array
    {
        $sessions = $this->goal->sessions()
            ->whereNotNull('score')
            ->orderByDesc('studied_on')
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'studied_on', 'score'])
            ->reverse()
            ->values();

        if ($sessions->isEmpty()) {
            return [];
        }

        $scores = $sessions->map(fn (StudySession $session) => (float) $session->score);
        $low = $scores->min();
        $high = $scores->max();
        $padding = ($high - $low) * 0.15 ?: max(1, abs($high) * 0.05);
        $floor = $low - $padding;
        $range = ($high + $padding) - $floor;
        $toY = fn (float $score): float => round(6 + (1 - ($score - $floor) / $range) * 88, 2);
        $count = $sessions->count();

        return [
            'latest' => StudySession::formatScore($scores->last()),
            'change' => $count > 1 ? round($scores->last() - $scores->first(), 2) : null,
            'high' => StudySession::formatScore($high),
            'low' => StudySession::formatScore($low),
            'highY' => $toY($high),
            'lowY' => $toY($low),
            'points' => $sessions->values()->map(fn (StudySession $session, int $index) => [
                'x' => $count === 1 ? 50.0 : round(3 + $index / ($count - 1) * 90, 2),
                'y' => $toY((float) $session->score),
                'score' => StudySession::formatScore($session->score),
                'date' => $session->studied_on->translatedFormat('D, d M Y'),
            ])->all(),
        ];
    }

    #[Computed]
    public function summary(): Goal
    {
        return Goal::query()->withStats()->withCount('sessions')->findOrFail($this->goal->id);
    }

    public function addTopic(): void
    {
        $this->validate(['newTopic' => ['required', 'string', 'max:150']]);

        $this->goal->topics()->create([
            'name' => trim($this->newTopic),
            'sort_order' => (int) $this->goal->topics()->max('sort_order') + 1,
        ]);

        $this->reset('newTopic');
    }

    public function toggleTopic(int $topicId): void
    {
        $topic = $this->goal->topics()->findOrFail($topicId);
        $topic->update(['is_done' => ! $topic->is_done]);

        if ($topic->is_done) {
            $this->dispatch('toast', message: 'Materi selesai ✅');
        }
    }

    public function moveTopic(int $topicId, int $direction): void
    {
        $topics = $this->goal->topics()->get()->values();
        $index = $topics->search(fn ($topic) => $topic->id === $topicId);
        $swapWith = $index + $direction;

        if ($index === false || $swapWith < 0 || $swapWith >= $topics->count()) {
            return;
        }

        $ordered = $topics->all();
        [$ordered[$index], $ordered[$swapWith]] = [$ordered[$swapWith], $ordered[$index]];

        foreach ($ordered as $position => $topic) {
            $topic->update(['sort_order' => $position]);
        }
    }

    public function deleteTopic(int $topicId): void
    {
        $this->goal->topics()->findOrFail($topicId)->delete();
    }

    public function addTask(): void
    {
        $this->validate([
            'newTask' => ['required', 'string', 'max:200'],
            'newTaskDue' => ['nullable', 'date'],
        ]);

        auth()->user()->tasks()->create([
            'goal_id' => $this->goal->id,
            'title' => trim($this->newTask),
            'due_date' => $this->newTaskDue ?: null,
        ]);

        $this->reset('newTask', 'newTaskDue');
    }

    public function toggleTask(int $taskId): void
    {
        $next = $this->goal->tasks()->findOrFail($taskId)->toggle();

        if ($next) {
            $this->dispatch('toast', message: 'Diulang lagi '.$next->due_date->translatedFormat('D, d M'));
        }
    }
};
?>

@php
    $summary = $this->summary;
    $daysLeft = $summary->daysLeft();
    $pace = $summary->pace();
@endphp

<div class="space-y-5">
    <a href="{{ route('goals.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-bold text-muted hover:text-ink">
        <x-icon name="chevron-left" class="size-4" /> Semua target
    </a>

    {{-- Hero target, berwarna sesuai warna target --}}
    <section class="relative isolate overflow-hidden rounded-[1.75rem] p-5 text-white shadow-xl"
        style="background: linear-gradient(135deg, {{ $goal->color }}, color-mix(in oklab, {{ $goal->color }} 62%, #0b0820)); box-shadow: 0 20px 40px -20px {{ $goal->color }}">
        <div class="absolute -top-16 -right-12 -z-10 size-48 rounded-full bg-white/10"></div>
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
                <h1 class="text-2xl leading-tight font-extrabold tracking-tight">{{ $goal->label() }}</h1>
                <div class="mt-2 flex flex-wrap gap-1.5">
                    @if ($goal->category)
                        <span class="chip bg-white/15">{{ $goal->category }}</span>
                    @endif
                    <span class="chip bg-white/15">Prioritas {{ strtolower(Goal::PRIORITIES[$goal->priority]) }}</span>
                    @if ($goal->status !== 'active')
                        <span class="chip bg-white/15">{{ Goal::STATUSES[$goal->status] }}</span>
                    @endif
                    @if ($goal->isOverdue())
                        <span class="chip bg-white text-danger">Deadline lewat {{ abs($daysLeft) }} hari</span>
                    @endif
                </div>
            </div>
            <x-ring :value="$summary->progress()" :size="84" :stroke="8" color="white" track="rgb(255 255 255 / 0.22)">
                <span class="text-lg font-extrabold">{{ $summary->progress() }}%</span>
            </x-ring>
        </div>

        <div class="mt-5 grid grid-cols-3 gap-2 rounded-2xl bg-white/12 p-3 text-center backdrop-blur" style="background-color: rgb(255 255 255 / 0.12)">
            <div>
                <p class="text-lg font-extrabold">{{ $summary->hoursLogged() }}<span class="text-sm opacity-75">{{ $goal->target_hours ? '/'.$goal->target_hours : '' }}</span></p>
                <p class="text-xs opacity-80">jam fokus</p>
            </div>
            <div>
                <p class="text-lg font-extrabold">{{ $summary->sessions_count }}</p>
                <p class="text-xs opacity-80">sesi</p>
            </div>
            <div>
                <p class="text-lg font-extrabold">{{ is_null($daysLeft) ? '–' : ($daysLeft < 0 ? 'Lewat' : $daysLeft) }}</p>
                <p class="text-xs opacity-80">{{ $goal->deadline ? 'hari · '.$goal->deadlineLabel() : 'tanpa deadline' }}</p>
            </div>
        </div>

        <div class="mt-4 flex gap-2">
            <a href="{{ route('study', ['goal' => $goal->id]) }}" wire:navigate class="btn flex-1 bg-white text-ink shadow-lg hover:bg-white/90" style="color: {{ $goal->color }}">
                <x-icon name="play" class="size-4" /> Mulai fokus
            </a>
            <a href="{{ route('goals.index', ['edit' => $goal->id]) }}" wire:navigate class="btn bg-white/15 px-3 text-white hover:bg-white/25" aria-label="Edit target">
                <x-icon name="pencil" class="size-4" />
            </a>
        </div>
    </section>

    @if ($goal->description)
        <p class="card text-sm whitespace-pre-line text-muted">{{ $goal->description }}</p>
    @endif

    {{-- Kecepatan menuju target --}}
    @if ($pace)
        <section @class(['card flex items-start gap-4', 'border-success/40' => $pace['onTrack'], 'border-warning/40' => !$pace['onTrack']])>
            <span @class(['icon-tile size-11', 'bg-success/15 text-success' => $pace['onTrack'], 'bg-warning/15 text-warning' => !$pace['onTrack']])>
                <x-icon :name="$pace['onTrack'] ? 'rocket' : 'trending'" />
            </span>
            <div class="min-w-0">
                <p class="font-bold">{{ $pace['onTrack'] ? 'Kamu sesuai jalur!' : 'Perlu sedikit dikejar' }}</p>
                <p class="mt-0.5 text-sm text-muted">
                    Butuh <span class="font-bold text-ink">{{ StudySession::formatMinutes($pace['perDay']) }}/hari</span>
                    selama {{ $pace['daysLeft'] }} hari lagi ({{ StudySession::formatMinutes($pace['remainingMinutes']) }} tersisa).
                    Rata-rata 14 hari terakhir: <span class="font-bold text-ink">{{ StudySession::formatMinutes($pace['recentPerDay']) }}/hari</span>.
                </p>
            </div>
        </section>
    @endif

    {{-- Tren skor latihan / try out --}}
    @if ($trend = $this->scoreTrend)
        <section class="card">
            <div class="mb-3 flex items-start justify-between gap-3">
                <div>
                    <h2 class="font-bold">Tren skor</h2>
                    <p class="text-xs text-muted">{{ count($trend['points']) }} skor terakhir</p>
                </div>
                <div class="text-right">
                    <p class="text-2xl font-bold">{{ $trend['latest'] }}</p>
                    @if (! is_null($trend['change']))
                        <p @class(['text-xs font-semibold', 'text-success' => $trend['change'] >= 0, 'text-danger' => $trend['change'] < 0])>
                            {{ $trend['change'] >= 0 ? '▲ +' : '▼ ' }}{{ StudySession::formatScore($trend['change']) }} dari awal
                        </p>
                    @endif
                </div>
            </div>

            <div class="relative h-40" x-data="{ active: null, points: @js($trend['points']) }" x-on:mouseleave="active = null">
                <div class="absolute inset-x-0 border-t border-dashed border-line" style="top: {{ $trend['highY'] }}%"></div>
                <div class="absolute inset-x-0 border-t border-dashed border-line" style="top: {{ $trend['lowY'] }}%"></div>
                <span class="absolute left-0 -translate-y-full pb-0.5 text-[10px] font-semibold text-muted" style="top: {{ $trend['highY'] }}%">{{ $trend['high'] }}</span>
                <span class="absolute left-0 pt-0.5 text-[10px] font-semibold text-muted" style="top: {{ $trend['lowY'] }}%">{{ $trend['low'] }}</span>

                <svg class="absolute inset-0 size-full overflow-visible" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                    <polyline points="{{ collect($trend['points'])->map(fn ($point) => $point['x'].','.$point['y'])->implode(' ') }}"
                        fill="none" stroke="{{ $goal->color }}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                </svg>

                @foreach ($trend['points'] as $index => $point)
                    <button type="button" wire:key="score-{{ $index }}"
                        class="absolute grid size-8 -translate-x-1/2 -translate-y-1/2 place-items-center rounded-full outline-none"
                        style="left: {{ $point['x'] }}%; top: {{ $point['y'] }}%"
                        x-on:mouseenter="active = {{ $index }}" x-on:focus="active = {{ $index }}" x-on:click="active = {{ $index }}"
                        aria-label="{{ $point['date'] }}: skor {{ $point['score'] }}">
                        <span class="size-2.5 rounded-full ring-2 ring-surface transition-transform" style="background-color: {{ $goal->color }}"
                            :class="active === {{ $index }} && 'scale-150'"></span>
                    </button>
                @endforeach

                <div x-show="active !== null" x-cloak
                    class="pointer-events-none absolute z-10 -translate-x-1/2 -translate-y-full whitespace-nowrap rounded-lg bg-ink px-2 py-1 text-xs font-semibold text-bg shadow"
                    :style="active !== null && `left: ${Math.min(82, Math.max(18, points[active].x))}%; top: calc(${points[active].y}% - 12px)`"
                    x-text="active !== null ? `${points[active].date} · ${points[active].score}` : ''"></div>
            </div>
        </section>
    @endif

    {{-- Materi --}}
    <section>
        <h2 class="mb-2 font-bold">Materi & langkah <span class="text-sm font-semibold text-muted">{{ $summary->topics_done_count }}/{{ $summary->topics_count }}</span></h2>
        <div class="card divide-y divide-line p-0">
            @foreach ($this->topics as $topic)
                <div wire:key="topic-{{ $topic->id }}" class="group flex items-center gap-3 px-4 py-2.5">
                    <input type="checkbox" class="size-5 shrink-0 accent-brand" wire:click="toggleTopic({{ $topic->id }})" @checked($topic->is_done) aria-label="Tandai {{ $topic->name }}">
                    <span @class(['min-w-0 flex-1 text-sm', 'text-muted line-through' => $topic->is_done])>{{ $topic->name }}</span>
                    <div class="flex items-center text-muted">
                        <button class="rounded-lg p-1.5 hover:bg-surface-2" wire:click="moveTopic({{ $topic->id }}, -1)" aria-label="Naikkan">
                            <x-icon name="chevron-left" class="size-4 rotate-90" />
                        </button>
                        <button class="rounded-lg p-1.5 hover:bg-surface-2" wire:click="moveTopic({{ $topic->id }}, 1)" aria-label="Turunkan">
                            <x-icon name="chevron-right" class="size-4 rotate-90" />
                        </button>
                        <button class="rounded-lg p-1.5 hover:bg-danger/10 hover:text-danger" wire:click="deleteTopic({{ $topic->id }})" wire:confirm="Hapus materi ini?" aria-label="Hapus">
                            <x-icon name="trash" class="size-4" />
                        </button>
                    </div>
                </div>
            @endforeach
            <form wire:submit="addTopic" class="flex gap-2 p-2">
                <input wire:model="newTopic" class="input py-2" placeholder="Tambah materi…" aria-label="Materi baru">
                <button class="btn btn-ghost px-3" aria-label="Tambah materi"><x-icon name="plus" class="size-4" /></button>
            </form>
        </div>
        @error('newTopic') <p class="error">{{ $message }}</p> @enderror
    </section>

    {{-- Tugas --}}
    <section>
        <h2 class="mb-2 font-bold">Tugas</h2>
        <div class="card divide-y divide-line p-0">
            @foreach ($this->tasks as $task)
                <label wire:key="task-{{ $task->id }}" class="flex cursor-pointer items-center gap-3 px-4 py-2.5">
                    <input type="checkbox" class="size-5 shrink-0 accent-brand" wire:click="toggleTask({{ $task->id }})" @checked($task->is_done)>
                    <span @class(['min-w-0 flex-1 text-sm', 'text-muted line-through' => $task->is_done])>{{ $task->title }}</span>
                    @if ($task->due_date)
                        <span @class(['text-xs', 'font-semibold text-danger' => $task->isOverdue(), 'text-muted' => !$task->isOverdue()])>{{ $task->due_date->translatedFormat('d M') }}</span>
                    @endif
                </label>
            @endforeach
            <form wire:submit="addTask" class="flex gap-2 p-2">
                <input wire:model="newTask" class="input min-w-0 py-2" placeholder="Tambah tugas…" aria-label="Tugas baru">
                <x-date-input wire:model="newTaskDue" wrapper-class="w-36 max-w-[45%] shrink-0" class="py-2" placeholder="Tenggat" aria-label="Tenggat" />
                <button class="btn btn-ghost px-3" aria-label="Tambah tugas"><x-icon name="plus" class="size-4" /></button>
            </form>
        </div>
        @error('newTask') <p class="error">{{ $message }}</p> @enderror
    </section>

    {{-- Jadwal --}}
    <section>
        <div class="mb-2 flex items-center justify-between">
            <h2 class="font-bold">Jadwal mingguan</h2>
            <a href="{{ route('schedule') }}" wire:navigate class="text-sm font-semibold text-brand">Atur</a>
        </div>
        @if ($this->scheduleSlots->isEmpty())
            <p class="card text-sm text-muted">Belum ada jadwal rutin untuk target ini.</p>
        @else
            <div class="flex flex-wrap gap-2">
                @foreach ($this->scheduleSlots as $slot)
                    <span wire:key="slot-{{ $slot->id }}" class="chip bg-surface px-3 py-1.5 text-sm text-ink ring-1 ring-line">
                        {{ ScheduleSlot::DAYS[$slot->day_of_week] }} · {{ $slot->start_time }}–{{ $slot->end_time }}
                    </span>
                @endforeach
            </div>
        @endif
    </section>

    {{-- Catatan --}}
    <section>
        <div class="mb-2 flex items-center justify-between">
            <h2 class="font-bold">Catatan</h2>
            <a href="{{ route('notes', ['goal' => $goal->id]) }}" wire:navigate class="text-sm font-semibold text-brand">Semua / tulis</a>
        </div>
        @if ($this->goalNotes->isEmpty())
            <p class="card text-sm text-muted">Belum ada catatan untuk target ini. Simpan ringkasan materi, link, atau strategi di <a href="{{ route('notes', ['goal' => $goal->id]) }}" wire:navigate class="font-semibold text-brand">Catatan</a>.</p>
        @else
            <div class="card divide-y divide-line p-0">
                @foreach ($this->goalNotes as $note)
                    <a href="{{ route('notes', ['goal' => $goal->id, 'open' => $note->id]) }}" wire:navigate wire:key="note-{{ $note->id }}" class="block px-4 py-2.5 hover:bg-surface-2">
                        <p class="truncate text-sm font-semibold">{{ $note->is_pinned ? '📌 ' : '' }}{{ $note->title }}</p>
                        @if ($note->body)
                            <p class="truncate text-xs text-muted">{{ str($note->body)->squish()->limit(90) }}</p>
                        @endif
                    </a>
                @endforeach
            </div>
        @endif
    </section>

    {{-- Riwayat --}}
    <section>
        <h2 class="mb-2 font-bold">Riwayat fokus</h2>
        <div class="card divide-y divide-line p-0">
            @forelse ($this->sessions as $session)
                <div wire:key="session-{{ $session->id }}" class="flex items-center gap-3 px-4 py-2.5">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium">{{ $session->topic?->name ?? 'Fokus umum' }}</p>
                        <p class="text-xs text-muted">{{ $session->studied_on->translatedFormat('D, d M') }}{{ $session->notes ? ' · '.$session->notes : '' }}</p>
                    </div>
                    @if (! is_null($session->score))
                        <span class="chip bg-brand-soft text-brand-ink">Skor {{ StudySession::formatScore($session->score) }}</span>
                    @endif
                    <span class="text-sm font-semibold">{{ StudySession::formatMinutes($session->minutes) }}</span>
                </div>
            @empty
                <p class="px-4 py-3 text-sm text-muted">Belum ada sesi fokus untuk target ini.</p>
            @endforelse
        </div>
    </section>
</div>
