<?php

use App\Models\PushSubscription;
use App\Models\StudySession;
use App\Models\User;
use App\Services\Telegram;
use App\Services\DataExporter;
use App\Services\DataImporter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use App\Services\Reminders;
use App\Services\WebPush;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('Akun')] class extends Component
{
    use WithFileUploads;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $importFile = null;

    public string $importMode = 'merge';

    /** @var array<string, int>|null */
    public ?array $importResult = null;

    #[Computed]
    public function calendarUrl(): ?string
    {
        $token = auth()->user()->calendar_token;

        return $token ? route('calendar.feed', ['token' => $token]) : null;
    }

    public function createCalendarLink(bool $regenerate = false): void
    {
        auth()->user()->calendarToken($regenerate);

        unset($this->calendarUrl);
        $this->dispatch('toast', message: $regenerate ? 'Tautan lama dinonaktifkan, tautan baru dibuat' : 'Tautan kalender dibuat');
    }

    public function importData(): void
    {
        $this->validate([
            'importFile' => ['required', 'file', 'max:10240', 'mimetypes:application/json,text/plain'],
            'importMode' => ['required', Rule::in(['merge', 'replace'])],
        ], ['importFile.required' => 'Pilih file ekspor JSON dulu.'], ['importFile' => 'file']);

        $data = json_decode((string) file_get_contents($this->importFile->getRealPath()), true);

        if (! is_array($data)) {
            $this->addError('importFile', 'File tidak bisa dibaca sebagai JSON.');

            return;
        }

        try {
            $counts = app(DataImporter::class)->import(auth()->user(), $data, $this->importMode === 'replace');
        } catch (InvalidArgumentException $exception) {
            $this->addError('importFile', $exception->getMessage());

            return;
        } catch (QueryException $exception) {
            // Seluruh impor dibatalkan (transaksi); data lama tidak berubah.
            report($exception);
            $this->addError('importFile', 'Isi file tidak sesuai (ada nilai yang terlalu panjang atau salah format). Tidak ada data yang diubah.');

            return;
        }

        $this->importResult = array_filter($counts);
        $this->reset('importFile');
        $this->dispatch('toast', message: 'Data berhasil dipulihkan ✅');
    }
    public string $name = '';

    public string $email = '';

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public int|float|string|null $weekly_target_hours = null;

    public string $monthly_budget = '';

    public function mount(): void
    {
        $user = auth()->user();

        $this->name = $user->name;
        $this->email = $user->email;
        $this->weekly_target_hours = $user->weekly_target_minutes ? round($user->weekly_target_minutes / 60, 1) : null;
        $this->monthly_budget = $user->monthly_budget ? (string) $user->monthly_budget : '';
    }

    #[Computed]
    public function deviceCount(): int
    {
        return auth()->user()->pushSubscriptions()->count();
    }

    public function saveProfile(): void
    {
        $user = auth()->user();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ], [], ['name' => 'nama']);

        $user->update($validated);

        $this->dispatch('toast', message: 'Profil disimpan');
    }

    public function savePassword(): void
    {
        $this->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ], [], ['current_password' => 'kata sandi saat ini', 'password' => 'kata sandi baru']);

        auth()->user()->update(['password' => $this->password]);

        $this->reset('current_password', 'password', 'password_confirmation');
        $this->dispatch('toast', message: 'Kata sandi diganti');
    }

    public function savePlanning(): void
    {
        $this->monthly_budget = preg_replace('/\D/', '', $this->monthly_budget) ?? '';

        $validated = $this->validate([
            'weekly_target_hours' => ['nullable', 'numeric', 'min:0.5', 'max:168'],
            'monthly_budget' => ['nullable', 'integer', 'min:1', 'max:999999999999'],
        ], [], ['weekly_target_hours' => 'target fokus mingguan', 'monthly_budget' => 'anggaran bulanan']);

        auth()->user()->update([
            'weekly_target_minutes' => filled($validated['weekly_target_hours']) ? (int) round($validated['weekly_target_hours'] * 60) : null,
            'monthly_budget' => filled($validated['monthly_budget']) ? (int) $validated['monthly_budget'] : null,
        ]);

        $this->dispatch('toast', message: 'Target & anggaran disimpan');
    }

    /**
     * Simpan langganan push dari browser (hasil PushSubscription.toJSON()).
     *
     * @param  array{endpoint?: string, keys?: array{p256dh?: string, auth?: string}}  $subscription
     */
    public function savePushSubscription(array $subscription): void
    {
        validator($subscription, [
            'endpoint' => ['required', 'url', 'starts_with:https://', 'max:500'],
            'keys.p256dh' => ['nullable', 'string', 'max:255'],
            'keys.auth' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $user = auth()->user();

        // Perangkat yang sama bisa berganti akun; langganan lama milik akun lain dilepas.
        PushSubscription::query()->where('endpoint', $subscription['endpoint'])->where('user_id', '!=', $user->id)->delete();

        $user->pushSubscriptions()->updateOrCreate(
            ['endpoint' => $subscription['endpoint']],
            ['public_key' => $subscription['keys']['p256dh'] ?? null, 'auth_token' => $subscription['keys']['auth'] ?? null],
        );

        unset($this->deviceCount);
        $this->dispatch('toast', message: 'Notifikasi aktif di perangkat ini 🔔');
    }

    public function deletePushSubscription(string $endpoint): void
    {
        auth()->user()->pushSubscriptions()->where('endpoint', $endpoint)->delete();

        unset($this->deviceCount);
        $this->dispatch('toast', message: 'Notifikasi dimatikan di perangkat ini');
    }

    public function sendTestPush(): void
    {
        $user = auth()->user();

        $user->reminders()->create([
            'key' => 'test:'.now()->format('YmdHisv'),
            'title' => 'Tes notifikasi',
            'body' => 'Notifikasi Prima Management sudah berfungsi. 🎉',
            'url' => route('account', absolute: false),
        ]);

        app(Reminders::class)->push($user->load('pushSubscriptions'));

        $this->dispatch('toast', message: 'Notifikasi tes dikirim');
    }

    public ?string $telegramLink = null;

    public function connectTelegram(): void
    {
        $this->telegramLink = app(Telegram::class)->linkUrl(auth()->user());
    }

    public function refreshTelegram(): void
    {
        if (auth()->user()->fresh()->hasTelegram()) {
            $this->telegramLink = null;
            $this->dispatch('toast', message: 'Telegram tersambung ✅');
        }
    }

    public function disconnectTelegram(): void
    {
        auth()->user()->forceFill(['telegram_chat_id' => null, 'telegram_link_code' => null])->save();

        $this->telegramLink = null;
        $this->dispatch('toast', message: 'Telegram diputus');
    }

    public function sendTestTelegram(): void
    {
        $user = auth()->user();
        $sent = app(Telegram::class)->sendMessage($user->telegram_chat_id, '<b>Tes pengingat</b>'."\n".'Pengingat Prima Management sudah berfungsi. 🎉', route('dashboard'));

        $this->dispatch('toast', message: $sent ? 'Pesan tes dikirim ke Telegram' : 'Gagal mengirim. Coba sambungkan ulang.');
    }

    public function exportJson(): StreamedResponse
    {
        $json = app(DataExporter::class)->toJson(auth()->user());

        return response()->streamDownload(fn () => print ($json), 'prima-management-'.now()->format('Ymd').'.json', ['Content-Type' => 'application/json']);
    }

    public function exportSessionsCsv(): StreamedResponse
    {
        $user = auth()->user();

        return response()->streamDownload(function () use ($user) {
            $output = fopen('php://output', 'w');
            app(DataExporter::class)->writeSessionsCsv($user, $output);
            fclose($output);
        }, 'sesi-fokus-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public string $delete_password = '';

    /** Hapus akun permanen beserta semua data & lampirannya. */
    public function deleteAccount(): void
    {
        $this->validate(['delete_password' => ['required', 'current_password']], [], ['delete_password' => 'kata sandi']);

        $user = auth()->user();

        if ($user->is_admin && User::query()->where('is_admin', true)->whereKeyNot($user->id)->doesntExist()) {
            $this->addError('delete_password', 'Kamu satu-satunya admin. Jadikan pengguna lain admin dulu sebelum menghapus akun ini.');

            return;
        }

        Auth::logout();
        $user->deleteAccount();
        session()->invalidate();
        session()->regenerateToken();
        session()->flash('status', 'Akunmu sudah dihapus. Terima kasih sudah memakai Prima Management.');

        $this->redirectRoute('login');
    }
};
?>

@php
    $webPush = app(WebPush::class);
@endphp

<div class="space-y-5">
    <x-page-header title="Akun" subtitle="Profil, tampilan, notifikasi, dan data kamu." icon="user" />

    {{-- Tampilan --}}
    <section class="card">
        <h2 class="section-title mb-1">Tampilan</h2>
        <p class="mb-3 text-sm text-muted">Pilih tema untuk perangkat ini.</p>
        <x-theme-toggle variant="segmented" />
    </section>

    {{-- Profil --}}
    <section class="card">
        <h2 class="mb-4 font-bold">Profil</h2>
        <form wire:submit="saveProfile" class="space-y-4">
            <div>
                <label class="label" for="name">Nama</label>
                <input id="name" wire:model="name" class="input" autocomplete="name">
                @error('name') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="email">Email</label>
                <input id="email" type="email" wire:model="email" class="input" autocomplete="email">
                @error('email') <p class="error">{{ $message }}</p> @enderror
            </div>
            <button class="btn btn-primary w-full" wire:loading.attr="disabled" wire:target="saveProfile">Simpan profil</button>
        </form>
    </section>

    {{-- Target & anggaran --}}
    <section class="card">
        <h2 class="mb-4 font-bold">Target & anggaran</h2>
        <form wire:submit="savePlanning" class="space-y-4">
            <div class="grid grid-cols-2 items-end gap-3">
                <div>
                    <label class="label" for="weekly_target_hours">Fokus / minggu (jam)</label>
                    <input id="weekly_target_hours" type="number" step="0.5" min="0.5" inputmode="decimal" wire:model="weekly_target_hours" class="input" placeholder="mis. 15">
                    @error('weekly_target_hours') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="monthly_budget">Anggaran / bulan (Rp)</label>
                    <input id="monthly_budget" inputmode="numeric" wire:model="monthly_budget" class="input" placeholder="mis. 2.500.000"
                        x-on:input="$el.value = $el.value.replace(/\D/g, '').replace(/\B(?=(\d{3})+(?!\d))/g, '.')">
                    @error('monthly_budget') <p class="error">{{ $message }}</p> @enderror
                </div>
            </div>
            <p class="text-xs text-muted">Kosongkan untuk menonaktifkan. Target mingguan tampil di Beranda, anggaran di Keuangan.</p>
            <button class="btn btn-primary w-full" wire:loading.attr="disabled" wire:target="savePlanning">Simpan</button>
        </form>
    </section>

    {{-- Notifikasi --}}
    <section class="card"
        x-data="{
            supported: 'serviceWorker' in navigator && 'PushManager' in window && window.isSecureContext,
            permission: window.Notification?.permission ?? 'default',
            subscribed: false,
            busy: false,
            publicKey: @js($webPush->publicKey()),
            async init() {
                if (! this.supported) return;
                const registration = await navigator.serviceWorker.ready;
                this.subscribed = !! (await registration.pushManager.getSubscription());
            },
            applicationServerKey() {
                const base64 = (this.publicKey + '='.repeat((4 - this.publicKey.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/');
                return Uint8Array.from(atob(base64), (char) => char.charCodeAt(0));
            },
            async enable() {
                this.busy = true;
                try {
                    this.permission = await Notification.requestPermission();
                    if (this.permission !== 'granted') return;
                    const registration = await navigator.serviceWorker.ready;
                    const subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: this.applicationServerKey() });
                    await $wire.savePushSubscription(subscription.toJSON());
                    this.subscribed = true;
                } catch (error) {
                    window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'Gagal mengaktifkan notifikasi' } }));
                } finally {
                    this.busy = false;
                }
            },
            async disable() {
                this.busy = true;
                try {
                    const registration = await navigator.serviceWorker.ready;
                    const subscription = await registration.pushManager.getSubscription();
                    if (subscription) {
                        await $wire.deletePushSubscription(subscription.endpoint);
                        await subscription.unsubscribe();
                    }
                    this.subscribed = false;
                } finally {
                    this.busy = false;
                }
            },
        }">
        <div class="mb-3 flex items-center gap-2">
            <x-icon name="bell" class="size-5 text-brand" />
            <h2 class="font-bold">Notifikasi pengingat</h2>
        </div>
        <ul class="mb-4 list-disc space-y-1 pl-5 text-sm text-muted">
            <li>{{ App\Services\Reminders::SLOT_LEAD_MINUTES }} menit sebelum slot jadwal dimulai</li>
            <li>Ringkasan pagi (07.00): tugas hari ini & terlambat, kebiasaan, deadline ≤ 7 hari, utang/piutang jatuh tempo</li>
            <li>Ajakan mengisi jurnal (20.30) bila kamu memakai jurnal dan belum mengisinya hari itu</li>
        </ul>

        @if (! $webPush->isConfigured())
            <p class="rounded-xl bg-surface-2 p-3 text-sm text-muted">Server belum punya kunci VAPID. Jalankan <code class="font-mono text-ink">php artisan app:vapid-keys</code> lalu salin hasilnya ke <code class="font-mono text-ink">.env</code>.</p>
        @else
            <template x-if="! supported">
                <p class="rounded-xl bg-surface-2 p-3 text-sm text-muted">Browser ini belum mendukung notifikasi push, atau aplikasi dibuka lewat koneksi tidak aman (http). Buka lewat https atau localhost, dan di iPhone pasang dulu ke Layar Utama.</p>
            </template>
            <template x-if="supported">
                <div class="space-y-3">
                    <p x-show="permission === 'denied'" x-cloak class="rounded-xl bg-danger/10 p-3 text-sm text-danger">Izin notifikasi diblokir. Aktifkan lagi dari pengaturan situs di browser.</p>
                    <div class="flex gap-2">
                        <button type="button" class="btn btn-primary flex-1" x-show="! subscribed" x-on:click="enable()" x-bind:disabled="busy || permission === 'denied'">
                            <x-icon name="bell" class="size-4" /> Aktifkan di perangkat ini
                        </button>
                        <button type="button" class="btn btn-ghost flex-1" x-show="subscribed" x-cloak x-on:click="disable()" x-bind:disabled="busy">Matikan di perangkat ini</button>
                        <button type="button" class="btn btn-ghost" x-show="subscribed" x-cloak wire:click="sendTestPush">Kirim tes</button>
                    </div>
                </div>
            </template>
            <p class="mt-3 text-xs text-muted">{{ $this->deviceCount }} perangkat menerima notifikasi. Pengingat dikirim oleh scheduler (<code class="font-mono">php artisan schedule:work</code>, sudah termasuk di <code class="font-mono">composer run dev</code>).</p>
        @endif
    </section>

    {{-- Telegram --}}
    @php $telegram = app(Telegram::class); @endphp
    <section class="card" @if ($telegramLink) wire:poll.5s="refreshTelegram" @endif>
        <div class="mb-1 flex items-center gap-2">
            <x-icon name="send" class="size-5 text-brand" />
            <h2 class="font-bold">Pengingat lewat Telegram</h2>
        </div>
        <p class="mb-4 text-sm text-muted">Pengingat yang sama dengan notifikasi push, dikirim ke chat Telegram. Lebih andal di iPhone dan tidak perlu memasang aplikasi.</p>

        @if (! $telegram->isConfigured())
            <p class="rounded-xl bg-surface-2 p-3 text-sm text-muted">Bot Telegram belum diatur di server. Admin perlu mengisi <code class="font-mono text-ink">TELEGRAM_BOT_TOKEN</code> dan <code class="font-mono text-ink">TELEGRAM_BOT_USERNAME</code> di <code class="font-mono text-ink">.env</code>.</p>
        @elseif (auth()->user()->hasTelegram())
            <p class="mb-3 rounded-xl bg-success/10 p-3 text-sm font-semibold text-success">Tersambung ke {{ '@'.$telegram->botUsername() }} ✅</p>
            <div class="flex gap-2">
                <button type="button" class="btn btn-ghost flex-1" wire:click="sendTestTelegram">Kirim tes</button>
                <button type="button" class="btn btn-ghost flex-1" wire:click="disconnectTelegram" wire:confirm="Berhenti menerima pengingat di Telegram?">Putuskan</button>
            </div>
        @elseif ($telegramLink)
            <div class="space-y-3">
                <a href="{{ $telegramLink }}" target="_blank" rel="noopener" class="btn btn-primary w-full"><x-icon name="send" class="size-4" /> Buka Telegram & tekan Start</a>
                <p class="text-xs text-muted">Halaman ini otomatis memperbarui begitu bot menerima pesan Start-mu. Tautan hanya berlaku untuk akunmu, jangan dibagikan.</p>
            </div>
        @else
            <button type="button" class="btn btn-primary w-full" wire:click="connectTelegram"><x-icon name="send" class="size-4" /> Sambungkan Telegram</button>
        @endif
    </section>

    {{-- Keamanan --}}
    <section class="card">
        <h2 class="mb-4 font-bold">Ganti kata sandi</h2>
        <form wire:submit="savePassword" class="space-y-4">
            <div>
                <label class="label" for="current_password">Kata sandi saat ini</label>
                <input id="current_password" type="password" wire:model="current_password" class="input" autocomplete="current-password">
                @error('current_password') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="label" for="password">Kata sandi baru</label>
                    <input id="password" type="password" wire:model="password" class="input" autocomplete="new-password">
                </div>
                <div>
                    <label class="label" for="password_confirmation">Ulangi</label>
                    <input id="password_confirmation" type="password" wire:model="password_confirmation" class="input" autocomplete="new-password">
                </div>
            </div>
            @error('password') <p class="error">{{ $message }}</p> @enderror
            <button class="btn btn-primary w-full" wire:loading.attr="disabled" wire:target="savePassword">Ganti kata sandi</button>
        </form>
    </section>

    {{-- Kalender --}}
    <section class="card">
        <div class="mb-1 flex items-center gap-2">
            <x-icon name="calendar-grid" class="size-5 text-brand" />
            <h2 class="section-title">Sinkron ke Google Calendar</h2>
        </div>
        <p class="mb-4 text-sm text-muted">Deadline target, tugas bertenggat, dan jadwal fokus mingguan muncul di aplikasi kalender HP-mu (diperbarui otomatis berkala).</p>
        @if ($this->calendarUrl)
            <div x-data="{ copied: false }" class="space-y-3">
                <div class="flex gap-2">
                    <input readonly value="{{ $this->calendarUrl }}" class="input min-w-0 font-mono text-xs" x-on:focus="$el.select()" aria-label="Tautan kalender">
                    <button type="button" class="btn btn-ghost shrink-0" x-on:click="navigator.clipboard?.writeText(@js($this->calendarUrl)); copied = true; setTimeout(() => copied = false, 2000)">
                        <x-icon name="link" class="size-4" /> <span x-text="copied ? 'Tersalin' : 'Salin'">Salin</span>
                    </button>
                </div>
                <ol class="list-decimal space-y-1 pl-5 text-xs text-muted">
                    <li>Buka Google Calendar di komputer → <b>Setelan</b> → <b>Tambahkan kalender</b> → <b>Dari URL</b>.</li>
                    <li>Tempel tautan di atas, lalu <b>Tambahkan kalender</b>. Di iPhone: Pengaturan → Kalender → Akun → Tambah Kalender Langganan.</li>
                </ol>
                <p class="text-xs text-muted">Tautan ini rahasia, jangan dibagikan. <button type="button" class="font-bold text-danger hover:underline" wire:click="createCalendarLink(true)" wire:confirm="Buat tautan baru? Kalender yang memakai tautan lama berhenti diperbarui.">Ganti tautan</button></p>
            </div>
        @else
            <button type="button" class="btn btn-primary w-full" wire:click="createCalendarLink"><x-icon name="link" class="size-4" /> Buat tautan kalender</button>
        @endif
    </section>

    {{-- Data --}}
    <section class="card">
        <h2 class="section-title mb-1">Data & backup</h2>
        <p class="mb-4 text-sm text-muted">Unduh salinan seluruh datamu kapan saja. Server juga membuat backup database otomatis setiap hari pukul 02.00 (14 terakhir disimpan).</p>
        <div class="grid gap-2 sm:grid-cols-2">
            <button type="button" class="btn btn-ghost" wire:click="exportJson"><x-icon name="download" class="size-4" /> Semua data (JSON)</button>
            <button type="button" class="btn btn-ghost" wire:click="exportSessionsCsv"><x-icon name="download" class="size-4" /> Sesi fokus (CSV)</button>
        </div>

        <div class="mt-5 border-t border-line pt-5">
            <h3 class="font-bold">Pulihkan dari file ekspor</h3>
            <p class="mb-3 text-sm text-muted">Unggah file JSON hasil "Semua data" untuk memulihkan atau memindahkan data dari akun/perangkat lain.</p>
            <form wire:submit="importData" class="space-y-3">
                <input type="file" wire:model="importFile" accept=".json,application/json"
                    class="block w-full text-sm text-muted file:mr-3 file:rounded-xl file:border-0 file:bg-brand-soft file:px-4 file:py-2 file:text-sm file:font-bold file:text-brand-ink hover:file:brightness-95">
                <div wire:loading wire:target="importFile" class="text-xs text-muted">Mengunggah…</div>
                @error('importFile') <p class="error">{{ $message }}</p> @enderror

                <div class="grid gap-2 sm:grid-cols-2">
                    <label @class(['flex cursor-pointer items-start gap-2 rounded-2xl border p-3 text-sm', 'border-brand bg-brand-soft/40' => $importMode === 'merge', 'border-line' => $importMode !== 'merge'])>
                        <input type="radio" wire:model.live="importMode" value="merge" class="mt-0.5 accent-brand">
                        <span><span class="block font-bold">Tambahkan</span><span class="text-xs text-muted">Data lama tetap ada, isi file ditambahkan.</span></span>
                    </label>
                    <label @class(['flex cursor-pointer items-start gap-2 rounded-2xl border p-3 text-sm', 'border-danger bg-danger/5' => $importMode === 'replace', 'border-line' => $importMode !== 'replace'])>
                        <input type="radio" wire:model.live="importMode" value="replace" class="mt-0.5 accent-brand">
                        <span><span class="block font-bold">Ganti semua</span><span class="text-xs text-muted">Hapus data sekarang, lalu pulihkan dari file.</span></span>
                    </label>
                </div>

                <button class="btn btn-ghost w-full" wire:loading.attr="disabled" wire:target="importData,importFile"
                    @if ($importMode === 'replace') wire:confirm="Semua data di akun ini akan dihapus lalu diganti isi file. Lanjutkan?" @endif>
                    <x-icon name="upload" class="size-4" /> Pulihkan data
                </button>
            </form>

            @if ($importResult)
                <div class="mt-3 rounded-2xl bg-success/10 p-3 text-sm text-success">
                    <p class="font-bold">Berhasil dipulihkan:</p>
                    <p>{{ collect($importResult)->map(fn ($count, $section) => $count.' '.str_replace('_', ' ', $section))->implode(' · ') }}</p>
                </div>
            @endif
        </div>
    </section>

    <form method="POST" action="{{ route('logout') }}" onsubmit="window.primaOffline?.clearPages()">
        @csrf
        <button class="btn btn-ghost w-full"><x-icon name="logout" class="size-4" /> Keluar</button>
    </form>

    {{-- Zona berbahaya --}}
    <section class="card border-danger/30" x-data="{ open: false }">
        <button type="button" class="flex w-full items-center justify-between text-left" x-on:click="open = ! open" :aria-expanded="open">
            <span>
                <span class="block font-bold text-danger">Hapus akun</span>
                <span class="block text-xs text-muted">Menghapus akun dan seluruh data secara permanen.</span>
            </span>
            <x-icon name="chevron-right" class="size-4 text-muted transition" ::class="open && 'rotate-90'" />
        </button>
        <form wire:submit="deleteAccount" class="mt-4 space-y-3" x-show="open" x-cloak>
            <p class="rounded-2xl bg-danger/10 p-3 text-sm text-danger">
                Semua target, sesi fokus, tugas, kebiasaan, keuangan, catatan beserta lampirannya akan dihapus dan <b>tidak bisa dikembalikan</b>.
                Unduh dulu "Semua data (JSON)" di atas bila ingin menyimpan salinan.
            </p>
            <div>
                <label class="label" for="delete_password">Ketik kata sandi untuk konfirmasi</label>
                <input id="delete_password" type="password" wire:model="delete_password" class="input" autocomplete="current-password">
                @error('delete_password') <p class="error">{{ $message }}</p> @enderror
            </div>
            <button class="btn w-full bg-danger text-white hover:brightness-110" wire:confirm="Yakin hapus akun beserta semua datanya?">
                <x-icon name="trash" class="size-4" /> Hapus akun saya permanen
            </button>
        </form>
    </section>
</div>
