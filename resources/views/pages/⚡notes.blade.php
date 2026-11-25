<?php

use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Catatan')] class extends Component
{
    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'goal', except: null)]
    public int|string|null $goalFilter = null;

    /** Dipakai halaman lain untuk langsung membuka catatan: /catatan?open=ID */
    #[Url(except: null)]
    public ?int $open = null;

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $title = '';

    public string $body = '';

    public int|string|null $goal_id = null;

    public bool $is_pinned = false;

    public function mount(): void
    {
        $this->updatedGoalFilter();

        if ($this->open) {
            $this->openEdit($this->open);
        }
    }

    public function updatedGoalFilter(): void
    {
        $this->goalFilter = filled($this->goalFilter) ? (int) $this->goalFilter : null;
    }

    #[Computed]
    public function notes(): Collection
    {
        $search = trim($this->search);

        return auth()->user()->notes()
            ->with('goal')
            ->when($this->goalFilter, fn ($query) => $query->where('goal_id', $this->goalFilter))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('title', 'like', "%{$search}%")
                ->orWhere('body', 'like', "%{$search}%")))
            ->orderByDesc('is_pinned')
            ->latest('updated_at')
            ->get();
    }

    #[Computed]
    public function goals(): Collection
    {
        return auth()->user()->goals()->orderBy('priority')->orderBy('name')->get();
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->goal_id = $this->goalFilter ?: null;
        $this->showForm = true;
    }

    public function openEdit(int $noteId): void
    {
        $note = auth()->user()->notes()->findOrFail($noteId);

        $this->resetForm();
        $this->editingId = $note->id;
        $this->title = $note->title;
        $this->body = (string) $note->body;
        $this->goal_id = $note->goal?->trashed() ? null : $note->goal_id;
        $this->is_pinned = $note->is_pinned;
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->open = null;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'max:150'],
            'body' => ['nullable', 'string', 'max:20000'],
            'goal_id' => ['nullable', Rule::exists('goals', 'id')->where('user_id', auth()->id())->whereNull('deleted_at')],
            'is_pinned' => ['boolean'],
        ], [], ['title' => 'judul', 'body' => 'isi catatan', 'goal_id' => 'target']);

        $attributes = [
            'title' => trim($validated['title']),
            'body' => $validated['body'] ?: null,
            'goal_id' => filled($validated['goal_id']) ? (int) $validated['goal_id'] : null,
            'is_pinned' => $validated['is_pinned'],
        ];

        if ($this->editingId) {
            auth()->user()->notes()->findOrFail($this->editingId)->update($attributes);
        } else {
            auth()->user()->notes()->create($attributes);
        }

        $this->closeForm();
        $this->dispatch('toast', message: 'Catatan disimpan');
    }

    public function togglePin(int $noteId): void
    {
        $note = auth()->user()->notes()->findOrFail($noteId);
        $note->update(['is_pinned' => ! $note->is_pinned]);
    }

    public function delete(int $noteId): void
    {
        auth()->user()->notes()->findOrFail($noteId)->delete();

        $this->closeForm();
        $this->dispatch('toast', message: 'Catatan dihapus');
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->reset(['editingId', 'title', 'body', 'goal_id', 'is_pinned']);
    }
};
?>

<div class="space-y-5">
    <x-page-header title="Catatan" subtitle="Ringkasan materi, ide, link, dan apa saja." icon="note">
        <x-slot:actions>
            <button class="btn btn-primary" wire:click="openCreate">
                <x-icon name="plus" class="size-4" /> Catatan
            </button>
        </x-slot:actions>
    </x-page-header>

    <div class="flex gap-2">
        <label class="relative block min-w-0 flex-1">
            <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted" />
            <input type="search" wire:model.live.debounce.300ms="search" class="input py-2 pl-9 text-sm" placeholder="Cari catatan…" aria-label="Cari catatan">
        </label>
        <select wire:model.live="goalFilter" class="input w-40 shrink-0 py-2 text-sm" aria-label="Filter target">
            <option value="">Semua target</option>
            @foreach ($this->goals as $goal)
                <option value="{{ $goal->id }}">{{ $goal->label() }}</option>
            @endforeach
        </select>
    </div>

    <div class="grid gap-3 sm:grid-cols-2">
        @forelse ($this->notes as $note)
            <div wire:key="note-{{ $note->id }}" class="card relative flex flex-col">
                <button class="absolute top-3 right-3 rounded-lg p-1.5 hover:bg-surface-2" wire:click="togglePin({{ $note->id }})"
                    aria-label="{{ $note->is_pinned ? 'Lepas sematan' : 'Sematkan' }}" title="{{ $note->is_pinned ? 'Lepas sematan' : 'Sematkan' }}">
                    <x-icon name="pin" @class(['size-4', 'text-brand' => $note->is_pinned, 'text-muted' => !$note->is_pinned]) />
                </button>
                <button class="flex flex-1 flex-col text-left" wire:click="openEdit({{ $note->id }})">
                    <p class="pr-8 font-bold leading-snug">{{ $note->title }}</p>
                    @if ($note->body)
                        <p class="mt-1 line-clamp-4 whitespace-pre-line text-sm text-muted">{{ $note->body }}</p>
                    @endif
                    <span class="mt-auto flex flex-wrap items-center gap-2 pt-3 text-xs text-muted">
                        @if ($note->goal)
                            <span class="inline-flex items-center gap-1"><span class="size-2 rounded-full" style="background-color: {{ $note->goal->color }}"></span>{{ $note->goal->label() }}</span>
                        @endif
                        <span>{{ $note->updated_at->diffForHumans() }}</span>
                    </span>
                </button>
            </div>
        @empty
            <p class="card text-center text-sm text-muted sm:col-span-2">
                {{ trim($search) !== '' || $goalFilter ? 'Tidak ada catatan yang cocok.' : 'Belum ada catatan. Tulis yang pertama!' }}
            </p>
        @endforelse
    </div>

    <x-sheet :show="$showForm" :title="$editingId ? 'Edit catatan' : 'Catatan baru'">
        <form wire:submit="save" class="space-y-4">
            <div>
                <label class="label" for="note-title">Judul</label>
                <input id="note-title" wire:model="title" class="input" placeholder="mis. Rumus cepat deret aritmetika" required>
                @error('title') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="note-body">Isi</label>
                <textarea id="note-body" wire:model="body" rows="10" class="input" placeholder="Tulis bebas…"></textarea>
                @error('body') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div class="grid grid-cols-[1fr_auto] items-end gap-3">
                <div>
                    <label class="label" for="note-goal">Terkait target</label>
                    <select id="note-goal" wire:model="goal_id" class="input">
                        <option value="">Tidak ada</option>
                        @foreach ($this->goals as $goal)
                            <option value="{{ $goal->id }}">{{ $goal->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <label class="flex items-center gap-2 pb-3 text-sm font-medium text-muted">
                    <input type="checkbox" wire:model="is_pinned" class="size-4 accent-brand"> Sematkan
                </label>
            </div>
            <div class="flex gap-2 pt-1">
                @if ($editingId)
                    <button type="button" class="btn btn-danger" wire:click="delete({{ $editingId }})" wire:confirm="Hapus catatan ini?">
                        <x-icon name="trash" class="size-4" />
                    </button>
                @endif
                <button class="btn btn-primary flex-1" wire:loading.attr="disabled">Simpan</button>
            </div>
        </form>
    </x-sheet>
</div>
