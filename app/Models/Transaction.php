<?php

namespace App\Models;

use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['recurring_transaction_id', 'type', 'amount', 'category', 'occurred_on', 'notes'])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    public const TYPES = ['expense' => 'Pengeluaran', 'income' => 'Pemasukan'];

    /** Saran kategori; pengguna tetap bebas mengetik kategori sendiri. */
    public const CATEGORIES = [
        'expense' => ['Makan', 'Transport', 'Belajar', 'Tagihan', 'Belanja', 'Hiburan', 'Kesehatan', 'Lainnya'],
        'income' => ['Gaji', 'Beasiswa', 'Freelance', 'Hadiah', 'Lainnya'],
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'occurred_on' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recurringTransaction(): BelongsTo
    {
        return $this->belongsTo(RecurringTransaction::class);
    }

    public function isIncome(): bool
    {
        return $this->type === 'income';
    }

    public static function formatRupiah(int $amount): string
    {
        return ($amount < 0 ? '-' : '').'Rp '.number_format(abs($amount), 0, ',', '.');
    }
}
