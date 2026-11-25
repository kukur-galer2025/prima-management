@props(['value' => 0, 'color' => null])

<div {{ $attributes->merge(['class' => 'h-2 w-full overflow-hidden rounded-full bg-surface-2']) }} role="progressbar"
    aria-valuenow="{{ $value }}" aria-valuemin="0" aria-valuemax="100">
    <div class="h-full rounded-full bg-brand transition-all duration-500"
        style="width: {{ max(0, min(100, $value)) }}%; {{ $color ? "background-color: {$color}" : '' }}"></div>
</div>
