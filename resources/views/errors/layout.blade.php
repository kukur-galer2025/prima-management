<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · Prima Management</title>
    <style>
        :root { --bg: #f6f5fb; --ink: #1c1830; --muted: #6b6780; --brand: #6d28d9; --on: #fff; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0c0a14; --ink: #eeeaf8; --muted: #9e98b4; --brand: #a78bfa; --on: #14101f; } }
        body { margin: 0; min-height: 100dvh; display: grid; place-items: center; background: var(--bg); color: var(--ink);
            font-family: system-ui, sans-serif; text-align: center; padding: 1rem; box-sizing: border-box; }
        main { max-width: 26rem; }
        img { width: 64px; border-radius: 16px; }
        h1 { margin: 1rem 0 .5rem; font-size: 1.5rem; }
        p { margin: 0; color: var(--muted); line-height: 1.5; }
        small { display: block; margin-top: 1.5rem; color: var(--muted); }
        a { display: inline-block; margin-top: 1.25rem; border-radius: 12px; padding: .75rem 1.25rem; font-weight: 700;
            background: var(--brand); color: var(--on); text-decoration: none; }
    </style>
</head>
<body>
    <main>
        <img src="/icons/icon-192.png" alt="">
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <a href="@yield('href', '/')">@yield('action', 'Kembali ke Beranda')</a>
        <small>Kode @yield('code')</small>
    </main>
</body>
</html>
