<?php

use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Utang & piutang')] class extends Component
{
    #[Url(except: 'payable')]
    public string $tab = 'payable';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $direction = 'payable';

    public string $person = '';

    public string $amount = '';

    public int|string|null $wallet_id = null;

    public string $started_on = '';

    public ?string $due_on = null;

    public string $notes = '';

    public bool $showPaymentForm = false;

    public ?int $paymentDebtId = null;

    public string $paymentAmount = '';

    public int|string|null $paymentWalletId = null;

    public string $paymentDate = '';

    public function mount(): void
    {
        if (! array_key_exists($this->tab, Debt::DIRECTIONS)) {
            $this->tab = 'payable';
        }
    }

    #[Computed]
    public function debts(): Collection
    {
        return auth()->user()->debts()
            ->where('direction', $this->tab)
            ->withSum('payments', 'amount')
            ->with(['payments' => fn ($query) => $query->with('wallet')->latest('paid_on')->latest('id')])
            ->orderByRaw('settled_at is not null')
            ->orderByRaw('due_on is null')
            ->orderBy('due_on')
            ->latest('started_on')
            ->get();
    }

    /** @return array{payable: int, receivable: int} sisa yang belum lunas per arah */
    #[Computed]
    public function totals(): array
    {
        $open = auth()->user()->debts()->whereNull('settled_at')->withSum('payments', 'amount')->get();

        return [
            'payable' => (int) $open->where('direction', 'payable')->sum(fn (Debt $debt) => $debt->remainingAmount()),
            'receivable' => (int) $open->where('direction', 'receivable')->sum(fn (Debt $debt) => $debt->remainingAmount()),
        ];
    }

    #[Computed]
    public function wallets(): Collection
    {
        return auth()->user()->wallets()->orderBy('position')->orderBy('id')->get();
    }

    public function openCreate(): void
    {
        $this->resetValidation();
        $this->reset(['editingId', 'person', 'amount', 'due_on', 'notes', 'wallet_id']);
        $this->direction = $this->tab;
        $this->started_on = today()->toDateString();
        $this->showForm = true;
    }

    public function openEdit(int $debtId): void
    {
        $debt = auth()->user()->debts()->findOrFail($debtId);

        $this->resetValidation();
        $this->editingId = $debt->id;
        $this->direction = $debt->direction;
        $this->person = $debt->person;
        $this->amount = number_format($debt->amount, 0, ',', '.');
        $this->wallet_id = $debt->wallet_id;
        $this->started_on = $debt->started_on->toDateString();
        $this->due_on = $debt->due_on?->toDateString();
        $this->notes = (string) $debt->notes;
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
    }

    public function save(): void
    {
        $this->amount = (string) preg_replace('/\D/', '', $this->amount);

        $validated = $this->validate([
            'direction' => ['required', Rule::in(array_keys(Debt::DIRECTIONS))],
            'person' => ['required', 'string', 'max:80'],
            'amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'wallet_id' => ['nullable', Rule::exists('wallets', 'id')->where('user_id', auth()->id())],
            'started_on' => ['required', 'date'],
            'due_on' => ['nullable', 'date', 'after_or_equal:started_on'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], ['due_on.after_or_equal' => 'Jatuh tempo tidak boleh sebelum tanggal pinjam.'], [
            'person' => 'nama', 'amount' => 'nominal', 'wallet_id' => 'dompet', 'started_on' => 'tanggal', 'due_on' => 'jatuh tempo',
        ]);

        $attributes = [
            'direction' => $validated['direction'],
            'person' => trim($validated['person']),
            'amount' => (int) $validated['amount'],
            'wallet_id' => filled($validated['wallet_id']) ? (int) $validated['wallet_id'] : null,
            'started_on' => $validated['started_on'],
            'due_on' => $validated['due_on'] ?: null,
            'notes' => $validated['notes'] ?: null,
        ];

        if ($this->editingId) {
            $debt = auth()->user()->debts()->findOrFail($this->editingId);
            $debt->update($attributes);
        } else {
            $debt = auth()->user()->debts()->create($attributes);
        }

        $debt->syncSettlement();
        $this->tab = $debt->direction;

        unset($this->debts, $this->totals);
        $this->closeForm();
        $this->dispatch('toast', message: Debt::DIRECTIONS[$debt->direction].' '.$debt->person.' disimpan');
    }

    public function delete(int $debtId): void
    {
        auth()->user()->debts()->findOrFail($debtId)->delete();

        unset($this->debts, $this->totals);
        $this->closeForm();
        $this->dispatch('toast', message: 'Catatan dihapus');
    }

    public function openPayment(int $debtId): void
    {
        $debt = auth()->user()->debts()->withSum('payments', 'amount')->findOrFail($debtId);

        $this->resetValidation();
        $this->paymentDebtId = $debt->id;
        $this->paymentAmount = number_format($debt->remainingAmount(), 0, ',', '.');
        $this->paymentWalletId = $debt->wallet_id ?? $this->wallets->first()?->id;
        $this->paymentDate = today()->toDateString();
        $this->showPaymentForm = true;
    }

    public function closePaymentForm(): void
    {
        $this->showPaymentForm = false;
    }

    public function savePayment(): void
    {
        $debt = auth()->user()->debts()->withSum('payments', 'amount')->findOrFail($this->paymentDebtId);
        $this->paymentAmount = (string) preg_replace('/\D/', '', $this->paymentAmount);

        $validated = $this->validate([
            'paymentAmount' => ['required', 'integer', 'min:1', 'max:'.max(1, $debt->remainingAmount())],
            'paymentWalletId' => ['nullable', Rule::exists('wallets', 'id')->where('user_id', auth()->id())],
            'paymentDate' => ['required', 'date'],
        ], ['paymentAmount.max' => 'Melebihi sisa '.Transaction::formatRupiah($debt->remainingAmount()).'.'], [
            'paymentAmount' => 'nominal', 'paymentWalletId' => 'dompet', 'paymentDate' => 'tanggal',
        ]);

        $debt->payments()->create([
            'amount' => (int) $validated['paymentAmount'],
            'wallet_id' => filled($validated['paymentWalletId']) ? (int) $validated['paymentWalletId'] : null,
            'paid_on' => $validated['paymentDate'],
        ]);

        $debt->syncSettlement();

        unset($this->debts, $this->totals);
        $this->closePaymentForm();
        $this->dispatch('toast', message: $debt->isSettled() ? "Lunas! {$debt->person} ✅" : 'Pembayaran '.Transaction::formatRupiah((int) $validated['paymentAmount']).' dicatat');
    }

    public function deletePayment(int $paymentId): void
    {
        $payment = DebtPayment::query()->whereIn('debt_id', auth()->user()->debts()->select('id'))->findOrFail($paymentId);
        $debt = $payment->debt;
        $payment->delete();
        $debt->syncSettlement();

        unset($this->debts, $this->totals);
        $this->dispatch('toast', message: 'Pembayaran dihapus');
    }
};
?>

@php
    $totals = $this->totals;
    $isPayable = $tab === 'payable';
    $paymentDebt = $paymentDebtId ? $this->debts->firstWhere('id', $paymentDebtId) : null;
@endphp

<div class="space-y-5">
    <x-page-header title="Utang & piutang" subtitle="Siapa berutang ke siapa, dan kapan jatuh temponya." icon="handshake">
        <x-slot:actions>
            <button class="btn btn-primary" wire:click="openCreate"><x-icon name="plus" class="size-4" /> Catat</button>
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-2 gap-2">
        @foreach (Debt::DIRECTIONS as $value => $label)
            <button wire:click="$set('tab', '{{ $value }}')" @class([
                'card p-4 text-left transition',
                'ring-2 ring-brand' => $tab === $value,
                'opacity-80 hover:opacity-100' => $tab !== $value,
            ])>
                <span class="block text-xs font-semibold text-muted">{{ $value === 'payable' ? 'Utang saya' : 'Piutang saya' }}</span>
                <span @class(['mt-1 block truncate text-xl font-extrabold', 'text-danger' => $value === 'payable' && $totals['payable'] > 0, 'text-success' => $value === 'receivable' && $totals['receivable'] > 0])>
                    {{ Transaction::formatRupiah($totals[$value]) }}
                </span>
            </button>
        @endforeach
    </div>

    @forelse ($this->debts as $debt)
        @php $paid = $debt->paidAmount(); $percent = $debt->amount ? (int) floor($paid / $debt->amount * 100) : 0; @endphp
        <section wire:key="debt-{{ $debt->id }}" @class(['card', 'opacity-70' => $debt->isSettled()]) x-data="{ open: false }">
            <div class="flex items-start gap-3">
                <span @class([
                    'grid size-11 shrink-0 place-items-center rounded-2xl text-base font-extrabold',
                    'bg-danger/10 text-danger' => $isPayable && ! $debt->isSettled(),
                    'bg-success/15 text-success' => ! $isPayable || $debt->isSettled(),
                ])>{{ mb_strtoupper(mb_substr($debt->person, 0, 1)) }}</span>
                <div class="min-w-0 flex-1">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <h2 class="truncate font-bold">{{ $debt->person }}</h2>
                            <p class="truncate text-xs text-muted">
                                {{ $isPayable ? 'Pinjam' : 'Dipinjamkan' }} {{ $debt->started_on->translatedFormat('d M Y') }}{{ $debt->notes ? ' · '.$debt->notes : '' }}
                            </p>
                        </div>
                        <button class="shrink-0 rounded-lg p-1 text-muted hover:bg-surface-2 hover:text-ink" wire:click="openEdit({{ $debt->id }})" aria-label="Edit"><x-icon name="pencil" class="size-4" /></button>
                    </div>
                    <p class="mt-1 text-sm">
                        @if ($debt->isSettled())
                            <span class="chip bg-success/15 text-success">Lunas</span>
                            <span class="text-muted">{{ Transaction::formatRupiah($debt->amount) }}</span>
                        @else
                            Sisa <span class="font-extrabold">{{ Transaction::formatRupiah($debt->remainingAmount()) }}</span>
                            <span class="text-muted">dari {{ Transaction::formatRupiah($debt->amount) }}</span>
                        @endif
                    </p>
                </div>
            </div>

            @unless ($debt->isSettled())
                <div class="mt-3">
                    <x-progress :value="$percent" :color="$isPayable ? 'var(--danger)' : 'var(--success)'" />
                    <div class="mt-1.5 flex flex-wrap items-center justify-between gap-x-3 text-xs text-muted">
                        <span>{{ $percent }}% {{ $isPayable ? 'terbayar' : 'kembali' }}</span>
                        @if ($debt->due_on)
                            <span @class(['font-semibold', 'text-danger' => $debt->isOverdue(), 'text-ink' => ! $debt->isOverdue() && today()->diffInDays($debt->due_on) <= 3])>
                                @if ($debt->isOverdue())
                                    Lewat tempo {{ $debt->due_on->translatedFormat('d M') }}
                                @elseif ($debt->due_on->isToday())
                                    Jatuh tempo hari ini
                                @else
                                    Jatuh tempo {{ $debt->due_on->translatedFormat('d M Y') }}
                                @endif
                            </span>
                        @endif
                    </div>
                </div>
            @endunless

            <div class="mt-3 flex gap-2">
                @unless ($debt->isSettled())
                    <button class="btn btn-primary flex-1 py-2 text-sm" wire:click="openPayment({{ $debt->id }})">{{ $isPayable ? 'Bayar' : 'Terima pembayaran' }}</button>
                @endunless
                @if ($debt->payments->isNotEmpty())
                    <button class="btn btn-ghost py-2 text-sm" x-on:click="open = ! open" :aria-expanded="open">Riwayat <x-icon name="chevron-right" class="size-4 transition" ::class="open && 'rotate-90'" /></button>
                @endif
            </div>

            @if ($debt->payments->isNotEmpty())
                <div x-show="open" x-cloak class="mt-3 divide-y divide-line border-t border-line">
                    @foreach ($debt->payments as $payment)
                        <div wire:key="payment-{{ $payment->id }}" class="flex items-center gap-2 py-2 text-sm">
                            <span class="min-w-0 flex-1">
                                <span class="block">{{ $payment->paid_on->translatedFormat('d M Y') }}</span>
                                @if ($payment->wallet)
                                    <span class="block truncate text-xs text-muted">{{ $payment->wallet->name }}</span>
                                @endif
                            </span>
                            <span class="shrink-0 font-bold">{{ Transaction::formatRupiah($payment->amount) }}</span>
                            <button class="rounded-lg p-1 text-muted hover:bg-danger/10 hover:text-danger" wire:click="deletePayment({{ $payment->id }})" wire:confirm="Hapus pembayaran ini?" aria-label="Hapus"><x-icon name="trash" class="size-4" /></button>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    @empty
        <x-empty-state :emoji="$isPayable ? '🤝' : '📒'" :title="$isPayable ? 'Tidak ada utang' : 'Tidak ada piutang'">
            {{ $isPayable ? 'Catat pinjaman dari teman atau keluarga supaya tidak lupa membayar.' : 'Catat uang yang kamu pinjamkan supaya tidak lupa menagih.' }}
        </x-empty-state>
    @endforelse

    <x-sheet :show="$showForm" :title="$editingId ? 'Edit catatan' : 'Catat utang / piutang'">
        <form wire:submit="save" class="space-y-4">
            <div class="grid grid-cols-2 gap-1.5 rounded-2xl bg-surface-2 p-1">
                @foreach (['payable' => 'Saya meminjam', 'receivable' => 'Saya meminjamkan'] as $value => $label)
                    <button type="button" wire:click="$set('direction', '{{ $value }}')" @class([
                        'rounded-xl py-2 text-sm font-bold transition',
                        'bg-surface text-ink shadow-sm' => $direction === $value,
                        'text-muted' => $direction !== $value,
                    ])>{{ $label }}</button>
                @endforeach
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="label" for="debt-person">{{ $direction === 'payable' ? 'Pinjam dari' : 'Dipinjam oleh' }}</label>
                    <input id="debt-person" wire:model="person" class="input" placeholder="Nama">
                    @error('person') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="debt-amount">Nominal</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2 text-sm font-bold text-muted">Rp</span>
                        <input id="debt-amount" wire:model="amount" inputmode="numeric" class="input pl-11 font-bold" placeholder="500.000"
                            x-on:input="$el.value = $el.value.replace(/\D/g, '').replace(/\B(?=(\d{3})+(?!\d))/g, '.')">
                    </div>
                    @error('amount') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="debt-start">Tanggal</label>
                    <input id="debt-start" type="date" wire:model="started_on" class="input">
                    @error('started_on') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="debt-due">Jatuh tempo</label>
                    <input id="debt-due" type="date" wire:model="due_on" class="input">
                    @error('due_on') <p class="error">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label class="label" for="debt-wallet">{{ $direction === 'payable' ? 'Uangnya masuk ke dompet' : 'Uangnya keluar dari dompet' }}</label>
                <select id="debt-wallet" wire:model="wallet_id" class="input">
                    <option value="">Tidak memengaruhi dompet</option>
                    @foreach ($this->wallets as $wallet)
                        <option value="{{ $wallet->id }}">{{ $wallet->emoji() }} {{ $wallet->name }}</option>
                    @endforeach
                </select>
                @error('wallet_id') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="debt-notes">Keterangan</label>
                <input id="debt-notes" wire:model="notes" class="input" placeholder="opsional, mis. untuk bayar kos">
            </div>
            <p class="text-xs text-muted">Pengingat dikirim di ringkasan pagi saat jatuh tempo tiba.</p>
            <div class="flex gap-2 pt-1">
                @if ($editingId)
                    <button type="button" class="btn btn-danger" wire:click="delete({{ $editingId }})" wire:confirm="Hapus catatan ini beserta riwayat pembayarannya?"><x-icon name="trash" class="size-4" /></button>
                @endif
                <button class="btn btn-primary flex-1">Simpan</button>
            </div>
        </form>
    </x-sheet>

    <x-sheet :show="$showPaymentForm" :title="($paymentDebt?->isPayable() ? 'Bayar ke ' : 'Terima dari ').($paymentDebt?->person ?? '')" close="closePaymentForm">
        <form wire:submit="savePayment" class="space-y-4">
            <div>
                <label class="label" for="paymentAmount">Nominal</label>
                <div class="relative">
                    <span class="pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2 text-sm font-bold text-muted">Rp</span>
                    <input id="paymentAmount" wire:model="paymentAmount" inputmode="numeric" class="input pl-11 text-lg font-extrabold"
                        x-on:input="$el.value = $el.value.replace(/\D/g, '').replace(/\B(?=(\d{3})+(?!\d))/g, '.')">
                </div>
                @if ($paymentDebt)
                    <p class="mt-1 text-xs text-muted">Sisa {{ Transaction::formatRupiah($paymentDebt->remainingAmount()) }}. Isi sebagian untuk mencicil.</p>
                @endif
                @error('paymentAmount') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="label" for="paymentWalletId">{{ $paymentDebt?->isPayable() ? 'Dari dompet' : 'Ke dompet' }}</label>
                    <select id="paymentWalletId" wire:model="paymentWalletId" class="input">
                        <option value="">Tidak memengaruhi dompet</option>
                        @foreach ($this->wallets as $wallet)
                            <option value="{{ $wallet->id }}">{{ $wallet->emoji() }} {{ $wallet->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="label" for="paymentDate">Tanggal</label>
                    <input id="paymentDate" type="date" wire:model="paymentDate" class="input">
                    @error('paymentDate') <p class="error">{{ $message }}</p> @enderror
                </div>
            </div>
            <button class="btn btn-primary w-full">Simpan</button>
        </form>
    </x-sheet>
</div>
