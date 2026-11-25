<?php

use App\Models\JournalEntry;
use App\Services\MoodInsights;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Jurnal')] class extends Component
{
    /** Bulan kalender mood, format Y-m. */
    #[Url]
    public string $month = '';

    /** Tanggal entri yang sedang diisi, format Y-m-d. */
    public string $date = '';

    public ?int $mood = null;

    public string $content = '';

    public function mount(): void
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month)) {
            $this->month = today()->format('Y-m');
        }

        $this->selectDate(today()->toDateString());
    }

    public function period(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $this->month);
    }

    public function shiftMonth(int $delta): void
    {
        $this->month = $this->period()->addMonthsNoOverflow($delta)->format('Y-m');
        unset($this->entries);
    }

    /** @return Collection<string, JournalEntry> tanggal → entri di bulan yang ditampilkan */
    #[Computed]
    public function entries(): Collection
    {
        return auth()->user()->journalEntries()
            ->whereDate('entry_on', '>=', $this->period())
            ->whereDate('entry_on', '<=', $this->period()->endOfMonth())
            ->orderByDesc('entry_on')
            ->get()
            ->keyBy(fn (JournalEntry $entry) => $entry->entry_on->toDateString());
    }

    #[Computed]
    public function insights(): array
    {
        return app(MoodInsights::class)->forUser(auth()->user(), today()->subDays(89), today());
    }

    #[Computed]
    public function streak(): int
    {
        $dates = auth()->user()->journalEntries()
            ->whereDate('entry_on', '>=', today()->subDays(365))
            ->pluck('entry_on')
            ->map(fn ($date) => $date->toDateString())
            ->flip();

        $cursor = today()->toImmutable();

        if (! $dates->has($cursor->toDateString())) {
            $cursor = $cursor->subDay();
        }

        $streak = 0;

        while ($dates->has($cursor->toDateString())) {
            $streak++;
            $cursor = $cursor->subDay();
        }

        return $streak;
    }

    public function selectDate(string $date): void
    {
        $parsed = rescue(fn () => CarbonImmutable::createFromFormat('!Y-m-d', $date), report: false);

        if (! $parsed || $parsed->isFuture()) {
            return;
        }

        $entry = auth()->user()->journalEntries()->whereDate('entry_on', $parsed)->first();

        $this->resetValidation();
        $this->date = $parsed->toDateString();
        $this->mood = $entry?->mood;
        $this->content = (string) $entry?->content;
    }

    public function updatedDate(string $value): void
    {
        $this->selectDate($value);
    }

    public function save(): void
    {
        $validated = $this->validate([
            'date' => ['required', 'date', 'before_or_equal:today'],
            'mood' => ['required', 'integer', 'between:1,5'],
            'content' => ['nullable', 'string', 'max:2000'],
        ], ['mood.required' => 'Pilih mood dulu.', 'date.before_or_equal' => 'Jurnal tidak bisa diisi untuk hari esok.'], ['date' => 'tanggal', 'content' => 'catatan']);

        $existing = auth()->user()->journalEntries()->whereDate('entry_on', $validated['date'])->first();
        $attributes = ['mood' => (int) $validated['mood'], 'content' => filled($validated['content']) ? trim($validated['content']) : null];

        $existing ? $existing->update($attributes) : auth()->user()->journalEntries()->create(['entry_on' => $validated['date']] + $attributes);

        unset($this->entries, $this->insights, $this->streak);
        $this->dispatch('toast', message: 'Jurnal '.CarbonImmutable::parse($validated['date'])->translatedFormat('d M').' disimpan '.JournalEntry::MOODS[$attributes['mood']]['emoji']);
    }

    public function delete(): void
    {
        auth()->user()->journalEntries()->whereDate('entry_on', $this->date)->delete();

        $this->mood = null;
        $this->content = '';
        unset($this->entries, $this->insights, $this->streak);
        $this->dispatch('toast', message: 'Entri jurnal dihapus');
    }
};
?>

@php
    $insights = $this->insights;
    $entries = $this->entries;
    $selected = CarbonImmutable::parse($date);
    $hasEntry = auth()->user()->journalEntries()->whereDate('entry_on', $date)->exists();
    $monthStart = $this->period();
    $leadingBlanks = $monthStart->dayOfWeekIso - 1;
    $formatMood = fn (float $value) => number_format($value, 1, ',', '.');
