<?php

namespace App\Models;

use Database\Factories\WalletFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'type', 'opening_balance', 'position'])]
class Wallet extends Model
{
    /** @use HasFactory<WalletFactory> */
    use HasFactory;

    public const TYPES = [
        'cash' => ['label' => 'Tunai', 'emoji' => '💵'],
        'bank' => ['label' => 'Rekening bank', 'emoji' => '🏦'],
        'ewallet' => ['label' => 'E-wallet', 'emoji' => '📱'],
        'other' => ['label' => 'Lainnya', 'emoji' => '👛'],
    ];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'integer',
            'position' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function emoji(): string
    {
        return self::TYPES[$this->type]['emoji'] ?? '👛';
    }

    /**
     * Saldo setiap dompet milik pengguna: saldo awal + pemasukan − pengeluaran ± transfer
     * − setoran tabungan ± utang/piutang (beserta cicilannya) yang memakai dompet itu.
     *
     * Penjumlahan dipisah (bukan "-amount") karena di MySQL kolom unsigned tidak boleh menjadi negatif.
     *
     * @return Collection<int, int> wallet_id → saldo
     */
    public static function balancesFor(User $user): Collection
    {
        $walletIds = $user->wallets()->pluck('opening_balance', 'id')->map(fn ($balance) => (int) $balance);

        if ($walletIds->isEmpty()) {
            return collect();
        }

        $flows = collect([
            $user->transactions()->whereNotNull('wallet_id')
                ->groupBy('wallet_id')
                ->selectRaw("wallet_id, sum(case when type = 'income' then amount else 0 end) - sum(case when type = 'income' then 0 else amount end) as total")
                ->pluck('total', 'wallet_id'),
            $user->walletTransfers()->groupBy('to_wallet_id')->selectRaw('to_wallet_id, sum(amount) as total')->pluck('total', 'to_wallet_id'),
            $user->walletTransfers()->groupBy('from_wallet_id')->selectRaw('from_wallet_id, -sum(amount) as total')->pluck('total', 'from_wallet_id'),
            SavingsDeposit::query()->whereIn('wallet_id', $walletIds->keys())
                ->groupBy('wallet_id')->selectRaw('wallet_id, -sum(amount) as total')->pluck('total', 'wallet_id'),
            $user->debts()->whereNotNull('wallet_id')
                ->groupBy('wallet_id')
                ->selectRaw("wallet_id, sum(case when direction = 'payable' then amount else 0 end) - sum(case when direction = 'payable' then 0 else amount end) as total")
                ->pluck('total', 'wallet_id'),
            DebtPayment::query()->join('debts', 'debts.id', '=', 'debt_payments.debt_id')
                ->whereIn('debt_payments.wallet_id', $walletIds->keys())
                ->groupBy('debt_payments.wallet_id')
                ->select('debt_payments.wallet_id', DB::raw("sum(case when debts.direction = 'payable' then 0 else debt_payments.amount end) - sum(case when debts.direction = 'payable' then debt_payments.amount else 0 end) as total"))
                ->pluck('total', 'wallet_id'),
        ]);

        return $walletIds->map(fn (int $opening, int $walletId) => $opening + (int) $flows->sum(fn (Collection $flow) => (int) ($flow[$walletId] ?? 0)));
    }
}
