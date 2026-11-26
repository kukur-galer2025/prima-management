<?php

use App\Models\Goal;
use App\Models\Group;
use App\Models\StudySession;
use App\Models\User;
use App\Services\Reminders;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Grup')] class extends Component
{
    /** Grup yang sedang dibuka. */
    #[Url(except: null)]
    public ?int $group = null;

    public string $newGroupName = '';

    public string $joinCode = '';

    public bool $showSettings = false;

    public string $renameTo = '';

    public function mount(): void
    {
        $this->group = $this->myGroups->firstWhere('id', $this->group)?->id ?? $this->myGroups->first()?->id;
    }

    #[Computed]
    public function myGroups(): Collection
    {
        return auth()->user()->groups()->withCount('memberships')->orderBy('name')->get();
    }

    #[Computed]
    public function current(): ?Group
    {
        return $this->group ? auth()->user()->groups()->find($this->group) : null;
    }

    #[Computed]
    public function leaderboard(): Collection
    {
        return $this->current?->leaderboard() ?? collect();
    }

    /** @return Collection<int, array{user: User, goals: Collection<int, Goal>}> anggota yang membagikan progres targetnya */
    #[Computed]
    public function sharedGoals(): Collection
    {
        return $this->leaderboard
            ->filter(fn (array $row) => $row['shareGoals'])
            ->map(fn (array $row) => [
                'user' => $row['user'],
                'goals' => $row['user']->goals()->where('status', 'active')->withStats()->orderBy('priority')->limit(5)->get(),
            ])
            ->filter(fn (array $row) => $row['goals']->isNotEmpty())
            ->values();
    }

    #[Computed]
    public function isOwner(): bool
    {
        return (bool) $this->current?->isOwnedBy(auth()->user());
    }

    #[Computed]
    public function mySharing(): bool
    {
        return (bool) $this->current?->memberships()->where('user_id', auth()->id())->value('share_goals');
    }

    public function selectGroup(int $groupId): void
    {
        $this->group = auth()->user()->groups()->findOrFail($groupId)->id;
        $this->showSettings = false;
        $this->forgetGroupData();
    }

    public function createGroup(): void
    {
        $validated = $this->validate(['newGroupName' => ['required', 'string', 'max:60']], [], ['newGroupName' => 'nama grup']);

        $group = Group::createFor(auth()->user(), trim($validated['newGroupName']));

        $this->reset('newGroupName');
        $this->group = $group->id;
        unset($this->myGroups);
        $this->forgetGroupData();
        $this->dispatch('toast', message: 'Grup dibuat. Bagikan kodenya: '.$group->invite_code);
    }

    public function joinGroup(): void
    {
        $throttleKey = 'join-group|'.auth()->id();

        if (RateLimiter::tooManyAttempts($throttleKey, 10)) {
            $this->addError('joinCode', 'Terlalu banyak percobaan. Coba lagi beberapa menit lagi.');

            return;
        }

        $this->validate(['joinCode' => ['required', 'string', 'max:12']], [], ['joinCode' => 'kode']);
        RateLimiter::hit($throttleKey, 600);

        $group = Group::query()->where('invite_code', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $this->joinCode)))->withCount('memberships')->first();

        if (! $group) {
            $this->addError('joinCode', 'Kode tidak ditemukan. Periksa lagi huruf dan angkanya.');

            return;
        }

        if (! $group->memberships()->where('user_id', auth()->id())->exists()) {
            if ($group->memberships_count >= Group::MAX_MEMBERS) {
                $this->addError('joinCode', 'Grup ini sudah penuh ('.Group::MAX_MEMBERS.' anggota).');

                return;
            }

            $group->memberships()->create(['user_id' => auth()->id(), 'role' => 'member']);
        }

        RateLimiter::clear($throttleKey);
        $this->reset('joinCode');
        $this->group = $group->id;
        unset($this->myGroups);
        $this->forgetGroupData();
        $this->dispatch('toast', message: 'Bergabung ke '.$group->name.' 🎉');
    }

    public function toggleSharing(): void
    {
        $membership = $this->current?->memberships()->where('user_id', auth()->id())->firstOrFail();
        $membership->update(['share_goals' => ! $membership->share_goals]);

        $this->forgetGroupData();
        $this->dispatch('toast', message: $membership->share_goals ? 'Progres targetmu kini terlihat di grup ini' : 'Progres targetmu disembunyikan dari grup ini');
    }

    /** Kirim semangat ke anggota lain lewat notifikasi/Telegram, sekali per anggota per hari. */
    public function cheer(int $userId): void
    {
        $group = $this->current;
        abort_unless($group, 404);

        $member = $group->members()->whereKey($userId)->firstOrFail();

        if ($member->is(auth()->user())) {
            return;
        }

        $date = today()->toDateString();
        $reminder = $member->reminders()->firstOrCreate(['key' => "cheer:{$group->id}:".auth()->id().":{$date}"], [
            'title' => '👏 '.auth()->user()->name.' menyemangatimu',
            'body' => "Dari grup {$group->name}. Ayo lanjutkan fokusmu hari ini!",
            'url' => route('groups', ['group' => $group->id], false),
        ]);

        if (! $reminder->wasRecentlyCreated) {
            $this->dispatch('toast', message: 'Kamu sudah menyemangati '.$member->name.' hari ini');

            return;
        }

        $reminders = app(Reminders::class);
        $reminders->push($member->load('pushSubscriptions'));
        $reminders->sendToTelegram($member, $reminder);

        $this->dispatch('toast', message: 'Semangat terkirim ke '.$member->name.' 👏');
    }

    public function leaveGroup(): void
    {
        $group = $this->current;
        abort_unless($group, 404);

        $name = $group->name;
        $group->removeMember(auth()->user());

        unset($this->myGroups);
        $this->group = $this->myGroups->first()?->id;
        $this->forgetGroupData();
        $this->dispatch('toast', message: 'Kamu keluar dari '.$name);
    }

    public function openSettings(): void
    {
        abort_unless($this->isOwner, 403);

        $this->renameTo = $this->current->name;
        $this->showSettings = true;
    }

    public function closeSettings(): void
    {
        $this->showSettings = false;
    }

    public function renameGroup(): void
    {
        abort_unless($this->isOwner, 403);

        $validated = $this->validate(['renameTo' => ['required', 'string', 'max:60']], [], ['renameTo' => 'nama grup']);
        $this->current->update(['name' => trim($validated['renameTo'])]);

        unset($this->myGroups, $this->current);
        $this->showSettings = false;
        $this->dispatch('toast', message: 'Nama grup diperbarui');
    }

    public function regenerateCode(): void
    {
        abort_unless($this->isOwner, 403);

        $this->current->update(['invite_code' => Group::newInviteCode()]);

        unset($this->current);
        $this->dispatch('toast', message: 'Kode baru dibuat; kode lama tidak berlaku lagi');
    }

    public function removeMember(int $userId): void
    {
        abort_unless($this->isOwner, 403);

        $member = $this->current->members()->whereKey($userId)->firstOrFail();

        if ($member->is(auth()->user())) {
            return;
        }

        $this->current->removeMember($member);

        $this->forgetGroupData();
        $this->dispatch('toast', message: $member->name.' dikeluarkan dari grup');
    }

    public function deleteGroup(): void
    {
        abort_unless($this->isOwner, 403);

        $name = $this->current->name;
        $this->current->delete();

        unset($this->myGroups);
        $this->group = $this->myGroups->first()?->id;
        $this->showSettings = false;
        $this->forgetGroupData();
        $this->dispatch('toast', message: 'Grup '.$name.' dihapus');
    }

    private function forgetGroupData(): void
    {
        unset($this->current, $this->leaderboard, $this->sharedGoals, $this->isOwner, $this->mySharing);
    }
};
?>

