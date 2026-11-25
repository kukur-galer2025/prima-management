@props(['variant' => 'icon'])

{{-- Pilihan tema: Sistem / Terang / Gelap. Disimpan per perangkat (localStorage). --}}
@if ($variant === 'segmented')
    <div x-data="{ preference: window.primaTheme?.get() ?? 'system' }" x-on:theme-changed.window="preference = $event.detail.preference"
        {{ $attributes->merge(['class' => 'grid grid-cols-3 gap-1 rounded-2xl bg-surface-2 p-1']) }} role="radiogroup" aria-label="Tema tampilan">
        @foreach (['system' => ['monitor', 'Sistem'], 'light' => ['sun', 'Terang'], 'dark' => ['moon', 'Gelap']] as $value => [$icon, $label])
            <button type="button" role="radio" x-on:click="window.primaTheme.set('{{ $value }}')" :aria-checked="preference === '{{ $value }}'"
                class="flex items-center justify-center gap-1.5 rounded-xl py-2 text-sm font-semibold transition"
                :class="preference === '{{ $value }}' ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink'">
                <x-icon :name="$icon" class="size-4" /> {{ $label }}
            </button>
        @endforeach
    </div>
@else
    <button type="button" x-data="{ preference: window.primaTheme?.get() ?? 'system' }" x-on:theme-changed.window="preference = $event.detail.preference"
        x-on:click="window.primaTheme.set({ system: 'light', light: 'dark', dark: 'system' }[preference])"
        :aria-label="'Tema: ' + { system: 'ikuti sistem', light: 'terang', dark: 'gelap' }[preference] + '. Ketuk untuk ganti.'"
        {{ $attributes->merge(['class' => 'grid size-10 place-items-center rounded-2xl text-muted transition hover:bg-surface-2 hover:text-ink']) }}>
        <span x-show="preference === 'system'"><x-icon name="monitor" /></span>
        <span x-show="preference === 'light'" x-cloak><x-icon name="sun" /></span>
        <span x-show="preference === 'dark'" x-cloak><x-icon name="moon" /></span>
    </button>
@endif
