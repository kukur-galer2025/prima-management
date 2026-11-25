<?php

use App\Models\Goal;
use App\Models\Habit;
use App\Models\ScheduleSlot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Kebiasaan')] class extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $icon = '';

    public string $color = '#059669';

    /** @var array<int, int|string> */
    public array $days = Habit::EVERY_DAY;

    /** Jam pengingat HH:MM, kosong = tanpa pengingat. */
    public ?string $remind_at = null;

    /** Kebiasaan yang riwayat setahunnya sedang dibuka. */
    public ?int $expandedHabit = null;

    public function toggleHistory(int $habitId): void
    {
        $this->expandedHabit = $this->expandedHabit === $habitId ? null : $habitId;
    }

    #[Computed]
    public function habits(): Collection
    {
        return auth()->user()->habits()
            ->with(['logs' => fn ($query) => $query->whereDate('logged_on', '>=', today()->subDays(400))])
            ->orderBy('name')
            ->get();
    }

    /** @return array<int, Carbon> 7 hari terakhir, berakhir hari ini */
    #[Computed]
    public function week(): array
    {
        return collect(range(6, 0))->map(fn (int $offset) => today()->subDays($offset))->all();
    }

    public function toggleDay(int $habitId, string $date): void
    {
        $habit = auth()->user()->habits()->findOrFail($habitId);
        $day = Carbon::parse($date)->startOfDay();

        if ($day->gt(today()) || $day->lt(today()->subDays(400))) {
            return;
        }

        $log = $habit->logs()->whereDate('logged_on', $day)->first();

        if ($log) {
            $log->delete();

            return;
        }

        $habit->logs()->create(['logged_on' => $day->toDateString()]);

        if ($day->isToday()) {
            $this->dispatch('toast', message: 'Mantap, '.$habit->name.' ✅');
        }
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->color = Goal::COLORS[$this->habits->count() % count(Goal::COLORS)];
        $this->showForm = true;
    }

    public function openEdit(int $habitId): void
    {
        $habit = auth()->user()->habits()->findOrFail($habitId);

        $this->resetForm();
        $this->editingId = $habit->id;
        $this->name = $habit->name;
        $this->icon = (string) $habit->icon;
        $this->color = $habit->color;
        $this->days = array_map('intval', $habit->days);
        $this->remind_at = $habit->remind_at;
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
    }

    public function toggleFormDay(int $day): void
    {
        $selected = array_map('intval', $this->days);

        $this->days = in_array($day, $selected, true)
            ? array_values(array_diff($selected, [$day]))
            : [...$selected, $day];
    }

    /** @param  array<int, int>  $days */
    public function setDays(array $days): void
    {
        $this->days = $days;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'icon' => ['nullable', 'string', 'max:16'],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'days' => ['required', 'array', 'min:1'],
            'days.*' => ['integer', 'between:1,7'],
            'remind_at' => ['nullable', 'date_format:H:i'],
        ], ['days.required' => 'Pilih minimal satu hari.', 'days.min' => 'Pilih minimal satu hari.'], ['name' => 'nama kebiasaan']);

        $attributes = [
            'name' => trim($validated['name']),
            'icon' => $validated['icon'] ?: null,
            'color' => $validated['color'],
            'days' => collect($validated['days'])->map(fn ($day) => (int) $day)->unique()->sort()->values()->all(),
            'remind_at' => $validated['remind_at'] ?: null,
        ];

        if ($this->editingId) {
            auth()->user()->habits()->findOrFail($this->editingId)->update($attributes);
        } else {
            auth()->user()->habits()->create($attributes);
        }

        $this->closeForm();
        $this->dispatch('toast', message: 'Kebiasaan disimpan');
    }

    public function delete(int $habitId): void
    {
        auth()->user()->habits()->findOrFail($habitId)->delete();

        $this->closeForm();
        $this->dispatch('toast', message: 'Kebiasaan dihapus');
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->reset(['editingId', 'name', 'icon', 'color', 'days', 'remind_at']);
    }
};
?>

