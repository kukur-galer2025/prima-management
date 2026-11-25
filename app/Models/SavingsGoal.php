<?php

namespace App\Models;

use Database\Factories\SavingsGoalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable(['name', 'emoji', 'target_amount', 'deadline'])]
class SavingsGoal extends Model
{
    /** @use HasFactory<SavingsGoalFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'target_amount' => 'integer',
            'deadline' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(SavingsDeposit::class);
    }

    /** Total terkumpul; memakai hasil withSum('deposits', 'amount') bila tersedia. */
    public function savedAmount(): int
    {
        if (array_key_exists('deposits_sum_amount', $this->attributes)) {
            return (int) $this->attributes['deposits_sum_amount'];
        }

        return (int) $this->deposits()->sum('amount');
    }

    public function remainingAmount(): int
    {
        return max(0, $this->target_amount - $this->savedAmount());
    }

    public function percent(): int
    {
        return $this->target_amount > 0 ? (int) min(100, floor($this->savedAmount() / $this->target_amount * 100)) : 0;
    }

    public function isReached(): bool
    {
        return $this->savedAmount() >= $this->target_amount;
    }

    /** Setoran per bulan yang dibutuhkan agar tercapai tepat waktu (null bila tanpa tenggat / sudah tercapai). */
    public function monthlyNeeded(?Carbon $today = null): ?int
    {
        if (! $this->deadline || $this->isReached()) {
            return null;
        }

        $today ??= today();
        // Sisa bulan dihitung dari jumlah hari (rata-rata 30,44 hari per bulan), minimal satu bulan.
        $months = max(1, (int) ceil($today->diffInDays($this->deadline, false) / 30.4375));

        return (int) ceil($this->remainingAmount() / $months);
    }
}
