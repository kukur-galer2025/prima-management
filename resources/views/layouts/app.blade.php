@php
    $groups = [
        'Utama' => [
            ['route' => 'dashboard', 'match' => 'dashboard', 'label' => 'Beranda', 'icon' => 'home'],
            ['route' => 'goals.index', 'match' => 'goals.*', 'label' => 'Target', 'icon' => 'target'],
            ['route' => 'study', 'match' => 'study', 'label' => 'Fokus', 'icon' => 'timer'],
            ['route' => 'tasks', 'match' => 'tasks', 'label' => 'Tugas', 'icon' => 'check'],
            ['route' => 'schedule', 'match' => 'schedule', 'label' => 'Jadwal', 'icon' => 'calendar'],
            ['route' => 'calendar', 'match' => 'calendar', 'label' => 'Kalender', 'icon' => 'calendar-grid'],
        ],
        'Kehidupan' => [
            ['route' => 'habits', 'match' => 'habits', 'label' => 'Kebiasaan', 'icon' => 'repeat'],
            ['route' => 'journal', 'match' => 'journal', 'label' => 'Jurnal', 'icon' => 'book'],
            ['route' => 'notes', 'match' => 'notes', 'label' => 'Catatan', 'icon' => 'note'],
        ],
        'Uang' => [
            ['route' => 'finance', 'match' => 'finance', 'label' => 'Keuangan', 'icon' => 'wallet'],
            ['route' => 'savings', 'match' => 'savings', 'label' => 'Tabungan', 'icon' => 'piggy'],
            ['route' => 'debts', 'match' => 'debts', 'label' => 'Utang & piutang', 'icon' => 'handshake'],
        ],
        'Wawasan' => [
            ['route' => 'stats', 'match' => 'stats', 'label' => 'Statistik', 'icon' => 'chart'],
            ['route' => 'report', 'match' => 'report', 'label' => 'Laporan bulanan', 'icon' => 'report'],
            ['route' => 'review', 'match' => 'review', 'label' => 'Review mingguan', 'icon' => 'sparkle'],
            ['route' => 'groups', 'match' => 'groups', 'label' => 'Grup', 'icon' => 'users'],
        ],
    ];

    if (auth()->user()->is_admin) {
        $groups['Pengelola'] = [
            ['route' => 'admin', 'match' => 'admin', 'label' => 'Admin', 'icon' => 'shield'],
        ];
    }

    // Navigasi bawah HP hanya muat 5 item; sisanya ada di halaman "Lainnya".
    $mobileNav = [
        $groups['Utama'][0],
        $groups['Utama'][1],
        $groups['Utama'][2],
        $groups['Utama'][3],
        ['route' => 'menu', 'match' => ['menu', 'schedule', 'calendar', 'habits', 'journal', 'finance', 'savings', 'debts', 'notes', 'stats', 'review', 'groups', 'account', 'admin', 'report', 'search'], 'label' => 'Lainnya', 'icon' => 'grid'],
    ];

    $user = auth()->user();
    $initial = mb_strtoupper(mb_substr($user->name, 0, 1));
@endphp
<!DOCTYPE html>
<html lang="id">

<head>
    @include('partials.head')
</head>

