<?php

namespace App\Models;

use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['wallet_id', 'recurring_transaction_id', 'type', 'amount', 'category', 'occurred_on', 'notes'])]
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

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function recurringTransaction(): BelongsTo
    {
        return $this->belongsTo(RecurringTransaction::class);
    }

    public function isIncome(): bool
    {
        return $this->type === 'income';
    }

    /**
     * Baca nominal yang diketik bebas: "25000", "25.000", "Rp25.000", "25rb", "25k", "1,5jt", "2 juta".
     */
    public static function parseAmount(string $input): ?int
    {
        $text = preg_replace('/^rp\.?\s*/', '', strtolower(trim($input)));

        if (! preg_match('/^(\d+(?:[.,]\d+)*)\s*(rb|ribu|k|jt|juta)?$/', (string) $text, $matches)) {
            return null;
        }

        $multiplier = match ($matches[2] ?? '') {
            'rb', 'ribu', 'k' => 1000,
            'jt', 'juta' => 1000000,
            default => 1,
        };

        // Dengan satuan, koma/titik adalah desimal (1,5jt); tanpa satuan, keduanya pemisah ribuan (25.000).
        $number = $multiplier > 1
            ? (float) str_replace(',', '.', $matches[1])
            : (float) str_replace(['.', ','], '', $matches[1]);

        $amount = (int) round($number * $multiplier);

        return $amount > 0 && $amount <= 999999999999 ? $amount : null;
    }

    public static function formatRupiah(int $amount): string
    {
        return ($amount < 0 ? '-' : '').'Rp '.number_format(abs($amount), 0, ',', '.');
    }
}
