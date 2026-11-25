<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="theme-color" content="#f6f5fb" id="theme-color">
{{-- Terapkan tema sebelum CSS dimuat agar tidak berkedip. Pilihan: system | light | dark --}}
<script data-navigate-once>
    (() => {
        const media = window.matchMedia('(prefers-color-scheme: dark)');
        const read = () => { try { return localStorage.getItem('prima.theme') || 'system'; } catch (e) { return 'system'; } };
        const apply = () => {
            const preference = read();
            const dark = preference === 'dark' || (preference === 'system' && media.matches);
            document.documentElement.dataset.theme = dark ? 'dark' : 'light';
            document.getElementById('theme-color')?.setAttribute('content', dark ? '#0c0a14' : '#f6f5fb');
        };
        window.primaTheme = {
            get: read,
            set(preference) {
                try { localStorage.setItem('prima.theme', preference); } catch (e) {}
                apply();
                window.dispatchEvent(new CustomEvent('theme-changed', { detail: { preference } }));
            },
        };
        apply();
        media.addEventListener('change', apply);
        document.addEventListener('livewire:navigated', apply);
    })();
</script>
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Prima Management">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="manifest" href="/manifest.webmanifest">
<link rel="icon" type="image/png" href="/icons/icon-192.png">
<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
<title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>
@fonts
@vite(['resources/css/app.css', 'resources/js/app.js'])
