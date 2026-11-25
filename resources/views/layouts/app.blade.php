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
            ['route' => 'finance', 'match' => 'finance', 'label' => 'Keuangan', 'icon' => 'wallet'],
            ['route' => 'notes', 'match' => 'notes', 'label' => 'Catatan', 'icon' => 'note'],
        ],
        'Wawasan' => [
            ['route' => 'stats', 'match' => 'stats', 'label' => 'Statistik', 'icon' => 'chart'],
            ['route' => 'review', 'match' => 'review', 'label' => 'Review mingguan', 'icon' => 'sparkle'],
        ],
    ];

    // Navigasi bawah HP hanya muat 5 item; sisanya ada di halaman "Lainnya".
    $mobileNav = [
        $groups['Utama'][0],
        $groups['Utama'][1],
        $groups['Utama'][2],
        $groups['Utama'][3],
        ['route' => 'menu', 'match' => ['menu', 'schedule', 'calendar', 'habits', 'finance', 'notes', 'stats', 'review', 'account'], 'label' => 'Lainnya', 'icon' => 'grid'],
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
        <aside class="sticky top-0 hidden h-dvh w-64 shrink-0 flex-col overflow-y-auto border-r border-line/70 bg-surface/70 p-4 backdrop-blur-xl md:flex">
            <a href="{{ route('dashboard') }}" wire:navigate class="mb-6 flex items-center gap-3 px-2">
                <img src="/icons/icon-192.png" alt="" class="size-10 rounded-2xl shadow-md shadow-brand/30">
                <span class="leading-tight">
                    <span class="block text-base font-extrabold tracking-tight">Prima</span>
                    <span class="block text-xs font-semibold text-muted">Management</span>
                </span>
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
            <div class="mt-4 flex items-center gap-2 rounded-2xl border border-line/70 bg-surface p-2">
                <a href="{{ route('account') }}" wire:navigate class="flex min-w-0 flex-1 items-center gap-2 rounded-xl p-1 hover:bg-surface-2">
                    <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-linear-to-br from-brand to-brand-2 text-sm font-extrabold text-on-brand">{{ $initial }}</span>
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-bold">{{ $user->name }}</span>
                        <span class="block truncate text-xs text-muted">Akun & pengaturan</span>
                    </span>
                </a>
                <x-theme-toggle />
                <form method="POST" action="{{ route('logout') }}" onsubmit="window.primaOffline?.clearPages()">
                    @csrf
                    <button class="grid size-10 place-items-center rounded-2xl text-muted transition hover:bg-danger/10 hover:text-danger" aria-label="Keluar" title="Keluar">
                        <x-icon name="logout" />
                    </button>
                </form>
            </div>
        </aside>

        <main class="min-w-0 flex-1 pb-32 md:pb-12">
            {{-- Top bar (HP) --}}
            <div class="sticky top-0 z-30 border-b border-line/50 bg-bg/80 pt-[env(safe-area-inset-top)] backdrop-blur-xl md:hidden">
                <div class="mx-auto flex max-w-3xl items-center justify-between px-4 py-2">
                    <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-2">
                        <img src="/icons/icon-192.png" alt="" class="size-8 rounded-xl">
                        <span class="text-sm font-extrabold tracking-tight">Prima Management</span>
                    </a>
                    <div class="flex items-center gap-1">
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
    <nav class="fixed inset-x-3 z-40 md:hidden" style="bottom: calc(0.75rem + env(safe-area-inset-bottom))">
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

    {{-- Toast notifikasi --}}
    <div x-data="{ show: false, message: '', timer: null }"
        x-on:toast.window="message = $event.detail.message; show = true; clearTimeout(timer); timer = setTimeout(() => show = false, 2800)"
        x-show="show" x-transition.opacity x-cloak
        class="fixed inset-x-0 bottom-28 z-[60] flex justify-center px-4 md:bottom-8">
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
