<?php

use App\Models\Debt;
use App\Models\JournalEntry;
use App\Models\Transaction;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Cari')] class extends Component
{
    /** Jumlah hasil maksimal per jenis data. */
    public const PER_GROUP = 6;

    #[Url(as: 'q', except: '')]
    public string $q = '';

    public function term(): string
    {
        return trim($this->q);
    }

    /**
     * Hasil pencarian per jenis data. Hanya data milik pengguna yang login.
     *
     * @return array<string, array{icon: string, items: array<int, array{title: string, meta: ?string, url: string}>}>
     */
    #[Computed]
    public function results(): array
    {
        $term = $this->term();

        if (mb_strlen($term) < 2) {
            return [];
        }

        $user = auth()->user();
        $like = "%{$term}%";
        $limit = self::PER_GROUP;
        $match = fn (array $columns) => function ($query) use ($columns, $like) {
            foreach ($columns as $index => $column) {
                $index === 0 ? $query->where($column, 'like', $like) : $query->orWhere($column, 'like', $like);
            }
        };

        $groups = [
            'Tugas' => ['icon' => 'check', 'items' => $user->tasks()->where($match(['title', 'notes']))->orderBy('is_done')->latest('updated_at')->limit($limit)->get()
                ->map(fn ($task) => [
                    'title' => $task->title,
                    'meta' => collect([$task->is_done ? 'Selesai' : ($task->started_at ? 'Dikerjakan' : 'Belum'), $task->dueLabel('d M Y')])->filter()->implode(' · '),
                    'url' => route('tasks', ['q' => $task->title, 'filter' => 'all']),
                ])->all()],
            'Catatan' => ['icon' => 'note', 'items' => $user->notes()->where($match(['title', 'body']))->latest('updated_at')->limit($limit)->get()
                ->map(fn ($note) => [
                    'title' => $note->title,
                    'meta' => Str::limit(trim(preg_replace('/\s+/', ' ', (string) $note->body)), 80) ?: null,
                    'url' => route('notes', ['open' => $note->id]),
                ])->all()],
            'Target' => ['icon' => 'target', 'items' => $user->goals()->where($match(['name', 'description', 'category']))->orderBy('priority')->limit($limit)->get()
                ->map(fn ($goal) => [
                    'title' => $goal->label(),
                    'meta' => $goal->deadline ? 'Deadline '.$goal->deadline->translatedFormat('d M Y') : null,
                    'url' => route('goals.show', $goal),
                ])->all()],
            'Transaksi' => ['icon' => 'wallet', 'items' => $user->transactions()->where($match(['category', 'notes']))->latest('occurred_on')->latest('id')->limit($limit)->get()
                ->map(fn ($transaction) => [
                    'title' => $transaction->category.($transaction->notes ? ' · '.$transaction->notes : ''),
                    'meta' => $transaction->occurred_on->translatedFormat('d M Y').' · '.($transaction->isIncome() ? '+' : '−').Transaction::formatRupiah($transaction->amount),
                    'url' => route('finance', ['month' => $transaction->occurred_on->format('Y-m')]),
                ])->all()],
            'Kebiasaan' => ['icon' => 'repeat', 'items' => $user->habits()->where($match(['name']))->orderBy('name')->limit($limit)->get()
                ->map(fn ($habit) => ['title' => $habit->label(), 'meta' => $habit->scheduleLabel(), 'url' => route('habits')])->all()],
            'Jurnal' => ['icon' => 'book', 'items' => $user->journalEntries()->where($match(['content']))->latest('entry_on')->limit($limit)->get()
                ->map(fn (JournalEntry $entry) => [
                    'title' => $entry->moodEmoji().' '.Str::limit((string) $entry->content, 80),
                    'meta' => $entry->entry_on->translatedFormat('l, d M Y'),
                    'url' => route('journal', ['month' => $entry->entry_on->format('Y-m')]),
                ])->all()],
            'Tabungan' => ['icon' => 'piggy', 'items' => $user->savingsGoals()->where($match(['name']))->withSum('deposits', 'amount')->limit($limit)->get()
                ->map(fn ($goal) => [
                    'title' => trim(($goal->emoji ?? '').' '.$goal->name),
                    'meta' => Transaction::formatRupiah($goal->savedAmount()).' / '.Transaction::formatRupiah($goal->target_amount),
                    'url' => route('savings'),
                ])->all()],
            'Utang & piutang' => ['icon' => 'handshake', 'items' => $user->debts()->where($match(['person', 'notes']))->withSum('payments', 'amount')->latest('started_on')->limit($limit)->get()
                ->map(fn (Debt $debt) => [
                    'title' => ($debt->isPayable() ? 'Utang ke ' : 'Piutang dari ').$debt->person,
                    'meta' => $debt->isSettled() ? 'Lunas' : 'Sisa '.Transaction::formatRupiah($debt->remainingAmount()),
                    'url' => route('debts', ['tab' => $debt->direction]),
                ])->all()],
        ];

        return array_filter($groups, fn (array $group) => $group['items'] !== []);
    }
};
?>

@php
    $results = $this->results;
    $count = collect($results)->sum(fn ($group) => count($group['items']));
@endphp

<div class="space-y-5">
    <x-page-header title="Cari" subtitle="Tugas, catatan, transaksi, target, dan lainnya sekaligus." icon="search" />

    <label class="relative block">
        <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3.5 size-5 -translate-y-1/2 text-muted" />
        <input type="search" wire:model.live.debounce.300ms="q" class="input py-3 pl-11" placeholder="Ketik minimal 2 huruf…" aria-label="Kata kunci" autofocus autocomplete="off">
    </label>

    @if (mb_strlen($this->term()) < 2)
        <x-empty-state emoji="🔎" title="Cari di semua data">Misalnya nama tugas, isi catatan, kategori pengeluaran, atau nama orang di utang-piutang.</x-empty-state>
    @elseif ($count === 0)
        <x-empty-state emoji="🤷" title="Tidak ditemukan">Tidak ada yang cocok dengan "{{ $this->term() }}".</x-empty-state>
    @else
        <p class="px-1 text-sm text-muted">{{ $count }} hasil untuk "<span class="font-semibold text-ink">{{ $this->term() }}</span>"</p>
        @foreach ($results as $groupName => $group)
            <section wire:key="search-{{ Str::slug($groupName) }}">
                <h2 class="mb-2 flex items-center gap-2 px-1 text-sm font-bold tracking-wide text-muted uppercase">
                    <x-icon :name="$group['icon']" class="size-4" /> {{ $groupName }}
                    <span class="font-semibold opacity-70">{{ count($group['items']) }}</span>
                </h2>
                <div class="card divide-y divide-line p-0">
                    @foreach ($group['items'] as $item)
                        <a href="{{ $item['url'] }}" wire:navigate wire:key="search-{{ Str::slug($groupName) }}-{{ $loop->index }}" class="flex items-center gap-3 px-4 py-3 hover:bg-surface-2/60">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold">{{ $item['title'] }}</span>
                                @if ($item['meta'])
                                    <span class="block truncate text-xs text-muted">{{ $item['meta'] }}</span>
                                @endif
                            </span>
                            <x-icon name="chevron-right" class="size-4 shrink-0 text-muted" />
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach
        <p class="px-1 text-xs text-muted">Menampilkan paling banyak {{ $this::PER_GROUP }} hasil per jenis. Perjelas kata kuncinya bila yang dicari belum muncul.</p>
    @endif
</div>
