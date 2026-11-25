<?php

use App\Models\RecurringTransaction;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Keuangan')] class extends Component
{
    /** Bulan yang ditampilkan, format Y-m. */
    #[Url]
    public string $month = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $type = 'expense';

    public string $amount = '';

    public string $category = '';

    public string $occurred_on = '';

    public string $notes = '';

    public bool $showRecurringForm = false;

    public ?int $recurringId = null;

    public int|string $day_of_month = 1;

    public bool $showBudgetForm = false;

    public string $budgetCategory = '';

    public string $budgetAmount = '';

    public function mount(): void
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month)) {
            $this->month = today()->format('Y-m');
        }

        // Catat transaksi rutin yang jatuh tempo, juga bila scheduler sedang tidak berjalan.
        RecurringTransaction::postDueFor(auth()->user());
    }

    public function period(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $this->month);
    }

    public function shiftMonth(int $delta): void
    {
        $this->month = $this->period()->addMonthsNoOverflow($delta)->format('Y-m');
    }

    #[Computed]
    public function transactions(): Collection
    {
        return auth()->user()->transactions()
            ->whereDate('occurred_on', '>=', $this->period())
            ->whereDate('occurred_on', '<=', $this->period()->endOfMonth())
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->get();
    }

    #[Computed]
    public function recurringTransactions(): Collection
    {
        return auth()->user()->recurringTransactions()->orderBy('day_of_month')->orderBy('category')->get();
    }

    /** @return Collection<string, int> kategori → anggaran */
    #[Computed]
    public function budgets(): Collection
    {
        return auth()->user()->budgets()->pluck('amount', 'category')->map(fn ($amount) => (int) $amount);
    }

    /**
     * @return array{income: int, expense: int, balance: int, budget: ?int, budgetPercent: ?int, categories: Collection<string, int>}
     */
    #[Computed]
    public function summary(): array
    {
        $income = (int) $this->transactions->where('type', 'income')->sum('amount');
        $expense = (int) $this->transactions->where('type', 'expense')->sum('amount');
        $budget = auth()->user()->monthly_budget;

        $spent = $this->transactions->where('type', 'expense')
            ->groupBy('category')
            ->map(fn (Collection $items) => (int) $items->sum('amount'));

        // Kategori yang punya anggaran tetap tampil walau belum ada pengeluaran.
        $categories = $this->budgets->map(fn () => 0)->merge($spent)->sortDesc();

        return [
            'income' => $income,
            'expense' => $expense,
            'balance' => $income - $expense,
            'budget' => $budget,
            'budgetPercent' => $budget ? (int) round($expense / $budget * 100) : null,
            'categories' => $categories,
        ];
    }

    /** @return array<int, string> */
    #[Computed]
    public function categorySuggestions(): array
    {
        $used = auth()->user()->transactions()->where('type', $this->type)->distinct()->pluck('category');

        return collect(Transaction::CATEGORIES[$this->type] ?? [])->merge($used)->unique()->values()->all();
    }

    public function openCreate(string $type = 'expense'): void
    {
        $this->resetForm();
        $this->type = array_key_exists($type, Transaction::TYPES) ? $type : 'expense';
        $this->occurred_on = $this->period()->isSameMonth(today()) ? today()->toDateString() : $this->period()->toDateString();
        $this->showForm = true;
    }

    public function openEdit(int $transactionId): void
    {
        $transaction = auth()->user()->transactions()->findOrFail($transactionId);

        $this->resetForm();
        $this->editingId = $transaction->id;
        $this->type = $transaction->type;
        $this->amount = (string) $transaction->amount;
        $this->category = $transaction->category;
        $this->occurred_on = $transaction->occurred_on->toDateString();
        $this->notes = (string) $transaction->notes;
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
    }

    public function save(): void
    {
        $validated = $this->validateTransaction(['occurred_on' => ['required', 'date']]);

        $attributes = [
            'type' => $validated['type'],
            'amount' => (int) $validated['amount'],
            'category' => trim($validated['category']),
            'occurred_on' => $validated['occurred_on'],
            'notes' => $validated['notes'] ?: null,
        ];

        if ($this->editingId) {
            auth()->user()->transactions()->findOrFail($this->editingId)->update($attributes);
        } else {
            auth()->user()->transactions()->create($attributes);
        }

        $this->closeForm();
        $this->dispatch('toast', message: Transaction::TYPES[$attributes['type']].' '.Transaction::formatRupiah($attributes['amount']).' tersimpan');
    }

    public function delete(int $transactionId): void
    {
        auth()->user()->transactions()->findOrFail($transactionId)->delete();

        $this->closeForm();
        $this->dispatch('toast', message: 'Transaksi dihapus');
    }

    public function openRecurring(?int $recurringId = null): void
    {
        $this->resetForm();
        $this->day_of_month = today()->day;

        if ($recurringId) {
            $recurring = auth()->user()->recurringTransactions()->findOrFail($recurringId);
            $this->recurringId = $recurring->id;
            $this->type = $recurring->type;
            $this->amount = (string) $recurring->amount;
            $this->category = $recurring->category;
            $this->notes = (string) $recurring->notes;
            $this->day_of_month = $recurring->day_of_month;
        }

        $this->showRecurringForm = true;
    }

    public function closeRecurringForm(): void
    {
        $this->showRecurringForm = false;
    }

    public function saveRecurring(): void
    {
        $validated = $this->validateTransaction(['day_of_month' => ['required', 'integer', 'between:1,31']], ['day_of_month' => 'tanggal']);

        $attributes = [
            'type' => $validated['type'],
            'amount' => (int) $validated['amount'],
            'category' => trim($validated['category']),
            'notes' => $validated['notes'] ?: null,
            'day_of_month' => (int) $validated['day_of_month'],
        ];

        if ($this->recurringId) {
            $recurring = auth()->user()->recurringTransactions()->findOrFail($this->recurringId);
            $dayChanged = $recurring->day_of_month !== $attributes['day_of_month'];
            $recurring->update($attributes + ($dayChanged ? ['next_run_on' => RecurringTransaction::firstRunOn($attributes['day_of_month'], today())] : []));
        } else {
            auth()->user()->recurringTransactions()->create($attributes + [
                'next_run_on' => RecurringTransaction::firstRunOn($attributes['day_of_month'], today()),
            ]);
        }

        RecurringTransaction::postDueFor(auth()->user());
        unset($this->transactions, $this->recurringTransactions);

        $this->closeRecurringForm();
        $this->dispatch('toast', message: 'Transaksi rutin disimpan 🔁');
    }

    public function deleteRecurring(int $recurringId): void
    {
        auth()->user()->recurringTransactions()->findOrFail($recurringId)->delete();

        $this->closeRecurringForm();
        $this->dispatch('toast', message: 'Transaksi rutin dihentikan');
    }

    public function openBudget(string $category): void
    {
        $this->resetValidation();
        $this->budgetCategory = $category;
        $this->budgetAmount = (string) ($this->budgets[$category] ?? '');
        $this->showBudgetForm = true;
    }

    public function closeBudgetForm(): void
    {
        $this->showBudgetForm = false;
    }

    public function saveBudget(): void
    {
        $this->budgetAmount = preg_replace('/\D/', '', $this->budgetAmount) ?? '';

        $validated = $this->validate([
            'budgetCategory' => ['required', 'string', 'max:50'],
            'budgetAmount' => ['nullable', 'integer', 'min:1', 'max:999999999999'],
        ], [], ['budgetCategory' => 'kategori', 'budgetAmount' => 'anggaran']);

        $category = trim($validated['budgetCategory']);

        if (blank($validated['budgetAmount'])) {
            auth()->user()->budgets()->where('category', $category)->delete();
        } else {
            auth()->user()->budgets()->updateOrCreate(['category' => $category], ['amount' => (int) $validated['budgetAmount']]);
        }

        unset($this->budgets, $this->summary);
        $this->closeBudgetForm();
        $this->dispatch('toast', message: 'Anggaran '.$category.' disimpan');
    }

    /**
     * @param  array<string, array<int, mixed>>  $extraRules
     * @param  array<string, string>  $extraAttributes
     * @return array<string, mixed>
     */
    private function validateTransaction(array $extraRules, array $extraAttributes = []): array
    {
        // "Rp 50.000" atau "50,000" → "50000"
        $this->amount = preg_replace('/\D/', '', $this->amount) ?? '';

        return $this->validate([
            'type' => ['required', Rule::in(array_keys(Transaction::TYPES))],
            'amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'category' => ['required', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:255'],
            ...$extraRules,
        ], [], ['amount' => 'nominal', 'category' => 'kategori', 'occurred_on' => 'tanggal', 'notes' => 'keterangan', ...$extraAttributes]);
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->reset(['editingId', 'recurringId', 'type', 'amount', 'category', 'occurred_on', 'notes', 'day_of_month']);
    }
};
?>

