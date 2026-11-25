@props(['emoji' => '✨', 'title'])

<div {{ $attributes->merge(['class' => 'card flex flex-col items-center gap-2 px-6 py-8 text-center']) }}>
    <span class="mb-1 grid size-14 place-items-center rounded-2xl bg-brand-soft text-3xl">{{ $emoji }}</span>
    <p class="font-bold">{{ $title }}</p>
    <div class="max-w-sm text-sm text-muted">{{ $slot }}</div>
</div>