@endphp

<div class="space-y-5">
    <x-page-header title="Jurnal" subtitle="Mood dan satu kalimat tentang harimu." icon="book">
        <x-slot:actions>
            @if ($this->streak > 0)
                <span class="chip bg-brand-soft px-3 py-1.5 text-sm text-brand-ink">🔥 {{ $this->streak }} hari</span>
            @endif
        </x-slot:actions>
    </x-page-header>

    {{-- Isi jurnal --}}
    <section class="card">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h2 class="section-title">{{ $selected->isToday() ? 'Bagaimana harimu?' : 'Jurnal '.$selected->translatedFormat('l, d M') }}</h2>
            <input type="date" wire:model.live="date" max="{{ today()->toDateString() }}" class="input w-auto py-1.5 text-sm" aria-label="Tanggal jurnal">
        </div>
        <form wire:submit="save" class="space-y-3">
            <div class="grid grid-cols-5 gap-1.5" role="radiogroup" aria-label="Mood">
                @foreach (JournalEntry::MOODS as $value => $meta)
                    <button type="button" role="radio" aria-checked="{{ $mood === $value ? 'true' : 'false' }}" wire:click="$set('mood', {{ $value }})" @class([
                        'flex flex-col items-center gap-0.5 rounded-2xl border py-2 transition',
                        'border-brand bg-brand-soft' => $mood === $value,
                        'border-line hover:bg-surface-2' => $mood !== $value,
                    ])>
                        <span @class(['text-3xl transition', 'scale-110' => $mood === $value, 'grayscale-50 opacity-70' => $mood && $mood !== $value])>{{ $meta['emoji'] }}</span>
                        <span class="text-[11px] font-bold text-muted">{{ $meta['label'] }}</span>
                    </button>
                @endforeach
            </div>
            @error('mood') <p class="error">{{ $message }}</p> @enderror
            <textarea wire:model="content" rows="3" class="input" placeholder="Satu kalimat tentang hari ini… (opsional)" aria-label="Catatan jurnal"></textarea>
            @error('content') <p class="error">{{ $message }}</p> @enderror
            @error('date') <p class="error">{{ $message }}</p> @enderror
            <div class="flex gap-2">
                @if ($hasEntry)
                    <button type="button" class="btn btn-danger" wire:click="delete" wire:confirm="Hapus entri jurnal tanggal ini?"><x-icon name="trash" class="size-4" /></button>
                @endif
                <button class="btn btn-primary flex-1" wire:loading.attr="disabled" wire:target="save">{{ $hasEntry ? 'Perbarui' : 'Simpan' }}</button>
            </div>
        </form>
    </section>

    {{-- Pola mood --}}
    <section class="card">
        <h2 class="section-title mb-1">Pola mood</h2>
        <p class="mb-3 text-xs text-muted">90 hari terakhir · {{ $insights['count'] }} entri</p>
        @if ($insights['count'] === 0)
            <p class="text-sm text-muted">Isi jurnal beberapa hari untuk melihat hubungan mood dengan fokus dan kebiasaanmu.</p>
        @else
            <div class="space-y-2.5">
                <div class="flex items-center gap-3 rounded-2xl bg-surface-2 p-3">
                    <span class="text-3xl">{{ JournalEntry::emojiFor($insights['average']) }}</span>
                    <p class="text-sm">Rata-rata mood <b>{{ $formatMood($insights['average']) }}</b> dari 5.</p>
                </div>
                @if ($insights['focus'])
                    @php $focus = $insights['focus']; @endphp
                    <div class="flex items-center gap-3 rounded-2xl bg-surface-2 p-3">
                        <span class="text-3xl">⏱️</span>
                        <p class="text-sm">
                            Di hari dengan fokus ≥ 1 jam, mood rata-rata <b>{{ $formatMood($focus['with']) }}</b>; di hari lain <b>{{ $formatMood($focus['without']) }}</b>.
                            @if ($focus['with'] - $focus['without'] >= 0.3)
                                <span class="text-success">Fokus tampaknya membuat harimu lebih baik.</span>
                            @elseif ($focus['without'] - $focus['with'] >= 0.3)
                                <span class="text-muted">Mungkin kamu butuh lebih banyak jeda di hari sibuk.</span>
                            @endif
                        </p>
                    </div>
                @endif
                @if ($insights['habits'])
                    @php $habits = $insights['habits']; @endphp
                    <div class="flex items-center gap-3 rounded-2xl bg-surface-2 p-3">
                        <span class="text-3xl">✅</span>
                        <p class="text-sm">
                            Saat semua kebiasaan hari itu beres, mood rata-rata <b>{{ $formatMood($habits['with']) }}</b>; kalau tidak, <b>{{ $formatMood($habits['without']) }}</b>.
                        </p>
                    </div>
                @endif
                @if ($insights['bestWeekday'])
                    <div class="flex items-center gap-3 rounded-2xl bg-surface-2 p-3">
                        <span class="text-3xl">📆</span>
                        <p class="text-sm">Hari terbaikmu biasanya <b>{{ $insights['bestWeekday']['day'] }}</b> (rata-rata {{ $formatMood($insights['bestWeekday']['average']) }}).</p>
                    </div>
                @endif
                @if (! $insights['focus'] && ! $insights['habits'])
                    <p class="text-xs text-muted">Perbandingan dengan fokus & kebiasaan muncul setelah ada minimal {{ \App\Services\MoodInsights::MIN_DAYS_PER_GROUP }} hari di masing-masing kelompok.</p>
                @endif
            </div>
        @endif
    </section>

    {{-- Kalender mood --}}
    <section class="card">
        <div class="mb-3 flex items-center justify-between gap-2">
            <h2 class="section-title">Kalender mood</h2>
            <div class="flex items-center gap-0.5 rounded-2xl border border-line bg-surface p-1">
                <button class="rounded-xl p-1.5 text-muted hover:bg-surface-2" wire:click="shiftMonth(-1)" aria-label="Bulan sebelumnya"><x-icon name="chevron-left" class="size-4" /></button>
                <span class="min-w-24 text-center text-sm font-bold">{{ $monthStart->translatedFormat('M Y') }}</span>
                <button class="rounded-xl p-1.5 text-muted hover:bg-surface-2" wire:click="shiftMonth(1)" aria-label="Bulan berikutnya"><x-icon name="chevron-right" class="size-4" /></button>
            </div>
        </div>
        <div class="grid grid-cols-7 gap-1 text-center">
            @foreach (['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'] as $dayName)
                <span class="pb-1 text-[11px] font-bold text-muted">{{ $dayName }}</span>
            @endforeach
            @for ($i = 0; $i < $leadingBlanks; $i++)
                <span></span>
            @endfor
            @for ($day = $monthStart; $day->lte($monthStart->endOfMonth()); $day = $day->addDay())
                @php $entry = $entries[$day->toDateString()] ?? null; @endphp
                <button type="button" wire:key="day-{{ $day->toDateString() }}" wire:click="selectDate('{{ $day->toDateString() }}')" @disabled($day->isFuture())
                    title="{{ $day->translatedFormat('d M') }}{{ $entry ? ': '.$entry->moodLabel() : '' }}"
                    @class([
                        'grid aspect-square place-items-center rounded-xl text-sm transition disabled:opacity-30',
                        'ring-2 ring-brand' => $day->toDateString() === $date,
                        'bg-surface-2 font-semibold text-muted hover:bg-line' => ! $entry,
                        'bg-brand-soft' => $entry,
                    ])>
                    {{ $entry ? $entry->moodEmoji() : $day->day }}
                </button>
            @endfor
        </div>
    </section>

    @if ($entries->filter(fn ($entry) => filled($entry->content))->isNotEmpty())
        <section>
            <h2 class="section-title mb-2">Catatan bulan ini</h2>
            <div class="card divide-y divide-line p-0">
                @foreach ($entries->filter(fn ($entry) => filled($entry->content)) as $entry)
                    <button type="button" wire:key="entry-{{ $entry->id }}" wire:click="selectDate('{{ $entry->entry_on->toDateString() }}')" class="flex w-full items-start gap-3 px-4 py-3 text-left hover:bg-surface-2/60">
                        <span class="text-2xl">{{ $entry->moodEmoji() }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-xs font-bold text-muted">{{ $entry->entry_on->translatedFormat('l, d M') }}</span>
                            <span class="block text-sm whitespace-pre-line">{{ $entry->content }}</span>
                        </span>
                    </button>
                @endforeach
            </div>
        </section>
    @endif
</div>
