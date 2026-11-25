<?php

use App\Models\ScheduleSlot;
use App\Models\StudySession;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Jadwal')] class extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    public ?int $goal_id = null;

    /** @var array<int, int|string> */
    public array $days = [];

    public string $start_time = '19:00';

    public string $end_time = '20:00';

    public string $notes = '';

    #[Computed]
    public function goals(): Collection
    {
        return auth()->user()->goals()->where('status', '!=', 'done')->orderBy('priority')->orderBy('name')->get();
    }

    /** @return Collection<int, Collection<int, ScheduleSlot>> */
    #[Computed]
    public function slotsByDay(): Collection
    {
        return auth()->user()->scheduleSlots()
            ->with('goal')
            ->orderBy('start_time')
            ->get()
            ->groupBy('day_of_week');
    }

    /** @return array{planned: int, done: int, percent: ?int, days: array<int, string>} */
    #[Computed]
    public function adherence(): array
    {
        return ScheduleSlot::weeklyAdherence(auth()->user());
    }

    public function openCreate(?int $day = null): void
    {
        $this->resetForm();
        $this->goal_id = $this->goals->first()?->id;
        $this->days = [$day ?? today()->dayOfWeekIso];
        $this->showForm = true;
    }

    public function openEdit(int $slotId): void
    {
        $slot = auth()->user()->scheduleSlots()->findOrFail($slotId);

        $this->resetForm();
        $this->editingId = $slot->id;
        $this->goal_id = $slot->goal_id;
        $this->days = [$slot->day_of_week];
        $this->start_time = $slot->start_time;
        $this->end_time = $slot->end_time;
        $this->notes = (string) $slot->notes;
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
    }

    public function toggleDay(int $day): void
    {
        if ($this->editingId) {
            $this->days = [$day];

            return;
        }

        $this->days = in_array($day, array_map('intval', $this->days), true)
            ? array_values(array_filter($this->days, fn ($selected) => (int) $selected !== $day))
            : [...$this->days, $day];
    }

    public function save(): void
    {
        $validated = $this->validate([
            'goal_id' => ['required', Rule::exists('goals', 'id')->where('user_id', auth()->id())->whereNull('deleted_at')],
            'days' => ['required', 'array', 'min:1'],
            'days.*' => ['integer', 'between:1,7'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'notes' => ['nullable', 'string', 'max:150'],
        ], [
            'days.required' => 'Pilih minimal satu hari.',
            'end_time.after' => 'Jam selesai harus setelah jam mulai.',
        ], ['goal_id' => 'target']);

        $attributes = [
            'goal_id' => $validated['goal_id'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'notes' => $validated['notes'] ?: null,
        ];

        $days = array_unique(array_map('intval', $validated['days']));
        $clash = auth()->user()->scheduleSlots()
            ->with('goal')
            ->whereIn('day_of_week', $days)
            ->when($this->editingId, fn ($query) => $query->whereKeyNot($this->editingId))
            ->orderBy('day_of_week')
            ->get()
            ->first(fn (ScheduleSlot $slot) => $slot->overlaps($validated['start_time'], $validated['end_time']));

        if ($clash) {
            $this->addError('start_time', "Bentrok dengan {$clash->goal->label()} ({$clash->start_time}–{$clash->end_time}) hari ".ScheduleSlot::DAYS[$clash->day_of_week].'.');

            return;
        }

        if ($this->editingId) {
            auth()->user()->scheduleSlots()->findOrFail($this->editingId)
                ->update($attributes + ['day_of_week' => (int) $validated['days'][0]]);
        } else {
            foreach ($days as $day) {
                auth()->user()->scheduleSlots()->create($attributes + ['day_of_week' => $day]);
            }
        }

        $this->closeForm();
        $this->dispatch('toast', message: 'Jadwal disimpan');
    }

    public function delete(int $slotId): void
    {
        auth()->user()->scheduleSlots()->findOrFail($slotId)->delete();

        $this->closeForm();
        $this->dispatch('toast', message: 'Jadwal dihapus');
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->reset(['editingId', 'goal_id', 'days', 'start_time', 'end_time', 'notes']);
    }
};
?>

@php
    $weeklyMinutes = $this->slotsByDay->flatten()->sum(fn ($slot) => $slot->durationMinutes());
    $today = today()->dayOfWeekIso;
    $adherence = $this->adherence;
    $dayStatus = [
        'done' => ['label' => 'Terlaksana', 'class' => 'bg-success/15 text-success', 'mark' => '✓'],
        'partial' => ['label' => 'Sebagian', 'class' => 'bg-amber-500/15 text-amber-600 dark:text-amber-400', 'mark' => '½'],
        'missed' => ['label' => 'Terlewat', 'class' => 'bg-danger/10 text-danger', 'mark' => '✕'],
    ];
@endphp

<div class="space-y-5">
    <x-page-header title="Jadwal" :subtitle="'Pola fokus mingguan · '.StudySession::formatMinutes($weeklyMinutes).' / minggu'" icon="calendar">
        <x-slot:actions>
            <button class="btn btn-primary" wire:click="openCreate" @disabled($this->goals->isEmpty())>
                <x-icon name="plus" class="size-4" /> Slot
            </button>
        </x-slot:actions>
    </x-page-header>

    {{-- Jadwal vs realisasi minggu ini --}}
    @if (! is_null($adherence['percent']))
        <section class="card">
            <div class="mb-2 flex items-end justify-between gap-3">
                <div>
                    <p class="text-sm font-semibold text-muted">Jadwal terlaksana minggu ini</p>
                    <p class="text-xs text-muted">{{ StudySession::formatMinutes($adherence['done']) }} dari {{ StudySession::formatMinutes($adherence['planned']) }} yang dijadwalkan sampai sekarang</p>
                </div>
                <p class="text-2xl font-bold">{{ $adherence['percent'] }}%</p>
            </div>
            <x-progress :value="$adherence['percent']" />
            <p class="mt-2 text-xs text-muted">Dihitung dari menit fokus per target di hari yang sama dengan slotnya.</p>
        </section>
    @endif

    @if ($this->goals->isEmpty())
        <p class="card text-sm text-muted">Buat target dulu, lalu atur jadwalnya di sini. <a href="{{ route('goals.index') }}" wire:navigate class="font-semibold text-brand">Tambah target →</a></p>
    @endif

    <div class="space-y-3">
        @foreach (ScheduleSlot::DAYS as $dayNumber => $dayName)
            @php $slots = $this->slotsByDay->get($dayNumber, collect()); @endphp
            <section wire:key="day-{{ $dayNumber }}" @class(['card p-3', 'ring-2 ring-brand/40' => $dayNumber === $today])>
                <div class="mb-2 flex items-center justify-between px-1">
                    <h2 class="font-bold">
                        {{ $dayName }}
                        @if ($dayNumber === $today) <span class="chip ml-1 bg-brand text-on-brand">Hari ini</span> @endif
                        @if ($status = $dayStatus[$adherence['days'][$dayNumber] ?? ''] ?? null)
                            <span class="chip ml-1 {{ $status['class'] }}">{{ $status['mark'] }} {{ $status['label'] }}</span>
                        @endif
                    </h2>
                    <button class="rounded-lg p-1.5 text-muted hover:bg-surface-2 hover:text-ink" wire:click="openCreate({{ $dayNumber }})" aria-label="Tambah slot {{ $dayName }}" @disabled($this->goals->isEmpty())>
                        <x-icon name="plus" class="size-4" />
                    </button>
                </div>
                @forelse ($slots as $slot)
                    <button wire:key="slot-{{ $slot->id }}" wire:click="openEdit({{ $slot->id }})"
                        class="mb-1.5 flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left last:mb-0"
                        style="background-color: color-mix(in srgb, {{ $slot->goal->color }} 14%, transparent)">
                        <span class="w-24 shrink-0 text-sm font-semibold tabular-nums">{{ $slot->start_time }}–{{ $slot->end_time }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-semibold" style="color: {{ $slot->goal->color }}">{{ $slot->goal->label() }}</span>
                            @if ($slot->notes) <span class="block truncate text-xs text-muted">{{ $slot->notes }}</span> @endif
                        </span>
                    </button>
                @empty
                    <p class="px-1 text-sm text-muted">Libur / bebas</p>
                @endforelse
            </section>
        @endforeach
    </div>

    <x-sheet :show="$showForm" :title="$editingId ? 'Edit slot' : 'Slot fokus baru'">
        <form wire:submit="save" class="space-y-4">
            <div>
                <label class="label" for="goal_id">Target</label>
                <select id="goal_id" wire:model="goal_id" class="input">
                    @foreach ($this->goals as $goal)
                        <option value="{{ $goal->id }}">{{ $goal->label() }}</option>
                    @endforeach
                </select>
                @error('goal_id') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div>
                <span class="label">{{ $editingId ? 'Hari' : 'Hari (bisa pilih beberapa)' }}</span>
                <div class="grid grid-cols-7 gap-1.5">
                    @foreach (ScheduleSlot::DAYS as $dayNumber => $dayName)
                        @php $selected = in_array($dayNumber, array_map('intval', $days), true); @endphp
                        <button type="button" wire:click="toggleDay({{ $dayNumber }})" @class([
                            'rounded-xl py-2 text-sm font-semibold transition',
                            'bg-brand text-on-brand' => $selected,
                            'bg-surface-2 text-muted' => !$selected,
                        ])>{{ mb_substr($dayName, 0, 3) }}</button>
                    @endforeach
                </div>
                @error('days') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="label" for="start_time">Mulai</label>
                    <input id="start_time" type="time" wire:model="start_time" class="input">
                    @error('start_time') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="end_time">Selesai</label>
                    <input id="end_time" type="time" wire:model="end_time" class="input">
                    @error('end_time') <p class="error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label class="label" for="notes">Catatan (opsional)</label>
                <input id="notes" wire:model="notes" class="input" placeholder="mis. Latihan soal Reading">
            </div>

            <div class="flex gap-2 pt-1">
                @if ($editingId)
                    <button type="button" class="btn btn-danger" wire:click="delete({{ $editingId }})" wire:confirm="Hapus slot ini?">
                        <x-icon name="trash" class="size-4" />
                    </button>
                @endif
                <button class="btn btn-primary flex-1" wire:loading.attr="disabled">Simpan</button>
            </div>
        </form>
    </x-sheet>
</div>
