<?php

use App\Models\Goal;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Target')] class extends Component
{
    #[Url]
    public string $status = 'active';

    /** Dipakai halaman detail untuk langsung membuka form edit: /target?edit=ID */
    #[Url(except: null)]
    public ?int $edit = null;

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $category = '';

    public string $description = '';

    public string $icon = '';

    public string $color = '#7c3aed';

    public int|string|null $target_hours = null;

    public ?string $deadline = null;

    public ?string $deadline_time = null;

    public int $priority = 2;

    public string $goalStatus = 'active';

    /** Materi awal, satu per baris (hanya saat membuat target baru). */
    public string $initialTopics = '';

    public function mount(): void
    {
        if ($this->edit) {
            $this->openEdit($this->edit);
        }
    }

    #[Computed]
    public function goals(): Collection
    {
        return auth()->user()->goals()
            ->withStats()
            ->when($this->status === 'trashed', fn ($query) => $query->onlyTrashed())
            ->when(! in_array($this->status, ['all', 'trashed'], true), fn ($query) => $query->where('status', $this->status))
            ->orderBy('priority')
            ->orderByRaw('deadline is null')
            ->orderBy('deadline')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function counts(): array
    {
        $counts = auth()->user()->goals()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'active' => (int) ($counts['active'] ?? 0),
            'paused' => (int) ($counts['paused'] ?? 0),
            'done' => (int) ($counts['done'] ?? 0),
            'all' => (int) $counts->sum(),
            'trashed' => auth()->user()->goals()->onlyTrashed()->count(),
        ];
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->color = Goal::COLORS[$this->counts['all'] % count(Goal::COLORS)];
        $this->showForm = true;
    }

    public function openEdit(int $goalId): void
    {
        $goal = auth()->user()->goals()->findOrFail($goalId);

        $this->resetForm();
        $this->editingId = $goal->id;
        $this->name = $goal->name;
        $this->category = (string) $goal->category;
        $this->description = (string) $goal->description;
        $this->icon = (string) $goal->icon;
        $this->color = $goal->color;
        $this->target_hours = $goal->target_hours;
        $this->deadline = $goal->deadline?->toDateString();
        $this->deadline_time = $goal->deadline_time;
        $this->priority = $goal->priority;
        $this->goalStatus = $goal->status;
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->edit = null;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:2000'],
            'icon' => ['nullable', 'string', 'max:16'],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'target_hours' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'deadline' => ['nullable', 'date', 'required_with:deadline_time'],
            'deadline_time' => ['nullable', 'date_format:H:i'],
            'priority' => ['required', Rule::in(array_keys(Goal::PRIORITIES))],
            'goalStatus' => ['required', Rule::in(array_keys(Goal::STATUSES))],
            'initialTopics' => ['nullable', 'string', 'max:5000'],
        ], ['deadline.required_with' => 'Isi tanggal deadline bila memakai jam.'], ['deadline_time' => 'jam deadline']);

        $attributes = [
            'name' => $validated['name'],
            'category' => $validated['category'] ?: null,
            'description' => $validated['description'] ?: null,
            'icon' => $validated['icon'] ?: null,
            'color' => $validated['color'],
            'target_hours' => filled($validated['target_hours']) ? (int) $validated['target_hours'] : null,
            'deadline' => $validated['deadline'] ?: null,
            'deadline_time' => $validated['deadline'] && $validated['deadline_time'] ? $validated['deadline_time'] : null,
            'priority' => $validated['priority'],
            'status' => $validated['goalStatus'],
        ];

        if ($this->editingId) {
            auth()->user()->goals()->findOrFail($this->editingId)->update($attributes);
            $message = 'Target diperbarui';
        } else {
            $goal = auth()->user()->goals()->create($attributes);

            collect(preg_split('/\r\n|\r|\n/', $this->initialTopics))
                ->map(fn (string $line) => trim($line, " \t-•*"))
                ->filter()
                ->values()
                ->each(fn (string $topic, int $index) => $goal->topics()->create(['name' => $topic, 'sort_order' => $index]));

            $message = 'Target ditambahkan';
        }

        $this->closeForm();
        $this->dispatch('toast', message: $message);
    }

    public function delete(int $goalId): void
    {
        auth()->user()->goals()->findOrFail($goalId)->archive();

        $this->closeForm();
        $this->dispatch('toast', message: 'Target dipindah ke Terhapus');
    }

    public function restore(int $goalId): void
    {
        auth()->user()->goals()->onlyTrashed()->findOrFail($goalId)->restore();

        $this->dispatch('toast', message: 'Target dipulihkan');
    }

    /** Hapus permanen: materi, jadwal, dan log fokusnya ikut terhapus dari database. */
    public function forceDelete(int $goalId): void
    {
        auth()->user()->goals()->onlyTrashed()->findOrFail($goalId)->forceDelete();

        $this->dispatch('toast', message: 'Target dihapus permanen');
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->reset(['editingId', 'name', 'category', 'description', 'icon', 'color', 'target_hours', 'deadline', 'deadline_time', 'priority', 'goalStatus', 'initialTopics']);
    }
};
?>

