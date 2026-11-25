@props(['days' => [], 'from', 'to', 'color' => 'var(--brand)', 'emptyLabel' => 'tidak ada aktivitas'])

@php
    // $days: ['Y-m-d' => ['level' => 0..4, 'label' => 'teks tooltip']]
    $first = \Carbon\CarbonImmutable::parse($from)->startOfDay();
    $last = \Carbon\CarbonImmutable::parse($to)->startOfDay();
    $mix = [1 => 30, 2 => 55, 3 => 80, 4 => 100];
    $weeks = [];

    for ($week = $first->startOfWeek(1); $week->lte($last); $week = $week->addWeek()) {
        $cells = [];
        for ($offset = 0; $offset < 7; $offset++) {
            $date = $week->addDays($offset);
            $cells[] = $date->lt($first) || $date->gt($last)
                ? null
                : ['level' => 0, 'label' => $date->translatedFormat('D, d M Y').': '.$emptyLabel, ...($days[$date->toDateString()] ?? [])];
        }
        $weeks[] = ['month' => $week->addDays(6)->day <= 7 ? $week->addDays(6)->translatedFormat('M') : '', 'cells' => $cells];
    }

    $fill = fn (int $level) => $level === 0 ? 'var(--surface-2)' : "color-mix(in oklab, {$color} {$mix[$level]}%, var(--surface-2))";
@endphp

{{-- Kalender panas: satu kolom = satu minggu, satu kotak = satu hari --}}
<div x-data="{ readout: null }" class="space-y-2">
    <div class="overflow-x-auto pb-1" x-init="$el.scrollLeft = $el.scrollWidth"
        x-on:mouseover="if ($event.target.dataset.label) readout = $event.target.dataset.label"
        x-on:click="if ($event.target.dataset.label) readout = $event.target.dataset.label">
        <div class="inline-flex gap-[3px]">
            @foreach ($weeks as $week)
                <div class="flex flex-col gap-[3px]">
                    <span class="h-3 text-[9px] leading-3 font-semibold whitespace-nowrap text-muted">{{ $week['month'] }}</span>
                    @foreach ($week['cells'] as $cell)
                        @if ($cell)
                            <span class="size-3 rounded-[3px]" style="background-color: {{ $fill($cell['level']) }}" data-label="{{ $cell['label'] }}" title="{{ $cell['label'] }}"></span>
                        @else
                            <span class="size-3"></span>
                        @endif
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>
    <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-muted">
        <span class="font-medium" x-text="readout ?? 'Arahkan atau ketuk kotak untuk detail'">Arahkan atau ketuk kotak untuk detail</span>
        <span class="flex items-center gap-1">
            Sedikit
            @foreach ([0, 1, 2, 3, 4] as $level)
                <span class="size-3 rounded-[3px]" style="background-color: {{ $fill($level) }}"></span>
            @endforeach
            Banyak
        </span>
    </div>
</div>
