<?php

namespace App\Models;

use Database\Factories\DebtFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['wallet_id', 'direction', 'person', 'amount', 'started_on', 'due_on', 'notes'])]
class Debt extends Model
{
    /** @use HasFactory<DebtFactory> */
    use HasFactory;

    public const DIRECTIONS = ['payable' => 'Utang', 'receivable' => 'Piutang'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'started_on' => 'date',
            'due_on' => 'date',
            'settled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(DebtPayment::class);
    }

    public function isPayable(): bool
    {
        return $this->direction === 'payable';
    }

    /** Total yang sudah dibayar; memakai hasil withSum('payments', 'amount') bila tersedia. */
    public function paidAmount(): int
    {
        if (array_key_exists('payments_sum_amount', $this->attributes)) {
            return (int) $this->attributes['payments_sum_amount'];
        }

        return (int) $this->payments()->sum('amount');
    }

    public function remainingAmount(): int
    {
        return max(0, $this->amount - $this->paidAmount());
    }

    public function isSettled(): bool
    {
        return $this->settled_at !== null;
    }

    public function isOverdue(): bool
    {
        return ! $this->isSettled() && $this->due_on && $this->due_on->lt(today());
    }

    /** Tandai lunas bila sisa sudah nol, atau buka lagi bila ada cicilan yang dihapus. */
    public function syncSettlement(): void
    {
        unset($this->attributes['payments_sum_amount']);
        $settled = $this->remainingAmount() === 0;

        if ($settled !== $this->isSettled()) {
            $this->forceFill(['settled_at' => $settled ? now() : null])->save();
        }
    }
}