@php
    $scheduledToday = $this->habits->filter(fn ($habit) => $habit->isScheduledOn(today()));
    $doneToday = $scheduledToday->filter(fn ($habit) => $habit->logs->contains(fn ($log) => $log->logged_on->isToday()));
@endphp

<div class="space-y-5">
    <x-page-header title="Kebiasaan" icon="repeat"
        :subtitle="$scheduledToday->isNotEmpty() ? $doneToday->count().' dari '.$scheduledToday->count().' kebiasaan hari ini sudah jalan.' : 'Bangun rutinitas kecil yang konsisten.'">
        <x-slot:actions>
            <button class="btn btn-primary" wire:click="openCreate">
                <x-icon name="plus" class="size-4" /> Kebiasaan
            </button>
        </x-slot:actions>
    </x-page-header>

    <div class="space-y-3">
        @forelse ($this->habits as $habit)
            @php
                $loggedDates = $habit->logs->map(fn ($log) => $log->logged_on->toDateString());
                $streak = $habit->streak($loggedDates);
                $rate = $habit->completionRate($loggedDates);
            @endphp
            <section wire:key="habit-{{ $habit->id }}" class="card">
                <div class="mb-3 flex items-start justify-between gap-3">
                    <button class="min-w-0 text-left" wire:click="openEdit({{ $habit->id }})">
                        <p class="truncate font-bold">{{ $habit->label() }}</p>
                        <p class="text-xs text-muted">{{ $habit->scheduleLabel() }}{{ $habit->remind_at ? ' · ⏰ '.$habit->remind_at : '' }}{{ is_null($rate) ? '' : ' · '.$rate.'% dalam 30 hari' }}</p>
                    </button>
                    <span @class(['flex shrink-0 items-center gap-1 text-sm font-bold', 'text-muted' => $streak === 0])>
                        <x-icon name="flame" @class(['size-4', 'text-orange-500' => $streak > 0]) /> {{ $streak }}
                    </span>
                </div>
                <div class="grid grid-cols-7 gap-1.5">
                    @foreach ($this->week as $day)
                        @php
                            $isDone = $loggedDates->contains($day->toDateString());
                            $isScheduled = $habit->isScheduledOn($day);
                        @endphp
                        <button type="button" wire:key="habit-{{ $habit->id }}-{{ $day->toDateString() }}"
                            wire:click="toggleDay({{ $habit->id }}, '{{ $day->toDateString() }}')"
                            aria-pressed="{{ $isDone ? 'true' : 'false' }}"
                            aria-label="{{ $habit->name }} {{ $day->translatedFormat('l, d M') }}"
                            @class([
                                'flex flex-col items-center gap-0.5 rounded-xl py-1.5 text-xs font-semibold transition',
                                'text-white' => $isDone,
                                'bg-surface-2 text-muted' => !$isDone && $isScheduled,
                                'border border-dashed border-line text-muted/60' => !$isDone && !$isScheduled,
                                'ring-2 ring-brand/50' => $day->isToday() && !$isDone,
                            ])
                            @if ($isDone) style="background-color: {{ $habit->color }}" @endif>
                            <span>{{ mb_substr($day->translatedFormat('D'), 0, 3) }}</span>
                            <span class="text-sm">{{ $isDone ? '✓' : $day->format('j') }}</span>
                        </button>
                    @endforeach
                </div>

                <button type="button" class="mt-3 inline-flex items-center gap-1 text-xs font-bold text-muted hover:text-ink" wire:click="toggleHistory({{ $habit->id }})">
                    <x-icon name="calendar-grid" class="size-3.5" /> {{ $expandedHabit === $habit->id ? 'Tutup riwayat' : 'Riwayat setahun' }}
                </button>
                @if ($expandedHabit === $habit->id)
                    <div class="mt-3">
                        <x-heatmap :from="today()->subDays(364)" :to="today()" :color="$habit->color" empty-label="belum dicentang"
                            :days="$loggedDates->mapWithKeys(fn ($date) => [$date => ['level' => 4, 'label' => \Illuminate\Support\Carbon::parse($date)->translatedFormat('D, d M Y').': ✓ dijalankan']])->all()" />
                    </div>
                @endif
            </section>
        @empty
            <x-empty-state emoji="🌱" title="Belum ada kebiasaan">
                Mulai dari yang kecil, mis. "Baca 10 halaman" atau "Olahraga 15 menit".
                <button class="link mt-2 block w-full" wire:click="openCreate">Tambah kebiasaan</button>
            </x-empty-state>
        @endforelse
    </div>

    @if ($this->habits->isNotEmpty())
        <p class="text-xs text-muted">Ketuk kotak hari untuk mencentang atau membatalkan. Kotak bergaris putus-putus = hari yang tidak dijadwalkan.</p>
    @endif

    <x-sheet :show="$showForm" :title="$editingId ? 'Edit kebiasaan' : 'Kebiasaan baru'">
        <form wire:submit="save" class="space-y-4">
            <div class="grid grid-cols-[4.5rem_1fr] gap-3">
                <div>
                    <label class="label" for="habit-icon">Ikon</label>
                    <input id="habit-icon" wire:model="icon" class="input text-center" placeholder="📖" maxlength="16">
                </div>
                <div>
                    <label class="label" for="habit-name">Nama kebiasaan</label>
                    <input id="habit-name" wire:model="name" class="input" placeholder="mis. Hafal 10 kosakata" required>
                    @error('name') <p class="error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <span class="label">Hari</span>
                <div class="grid grid-cols-7 gap-1.5">
                    @foreach (ScheduleSlot::DAYS as $dayNumber => $dayName)
                        @php $selected = in_array($dayNumber, array_map('intval', $days), true); @endphp
                        <button type="button" wire:click="toggleFormDay({{ $dayNumber }})" @class([
                            'rounded-xl py-2 text-sm font-semibold transition',
                            'bg-brand text-on-brand' => $selected,
                            'bg-surface-2 text-muted' => !$selected,
                        ])>{{ mb_substr($dayName, 0, 3) }}</button>
                    @endforeach
                </div>
                <div class="mt-2 flex flex-wrap gap-1.5">
                    <button type="button" class="chip bg-surface-2 px-3 py-1 text-muted hover:text-ink" wire:click="setDays([1,2,3,4,5,6,7])">Setiap hari</button>
                    <button type="button" class="chip bg-surface-2 px-3 py-1 text-muted hover:text-ink" wire:click="setDays([1,2,3,4,5])">Hari kerja</button>
                    <button type="button" class="chip bg-surface-2 px-3 py-1 text-muted hover:text-ink" wire:click="setDays([6,7])">Akhir pekan</button>
                </div>
                @error('days') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="label" for="habit-remind">Ingatkan jam (opsional)</label>
                <div class="flex items-center gap-2">
                    <input id="habit-remind" type="time" wire:model="remind_at" class="input w-36">
                    @if ($remind_at)
                        <button type="button" class="text-xs font-bold text-muted hover:text-ink" wire:click="$set('remind_at', null)">Hapus</button>
                    @endif
                </div>
                <p class="mt-1 text-xs text-muted">Dikirim lewat notifikasi/Telegram di hari terjadwal, kalau belum dicentang.</p>
                @error('remind_at') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div>
                <span class="label">Warna</span>
                <div class="flex flex-wrap gap-2">
                    @foreach (Goal::COLORS as $swatch)
                        <button type="button" wire:click="$set('color', '{{ $swatch }}')" aria-label="Warna {{ $swatch }}"
                            @class(['size-9 rounded-full ring-offset-2 ring-offset-surface', 'ring-2 ring-ink' => $color === $swatch])
                            style="background-color: {{ $swatch }}"></button>
                    @endforeach
                </div>
            </div>

            <div class="flex gap-2 pt-1">
                @if ($editingId)
                    <button type="button" class="btn btn-danger" wire:click="delete({{ $editingId }})" wire:confirm="Hapus kebiasaan ini beserta seluruh riwayat centangnya?">
                        <x-icon name="trash" class="size-4" />
                    </button>
                @endif
                <button class="btn btn-primary flex-1" wire:loading.attr="disabled">Simpan</button>
            </div>
        </form>
    </x-sheet>
</div>
