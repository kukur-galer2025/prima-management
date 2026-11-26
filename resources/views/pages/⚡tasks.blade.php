<?php

use App\Models\Task;
use App\Models\TaskItem;
use App\Services\QuickTaskParser;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    /** Tampilan: daftar atau papan kanban. */
    #[Url(except: 'list')]
    public string $view = 'list';

    /** Kolom kanban tugas yang sedang diedit. */
    public string $column = 'todo';

    public string $newItem = '';

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
        $tasks = $this->taskQuery()
            ->when($this->filter === 'open', fn ($query) => $query->where('is_done', false))
            ->when($this->filter === 'done', fn ($query) => $query->where('is_done', true))
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
            'Hari ini' => $tasks->filter(fn (Task $task) => ! $task->is_done && $task->due_date?->isToday() && ! $task->isOverdue()),
            '7 hari ke depan' => $tasks->filter(fn (Task $task) => ! $task->is_done && $task->due_date?->gt(today()) && $task->due_date->lte($weekAhead)),
            'Nanti' => $tasks->filter(fn (Task $task) => ! $task->is_done && $task->due_date?->gt($weekAhead)),
            'Tanpa tanggal' => $tasks->filter(fn (Task $task) => ! $task->is_done && ! $task->due_date)->sortBy('priority'),
            'Selesai' => $tasks->filter(fn (Task $task) => $task->is_done),
        ])->filter(fn (Collection $group) => $group->isNotEmpty())->all();
    }

    /**
     * Papan kanban: Belum, Dikerjakan, dan Selesai (14 hari terakhir), diurutkan manual lewat drag-and-drop.
     *
     * @return array<string, Collection<int, Task>>
     */
    #[Computed]
    public function board(): array
    {
        $tasks = $this->taskQuery()
            ->where(fn ($query) => $query->where('is_done', false)->orWhere('done_at', '>=', now()->subDays(14)))
            ->orderBy('position')
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->orderBy('priority')
            ->latest('done_at')
            ->get();

        return collect(Task::COLUMNS)
            ->map(fn (string $label, string $column) => $tasks->filter(fn (Task $task) => $task->column() === $column)->values())
            ->all();
    }

    #[Computed]
    public function editingTask(): ?Task
    {
        return $this->editingId ? auth()->user()->tasks()->with('items')->find($this->editingId) : null;
    }

    public function setView(string $view): void
    {
        $this->view = $view === 'board' ? 'board' : 'list';
    }

    /**
     * Dipanggil wire:sort saat kartu dipindah: ke kolom lain dan/atau ke urutan baru.
     */
    public function moveTask(int|string $taskId, int $position, string $column): void
    {
        if (! array_key_exists($column, Task::COLUMNS)) {
            return;
        }

        $task = auth()->user()->tasks()->findOrFail((int) $taskId);
        $justCompleted = $column === 'done' && ! $task->is_done;
        $next = $task->column() !== $column ? $task->moveToColumn($column) : null;

        $siblings = collect($this->board[$column] ?? [])
            ->reject(fn (Task $item) => $item->is($task))
            ->values();
        $siblings->splice(max(0, min($position, $siblings->count())), 0, [$task]);

        $siblings->each(fn (Task $item, int $index) => $item->position === $index ?: $item->updateQuietly(['position' => $index]));

        unset($this->board, $this->groups);

        if ($next) {
            $this->dispatch('toast', message: 'Beres! Muncul lagi '.$next->due_date->translatedFormat('D, d M').' 🔁');
        } elseif ($justCompleted) {
            $this->dispatch('toast', message: 'Satu beres! 🎉');
        }
    }

    public function addItem(): void
    {
        $task = auth()->user()->tasks()->findOrFail($this->editingId);

        $this->validate(['newItem' => ['required', 'string', 'max:200']], [], ['newItem' => 'subtugas']);

        $task->items()->create(['title' => trim($this->newItem), 'position' => (int) $task->items()->max('position') + 1]);

        $this->reset('newItem');
        unset($this->editingTask);
    }

    public function toggleItem(int $itemId): void
    {
        $item = $this->ownedItem($itemId);
        $item->update(['is_done' => ! $item->is_done]);

        unset($this->editingTask);
    }

    public function deleteItem(int $itemId): void
    {
        $this->ownedItem($itemId)->delete();

        unset($this->editingTask);
    }

    /**
     * Pratinjau hasil penguraian judul (tanggal, jam, prioritas, target, pengulangan).
     *
     * @return array{title: string, due_date: ?string, due_time: ?string, priority: ?int, recurrence: ?string, goal_id: ?int, goal_label: ?string, detected: bool}|null
     */
    #[Computed]
    public function quickPreview(): ?array
    {
        if ($this->showForm || blank($this->title)) {
            return null;
        }

        $parsed = app(QuickTaskParser::class)->parse($this->title, $this->goals, now());

        return $parsed['detected'] ? $parsed : null;
    }

    public function quickAdd(): void
    {
        // Isian manual (tanggal, jam, dll.) diutamakan; sisanya diambil dari kalimat judul.
        if ($parsed = $this->quickPreview) {
            $this->title = $parsed['title'];
            $this->due_date = $this->due_date ?: $parsed['due_date'];
            $this->due_time = $this->due_time ?: $parsed['due_time'];
            $this->goal_id = filled($this->goal_id) ? $this->goal_id : $parsed['goal_id'];
            $this->recurrence = $this->recurrence ?: (string) $parsed['recurrence'];
            $this->priority = (int) $this->priority !== 2 ? $this->priority : ($parsed['priority'] ?? 2);
        }

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
        $this->column = $task->column();
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->reset('editingId', 'title', 'due_date', 'due_time', 'notes', 'goal_id', 'priority', 'recurrence', 'column', 'newItem');
        $this->resetValidation();
    }

    public function update(): void
    {
        $validated = $this->validateTask();
        $this->validate(['column' => ['required', Rule::in(array_keys(Task::COLUMNS))]]);

        $task = auth()->user()->tasks()->findOrFail($this->editingId);
        $task->update($validated);
        $next = $task->column() !== $this->column ? $task->moveToColumn($this->column) : null;

        $this->closeForm();
        $this->dispatch('toast', message: $next ? 'Beres! Muncul lagi '.$next->due_date->translatedFormat('D, d M').' 🔁' : 'Tugas diperbarui');
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

    /** Tugas milik pengguna beserta target dan jumlah subtugas (total & selesai), sudah difilter pencarian. */
    private function taskQuery(): HasMany
    {
        $search = trim($this->search);

        return auth()->user()->tasks()
            ->with('goal')
            ->withCount(['items', 'items as items_done_count' => fn ($query) => $query->where('is_done', true)])
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('title', 'like', "%{$search}%")
                ->orWhere('notes', 'like', "%{$search}%")));
    }

    private function ownedItem(int $itemId): TaskItem
    {
        return TaskItem::query()->whereIn('task_id', auth()->user()->tasks()->select('id'))->findOrFail($itemId);
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
    <x-page-header title="Tugas" subtitle="Hal-hal konkret yang harus dibereskan." icon="check">
        <x-slot:actions>
            <div class="flex rounded-2xl border border-line bg-surface p-1" role="group" aria-label="Tampilan">
                @foreach (['list' => ['list', 'Daftar'], 'board' => ['columns', 'Papan']] as $key => [$icon, $label])
                    <button wire:click="setView('{{ $key }}')" aria-pressed="{{ $view === $key ? 'true' : 'false' }}" @class([
                        'flex items-center gap-1.5 rounded-xl px-3 py-1.5 text-sm font-bold transition',
                        'bg-brand text-on-brand' => $view === $key,
                        'text-muted hover:text-ink' => $view !== $key,
                    ])><x-icon :name="$icon" class="size-4" /> {{ $label }}</button>
                @endforeach
            </div>
        </x-slot:actions>
    </x-page-header>

    <form wire:submit="quickAdd" class="card space-y-2 p-3">
        <div class="flex gap-2">
            <input wire:model.live.debounce.400ms="title" class="input min-w-0 flex-1" placeholder="Coba: Daftar ulang besok jam 10 !penting" aria-label="Judul tugas" autocomplete="off" @if ($showForm) disabled @endif>
            <button class="btn btn-primary shrink-0 px-3.5" aria-label="Tambah tugas"><x-icon name="plus" class="size-5" /><span class="hidden sm:inline">Tambah</span></button>
        </div>
        @if (! $showForm) @error('title') <p class="error">{{ $message }}</p> @enderror @endif
        @if ($preview = $this->quickPreview)
            <div class="flex flex-wrap items-center gap-1.5 px-1 text-xs" aria-live="polite">
                <span class="font-semibold text-muted">Terbaca:</span>
                <span class="chip bg-surface-2 text-ink">“{{ $preview['title'] }}”</span>
                @if ($preview['due_date'])
                    <span class="chip bg-brand-soft text-brand-ink">📅 {{ \Illuminate\Support\Carbon::parse($preview['due_date'])->translatedFormat('D, d M') }}{{ $preview['due_time'] ? ' · '.$preview['due_time'] : '' }}</span>
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
        {{-- Di HP: tanggal & jam selalu tampil; target, prioritas, pengulangan dilipat supaya daftar tugas tidak terdorong jauh ke bawah. --}}
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-[10rem_8rem_minmax(0,1fr)]">
            <x-date-input wire:model="due_date" class="py-2 text-sm" placeholder="Tanggal tenggat" aria-label="Tenggat" :disabled="$showForm" />
            <x-date-input type="time" wire:model="due_time" class="py-2 text-sm" placeholder="Jam tenggat" aria-label="Jam tenggat (opsional)" :disabled="$showForm" />
            <input type="checkbox" id="task-more" class="peer sr-only" @checked(filled($goal_id) || (int) $priority !== 2 || filled($recurrence))>
            <label for="task-more" class="col-span-2 flex cursor-pointer items-center justify-center gap-1 rounded-xl py-1 text-xs font-semibold text-muted hover:text-ink sm:hidden peer-checked:[&_svg]:rotate-90">
                Target, prioritas & pengulangan <x-icon name="chevron-right" class="size-3.5 transition" />
            </label>
            <select wire:model="goal_id" class="input col-span-2 min-w-0 py-2 text-sm max-sm:hidden max-sm:peer-checked:block sm:col-span-1" aria-label="Target" @if ($showForm) disabled @endif>
                <option value="">Tanpa target</option>
                @foreach ($this->goals as $goal)
                    <option value="{{ $goal->id }}">{{ $goal->label() }}</option>
                @endforeach
            </select>
            <select wire:model="priority" class="input col-span-2 min-w-0 py-2 text-sm max-sm:hidden max-sm:peer-checked:block" aria-label="Prioritas" @if ($showForm) disabled @endif>
                @foreach (Task::PRIORITIES as $value => $label)
                    <option value="{{ $value }}">Prioritas {{ strtolower($label) }}</option>
                @endforeach
            </select>
            <select wire:model="recurrence" class="input col-span-2 min-w-0 py-2 text-sm max-sm:hidden max-sm:peer-checked:block sm:col-span-1" aria-label="Pengulangan" @if ($showForm) disabled @endif>
                <option value="">Tidak berulang</option>
                @foreach (Task::RECURRENCES as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        @if (! $showForm) @error('due_date') <p class="error">{{ $message }}</p> @enderror @endif
    </form>

    <div class="space-y-3">
        <label class="relative block">
            <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted" />
            <input type="search" wire:model.live.debounce.300ms="search" class="input py-2 pl-9 text-sm" placeholder="Cari tugas atau catatannya…" aria-label="Cari tugas">
        </label>
        @if ($view === 'list')
            <div class="flex gap-2">
                @foreach (['open' => 'Belum selesai', 'done' => 'Selesai', 'all' => 'Semua'] as $key => $label)
                    <button wire:click="$set('filter', '{{ $key }}')" @class([
                        'chip px-3.5 py-1.5 text-sm',
                        'bg-brand text-on-brand' => $filter === $key,
                        'bg-surface-2 text-muted' => $filter !== $key,
                    ])>{{ $label }}</button>
                @endforeach
            </div>
        @endif
    </div>

    @if ($view === 'board')
        {{-- Papan kanban: geser kartu antar-kolom (di HP kolom bisa digeser ke samping) --}}
        <div class="-mx-4 flex snap-x snap-mandatory gap-3 overflow-x-auto px-4 pb-2 md:mx-0 md:grid md:grid-cols-3 md:overflow-visible md:px-0">
            @foreach ($this->board as $columnKey => $columnTasks)
                <section wire:key="column-{{ $columnKey }}" class="w-[78vw] max-w-xs shrink-0 snap-start rounded-3xl bg-surface-2/70 p-2.5 md:w-auto md:max-w-none">
                    <h2 class="mb-2 flex items-center justify-between px-1.5 text-sm font-bold">
                        <span class="flex items-center gap-2">
                            <span @class(['size-2.5 rounded-full', 'bg-muted' => $columnKey === 'todo', 'bg-brand' => $columnKey === 'doing', 'bg-success' => $columnKey === 'done'])></span>
                            {{ Task::COLUMNS[$columnKey] }}
                        </span>
                        <span class="chip bg-surface px-2 py-0 text-muted">{{ $columnTasks->count() }}</span>
                    </h2>
                    <div class="relative">
                    @if ($columnTasks->isEmpty())
                        <p class="pointer-events-none absolute inset-0 grid place-items-center rounded-2xl border-2 border-dashed border-line text-xs text-muted">Seret kartu ke sini</p>
                    @endif
                    <div class="relative flex min-h-24 flex-col gap-2" wire:sort="moveTask" wire:sort:group="tasks" wire:sort:group-id="{{ $columnKey }}">
                        @foreach ($columnTasks as $task)
                            <div wire:key="card-{{ $task->id }}" wire:sort:item="{{ $task->id }}"
                                class="cursor-grab rounded-2xl border border-line/70 bg-surface p-3 shadow-sm active:cursor-grabbing">
                                <button type="button" class="block w-full text-left" wire:click="openEdit({{ $task->id }})">
                                    <span @class(['block text-sm font-semibold', 'text-muted line-through' => $task->is_done])>
                                        @if ($task->priority === 1 && ! $task->is_done)
                                            <span class="chip mr-1 bg-danger/10 px-1.5 py-0 text-danger">Penting</span>
                                        @endif
                                        {{ $task->title }}
                                    </span>
                                    <span class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted">
                                        @if ($task->due_date)
                                            <span @class(['font-semibold text-danger' => $task->isOverdue()])>📅 {{ $task->dueLabel('d M') }}</span>
                                        @endif
                                        @if ($task->items_count)
                                            <span @class(['font-semibold', 'text-success' => $task->items_done_count === $task->items_count])>☑ {{ $task->items_done_count }}/{{ $task->items_count }}</span>
                                        @endif
                                        @if ($task->goal)
                                            <span class="inline-flex items-center gap-1"><span class="size-2 rounded-full" style="background-color: {{ $task->goal->color }}"></span>{{ $task->goal->label() }}</span>
                                        @endif
                                    </span>
                                </button>
                            </div>
                        @endforeach
                    </div>
                    </div>
                </section>
            @endforeach
        </div>
        <p class="text-center text-xs text-muted">Seret kartu untuk memindahkan atau mengurutkan. Kolom Selesai menampilkan 14 hari terakhir.</p>
    @else
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
                                @if ($task->column() === 'doing')
                                    <span class="font-semibold text-brand">● Dikerjakan</span>
                                @endif
                                @if ($task->items_count)
                                    <span @class(['font-semibold', 'text-success' => $task->items_done_count === $task->items_count])>☑ {{ $task->items_done_count }}/{{ $task->items_count }}</span>
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
    @endif

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
                    <x-date-input id="edit-due" wire:model="due_date" />
                    @error('due_date') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="edit-time">Jam tenggat (opsional)</label>
                    <x-date-input type="time" id="edit-time" wire:model="due_time" placeholder="mis. 23:59" />
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
                <span class="label">Status</span>
                <div class="grid grid-cols-3 gap-1.5 rounded-2xl bg-surface-2 p-1">
                    @foreach (Task::COLUMNS as $value => $label)
                        <button type="button" wire:click="$set('column', '{{ $value }}')" @class([
                            'rounded-xl py-2 text-sm font-bold transition',
                            'bg-surface text-ink shadow-sm' => $column === $value,
                            'text-muted' => $column !== $value,
                        ])>{{ $label }}</button>
                    @endforeach
                </div>
            </div>

            {{-- Subtugas: disimpan langsung, tidak menunggu tombol Simpan --}}
            <div>
                @php $items = $this->editingTask?->items ?? collect(); @endphp
                <span class="label">Subtugas @if ($items->isNotEmpty()) <span class="font-normal text-muted">{{ $items->where('is_done', true)->count() }}/{{ $items->count() }}</span> @endif</span>
                @if ($items->isNotEmpty())
                    <x-progress class="mb-2" :value="(int) round($items->where('is_done', true)->count() / $items->count() * 100)" />
                    <ul class="mb-2 divide-y divide-line rounded-2xl border border-line">
                        @foreach ($items as $item)
                            <li wire:key="item-{{ $item->id }}" class="flex items-center gap-2 px-3 py-2">
                                <input type="checkbox" class="size-4 shrink-0 accent-brand" wire:click="toggleItem({{ $item->id }})" @checked($item->is_done) aria-label="Centang {{ $item->title }}">
                                <span @class(['min-w-0 flex-1 text-sm', 'text-muted line-through' => $item->is_done])>{{ $item->title }}</span>
                                <button type="button" class="rounded-lg p-1 text-muted hover:bg-danger/10 hover:text-danger" wire:click="deleteItem({{ $item->id }})" aria-label="Hapus subtugas"><x-icon name="x" class="size-4" /></button>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <div class="flex gap-2">
                    <input wire:model="newItem" wire:keydown.enter.prevent="addItem" class="input py-2 text-sm" placeholder="Tambah subtugas…" aria-label="Subtugas baru">
                    <button type="button" class="btn btn-ghost shrink-0 px-3 py-2" wire:click="addItem" aria-label="Tambah subtugas"><x-icon name="plus" class="size-4" /></button>
                </div>
                @error('newItem') <p class="error">{{ $message }}</p> @enderror
            </div>

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
