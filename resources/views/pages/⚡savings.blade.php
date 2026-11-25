<?php

use App\Models\SavingsDeposit;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Tabungan')] class extends Component
{
    public const EMOJIS = ['💰', '💻', '📱', '🏍️', '🏠', '🎓', '✈️', '🏖️', '💍', '🛟', '🎁', '🩺'];

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $emoji = '💰';

    public string $target_amount = '';

    public ?string $deadline = null;

    public bool $showDepositForm = false;

    public ?int $depositGoalId = null;

    public string $depositMode = 'deposit';

    public string $depositAmount = '';

    public int|string|null $depositWalletId = null;

    public string $depositDate = '';

    public string $depositNotes = '';

    #[Computed]
    public function goals(): Collection
    {
        return auth()->user()->savingsGoals()
            ->withSum('deposits', 'amount')
            ->with(['deposits' => fn ($query) => $query->with('wallet')->latest('deposited_on')->latest('id')])
            ->orderByRaw('deadline is null')
            ->orderBy('deadline')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function wallets(): Collection
    {
        return auth()->user()->wallets()->orderBy('position')->orderBy('id')->get();
    }

    public function openCreate(): void
    {
        $this->resetValidation();
        $this->reset(['editingId', 'name', 'emoji', 'target_amount', 'deadline']);
        $this->showForm = true;
    }

    public function openEdit(int $goalId): void
    {
        $goal = auth()->user()->savingsGoals()->findOrFail($goalId);

        $this->resetValidation();
        $this->editingId = $goal->id;
        $this->name = $goal->name;
        $this->emoji = $goal->emoji ?: '💰';
        $this->target_amount = number_format($goal->target_amount, 0, ',', '.');
        $this->deadline = $goal->deadline?->toDateString();
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
    }

    public function save(): void
    {
        $this->target_amount = (string) preg_replace('/\D/', '', $this->target_amount);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'emoji' => ['nullable', 'string', 'max:16'],
            'target_amount' => ['required', 'integer', 'min:1000', 'max:999999999999'],
            'deadline' => ['nullable', 'date'],
        ], [], ['name' => 'nama tabungan', 'target_amount' => 'target', 'deadline' => 'tenggat']);

        $attributes = [
            'name' => trim($validated['name']),
            'emoji' => $validated['emoji'] ?: null,
            'target_amount' => (int) $validated['target_amount'],
            'deadline' => $validated['deadline'] ?: null,
        ];

        if ($this->editingId) {
            auth()->user()->savingsGoals()->findOrFail($this->editingId)->update($attributes);
        } else {
            auth()->user()->savingsGoals()->create($attributes);
        }

        unset($this->goals);
        $this->closeForm();
        $this->dispatch('toast', message: 'Tabungan '.$attributes['name'].' disimpan');
    }

    public function delete(int $goalId): void
    {
        $goal = auth()->user()->savingsGoals()->findOrFail($goalId);
        $goal->delete();

        unset($this->goals);
        $this->closeForm();
        $this->dispatch('toast', message: 'Tabungan '.$goal->name.' dihapus');
    }

    public function openDeposit(int $goalId, string $mode = 'deposit'): void
    {
        auth()->user()->savingsGoals()->findOrFail($goalId);

        $this->resetValidation();
        $this->reset(['depositAmount', 'depositNotes']);
        $this->depositGoalId = $goalId;
        $this->depositMode = $mode === 'withdraw' ? 'withdraw' : 'deposit';
        $this->depositWalletId = $this->wallets->first()?->id;
        $this->depositDate = today()->toDateString();
        $this->showDepositForm = true;
    }

    public function closeDepositForm(): void
    {
        $this->showDepositForm = false;
    }

    public function saveDeposit(): void
    {
        $goal = auth()->user()->savingsGoals()->withSum('deposits', 'amount')->findOrFail($this->depositGoalId);
        $this->depositAmount = (string) preg_replace('/\D/', '', $this->depositAmount);
        $isWithdrawal = $this->depositMode === 'withdraw';

        $validated = $this->validate([
            'depositMode' => ['required', Rule::in(['deposit', 'withdraw'])],
            'depositAmount' => ['required', 'integer', 'min:1', 'max:'.($isWithdrawal ? max(1, $goal->savedAmount()) : 999999999999)],
            'depositWalletId' => ['nullable', Rule::exists('wallets', 'id')->where('user_id', auth()->id())],
            'depositDate' => ['required', 'date'],
            'depositNotes' => ['nullable', 'string', 'max:255'],
        ], ['depositAmount.max' => 'Penarikan tidak boleh melebihi saldo tabungan ('.Transaction::formatRupiah($goal->savedAmount()).').'], [
            'depositAmount' => 'nominal', 'depositWalletId' => 'dompet', 'depositDate' => 'tanggal',
        ]);

        $amount = (int) $validated['depositAmount'];
        $wasReached = $goal->isReached();

        $goal->deposits()->create([
            'wallet_id' => filled($validated['depositWalletId']) ? (int) $validated['depositWalletId'] : null,
            'amount' => $isWithdrawal ? -$amount : $amount,
            'deposited_on' => $validated['depositDate'],
            'notes' => $validated['depositNotes'] ?: null,
        ]);

        $goal = $goal->fresh()->loadSum('deposits', 'amount');

        unset($this->goals);
        $this->closeDepositForm();
        $this->dispatch('toast', message: ! $wasReached && $goal->isReached()
            ? "Target {$goal->name} tercapai! 🎉"
            : ($isWithdrawal ? 'Penarikan ' : 'Setoran ').Transaction::formatRupiah($amount).' dicatat');
    }

    public function deleteDeposit(int $depositId): void
    {
        $goalIds = auth()->user()->savingsGoals()->pluck('id');
        SavingsDeposit::query()->whereIn('savings_goal_id', $goalIds)->findOrFail($depositId)->delete();

        unset($this->goals);
        $this->dispatch('toast', message: 'Riwayat dihapus');
    }
};
?>

