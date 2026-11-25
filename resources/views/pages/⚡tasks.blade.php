<?php

use App\Models\Task;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Tugas')] class extends Component
{
    #[Url]
    public string $filter = 'open';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public string $title = '';

    public ?string $due_date = null;

    public ?string $due_time = null;

    public int|string|null $goal_id = null;

    public int|string $priority = 2;

    public string $recurrence = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $notes = '';

    #[Computed]
    public function goals(): Collection
    {
        return auth()->user()->goals()->orderBy('priority')->orderBy('name')->get();
    }

    /** @return array<string, Collection<int, Task>> */
    #[Computed]
    public function groups(): array
    {
        $search = trim($this->search);

        $tasks = auth()->user()->tasks()
            ->with('goal')
            ->when($this->filter === 'open', fn ($query) => $query->where('is_done', false))
            ->when($this->filter === 'done', fn ($query) => $query->where('is_done', true))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('title', 'like', "%{$search}%")
                ->orWhere('notes', 'like', "%{$search}%")))
            ->orderBy('is_done')
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->orderByRaw('due_time is null')
            ->orderBy('due_time')
            ->orderBy('priority')
            ->latest('done_at')
            ->get();

        if ($this->filter === 'done') {
            return $tasks->isEmpty() ? [] : ['Selesai' => $tasks];
        }

        $weekAhead = today()->addDays(7);

        return collect([
            'Terlambat' => $tasks->filter(fn (Task $task) => $task->isOverdue()),
            'Hari ini' => $tasks->filter(fn (Task $task) => ! $task->is_done && $task->due_date?->isToday()),
            '7 hari ke depan' => $tasks->filter(fn (Task $task) => ! $task->is_done && $task->due_date?->gt(today()) && $task->due_date->lte($weekAhead)),
            'Nanti' => $tasks->filter(fn (Task $task) => ! $task->is_done && $task->due_date?->gt($weekAhead)),
            'Tanpa tanggal' => $tasks->filter(fn (Task $task) => ! $task->is_done && ! $task->due_date)->sortBy('priority'),
            'Selesai' => $tasks->filter(fn (Task $task) => $task->is_done),
        ])->filter(fn (Collection $group) => $group->isNotEmpty())->all();
    }

    public function quickAdd(): void
    {
        $validated = $this->validateTask();

        auth()->user()->tasks()->create($validated);

        $this->reset('title', 'due_date', 'due_time', 'notes', 'priority', 'recurrence');
        $this->dispatch('toast', message: 'Tugas ditambahkan');
    }

    public function openEdit(int $taskId): void
    {
        $task = auth()->user()->tasks()->findOrFail($taskId);

        $this->resetValidation();
        $this->editingId = $task->id;
        $this->title = $task->title;
        $this->due_date = $task->due_date?->toDateString();
        $this->due_time = $task->due_time;
        $this->goal_id = $task->goal?->trashed() ? null : $task->goal_id;
        $this->notes = (string) $task->notes;
        $this->priority = $task->priority;
        $this->recurrence = (string) $task->recurrence;
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->reset('editingId', 'title', 'due_date', 'due_time', 'notes', 'goal_id', 'priority', 'recurrence');
        $this->resetValidation();
    }

    public function update(): void
    {
        $validated = $this->validateTask();

        auth()->user()->tasks()->findOrFail($this->editingId)->update($validated);

        $this->closeForm();
        $this->dispatch('toast', message: 'Tugas diperbarui');
    }

    public function toggle(int $taskId): void
    {
        $task = auth()->user()->tasks()->findOrFail($taskId);
        $next = $task->toggle();

        if ($next) {
            $this->dispatch('toast', message: 'Beres! Muncul lagi '.$next->due_date->translatedFormat('D, d M').' 🔁');
        } elseif ($task->is_done) {
            $this->dispatch('toast', message: 'Satu beres! 🎉');
        }
    }

    public function delete(int $taskId): void
    {
        auth()->user()->tasks()->findOrFail($taskId)->delete();

        $this->closeForm();
        $this->dispatch('toast', message: 'Tugas dihapus');
    }

    /** @return array{title: string, due_date: ?string, due_time: ?string, goal_id: ?int, notes: ?string, priority: int, recurrence: ?string} */
    private function validateTask(): array
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'max:200'],
            'due_date' => ['nullable', 'date', 'required_with:due_time'],
            'due_time' => ['nullable', 'date_format:H:i'],
            'goal_id' => ['nullable', Rule::exists('goals', 'id')->where('user_id', auth()->id())->whereNull('deleted_at')],
            'notes' => ['nullable', 'string', 'max:2000'],
            'priority' => ['required', Rule::in(array_keys(Task::PRIORITIES))],
            'recurrence' => ['nullable', Rule::in(array_keys(Task::RECURRENCES))],
        ], ['due_date.required_with' => 'Isi tanggal tenggat bila memakai jam.'], ['title' => 'judul tugas', 'due_date' => 'tenggat', 'due_time' => 'jam', 'goal_id' => 'target', 'priority' => 'prioritas', 'recurrence' => 'pengulangan']);

        return [
            'title' => trim($validated['title']),
            'due_date' => $validated['due_date'] ?: null,
            'due_time' => $validated['due_time'] ?: null,
            'goal_id' => filled($validated['goal_id']) ? (int) $validated['goal_id'] : null,
            'notes' => $validated['notes'] ?: null,
            'priority' => (int) $validated['priority'],
            'recurrence' => $validated['recurrence'] ?: null,
        ];
    }
};
?>

