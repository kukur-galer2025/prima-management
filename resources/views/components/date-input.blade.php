@props(['type' => 'date', 'placeholder' => null])

@php
    $model = $attributes->wire('model')->value();
    $placeholder ??= $type === 'time' ? 'Jam' : 'Pilih tanggal';
@endphp

{{--
    Kolom tanggal/jam. Safari iPhone menampilkan kolom kosong sebagai kotak polos tanpa petunjuk,
    jadi petunjuk ditampilkan di atasnya (hanya di iOS, lihat .date-placeholder di app.css) selama nilainya kosong.
--}}
<div class="relative min-w-0 {{ $attributes->get('wrapper-class') }}">
    <input type="{{ $type }}" {{ $attributes->except('wrapper-class')->merge(['class' => 'input']) }}>
    @if ($model)
        <span class="date-placeholder" aria-hidden="true" x-data x-show="! $wire.{{ $model }}">{{ $placeholder }}</span>
    @endif
</div>
