@props(['value' => 0, 'size' => 72, 'stroke' => 8, 'color' => 'currentColor', 'track' => 'color-mix(in oklab, currentColor 18%, transparent)'])

@php
    $radius = ($size - $stroke) / 2;
    $circumference = 2 * M_PI * $radius;
    $clamped = max(0, min(100, (int) $value));
@endphp

{{-- Cincin progres; isi slot tampil di tengah --}}
<div {{ $attributes->merge(['class' => 'relative inline-grid shrink-0 place-items-center']) }}
    style="width: {{ $size }}px; height: {{ $size }}px" role="progressbar" aria-valuenow="{{ $clamped }}" aria-valuemin="0" aria-valuemax="100">
    <svg class="absolute inset-0 -rotate-90" viewBox="0 0 {{ $size }} {{ $size }}" aria-hidden="true">
        <circle cx="{{ $size / 2 }}" cy="{{ $size / 2 }}" r="{{ $radius }}" fill="none" stroke="{{ $track }}" stroke-width="{{ $stroke }}" />
        <circle cx="{{ $size / 2 }}" cy="{{ $size / 2 }}" r="{{ $radius }}" fill="none" stroke="{{ $color }}" stroke-width="{{ $stroke }}"
            stroke-linecap="round" stroke-dasharray="{{ round($circumference, 2) }}"
            stroke-dashoffset="{{ round($circumference * (1 - $clamped / 100), 2) }}" class="transition-[stroke-dashoffset] duration-700" />
    </svg>
    <div class="relative text-center leading-tight">{{ $slot }}</div>
</div>