@php
    $current = $this->current;
    $leaderboard = $this->leaderboard;
    $maxMinutes = max(1, $leaderboard->max('minutes') ?? 1);
@endphp

<div class="space-y-5">
    <x-page-header title="Grup" subtitle="Saling menyemangati bersama teman atau kelompok belajar." icon="users" />

    @if ($this->myGroups->isNotEmpty())
        <div class="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1">
            @foreach ($this->myGroups as $item)
                <button wire:key="group-tab-{{ $item->id }}" wire:click="selectGroup({{ $item->id }})" @class([
                    'chip shrink-0 px-3.5 py-1.5 text-sm',
                    'bg-brand text-on-brand' => $group === $item->id,
                    'bg-surface-2 text-muted hover:text-ink' => $group !== $item->id,
                ])>{{ $item->name }} <span class="opacity-70">· {{ $item->memberships_count }}</span></button>
            @endforeach
        </div>
    @endif

    @if ($current)
        <section class="hero">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-sm font-semibold opacity-80">Minggu ini · {{ today()->startOfWeek(1)->translatedFormat('d M') }} – {{ today()->endOfWeek(7)->translatedFormat('d M') }}</p>
                    <h2 class="mt-1 truncate text-2xl font-extrabold tracking-tight">{{ $current->name }}</h2>
                    <p class="mt-1 text-sm opacity-90">Total fokus grup <b>{{ StudySession::formatMinutes((int) $leaderboard->sum('minutes')) }}</b> · {{ $leaderboard->count() }} anggota</p>
                </div>
                <div x-data="{ copied: false }" class="rounded-2xl p-3 text-center" style="background-color: rgb(255 255 255 / 0.14)">
                    <p class="text-[11px] font-bold tracking-wider uppercase opacity-80">Kode undangan</p>
                    <button type="button" class="font-mono text-xl font-extrabold tracking-[0.2em]" x-on:click="navigator.clipboard?.writeText(@js($current->invite_code)); copied = true; setTimeout(() => copied = false, 2000)">
                        {{ $current->invite_code }}
                    </button>
                    <p class="text-[11px] opacity-80" x-text="copied ? 'Tersalin!' : 'Ketuk untuk menyalin'">Ketuk untuk menyalin</p>
                </div>
            </div>
        </section>

        {{-- Papan peringkat --}}
        <section class="card">
            <h2 class="section-title mb-3">Papan fokus minggu ini</h2>
            <div class="space-y-3">
                @foreach ($leaderboard as $index => $row)
                    @php $isMe = $row['user']->is(auth()->user()); @endphp
                    <div wire:key="rank-{{ $row['user']->id }}" class="flex items-center gap-3">
                        <span @class([
                            'grid size-8 shrink-0 place-items-center rounded-full text-sm font-extrabold',
                            'bg-amber-400/20 text-amber-600' => $index === 0 && $row['minutes'] > 0,
                            'bg-surface-2 text-muted' => $index > 0 || $row['minutes'] === 0,
                        ])>{{ $index === 0 && $row['minutes'] > 0 ? '🏆' : $index + 1 }}</span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-2 text-sm">
                                <span class="truncate font-bold">
                                    {{ $row['user']->name }}
                                    @if ($isMe) <span class="chip ml-1 bg-brand-soft px-1.5 py-0 text-brand-ink">Kamu</span> @endif
                                    @if ($row['role'] === 'owner') <span class="chip ml-1 bg-surface-2 px-1.5 py-0 text-muted">Pemilik</span> @endif
                                </span>
                                <span class="shrink-0 font-extrabold">{{ StudySession::formatMinutes($row['minutes']) }}</span>
                            </div>
                            <div class="mt-1 h-2 overflow-hidden rounded-full bg-surface-2">
                                <div @class(['h-full rounded-full', 'bg-linear-to-r from-brand to-brand-2' => $isMe, 'bg-brand/40' => ! $isMe])
                                    style="width: {{ $row['minutes'] ? max(3, round($row['minutes'] / $maxMinutes * 100)) : 0 }}%"></div>
                            </div>
                            <p class="mt-1 text-xs text-muted">{{ $row['activeDays'] }} hari aktif{{ $row['streak'] ? ' · 🔥 '.$row['streak'].' hari beruntun' : '' }}</p>
                        </div>
                        @unless ($isMe)
                            <button class="btn btn-ghost shrink-0 px-3 py-2 text-sm" wire:click="cheer({{ $row['user']->id }})" title="Kirim semangat" aria-label="Semangati {{ $row['user']->name }}">👏</button>
                        @endunless
                    </div>
                @endforeach
            </div>
            <p class="mt-4 text-xs text-muted">Yang terlihat oleh anggota grup hanya total menit fokus, hari aktif, dan streak. Isi sesi, tugas, keuangan, dan catatanmu tetap pribadi.</p>
        </section>

        {{-- Progres target yang dibagikan --}}
        <section class="card">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="section-title">Progres target</h2>
                <label class="flex cursor-pointer items-center gap-2 text-sm font-semibold">
                    <input type="checkbox" class="size-4 accent-brand" wire:click="toggleSharing" @checked($this->mySharing)>
                    Bagikan progres targetku
                </label>
            </div>
            @forelse ($this->sharedGoals as $row)
                <div wire:key="shared-{{ $row['user']->id }}" class="mb-4 last:mb-0">
                    <p class="mb-2 text-sm font-bold">{{ $row['user']->name }}</p>
                    <div class="space-y-2">
                        @foreach ($row['goals'] as $goal)
                            <div wire:key="shared-goal-{{ $goal->id }}">
                                <div class="mb-1 flex items-center justify-between gap-2 text-xs">
                                    <span class="truncate font-semibold">{{ $goal->label() }}</span>
                                    <span class="shrink-0 font-bold" style="color: {{ $goal->color }}">{{ $goal->progress() }}%</span>
                                </div>
                                <x-progress :value="$goal->progress()" :color="$goal->color" />
                            </div>
                        @endforeach
                    </div>
                </div>
            @empty
                <p class="text-sm text-muted">Belum ada anggota yang membagikan progres targetnya. Centang "Bagikan progres targetku" untuk memulai. Yang terlihat hanya nama target aktif dan persentasenya.</p>
            @endforelse
        </section>

        <div class="flex flex-wrap gap-2">
            @if ($this->isOwner)
                <button class="btn btn-ghost flex-1" wire:click="openSettings"><x-icon name="pencil" class="size-4" /> Kelola grup</button>
            @endif
            <button class="btn btn-ghost flex-1 text-danger" wire:click="leaveGroup"
                wire:confirm="{{ $this->isOwner && $leaderboard->count() > 1 ? 'Keluar dari grup? Kepemilikan dipindahkan ke anggota terlama.' : ($leaderboard->count() === 1 ? 'Keluar dari grup? Grup akan dihapus karena tidak ada anggota lain.' : 'Keluar dari grup ini?') }}">
                <x-icon name="logout" class="size-4" /> Keluar dari grup
            </button>
        </div>
    @endif

    {{-- Buat / gabung --}}
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <form wire:submit="createGroup" class="card space-y-3">
            <h2 class="section-title">Buat grup baru</h2>
            <input wire:model="newGroupName" class="input" placeholder="mis. Pejuang TOEFL 2027" aria-label="Nama grup">
            @error('newGroupName') <p class="error">{{ $message }}</p> @enderror
            <button class="btn btn-primary w-full"><x-icon name="plus" class="size-4" /> Buat grup</button>
        </form>
        <form wire:submit="joinGroup" class="card space-y-3">
            <h2 class="section-title">Gabung dengan kode</h2>
            <input wire:model="joinCode" class="input font-mono uppercase tracking-widest" placeholder="ABCD2345" autocomplete="off" aria-label="Kode undangan">
            @error('joinCode') <p class="error">{{ $message }}</p> @enderror
            <button class="btn btn-ghost w-full">Gabung</button>
        </form>
    </div>

    @if ($current && $this->isOwner)
        <x-sheet :show="$showSettings" title="Kelola grup" close="closeSettings">
            <div class="space-y-5">
                <form wire:submit="renameGroup" class="space-y-2">
                    <label class="label" for="renameTo">Nama grup</label>
                    <div class="flex gap-2">
                        <input id="renameTo" wire:model="renameTo" class="input">
                        <button class="btn btn-primary shrink-0">Simpan</button>
                    </div>
                    @error('renameTo') <p class="error">{{ $message }}</p> @enderror
                </form>

                <div>
                    <p class="label">Kode undangan: <span class="font-mono font-bold text-ink">{{ $current->invite_code }}</span></p>
                    <button type="button" class="btn btn-ghost w-full" wire:click="regenerateCode" wire:confirm="Buat kode baru? Kode lama tidak bisa dipakai lagi.">Buat kode baru</button>
                </div>

                <div>
                    <p class="label">Anggota</p>
                    <div class="divide-y divide-line rounded-2xl border border-line">
                        @foreach ($leaderboard as $row)
                            <div wire:key="manage-{{ $row['user']->id }}" class="flex items-center justify-between gap-2 px-3 py-2 text-sm">
                                <span class="truncate">{{ $row['user']->name }}</span>
                                @if (! $row['user']->is(auth()->user()))
                                    <button type="button" class="text-xs font-bold text-danger hover:underline" wire:click="removeMember({{ $row['user']->id }})" wire:confirm="Keluarkan {{ $row['user']->name }} dari grup?">Keluarkan</button>
                                @else
                                    <span class="text-xs text-muted">Pemilik</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>

                <button type="button" class="btn btn-danger w-full" wire:click="deleteGroup" wire:confirm="Hapus grup ini untuk semua anggota? Data pribadi anggota tidak ikut terhapus.">
                    <x-icon name="trash" class="size-4" /> Hapus grup
                </button>
            </div>
        </x-sheet>
    @endif
</div>
