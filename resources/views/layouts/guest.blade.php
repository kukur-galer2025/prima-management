<!DOCTYPE html>
<html lang="id">

<head>
    @include('partials.head')
</head>

<body class="min-h-dvh bg-bg font-sans text-ink antialiased">
    <div class="grid grid-cols-1 min-h-dvh lg:grid-cols-2">
        {{-- Panel perkenalan (layar lebar) --}}
        <aside class="relative isolate hidden overflow-hidden bg-linear-to-br from-brand to-brand-2 p-12 text-on-brand lg:flex lg:flex-col lg:justify-between">
            <div class="absolute -top-24 -right-24 -z-10 size-96 rounded-full bg-white/10"></div>
            <div class="absolute -bottom-32 -left-16 -z-10 size-80 rounded-full bg-white/10"></div>
            <div class="flex items-center gap-3">
                <img src="/icons/icon-192.png" alt="" class="size-11 rounded-2xl shadow-lg ring-2 ring-white/30">
                <span class="text-lg font-extrabold tracking-tight">Prima Management</span>
            </div>
            <div class="max-w-md">
                <h2 class="text-4xl leading-tight font-extrabold tracking-tight">Satu tempat untuk target, waktu, dan kebiasaanmu.</h2>
                <ul class="mt-8 space-y-3 text-base font-medium opacity-90">
                    <li class="flex items-center gap-3"><span class="grid size-8 place-items-center rounded-xl bg-white/15">🎯</span> Target dengan progres & hitung mundur</li>
                    <li class="flex items-center gap-3"><span class="grid size-8 place-items-center rounded-xl bg-white/15">⏱️</span> Sesi fokus, Pomodoro, dan jadwal mingguan</li>
                    <li class="flex items-center gap-3"><span class="grid size-8 place-items-center rounded-xl bg-white/15">🔥</span> Kebiasaan, keuangan, dan review mingguan</li>
                </ul>
            </div>
            <p class="text-sm opacity-70">© {{ now()->year }} Prima Management</p>
        </aside>

        <main class="relative flex items-center justify-center px-4 py-10">
            <div class="absolute top-4 right-4"><x-theme-toggle /></div>
            <div class="w-full max-w-sm">
                {{ $slot }}
            </div>
        </main>
    </div>

    @include('partials.sw')
</body>

</html>
