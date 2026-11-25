@props(['title', 'show' => false, 'close' => 'closeForm'])

{{-- Bottom sheet di HP, modal di tengah di layar lebar --}}
@if ($show)
    <div class="fixed inset-0 z-50 flex items-end justify-center sm:items-center" x-data
        x-on:keydown.escape.window="$wire.{{ $close }}()">
        <div class="absolute inset-0 bg-black/50" wire:click="{{ $close }}"></div>
        <div
            class="pb-safe relative max-h-[92dvh] w-full overflow-y-auto rounded-t-3xl bg-surface p-5 shadow-2xl sm:max-w-lg sm:rounded-3xl">
            <div class="mx-auto mb-3 h-1.5 w-10 rounded-full bg-line sm:hidden"></div>
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-lg font-bold">{{ $title }}</h2>
                <button type="button" class="rounded-full p-1.5 text-muted hover:bg-surface-2" wire:click="{{ $close }}"
                    aria-label="Tutup">
                    <x-icon name="x" />
                </button>
            </div>
            {{ $slot }}
        </div>
    </div>
@endif