<div class="space-y-5">
    <x-page-header title="Tugas" subtitle="Hal-hal konkret yang harus dibereskan." icon="check" />

    <form wire:submit="quickAdd" class="card space-y-2 p-3">
        <input wire:model="title" class="input" placeholder="mis. Minta surat rekomendasi ke dosen PA" aria-label="Judul tugas" @if ($showForm) disabled @endif>
        @if (! $showForm) @error('title') <p class="error">{{ $message }}</p> @enderror @endif
        <div class="flex gap-2">
            <select wire:model="goal_id" class="input min-w-0 py-2 text-sm" aria-label="Target" @if ($showForm) disabled @endif>
                <option value="">Tanpa target</option>
                @foreach ($this->goals as $goal)
                    <option value="{{ $goal->id }}">{{ $goal->label() }}</option>
                @endforeach
            </select>
            <input type="date" wire:model="due_date" class="input w-36 shrink-0 py-2 text-sm" aria-label="Tenggat" @if ($showForm) disabled @endif>
            <input type="time" wire:model="due_time" class="input w-26 shrink-0 py-2 text-sm" aria-label="Jam (opsional)" @if ($showForm) disabled @endif>
        </div>
        @if (! $showForm) @error('due_date') <p class="error">{{ $message }}</p> @enderror @endif
        <div class="flex gap-2">
            <select wire:model="priority" class="input min-w-0 py-2 text-sm" aria-label="Prioritas" @if ($showForm) disabled @endif>
                @foreach (Task::PRIORITIES as $value => $label)
                    <option value="{{ $value }}">Prioritas {{ strtolower($label) }}</option>
                @endforeach
            </select>
            <select wire:model="recurrence" class="input min-w-0 py-2 text-sm" aria-label="Pengulangan" @if ($showForm) disabled @endif>
                <option value="">Tidak berulang</option>
                @foreach (Task::RECURRENCES as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <button class="btn btn-primary shrink-0 px-3" aria-label="Tambah tugas"><x-icon name="plus" class="size-5" /></button>
        </div>
    </form>

    <div class="space-y-3">
        <label class="relative block">
            <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted" />
            <input type="search" wire:model.live.debounce.300ms="search" class="input py-2 pl-9 text-sm" placeholder="Cari tugas atau catatannya…" aria-label="Cari tugas">
        </label>
        <div class="flex gap-2">
            @foreach (['open' => 'Belum selesai', 'done' => 'Selesai', 'all' => 'Semua'] as $key => $label)
                <button wire:click="$set('filter', '{{ $key }}')" @class([
                    'chip px-3.5 py-1.5 text-sm',
                    'bg-brand text-on-brand' => $filter === $key,
                    'bg-surface-2 text-muted' => $filter !== $key,
                ])>{{ $label }}</button>
            @endforeach
        </div>
    </div>

    @forelse ($this->groups as $groupName => $tasks)
        <section wire:key="group-{{ $groupName }}">
            <h2 @class(['mb-2 text-sm font-bold uppercase tracking-wide', 'text-danger' => $groupName === 'Terlambat', 'text-muted' => $groupName !== 'Terlambat'])>
                {{ $groupName }} <span class="font-semibold opacity-70">{{ $tasks->count() }}</span>
            </h2>
            <div class="card divide-y divide-line p-0">
                @foreach ($tasks as $task)
                    <div wire:key="task-{{ $task->id }}" class="flex items-center gap-3 px-4 py-3">
                        <input type="checkbox" class="size-5 shrink-0 accent-brand" wire:click="toggle({{ $task->id }})" @checked($task->is_done) aria-label="Selesaikan {{ $task->title }}">
                        <button class="min-w-0 flex-1 text-left" wire:click="openEdit({{ $task->id }})">
                            <span @class(['block text-sm font-medium', 'text-muted line-through' => $task->is_done])>
                                @if ($task->priority === 1 && ! $task->is_done)
                                    <span class="chip mr-1 bg-danger/10 px-1.5 py-0 text-danger">Penting</span>
                                @endif
                                {{ $task->title }}
                            </span>
                            <span class="flex flex-wrap items-center gap-x-2 text-xs text-muted">
                                @if ($task->goal)
                                    <span class="inline-flex items-center gap-1"><span class="size-2 rounded-full" style="background-color: {{ $task->goal->color }}"></span>{{ $task->goal->label() }}</span>
                                @endif
                                @if ($task->due_date)
                                    <span @class(['font-semibold text-danger' => $task->isOverdue()])>{{ $task->dueLabel() }}</span>
                                @endif
                                @if ($task->recurrence)
                                    <span class="inline-flex items-center gap-0.5"><x-icon name="repeat" class="size-3" /> {{ Task::RECURRENCES[$task->recurrence] }}</span>
                                @endif
                                @if ($task->notes)
                                    <span class="truncate">{{ str($task->notes)->limit(40) }}</span>
                                @endif
                            </span>
                        </button>
                    </div>
                @endforeach
            </div>
        </section>
    @empty
        <p class="card text-center text-sm text-muted">
            @if (trim($search) !== '')
                Tidak ada tugas yang cocok dengan "{{ $search }}".
            @else
                {{ $filter === 'done' ? 'Belum ada tugas yang selesai.' : 'Semua beres. Tambahkan tugas baru di atas. ✨' }}
            @endif
        </p>
    @endforelse

    <x-sheet :show="$showForm" title="Edit tugas">
        <form wire:submit="update" class="space-y-4">
            <div>
                <label class="label" for="edit-title">Judul</label>
                <input id="edit-title" wire:model="title" class="input">
                @error('title') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="label" for="edit-goal">Target</label>
                    <select id="edit-goal" wire:model="goal_id" class="input">
                        <option value="">Tanpa target</option>
                        @foreach ($this->goals as $goal)
                            <option value="{{ $goal->id }}">{{ $goal->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="label" for="edit-due">Tenggat</label>
                    <input id="edit-due" type="date" wire:model="due_date" class="input">
                    @error('due_date') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="edit-time">Jam (opsional)</label>
                    <input id="edit-time" type="time" wire:model="due_time" class="input">
                    @error('due_time') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="flex items-end pb-3 text-xs text-muted">Notifikasi dikirim 10 menit sebelum jam tenggat.</div>
                <div>
                    <label class="label" for="edit-priority">Prioritas</label>
                    <select id="edit-priority" wire:model="priority" class="input">
                        @foreach (Task::PRIORITIES as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="label" for="edit-recurrence">Ulangi</label>
                    <select id="edit-recurrence" wire:model="recurrence" class="input">
                        <option value="">Tidak berulang</option>
                        @foreach (Task::RECURRENCES as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <p class="text-xs text-muted">Tugas berulang otomatis muncul lagi dengan tenggat berikutnya setiap kali dicentang selesai.</p>
            <div>
                <label class="label" for="edit-notes">Catatan</label>
                <textarea id="edit-notes" wire:model="notes" rows="3" class="input"></textarea>
            </div>
            <div class="flex gap-2 pt-1">
                <button type="button" class="btn btn-danger" wire:click="delete({{ $editingId ?? 0 }})" wire:confirm="Hapus tugas ini?">
                    <x-icon name="trash" class="size-4" />
                </button>
                <button class="btn btn-primary flex-1">Simpan</button>
            </div>
        </form>
    </x-sheet>
</div>
