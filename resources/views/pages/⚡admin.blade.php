<?php

use App\Models\AdminActivity;
use App\Models\User;
use App\Notifications\AccountInvitation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Admin')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: 'all')]
    public string $status = 'all';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public bool $is_admin = false;

    public bool $sendInvitation = true;

    /**
     * Dicek di setiap request (termasuk aksi Livewire yang tidak melewati middleware route).
     */
    public function boot(): void
    {
        Gate::authorize('admin');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    /** @return array{total: int, active: int, disabled: int, admins: int, storage: int} */
    #[Computed]
    public function stats(): array
    {
        return [
            'total' => User::query()->count(),
            'active' => User::query()->where('last_active_at', '>=', now()->subDays(7))->count(),
            'disabled' => User::query()->whereNotNull('disabled_at')->count(),
            'admins' => User::query()->where('is_admin', true)->count(),
            'storage' => (int) DB::table('note_attachments')->sum('size'),
        ];
    }

    #[Computed]
    public function users()
    {
        $search = trim($this->search);

        return User::query()
            ->withSum('noteAttachments as storage_used', 'size')
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->when($this->status === 'active', fn ($query) => $query->whereNull('disabled_at'))
            ->when($this->status === 'disabled', fn ($query) => $query->whereNotNull('disabled_at'))
            ->when($this->status === 'admin', fn ($query) => $query->where('is_admin', true))
            ->orderByDesc('is_admin')
            ->orderBy('name')
            ->paginate(20);
    }

    #[Computed]
    public function activities(): Collection
    {
        return AdminActivity::query()->latest('created_at')->latest('id')->limit(30)->get();
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function openEdit(int $userId): void
    {
        $user = User::query()->findOrFail($userId);

        $this->resetForm();
        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->is_admin = $user->is_admin;
        $this->sendInvitation = false;
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
    }

    public function save(): void
    {
        $this->email = strtolower(trim($this->email));

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editingId)],
            // Akun baru tanpa undangan email wajib diberi kata sandi awal.
            'password' => [$this->editingId || $this->sendInvitation ? 'nullable' : 'required', 'string', PasswordRule::min(8)],
            'is_admin' => ['boolean'],
        ], ['password.required' => 'Isi kata sandi awal, atau centang kirim email undangan.'], ['name' => 'nama', 'password' => 'kata sandi']);

        if ($this->editingId) {
            $this->updateUser(User::query()->findOrFail($this->editingId), $validated);

            return;
        }

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            // Tanpa kata sandi awal, akun diberi kata sandi acak yang diganti pengguna lewat email undangan.
            'password' => $validated['password'] ?: Str::password(32),
        ]);
        $user->forceFill(['email_verified_at' => now(), 'is_admin' => $validated['is_admin']])->save();

        $invited = $this->sendInvitation && $this->mailSent(
            fn () => $user->notify(new AccountInvitation(Password::broker()->createToken($user), auth()->user()->name)),
        );

        AdminActivity::record(auth()->user(), $user, $invited ? 'invited' : 'created', $user->is_admin ? ['admin' => true] : null);

        unset($this->stats, $this->activities);
        $this->closeForm();
        $this->dispatch('toast', message: match (true) {
            $invited => "Akun dibuat, undangan dikirim ke {$user->email}",
            $this->sendInvitation => 'Akun dibuat, tapi email undangan GAGAL terkirim. Buka akun itu lalu isi kata sandinya.',
            default => "Akun {$user->email} dibuat",
        });
    }

    /** Email yang gagal (SMTP salah / mati) dilaporkan, tapi tidak boleh membuat halaman error. */
    private function mailSent(Closure $send): bool
    {
        try {
            $send();

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    public function toggleDisabled(int $userId): void
    {
        $user = User::query()->findOrFail($userId);

        if ($user->is(auth()->user())) {
            $this->dispatch('toast', message: 'Kamu tidak bisa menonaktifkan akunmu sendiri');

            return;
        }

        $user->forceFill(['disabled_at' => $user->isDisabled() ? null : now()])->save();

        // Keluarkan dari semua perangkat yang sedang login.
        if ($user->isDisabled()) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->forceFill(['remember_token' => Str::random(60)])->save();
        }

        AdminActivity::record(auth()->user(), $user, $user->isDisabled() ? 'disabled' : 'enabled');

        unset($this->stats, $this->activities);
        $this->dispatch('toast', message: $user->isDisabled() ? "{$user->name} dinonaktifkan" : "{$user->name} diaktifkan lagi");
    }

    public function sendResetLink(int $userId): void
    {
        $user = User::query()->findOrFail($userId);
        $status = null;

        if (! $this->mailSent(function () use ($user, &$status) {
            $status = Password::sendResetLink(['email' => $user->email]);
        })) {
            $this->dispatch('toast', message: 'Email gagal terkirim. Periksa pengaturan SMTP.');

            return;
        }

        if ($status === Password::RESET_LINK_SENT) {
            AdminActivity::record(auth()->user(), $user, 'reset_link_sent');
            unset($this->activities);
        }

        $this->dispatch('toast', message: $status === Password::RESET_LINK_SENT
            ? "Tautan atur ulang kata sandi dikirim ke {$user->email}"
            : 'Tunggu sebentar sebelum mengirim tautan lagi');
    }

    public function deleteUser(int $userId): void
    {
        $user = User::query()->findOrFail($userId);

        if ($user->is(auth()->user())) {
            $this->dispatch('toast', message: 'Hapus akunmu sendiri lewat halaman Akun');

            return;
        }

        $name = $user->name;
        AdminActivity::record(auth()->user(), $user, 'deleted');
        $user->deleteAccount();

        unset($this->stats, $this->activities);
        $this->closeForm();
        $this->dispatch('toast', message: "Akun {$name} beserta datanya dihapus");
    }

    /**
     * @param  array{name: string, email: string, password: ?string, is_admin: bool}  $validated
     */
    private function updateUser(User $user, array $validated): void
    {
        if ($user->is(auth()->user()) && ! $validated['is_admin']) {
            $this->addError('is_admin', 'Kamu tidak bisa mencabut status admin dari akunmu sendiri.');

            return;
        }

        $user->fill(['name' => $validated['name'], 'email' => $validated['email']]);
        $user->forceFill(['is_admin' => $validated['is_admin']]);

        if (filled($validated['password'])) {
            $user->forceFill(['password' => $validated['password'], 'remember_token' => Str::random(60)]);
        }

        // Catat apa saja yang berubah (tanpa menyimpan nilai kata sandi).
        $changedProfile = array_values(array_intersect(array_keys($user->getDirty()), ['name', 'email']));
        $roleChanged = $user->isDirty('is_admin');
        $passwordChanged = $user->isDirty('password');

        $user->save();

        if ($changedProfile) {
            AdminActivity::record(auth()->user(), $user, 'updated', ['fields' => $changedProfile]);
        }

        if ($roleChanged) {
            AdminActivity::record(auth()->user(), $user, $user->is_admin ? 'promoted' : 'demoted');
        }

        if ($passwordChanged) {
            AdminActivity::record(auth()->user(), $user, 'password_changed');
        }

        unset($this->stats, $this->activities);
        $this->closeForm();
        $this->dispatch('toast', message: "Akun {$user->name} diperbarui");
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->reset(['editingId', 'name', 'email', 'password', 'is_admin', 'sendInvitation']);
    }
};
?>

