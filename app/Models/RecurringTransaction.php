<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['wallet_id', 'type', 'amount', 'category', 'notes', 'day_of_month', 'next_run_on'])]
class RecurringTransaction extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'day_of_month' => 'integer',
            'next_run_on' => 'date',
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

    /** Tanggal ke-N di bulan tertentu; bulan yang lebih pendek memakai hari terakhirnya (31 → 28 Feb). */
    public static function occurrenceIn(CarbonInterface $month, int $day): CarbonImmutable
    {
        $start = CarbonImmutable::parse($month)->startOfMonth();

        return $start->setDay(min($day, $start->daysInMonth));
    }

    /** Kemunculan pertama: bulan ini kalau tanggalnya belum lewat, kalau sudah lewat bulan depan. */
    public static function firstRunOn(int $day, CarbonInterface $today): CarbonImmutable
    {
        $today = CarbonImmutable::parse($today)->startOfDay();
        $thisMonth = self::occurrenceIn($today, $day);

        return $thisMonth->gte($today) ? $thisMonth : self::occurrenceIn($today->addMonthNoOverflow(), $day);
    }

    /**
     * Catat semua transaksi rutin yang sudah jatuh tempo (termasuk bulan-bulan yang terlewat).
     *
     * @return int jumlah transaksi yang dibuat
     */
    public static function postDueFor(User $user, ?CarbonInterface $today = null): int
    {
        $today = CarbonImmutable::parse($today ?? today())->startOfDay();
        $posted = 0;

        foreach ($user->recurringTransactions()->whereDate('next_run_on', '<=', $today)->get() as $recurring) {
            for ($guard = 0; $guard < 24 && $recurring->next_run_on->lte($today); $guard++) {
                $user->transactions()->create([
                    'recurring_transaction_id' => $recurring->id,
                    'wallet_id' => $recurring->wallet_id,
                    'type' => $recurring->type,
                    'amount' => $recurring->amount,
                    'category' => $recurring->category,
                    'notes' => $recurring->notes,
                    'occurred_on' => $recurring->next_run_on->toDateString(),
                ]);

                $recurring->next_run_on = self::occurrenceIn($recurring->next_run_on->copy()->startOfMonth()->addMonth(), $recurring->day_of_month);
                $posted++;
            }

            $recurring->save();
        }

        return $posted;
    }
}