@php
    $goals = $this->goals;
    $totalSaved = $goals->sum(fn ($goal) => $goal->savedAmount());
    $totalTarget = $goals->sum('target_amount');
    $depositGoal = $depositGoalId ? $goals->firstWhere('id', $depositGoalId) : null;
@endphp

<div class="space-y-5">
    <x-page-header title="Tabungan" subtitle="Menabung untuk tujuan yang jelas." icon="piggy">
        <x-slot:actions>
            <button class="btn btn-primary" wire:click="openCreate"><x-icon name="plus" class="size-4" /> Tabungan</button>
        </x-slot:actions>
    </x-page-header>

    @if ($goals->isNotEmpty())
        <section class="hero">
            <p class="text-sm font-semibold opacity-80">Total terkumpul</p>
            <p class="mt-1 text-3xl font-extrabold tracking-tight">{{ Transaction::formatRupiah($totalSaved) }}</p>
            <p class="mt-1 text-sm opacity-80">dari target {{ Transaction::formatRupiah($totalTarget) }} · {{ $goals->filter->isReached()->count() }} dari {{ $goals->count() }} tercapai</p>
        </section>
    @endif

    @forelse ($goals as $goal)
        @php $reached = $goal->isReached(); $monthly = $goal->monthlyNeeded(); @endphp
        <section wire:key="saving-{{ $goal->id }}" class="card" x-data="{ open: false }">
            <div class="flex items-start gap-3">
                <span class="grid size-12 shrink-0 place-items-center rounded-2xl bg-brand-soft text-2xl">{{ $goal->emoji ?: '💰' }}</span>
                <div class="min-w-0 flex-1">
                    <div class="flex items-start justify-between gap-2">
                        <h2 class="truncate font-bold">{{ $goal->name }}</h2>
                        <button class="shrink-0 rounded-lg p-1 text-muted hover:bg-surface-2 hover:text-ink" wire:click="openEdit({{ $goal->id }})" aria-label="Edit {{ $goal->name }}"><x-icon name="pencil" class="size-4" /></button>
                    </div>
                    <p class="text-sm"><span class="font-extrabold">{{ Transaction::formatRupiah($goal->savedAmount()) }}</span> <span class="text-muted">/ {{ Transaction::formatRupiah($goal->target_amount) }}</span></p>
                </div>
            </div>

            <div class="mt-3">
                <x-progress :value="$goal->percent()" :color="$reached ? 'var(--success)' : null" />
                <div class="mt-1.5 flex flex-wrap items-center justify-between gap-x-3 text-xs text-muted">
                    <span>
                        @if ($reached)
                            <span class="font-bold text-success">Tercapai 🎉</span>
                        @else
                            {{ $goal->percent() }}% · kurang {{ Transaction::formatRupiah($goal->remainingAmount()) }}
                        @endif
                    </span>
                    @if ($goal->deadline)
                        <span @class(['font-semibold text-danger' => ! $reached && $goal->deadline->isPast()])>
                            Tenggat {{ $goal->deadline->translatedFormat('d M Y') }}
                        </span>
                    @endif
                </div>
                @if ($monthly)
                    <p class="mt-2 rounded-xl bg-surface-2 px-3 py-2 text-xs text-muted">
                        @if ($goal->deadline->isPast())
                            Tenggat sudah lewat. Ubah tenggat atau terus menabung.
                        @else
                            Sisihkan <b class="text-ink">{{ Transaction::formatRupiah($monthly) }}/bulan</b> supaya tercapai tepat waktu.
                        @endif
                    </p>
                @endif
            </div>

            <div class="mt-3 flex gap-2">
                <button class="btn btn-primary flex-1 py-2 text-sm" wire:click="openDeposit({{ $goal->id }})"><x-icon name="plus" class="size-4" /> Setor</button>
                <button class="btn btn-ghost py-2 text-sm" wire:click="openDeposit({{ $goal->id }}, 'withdraw')" @disabled($goal->savedAmount() <= 0)>Tarik</button>
                @if ($goal->deposits->isNotEmpty())
                    <button class="btn btn-ghost py-2 text-sm" x-on:click="open = ! open" :aria-expanded="open">Riwayat <x-icon name="chevron-right" class="size-4 transition" ::class="open && 'rotate-90'" /></button>
                @endif
            </div>

            @if ($goal->deposits->isNotEmpty())
                <div x-show="open" x-cloak class="mt-3 divide-y divide-line border-t border-line">
                    @foreach ($goal->deposits as $deposit)
                        <div wire:key="deposit-{{ $deposit->id }}" class="flex items-center gap-2 py-2 text-sm">
                            <span class="min-w-0 flex-1">
                                <span class="block">{{ $deposit->deposited_on->translatedFormat('d M Y') }}</span>
                                <span class="block truncate text-xs text-muted">{{ collect([$deposit->wallet?->name, $deposit->notes])->filter()->implode(' · ') ?: ($deposit->amount < 0 ? 'Penarikan' : 'Setoran') }}</span>
                            </span>
                            <span @class(['shrink-0 font-bold', 'text-success' => $deposit->amount > 0, 'text-danger' => $deposit->amount < 0])>
                                {{ $deposit->amount > 0 ? '+' : '−' }}{{ Transaction::formatRupiah(abs($deposit->amount)) }}
                            </span>
                            <button class="rounded-lg p-1 text-muted hover:bg-danger/10 hover:text-danger" wire:click="deleteDeposit({{ $deposit->id }})" wire:confirm="Hapus catatan ini?" aria-label="Hapus"><x-icon name="trash" class="size-4" /></button>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    @empty
        <x-empty-state emoji="🐷" title="Belum ada tabungan">
            Buat tujuan seperti "Laptop baru Rp8 juta", lalu catat setiap setoran. Prima Management menghitung berapa yang perlu disisihkan per bulan.
        </x-empty-state>
    @endforelse

    <x-sheet :show="$showForm" :title="$editingId ? 'Edit tabungan' : 'Tabungan baru'">
        <form wire:submit="save" class="space-y-4">
            <div class="flex flex-wrap gap-1.5">
                @foreach ($this::EMOJIS as $option)
                    <button type="button" wire:key="emoji-{{ $loop->index }}" wire:click="$set('emoji', @js($option))" @class([
                        'grid size-10 place-items-center rounded-xl text-xl transition',
                        'bg-brand-soft ring-2 ring-brand' => $emoji === $option,
                        'bg-surface-2' => $emoji !== $option,
                    ])>{{ $option }}</button>
                @endforeach
            </div>
            <div>
                <label class="label" for="saving-name">Untuk apa?</label>
                <input id="saving-name" wire:model="name" class="input" placeholder="mis. Laptop baru">
                @error('name') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="label" for="saving-target">Target</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2 text-sm font-bold text-muted">Rp</span>
                        <input id="saving-target" wire:model="target_amount" inputmode="numeric" class="input pl-11 font-bold" placeholder="8.000.000"
                            x-on:input="$el.value = $el.value.replace(/\D/g, '').replace(/\B(?=(\d{3})+(?!\d))/g, '.')">
                    </div>
                    @error('target_amount') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="saving-deadline">Tenggat (opsional)</label>
                    <input id="saving-deadline" type="date" wire:model="deadline" class="input">
                    @error('deadline') <p class="error">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex gap-2 pt-1">
                @if ($editingId)
                    <button type="button" class="btn btn-danger" wire:click="delete({{ $editingId }})" wire:confirm="Hapus tabungan ini beserta riwayatnya? Saldo dompet yang terkait ikut kembali."><x-icon name="trash" class="size-4" /></button>
                @endif
                <button class="btn btn-primary flex-1">Simpan</button>
            </div>
        </form>
    </x-sheet>

    <x-sheet :show="$showDepositForm" :title="($depositMode === 'withdraw' ? 'Tarik dari ' : 'Setor ke ').($depositGoal?->name ?? 'tabungan')" close="closeDepositForm">
        <form wire:submit="saveDeposit" class="space-y-4">
            <div class="grid grid-cols-2 gap-1.5 rounded-2xl bg-surface-2 p-1">
                @foreach (['deposit' => 'Setor', 'withdraw' => 'Tarik'] as $value => $label)
                    <button type="button" wire:click="$set('depositMode', '{{ $value }}')" @class([
                        'rounded-xl py-2 text-sm font-bold transition',
                        'bg-surface text-ink shadow-sm' => $depositMode === $value,
                        'text-muted' => $depositMode !== $value,
                    ])>{{ $label }}</button>
                @endforeach
            </div>
            <div>
                <label class="label" for="depositAmount">Nominal</label>
                <div class="relative">
                    <span class="pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2 text-sm font-bold text-muted">Rp</span>
                    <input id="depositAmount" wire:model="depositAmount" inputmode="numeric" class="input pl-11 text-lg font-extrabold" placeholder="500.000"
                        x-on:input="$el.value = $el.value.replace(/\D/g, '').replace(/\B(?=(\d{3})+(?!\d))/g, '.')">
                </div>
                @error('depositAmount') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="label" for="depositWalletId">{{ $depositMode === 'withdraw' ? 'Masuk ke dompet' : 'Diambil dari dompet' }}</label>
                    <select id="depositWalletId" wire:model="depositWalletId" class="input">
                        <option value="">Tidak dari dompet</option>
                        @foreach ($this->wallets as $wallet)
                            <option value="{{ $wallet->id }}">{{ $wallet->emoji() }} {{ $wallet->name }}</option>
                        @endforeach
                    </select>
                    @error('depositWalletId') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="depositDate">Tanggal</label>
                    <input id="depositDate" type="date" wire:model="depositDate" class="input">
                    @error('depositDate') <p class="error">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label class="label" for="depositNotes">Keterangan</label>
                <input id="depositNotes" wire:model="depositNotes" class="input" placeholder="opsional">
            </div>
            <p class="text-xs text-muted">Bila memilih dompet, saldo dompet itu ikut berkurang saat menyetor (dan bertambah saat menarik). Setoran tidak dihitung sebagai pengeluaran.</p>
            <button class="btn btn-primary w-full">Simpan</button>
        </form>
    </x-sheet>
</div>