<div class="space-y-5">
    <x-page-header title="Target" subtitle="Semua yang sedang kamu kejar." icon="target">
        <x-slot:actions>
            <button class="btn btn-primary" wire:click="openCreate">
                <x-icon name="plus" class="size-4" /> Target
            </button>
        </x-slot:actions>
    </x-page-header>

    <div class="flex gap-2 overflow-x-auto pb-1">
        @foreach (['active' => 'Aktif', 'paused' => 'Ditunda', 'done' => 'Selesai', 'all' => 'Semua', 'trashed' => 'Terhapus'] as $key => $label)
            <button wire:click="$set('status', '{{ $key }}')" @class([
                'chip shrink-0 px-3.5 py-1.5 text-sm',
                'bg-brand text-on-brand' => $status === $key,
                'bg-surface-2 text-muted' => $status !== $key,
            ])>{{ $label }} <span class="opacity-70">{{ $this->counts[$key] }}</span></button>
        @endforeach
    </div>

    @if ($status === 'trashed')
        <p class="text-sm text-muted">Target yang dihapus disimpan di sini. Log fokus, tugas, dan catatannya tetap aman sampai kamu menghapusnya permanen.</p>
    @endif

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        @forelse ($this->goals as $goal)
            @if ($goal->trashed())
                <div wire:key="trashed-{{ $goal->id }}" class="card relative overflow-hidden">
                    <div class="absolute inset-y-0 left-0 w-1.5 opacity-50" style="background-color: {{ $goal->color }}"></div>
                    <div class="pl-2">
                        <p class="font-bold leading-snug">{{ $goal->label() }}</p>
                        <p class="mt-1 text-xs text-muted">Dihapus {{ $goal->deleted_at->diffForHumans() }} · {{ $goal->hoursLogged() }} jam fokus tersimpan</p>
                        <div class="mt-3 flex gap-2">
                            <button class="btn btn-ghost flex-1 py-2" wire:click="restore({{ $goal->id }})">
                                <x-icon name="restore" class="size-4" /> Pulihkan
                            </button>
                            <button class="btn btn-danger py-2" wire:click="forceDelete({{ $goal->id }})"
                                wire:confirm="Hapus permanen? Semua materi dan log fokus target ini ikut hilang dan tidak bisa dikembalikan.">
                                Hapus permanen
                            </button>
                        </div>
                    </div>
                </div>
                @continue
            @endif
            <a href="{{ route('goals.show', $goal) }}" wire:navigate wire:key="goal-{{ $goal->id }}"
                class="card card-hover relative block overflow-hidden">
                <div class="absolute inset-y-0 left-0 w-1.5" style="background-color: {{ $goal->color }}"></div>
                <div class="pl-2">
                    <div class="mb-1 flex items-start justify-between gap-2">
                        <p class="font-bold leading-snug">{{ $goal->label() }}</p>
                        <span class="text-sm font-bold" style="color: {{ $goal->color }}">{{ $goal->progress() }}%</span>
                    </div>
                    <div class="mb-3 flex flex-wrap gap-1.5">
                        @if ($goal->category)
                            <span class="chip bg-surface-2 text-muted">{{ $goal->category }}</span>
                        @endif
                        @if ($goal->priority === 1)
                            <span class="chip bg-danger/10 text-danger">Prioritas tinggi</span>
                        @endif
                        @if ($goal->status !== 'active')
                            <span class="chip bg-surface-2 text-muted">{{ Goal::STATUSES[$goal->status] }}</span>
                        @endif
                        @if ($goal->isOverdue())
                            <span class="chip bg-danger/10 text-danger"><x-icon name="flag" class="size-3" /> Lewat {{ abs($goal->daysLeft()) }} hari</span>
                        @elseif (! is_null($goal->daysLeft()) && $goal->daysLeft() >= 0)
                            <span class="chip bg-brand-soft text-brand-ink" title="Deadline {{ $goal->deadlineLabel() }}"><x-icon name="flag" class="size-3" /> {{ $goal->daysLeft() === 0 ? 'Hari ini' : $goal->daysLeft().' hari lagi' }}{{ $goal->deadline_time ? ' · '.$goal->deadline_time : '' }}</span>
                        @endif
                    </div>
                    <x-progress :value="$goal->progress()" :color="$goal->color" />
                    <p class="mt-2 text-xs text-muted">
                        {{ $goal->hoursLogged() }}{{ $goal->target_hours ? ' / '.$goal->target_hours : '' }} jam
                        · {{ $goal->topics_done_count }}/{{ $goal->topics_count }} langkah
                    </p>
                    @if ($pace = $goal->pace())
                        <p @class(['mt-1 text-xs font-bold', 'text-success' => $pace['onTrack'], 'text-warning' => !$pace['onTrack']])>
                            {{ $pace['onTrack'] ? '✓ Sesuai jalur' : '⚠ Perlu dikejar' }} · butuh {{ \App\Models\StudySession::formatMinutes($pace['perDay']) }}/hari
                        </p>
                    @endif
                </div>
            </a>
        @empty
            <div class="card text-center text-sm text-muted sm:col-span-2">
                Belum ada target di sini.
                <button class="font-semibold text-brand" wire:click="openCreate">Tambah target baru</button>
            </div>
        @endforelse
    </div>

    <x-sheet :show="$showForm" :title="$editingId ? 'Edit target' : 'Target baru'">
        <form wire:submit="save" class="space-y-4">
            <div class="grid grid-cols-[4.5rem_1fr] gap-3">
                <div>
                    <label class="label" for="icon">Ikon</label>
                    <input id="icon" wire:model="icon" class="input text-center" placeholder="📘" maxlength="16">
                </div>
                <div>
                    <label class="label" for="name">Nama target</label>
                    <input id="name" wire:model="name" class="input" placeholder="mis. TOEFL ITP 550" required>
                    @error('name') <p class="error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="label" for="category">Kategori</label>
                    <input id="category" wire:model="category" class="input" placeholder="Bahasa, Tes, Riset…" list="categories">
                    <datalist id="categories">
                        @foreach (auth()->user()->goals()->whereNotNull('category')->distinct()->pluck('category') as $existingCategory)
                            <option value="{{ $existingCategory }}">
                        @endforeach
                    </datalist>
                </div>
                <div>
                    <label class="label" for="priority">Prioritas</label>
                    <select id="priority" wire:model="priority" class="input">
                        @foreach (Goal::PRIORITIES as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="label" for="deadline">Deadline / tanggal ujian</label>
                    <x-date-input id="deadline" wire:model="deadline" />
                    @error('deadline') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="deadline_time">Jam (opsional)</label>
                    <x-date-input type="time" id="deadline_time" wire:model="deadline_time" placeholder="mis. 08:00" />
                    @error('deadline_time') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="target_hours">Target jam fokus</label>
                    <input id="target_hours" type="number" inputmode="numeric" min="1" wire:model="target_hours" class="input" placeholder="mis. 100">
                    @error('target_hours') <p class="error">{{ $message }}</p> @enderror
                </div>
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

            <div>
                <label class="label" for="description">Catatan</label>
                <textarea id="description" wire:model="description" rows="2" class="input" placeholder="Skor target, link materi, strategi…"></textarea>
            </div>

            @if ($editingId)
                <div>
                    <label class="label" for="goalStatus">Status</label>
                    <select id="goalStatus" wire:model="goalStatus" class="input">
                        @foreach (Goal::STATUSES as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            @else
                <div>
                    <label class="label" for="initialTopics">Materi / langkah (opsional, satu per baris)</label>
                    <textarea id="initialTopics" wire:model="initialTopics" rows="4" class="input" placeholder="Listening&#10;Structure&#10;Reading"></textarea>
                </div>
            @endif

            <div class="flex gap-2 pt-1">
                @if ($editingId)
                    <button type="button" class="btn btn-danger" wire:click="delete({{ $editingId }})"
                        wire:confirm="Pindahkan target ini ke Terhapus? Log fokus, tugas, dan catatannya tetap tersimpan; slot jadwal mingguannya dihapus.">
                        <x-icon name="trash" class="size-4" />
                    </button>
                @endif
                <button class="btn btn-primary flex-1" wire:loading.attr="disabled">Simpan</button>
            </div>
        </form>
    </x-sheet>
</div>