@php
    $summary = $this->summary;
    $maxCategory = max(1, $summary['categories']->max() ?? 1, $this->budgets->max() ?? 1);
@endphp

<div class="space-y-5">
    <x-page-header title="Keuangan" subtitle="Pemasukan, pengeluaran, dan anggaran." icon="wallet">
        <x-slot:actions>
            <div class="flex items-center gap-0.5 rounded-2xl border border-line bg-surface p-1">
                <button class="rounded-xl p-1.5 text-muted hover:bg-surface-2" wire:click="shiftMonth(-1)" aria-label="Bulan sebelumnya"><x-icon name="chevron-left" class="size-4" /></button>
                <span class="min-w-24 text-center text-sm font-bold">{{ $this->period()->translatedFormat('M Y') }}</span>
                <button class="rounded-xl p-1.5 text-muted hover:bg-surface-2" wire:click="shiftMonth(1)" aria-label="Bulan berikutnya"><x-icon name="chevron-right" class="size-4" /></button>
            </div>
        </x-slot:actions>
    </x-page-header>

    {{-- Ringkasan bulan --}}
    <section class="hero">
        <p class="text-sm font-semibold opacity-80">Selisih {{ $this->period()->translatedFormat('F Y') }}</p>
        <p class="mt-1 text-3xl font-extrabold tracking-tight">{{ Transaction::formatRupiah($summary['balance']) }}</p>
        <div class="mt-4 grid grid-cols-2 gap-2">
            <div class="rounded-2xl p-3" style="background-color: rgb(255 255 255 / 0.12)">
                <p class="flex items-center gap-1 text-xs font-semibold opacity-80"><x-icon name="arrow-up" class="size-3.5" /> Pemasukan</p>
                <p class="truncate font-extrabold">{{ Transaction::formatRupiah($summary['income']) }}</p>
            </div>
            <div class="rounded-2xl p-3" style="background-color: rgb(255 255 255 / 0.12)">
                <p class="flex items-center gap-1 text-xs font-semibold opacity-80"><x-icon name="arrow-down" class="size-3.5" /> Pengeluaran</p>
                <p class="truncate font-extrabold">{{ Transaction::formatRupiah($summary['expense']) }}</p>
            </div>
        </div>
    </section>

    <div class="grid grid-cols-2 gap-2">
        <button class="btn btn-primary" wire:click="openCreate('expense')"><x-icon name="arrow-down" class="size-4" /> Pengeluaran</button>
        <button class="btn btn-ghost" wire:click="openCreate('income')"><x-icon name="arrow-up" class="size-4" /> Pemasukan</button>
    </div>

    <section class="card">
        @if ($summary['budget'])
            <div class="mb-2 flex items-end justify-between gap-3">
                <div>
                    <p class="section-title">Anggaran bulanan</p>
                    <p class="text-xs text-muted">{{ Transaction::formatRupiah($summary['expense']) }} dari {{ Transaction::formatRupiah($summary['budget']) }}</p>
                </div>
                <p @class(['text-xl font-extrabold', 'text-danger' => $summary['budgetPercent'] > 100])>{{ $summary['budgetPercent'] }}%</p>
            </div>
            <x-progress :value="$summary['budgetPercent']" :color="$summary['budgetPercent'] > 100 ? 'var(--danger)' : null" />
            <p class="mt-2 text-xs text-muted">
                {{ $summary['budgetPercent'] > 100
                    ? 'Melebihi anggaran '.Transaction::formatRupiah($summary['expense'] - $summary['budget']).'.'
                    : 'Sisa '.Transaction::formatRupiah($summary['budget'] - $summary['expense']).' bulan ini.' }}
            </p>
        @else
            <p class="text-sm text-muted">Belum ada anggaran bulanan total. <a href="{{ route('account') }}" wire:navigate class="link">Atur di Akun →</a></p>
        @endif
    </section>

    {{-- Per kategori + anggaran per kategori --}}
    <section class="card">
        <div class="mb-3 flex items-center justify-between gap-2">
            <h2 class="section-title">Pengeluaran per kategori</h2>
            <button class="link" wire:click="openBudget('')">+ Anggaran kategori</button>
        </div>
        @forelse ($summary['categories'] as $categoryName => $total)
            @php
                $categoryBudget = $this->budgets[$categoryName] ?? null;
                $overBudget = $categoryBudget && $total > $categoryBudget;
            @endphp
            <button type="button" wire:key="cat-{{ md5($categoryName) }}" wire:click="openBudget(@js($categoryName))" class="mb-3 block w-full text-left last:mb-0">
                <div class="mb-1 flex items-center justify-between gap-2 text-sm">
                    <span class="truncate font-semibold">{{ $categoryName }}</span>
                    <span class="shrink-0 text-muted">
                        <span @class(['font-bold', 'text-danger' => $overBudget, 'text-ink' => !$overBudget])>{{ Transaction::formatRupiah($total) }}</span>
                        @if ($categoryBudget)
                            / {{ Transaction::formatRupiah($categoryBudget) }}
                        @elseif ($summary['expense'])
                            · {{ round($total / $summary['expense'] * 100) }}%
                        @endif
                    </span>
                </div>
                <div class="h-2.5 overflow-hidden rounded-full bg-surface-2">
                    <div @class(['h-full rounded-full', 'bg-danger' => $overBudget, 'bg-expense' => !$overBudget])
                        style="width: {{ $categoryBudget ? min(100, max(2, round($total / $categoryBudget * 100))) : max(2, round($total / $maxCategory * 100)) }}%"></div>
                </div>
                @if ($overBudget)
                    <p class="mt-1 text-xs font-semibold text-danger">Lewat {{ Transaction::formatRupiah($total - $categoryBudget) }} dari anggaran</p>
                @endif
            </button>
        @empty
            <p class="text-sm text-muted">Belum ada pengeluaran di bulan ini.</p>
        @endforelse
        <p class="mt-3 text-xs text-muted">Ketuk kategori untuk mengatur anggarannya.</p>
    </section>

    {{-- Transaksi rutin --}}
    <section class="card">
        <div class="mb-3 flex items-center justify-between gap-2">
            <div>
                <h2 class="section-title">Transaksi rutin</h2>
                <p class="text-xs text-muted">Dicatat otomatis setiap bulan (kos, langganan, gaji…)</p>
            </div>
            <button class="btn btn-ghost px-3 py-2" wire:click="openRecurring" aria-label="Tambah transaksi rutin"><x-icon name="plus" class="size-4" /></button>
        </div>
        @forelse ($this->recurringTransactions as $recurring)
            <button type="button" wire:key="rec-{{ $recurring->id }}" wire:click="openRecurring({{ $recurring->id }})" class="flex w-full items-center gap-3 rounded-2xl px-2 py-2 text-left hover:bg-surface-2">
                <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-surface-2 text-center leading-none">
                    <span><span class="block text-[9px] font-bold text-muted uppercase">tgl</span><span class="text-sm font-extrabold">{{ $recurring->day_of_month }}</span></span>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-bold">{{ $recurring->category }}{{ $recurring->notes ? ' · '.$recurring->notes : '' }}</span>
                    <span class="block text-xs text-muted">Berikutnya {{ $recurring->next_run_on->translatedFormat('d M Y') }}</span>
                </span>
                <span @class(['shrink-0 text-sm font-extrabold', 'text-success' => $recurring->type === 'income'])>
                    {{ $recurring->type === 'income' ? '+' : '−' }}{{ Transaction::formatRupiah($recurring->amount) }}
                </span>
            </button>
        @empty
            <p class="text-sm text-muted">Belum ada. Tambahkan tagihan atau pemasukan yang rutin tiap bulan.</p>
        @endforelse
    </section>

    {{-- Daftar transaksi --}}
    <section>
        <h2 class="section-title mb-2">Transaksi</h2>
        @forelse ($this->transactions->groupBy(fn ($transaction) => $transaction->occurred_on->toDateString()) as $date => $items)
            <div wire:key="day-{{ $date }}" class="mb-3">
                <p class="mb-1 px-1 text-xs font-bold tracking-wide text-muted uppercase">{{ \Illuminate\Support\Carbon::parse($date)->translatedFormat('l, d M') }}</p>
                <div class="card divide-y divide-line p-0">
                    @foreach ($items as $transaction)
                        <button wire:key="trx-{{ $transaction->id }}" wire:click="openEdit({{ $transaction->id }})" class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-surface-2/60">
                            <span @class([
                                'grid size-9 shrink-0 place-items-center rounded-xl',
                                'bg-success/15 text-success' => $transaction->isIncome(),
                                'bg-danger/10 text-danger' => !$transaction->isIncome(),
                            ])>
                                <x-icon :name="$transaction->isIncome() ? 'arrow-up' : 'arrow-down'" class="size-4" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-bold">
                                    {{ $transaction->category }}
                                    @if ($transaction->recurring_transaction_id)
                                        <span class="chip ml-1 bg-surface-2 px-1.5 py-0 text-muted">🔁 rutin</span>
                                    @endif
                                </span>
                                @if ($transaction->notes)
                                    <span class="block truncate text-xs text-muted">{{ $transaction->notes }}</span>
                                @endif
                            </span>
                            <span @class(['shrink-0 text-sm font-extrabold', 'text-success' => $transaction->isIncome()])>
                                {{ $transaction->isIncome() ? '+' : '−' }}{{ Transaction::formatRupiah($transaction->amount) }}
                            </span>
                        </button>
                    @endforeach
                </div>
            </div>
        @empty
            <x-empty-state emoji="💸" title="Belum ada transaksi bulan ini">Catat pengeluaran pertamamu lewat tombol di atas.</x-empty-state>
        @endforelse
    </section>

    {{-- Form transaksi & transaksi rutin memakai field yang sama --}}
    @foreach (['transaction' => [$showForm, 'save', 'closeForm', $editingId ? 'Edit transaksi' : 'Transaksi baru'], 'recurring' => [$showRecurringForm, 'saveRecurring', 'closeRecurringForm', $recurringId ? 'Edit transaksi rutin' : 'Transaksi rutin baru']] as $form => [$visible, $submit, $close, $heading])
        <x-sheet :show="$visible" :title="$heading" :close="$close" wire:key="sheet-{{ $form }}">
            <form wire:submit="{{ $submit }}" class="space-y-4">
                <div class="grid grid-cols-2 gap-1.5 rounded-2xl bg-surface-2 p-1">
                    @foreach (Transaction::TYPES as $value => $label)
                        <button type="button" wire:click="$set('type', '{{ $value }}')" @class([
                            'rounded-xl py-2 text-sm font-bold transition',
                            'bg-surface text-ink shadow-sm' => $type === $value,
                            'text-muted' => $type !== $value,
                        ])>{{ $label }}</button>
                    @endforeach
                </div>

                <div>
                    <label class="label" for="{{ $form }}-amount">Nominal</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2 text-sm font-bold text-muted">Rp</span>
                        <input id="{{ $form }}-amount" wire:model="amount" inputmode="numeric" class="input pl-11 text-lg font-extrabold" placeholder="50.000" autocomplete="off"
                            x-on:input="$el.value = $el.value.replace(/\D/g, '').replace(/\B(?=(\d{3})+(?!\d))/g, '.')">
                    </div>
                    @error('amount') <p class="error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="label" for="{{ $form }}-category">Kategori</label>
                    <input id="{{ $form }}-category" wire:model="category" class="input" list="category-options" placeholder="Pilih atau ketik kategori">
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @foreach (array_slice($this->categorySuggestions, 0, 8) as $suggestion)
                            <button type="button" wire:key="{{ $form }}-suggest-{{ md5($suggestion) }}" wire:click="$set('category', @js($suggestion))" @class([
                                'chip px-3 py-1',
                                'bg-brand text-on-brand' => $category === $suggestion,
                                'bg-surface-2 text-muted hover:text-ink' => $category !== $suggestion,
                            ])>{{ $suggestion }}</button>
                        @endforeach
                    </div>
                    @error('category') <p class="error">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-3">
                    @if ($form === 'transaction')
                        <div>
                            <label class="label" for="occurred_on">Tanggal</label>
                            <input id="occurred_on" type="date" wire:model="occurred_on" class="input">
                            @error('occurred_on') <p class="error">{{ $message }}</p> @enderror
                        </div>
                    @else
                        <div>
                            <label class="label" for="day_of_month">Setiap tanggal</label>
                            <input id="day_of_month" type="number" min="1" max="31" inputmode="numeric" wire:model="day_of_month" class="input">
                            @error('day_of_month') <p class="error">{{ $message }}</p> @enderror
                        </div>
                    @endif
                    <div>
                        <label class="label" for="{{ $form }}-notes">Keterangan</label>
                        <input id="{{ $form }}-notes" wire:model="notes" class="input" placeholder="opsional">
                    </div>
                </div>

                @if ($form === 'recurring')
                    <p class="text-xs text-muted">Bulan yang lebih pendek memakai tanggal terakhirnya (mis. tgl 31 → 28/29 Feb). Transaksi yang sudah tercatat tidak ikut berubah.</p>
                @endif

                <div class="flex gap-2 pt-1">
                    @if ($form === 'transaction' && $editingId)
                        <button type="button" class="btn btn-danger" wire:click="delete({{ $editingId }})" wire:confirm="Hapus transaksi ini?"><x-icon name="trash" class="size-4" /></button>
                    @elseif ($form === 'recurring' && $recurringId)
                        <button type="button" class="btn btn-danger" wire:click="deleteRecurring({{ $recurringId }})" wire:confirm="Hentikan transaksi rutin ini? Transaksi yang sudah tercatat tetap ada."><x-icon name="trash" class="size-4" /></button>
                    @endif
                    <button class="btn btn-primary flex-1" wire:loading.attr="disabled">Simpan</button>
                </div>
            </form>
        </x-sheet>
    @endforeach

    <datalist id="category-options">
        @foreach ($this->categorySuggestions as $suggestion)
            <option value="{{ $suggestion }}">
        @endforeach
    </datalist>

    <x-sheet :show="$showBudgetForm" title="Anggaran kategori" close="closeBudgetForm">
        <form wire:submit="saveBudget" class="space-y-4">
            <div>
                <label class="label" for="budgetCategory">Kategori</label>
                <input id="budgetCategory" wire:model="budgetCategory" class="input" list="expense-categories" placeholder="mis. Makan">
                <datalist id="expense-categories">
                    @foreach (Transaction::CATEGORIES['expense'] as $suggestion)
                        <option value="{{ $suggestion }}">
                    @endforeach
                </datalist>
                @error('budgetCategory') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="budgetAmount">Anggaran per bulan</label>
                <div class="relative">
                    <span class="pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2 text-sm font-bold text-muted">Rp</span>
                    <input id="budgetAmount" wire:model="budgetAmount" inputmode="numeric" class="input pl-11 font-bold" placeholder="kosongkan untuk menghapus"
                        x-on:input="$el.value = $el.value.replace(/\D/g, '').replace(/\B(?=(\d{3})+(?!\d))/g, '.')">
                </div>
                @error('budgetAmount') <p class="error">{{ $message }}</p> @enderror
            </div>
            <button class="btn btn-primary w-full">Simpan anggaran</button>
        </form>
    </x-sheet>
</div>
