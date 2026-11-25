@props(['title', 'subtitle' => null, 'icon' => null])

{{-- Judul halaman: ikon bergradasi, judul, subjudul, dan slot "actions" untuk tombol di kanan --}}
<header {{ $attributes->merge(['class' => 'flex items-center justify-between gap-3']) }}>
    <div class="flex min-w-0 items-center gap-3">
        @if ($icon)
            <span class="icon-tile size-11 bg-linear-to-br from-brand to-brand-2 text-on-brand shadow-md shadow-brand/25">
                <x-icon :name="$icon" class="size-5" />
            </span>
        @endif
        <div class="min-w-0">
            <h1 class="truncate text-2xl font-extrabold tracking-tight">{{ $title }}</h1>
            @if ($subtitle)
                <p class="line-clamp-2 text-sm text-muted">{{ $subtitle }}</p>
            @endif
        </div>
    </div>
    @isset($actions)
        <div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>
    @endisset
</header>