<body class="min-h-dvh bg-bg font-sans text-ink antialiased">
    <div class="md:flex">
        {{-- Sidebar (tablet / laptop) --}}
        <aside class="print:hidden sticky top-0 hidden h-dvh w-64 shrink-0 flex-col overflow-y-auto border-r border-line/70 bg-surface/70 p-4 backdrop-blur-xl md:flex">
            <a href="{{ route('dashboard') }}" wire:navigate class="mb-6 flex items-center gap-3 px-2">
                <img src="/icons/icon-192.png" alt="" class="size-10 rounded-2xl shadow-md shadow-brand/30">
                <span class="leading-tight">
                    <span class="block text-base font-extrabold tracking-tight">Prima</span>
                    <span class="block text-xs font-semibold text-muted">Management</span>
                </span>
            </a>
            <a href="{{ route('search') }}" wire:navigate @class([
                'mb-5 flex items-center gap-2 rounded-2xl border px-3 py-2 text-sm transition',
                'border-brand bg-brand-soft text-brand-ink' => request()->routeIs('search'),
                'border-line/70 text-muted hover:border-brand/40 hover:text-ink' => ! request()->routeIs('search'),
            ])>
                <x-icon name="search" class="size-4" /> Cari…
            </a>
            <nav class="flex flex-1 flex-col gap-5">
                @foreach ($groups as $groupName => $items)
                    <div>
                        <p class="mb-1.5 px-3 text-[11px] font-bold tracking-wider text-muted/80 uppercase">{{ $groupName }}</p>
                        <div class="flex flex-col gap-0.5">
                            @foreach ($items as $item)
                                @php $active = request()->routeIs($item['match']); @endphp
                                <a href="{{ route($item['route']) }}" wire:navigate @class([
                                    'group relative flex items-center gap-3 rounded-2xl px-3 py-2.5 text-sm font-semibold transition',
                                    'bg-brand-soft text-brand-ink' => $active,
                                    'text-muted hover:bg-surface-2 hover:text-ink' => !$active,
                                ])>
                                    @if ($active)
                                        <span class="absolute top-2 bottom-2 left-0 w-1 rounded-full bg-linear-to-b from-brand to-brand-2"></span>
                                    @endif
                                    <x-icon :name="$item['icon']" />
                                    {{ $item['label'] }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </nav>
            <div class="mt-4 rounded-2xl border border-line/70 bg-surface p-2">
                <a href="{{ route('account') }}" wire:navigate @class([
                    'flex items-center gap-2.5 rounded-xl p-1.5 transition hover:bg-surface-2',
                    'bg-brand-soft' => request()->routeIs('account'),
                ])>
                    <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-linear-to-br from-brand to-brand-2 text-sm font-extrabold text-on-brand">{{ $initial }}</span>
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-bold">{{ $user->name }}</span>
                        <span class="block truncate text-xs text-muted">{{ $user->email }}</span>
                    </span>
                </a>
                <div class="mt-1 flex items-center justify-between border-t border-line/70 pt-1">
                    <x-theme-toggle />
                    <a href="{{ route('account') }}" wire:navigate class="text-xs font-bold text-muted hover:text-ink">Pengaturan</a>
                    <form method="POST" action="{{ route('logout') }}" onsubmit="window.primaOffline?.clearPages()">
                        @csrf
                        <button class="grid size-9 place-items-center rounded-2xl text-muted transition hover:bg-danger/10 hover:text-danger" aria-label="Keluar" title="Keluar">
                            <x-icon name="logout" />
                        </button>
                    </form>
                </div>
            </div>
        </aside>

        <main class="min-w-0 flex-1 pb-32 md:pb-12 print:pb-0">
            {{-- Top bar (HP) --}}
            <div class="print:hidden sticky top-0 z-30 border-b border-line/50 bg-bg/80 pt-[env(safe-area-inset-top)] backdrop-blur-xl md:hidden">
                <div class="mx-auto flex max-w-3xl items-center justify-between px-4 py-2">
                    <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-2">
                        <img src="/icons/icon-192.png" alt="" class="size-8 rounded-xl">
                        <span class="text-sm font-extrabold tracking-tight">Prima Management</span>
                    </a>
                    <div class="flex items-center gap-1">
                        <a href="{{ route('search') }}" wire:navigate class="grid size-10 place-items-center rounded-2xl text-muted transition hover:bg-surface-2 hover:text-ink" aria-label="Cari"><x-icon name="search" /></a>
                        <x-theme-toggle />
                        <a href="{{ route('account') }}" wire:navigate class="grid size-9 place-items-center rounded-xl bg-linear-to-br from-brand to-brand-2 text-sm font-extrabold text-on-brand" aria-label="Akun">{{ $initial }}</a>
                    </div>
                </div>
            </div>

            <div class="mx-auto max-w-3xl px-4 pt-5 md:px-8 md:pt-8">
                {{ $slot }}
            </div>
        </main>
    </div>

    {{-- Navigasi bawah (HP), mengambang --}}
    <nav class="print:hidden fixed inset-x-3 z-40 md:hidden" style="bottom: calc(0.75rem + env(safe-area-inset-bottom))">
        <div class="mx-auto grid max-w-md grid-cols-5 rounded-3xl border border-line/70 bg-surface/90 px-1 shadow-xl shadow-black/10 backdrop-blur-xl">
            @foreach ($mobileNav as $item)
                @php $active = request()->routeIs($item['match']); @endphp
                <a href="{{ route($item['route']) }}" wire:navigate @class([
                    'flex flex-col items-center gap-0.5 py-2 text-[11px] font-bold transition',
                    'text-brand' => $active,
                    'text-muted' => !$active,
                ])>
                    @if ($item['route'] === 'study')
                        <span class="-mt-6 mb-0.5 grid size-12 place-items-center rounded-2xl bg-linear-to-br from-brand to-brand-2 text-on-brand shadow-lg shadow-brand/40 ring-4 ring-bg">
                            <x-icon :name="$item['icon']" class="size-6" />
                        </span>
                    @else
                        <span @class(['grid h-7 w-12 place-items-center rounded-full transition', 'bg-brand-soft' => $active])>
                            <x-icon :name="$item['icon']" class="size-5" />
                        </span>
                    @endif
                    {{ $item['label'] }}
                </a>
            @endforeach
        </div>
    </nav>

    {{-- Popup konfirmasi (pengganti confirm() bawaan browser); dipakai wire:confirm dan window.primaConfirm() --}}
    <div x-data="{ open: false, message: '', confirmText: 'Ya, lanjutkan', danger: false, resolve: null,
            answer(value) { this.open = false; this.resolve?.(value); this.resolve = null; } }"
        x-on:prima-confirm.window="message = $event.detail.message; confirmText = $event.detail.confirmText; danger = $event.detail.danger; resolve = $event.detail.resolve; open = true; $nextTick(() => $refs.ok.focus())"
        x-on:keydown.escape.window="open && answer(false)"
        x-show="open" x-cloak class="fixed inset-0 z-70 flex items-end justify-center p-3 sm:items-center print:hidden"
        role="alertdialog" aria-modal="true" aria-labelledby="prima-confirm-message">
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" x-show="open" x-transition.opacity x-on:click="answer(false)"></div>
        <div class="relative w-full max-w-sm rounded-3xl bg-surface p-5 shadow-2xl" style="margin-bottom: env(safe-area-inset-bottom)"
            x-show="open" x-transition:enter="transition duration-200" x-transition:enter-start="translate-y-6 opacity-0 sm:translate-y-0 sm:scale-95" x-transition:enter-end="translate-y-0 opacity-100 sm:scale-100">
            <div class="mb-4 flex items-start gap-3">
                <span class="grid size-11 shrink-0 place-items-center rounded-2xl" :class="danger ? 'bg-danger/10 text-danger' : 'bg-brand-soft text-brand-ink'">
                    <span x-show="danger"><x-icon name="trash" class="size-5" /></span>
                    <span x-show="! danger"><x-icon name="bell" class="size-5" /></span>
                </span>
                <p id="prima-confirm-message" class="pt-1 text-base font-semibold whitespace-pre-line" x-text="message"></p>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <button type="button" class="btn btn-ghost" x-on:click="answer(false)">Batal</button>
                <button type="button" x-ref="ok" class="btn" :class="danger ? 'bg-danger text-white hover:brightness-110' : 'btn-primary'" x-on:click="answer(true)" x-text="confirmText"></button>
            </div>
        </div>
    </div>

    <script data-navigate-once>
        (() => {
            // Kata-kata ini menandai tindakan yang menghapus/menghentikan sesuatu: tombolnya dibuat merah.
            const DANGER = /hapus|keluarkan|hentikan|nonaktifkan|reset|ganti semua|putuskan|keluar dari/i;

            window.primaConfirm = (message, options = {}) => new Promise((resolve) => {
                const verb = message.match(DANGER)?.[0].toLowerCase().replace('keluar dari', 'keluar');
                const danger = options.danger ?? Boolean(verb);
                window.dispatchEvent(new CustomEvent('prima-confirm', { detail: {
                    message, resolve, danger,
                    confirmText: options.confirmText ?? (verb ? 'Ya, ' + verb : 'Ya, lanjutkan'),
                } }));
            });

            // Livewire tidak mengizinkan directive bawaan didaftarkan ulang, jadi fungsi konfirmasinya
            // ditimpa lewat hook directive.init (berjalan setelah bawaan Livewire memasang confirm()).
            document.addEventListener('livewire:init', () => {
                Livewire.hook('directive.init', ({ el, directive }) => {
                    if (directive.value !== 'confirm' || directive.modifiers.includes('prompt')) return;

                    const message = (directive.expression || 'Yakin?').replaceAll('\\n', '\n');

                    // Elemen dengan aksi Livewire sendiri (wire:click, wire:submit): tahan aksinya sampai dijawab.
                    el.__livewire_confirm = (action, instead) => {
                        instead();
                        window.primaConfirm(message).then((ok) => ok && action());
                    };

                    // Tombol submit di dalam form wire:submit: Livewire tidak memeriksa konfirmasinya, jadi tahan klik di sini.
                    if (el.type === 'submit' && el.form) {
                        el.addEventListener('click', (event) => {
                            if (el.__primaConfirmed) return;
                            event.preventDefault();
                            event.stopImmediatePropagation();
                            window.primaConfirm(message).then((ok) => {
                                if (! ok) return;
                                el.__primaConfirmed = true;
                                el.form.requestSubmit(el);
                                el.__primaConfirmed = false;
                            });
                        }, true);
                    }
                });
            });
        })();
    </script>

    {{-- Toast notifikasi --}}
    <div x-data="{ show: false, message: '', timer: null }"
        @if (session('status')) x-init="message = @js(session('status')); show = true; timer = setTimeout(() => show = false, 4000)" @endif
        x-on:toast.window="message = $event.detail.message; show = true; clearTimeout(timer); timer = setTimeout(() => show = false, 2800)"
        x-show="show" x-transition.opacity x-cloak
        class="fixed inset-x-0 bottom-28 z-60 flex justify-center px-4 md:bottom-8">
        <div class="rounded-2xl bg-ink px-4 py-2.5 text-sm font-semibold text-bg shadow-xl" x-text="message"></div>
    </div>

    {{-- Antrean sesi fokus yang dicatat saat offline; dikirim otomatis begitu online lagi --}}
    <script data-navigate-once>
        (() => {
            const KEY = 'prima.offlineSessions';
            const SYNC_URL = @js(route('study.sync'));
            const read = () => { try { return JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { return []; } };
            const write = (items) => {
                try { items.length ? localStorage.setItem(KEY, JSON.stringify(items)) : localStorage.removeItem(KEY); } catch (e) {}
                window.dispatchEvent(new CustomEvent('offline-queue-changed', { detail: { count: items.length } }));
            };
            const toast = (message) => window.dispatchEvent(new CustomEvent('toast', { detail: { message } }));
            const uuid = () => window.crypto?.randomUUID?.() ?? '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, (c) =>
                (c ^ (crypto.getRandomValues(new Uint8Array(1))[0] & (15 >> (c / 4)))).toString(16));
            let syncing = false;

            window.primaOffline = {
                count: () => read().length,
                queue(session) { write([...read(), { ...session, client_uuid: uuid() }]); },
                async sync() {
                    const items = read();
                    if (syncing || !items.length || !navigator.onLine) return;
                    syncing = true;
                    try {
                        const response = await fetch(SYNC_URL, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                Accept: 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content,
                            },
                            body: JSON.stringify({ sessions: items }),
                        });
                        if (response.ok) {
                            const { synced } = await response.json();
                            write(read().filter((item) => !synced.includes(item.client_uuid)));
                            toast(`${synced.length} sesi offline tersinkron ✅`);
                        } else if (response.status === 422) {
                            // Buang sesi yang tidak valid lagi (mis. targetnya sudah dihapus) agar sisanya tetap terkirim.
                            const { errors = {} } = await response.json();
                            const invalid = new Set(Object.keys(errors).map((key) => Number(key.split('.')[1])).filter(Number.isInteger));
                            const invalidIds = items.filter((item, index) => invalid.has(index)).map((item) => item.client_uuid);
                            write(read().filter((item) => !invalidIds.includes(item.client_uuid)));
                            if (invalidIds.length) toast(`${invalidIds.length} sesi offline tidak valid dan dilewati`);
                        }
                    } catch (e) {
                        // Tetap di antrean; dicoba lagi saat online atau pindah halaman.
                    } finally {
                        syncing = false;
                    }
                },
                clearPages() { navigator.serviceWorker?.controller?.postMessage('clear-pages'); },
            };

            window.addEventListener('online', () => window.primaOffline.sync());
            document.addEventListener('livewire:navigated', () => window.primaOffline.sync());
            window.primaOffline.sync();
        })();
    </script>

    @include('partials.sw')
</body>

</html>