@php
    $stats = $this->stats;
    $formatBytes = fn (int $bytes) => $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '.').' MB' : number_format($bytes / 1024, 0, ',', '.').' KB';
@endphp

<div class="space-y-5">
    <x-page-header title="Admin" subtitle="Kelola akun pengguna Prima Management." icon="shield">
        <x-slot:actions>
            <button class="btn btn-primary" wire:click="openCreate"><x-icon name="plus" class="size-4" /> Pengguna</button>
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
        @foreach ([
            ['Pengguna', $stats['total'], 'user'],
            ['Aktif 7 hari', $stats['active'], 'bolt'],
            ['Nonaktif', $stats['disabled'], 'x'],
            ['Lampiran', $formatBytes($stats['storage']), 'upload'],
        ] as [$label, $value, $icon])
            <div class="card p-4">
                <p class="flex items-center gap-1.5 text-xs font-semibold text-muted"><x-icon :name="$icon" class="size-4" /> {{ $label }}</p>
                <p class="mt-1 text-2xl font-extrabold tracking-tight">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    <div class="space-y-3">
        <label class="relative block">
            <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted" />
            <input type="search" wire:model.live.debounce.300ms="search" class="input py-2 pl-9 text-sm" placeholder="Cari nama atau email…" aria-label="Cari pengguna">
        </label>
        <div class="flex flex-wrap gap-2">
            @foreach (['all' => 'Semua', 'active' => 'Aktif', 'disabled' => 'Nonaktif', 'admin' => 'Admin'] as $key => $label)
                <button wire:click="$set('status', '{{ $key }}')" @class([
                    'chip px-3.5 py-1.5 text-sm',
                    'bg-brand text-on-brand' => $status === $key,
                    'bg-surface-2 text-muted' => $status !== $key,
                ])>{{ $label }}</button>
            @endforeach
        </div>
    </div>

    <section class="card divide-y divide-line p-0">
        @forelse ($this->users as $user)
            @php $isSelf = $user->is(auth()->user()); @endphp
            <div wire:key="user-{{ $user->id }}" class="flex flex-wrap items-center gap-3 px-4 py-3">
                <span @class([
                    'grid size-10 shrink-0 place-items-center rounded-xl text-sm font-extrabold',
                    'bg-linear-to-br from-brand to-brand-2 text-on-brand' => ! $user->isDisabled(),
                    'bg-surface-2 text-muted' => $user->isDisabled(),
                ])>{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</span>
                <div class="min-w-0 flex-1 basis-48">
                    <p class="flex flex-wrap items-center gap-1.5 text-sm font-bold">
                        <span @class(['truncate', 'text-muted line-through' => $user->isDisabled()])>{{ $user->name }}</span>
                        @if ($user->is_admin) <span class="chip bg-brand-soft px-1.5 py-0 text-brand-ink">Admin</span> @endif
                        @if ($user->isDisabled()) <span class="chip bg-danger/10 px-1.5 py-0 text-danger">Nonaktif</span> @endif
                        @if ($isSelf) <span class="chip bg-surface-2 px-1.5 py-0 text-muted">Kamu</span> @endif
                    </p>
                    <p class="truncate text-xs text-muted">{{ $user->email }}</p>
                    <p class="text-xs text-muted">
                        {{ $user->last_active_at ? 'Aktif '.$user->last_active_at->diffForHumans() : 'Belum pernah masuk' }}
                        · {{ $formatBytes((int) $user->storage_used) }} lampiran
                        @if ($user->telegram_chat_id) · Telegram ✓ @endif
                    </p>
                </div>
                <div class="ml-auto flex shrink-0 items-center gap-1">
                    <button class="btn btn-ghost px-3 py-2 text-xs" wire:click="openEdit({{ $user->id }})" aria-label="Edit {{ $user->name }}"><x-icon name="pencil" class="size-4" /></button>
                    @unless ($isSelf)
                        <button class="btn btn-ghost px-3 py-2 text-xs" wire:click="toggleDisabled({{ $user->id }})"
                            @unless ($user->isDisabled()) wire:confirm="Nonaktifkan {{ $user->name }}? Ia akan langsung keluar dari semua perangkat." @endunless>
                            {{ $user->isDisabled() ? 'Aktifkan' : 'Nonaktifkan' }}
                        </button>
                    @endunless
                </div>
            </div>
        @empty
            <p class="p-6 text-center text-sm text-muted">Tidak ada pengguna yang cocok.</p>
        @endforelse
    </section>

    @if ($this->users->hasPages())
        <div class="flex items-center justify-between gap-2">
            <button class="btn btn-ghost" wire:click="previousPage" @disabled($this->users->onFirstPage())><x-icon name="chevron-left" class="size-4" /> Sebelumnya</button>
            <span class="text-sm text-muted">Hal. {{ $this->users->currentPage() }} / {{ $this->users->lastPage() }}</span>
            <button class="btn btn-ghost" wire:click="nextPage" @disabled(! $this->users->hasMorePages())>Berikutnya <x-icon name="chevron-right" class="size-4" /></button>
        </div>
    @endif

    {{-- Log aktivitas admin --}}
    <section class="card" x-data="{ all: false }">
        <h2 class="section-title mb-1">Aktivitas admin</h2>
        <p class="mb-3 text-xs text-muted">Siapa melakukan apa terhadap akun pengguna, 30 terakhir.</p>
        @forelse ($this->activities as $activity)
            <div wire:key="activity-{{ $activity->id }}" class="flex items-start gap-3 border-t border-line py-2.5 first:border-t-0" @if ($loop->index >= 8) x-show="all" x-cloak @endif>
                <span class="mt-0.5 text-lg">{{ match ($activity->action) {
                    'created', 'invited' => '🆕', 'deleted' => '🗑️', 'disabled' => '⛔', 'enabled' => '✅',
                    'promoted', 'demoted' => '🛡️', 'password_changed', 'reset_link_sent' => '🔑', default => '✏️',
                } }}</span>
                <p class="min-w-0 flex-1 text-sm">
                    <b>{{ $activity->actor_label }}</b> {{ $activity->description() }} <b class="break-all">{{ $activity->subject_label }}</b>
                    @if ($fields = $activity->details['fields'] ?? null)
                        <span class="text-muted">({{ collect($fields)->map(fn ($field) => $field === 'name' ? 'nama' : $field)->implode(', ') }})</span>
                    @endif
                    <span class="block text-xs text-muted">{{ $activity->created_at->translatedFormat('d M Y, H:i') }} · {{ $activity->created_at->diffForHumans() }}</span>
                </p>
            </div>
        @empty
            <p class="text-sm text-muted">Belum ada aktivitas.</p>
        @endforelse
        @if ($this->activities->count() > 8)
            <button class="link mt-2" x-on:click="all = ! all" x-text="all ? 'Tampilkan lebih sedikit' : 'Tampilkan semua'">Tampilkan semua</button>
        @endif
    </section>

    <x-sheet :show="$showForm" :title="$editingId ? 'Edit pengguna' : 'Pengguna baru'">
        <form wire:submit="save" class="space-y-4">
            <div>
                <label class="label" for="admin-name">Nama</label>
                <input id="admin-name" wire:model="name" class="input" autocomplete="off">
                @error('name') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="admin-email">Email</label>
                <input id="admin-email" type="email" wire:model="email" class="input" autocomplete="off">
                @error('email') <p class="error">{{ $message }}</p> @enderror
            </div>

            @unless ($editingId)
                <label class="flex items-start gap-2 rounded-2xl border border-line p-3 text-sm">
                    <input type="checkbox" wire:model.live="sendInvitation" class="mt-0.5 size-4 accent-brand">
                    <span><span class="block font-bold">Kirim email undangan</span><span class="text-xs text-muted">Pengguna membuat kata sandinya sendiri lewat tautan di email (butuh SMTP).</span></span>
                </label>
            @endunless

            <div>
                <label class="label" for="admin-password">{{ $editingId ? 'Kata sandi baru (opsional)' : ($sendInvitation ? 'Kata sandi awal (opsional)' : 'Kata sandi awal') }}</label>
                <input id="admin-password" type="text" wire:model="password" class="input font-mono" autocomplete="new-password" placeholder="min. 8 karakter">
                @error('password') <p class="error">{{ $message }}</p> @enderror
                @if ($editingId)
                    <p class="mt-1 text-xs text-muted">Kosongkan bila tidak ingin mengganti. Mengganti kata sandi mengeluarkan "ingat saya" di perangkat lain.</p>
                @endif
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" wire:model="is_admin" class="size-4 accent-brand"> Jadikan admin (bisa mengelola pengguna)
            </label>
            @error('is_admin') <p class="error">{{ $message }}</p> @enderror

            <div class="flex flex-wrap gap-2 pt-1">
                @if ($editingId && $editingId !== auth()->id())
                    <button type="button" class="btn btn-danger" wire:click="deleteUser({{ $editingId }})"
                        wire:confirm="Hapus akun ini beserta SEMUA datanya (target, tugas, keuangan, catatan, lampiran)? Tidak bisa dibatalkan.">
                        <x-icon name="trash" class="size-4" />
                    </button>
                @endif
                @if ($editingId)
                    <button type="button" class="btn btn-ghost" wire:click="sendResetLink({{ $editingId }})">Kirim tautan reset</button>
                @endif
                <button class="btn btn-primary flex-1" wire:loading.attr="disabled" wire:target="save">Simpan</button>
            </div>
        </form>
    </x-sheet>
</div>
