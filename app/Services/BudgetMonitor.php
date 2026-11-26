<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Pemakaian anggaran bulan berjalan (per kategori dan total) beserta peringatan 80% / 100%.
 */
class BudgetMonitor
{
    /** Ambang peringatan dalam persen, dari yang tertinggi. */
    public const THRESHOLDS = [100, 80];

    /**
     * @return Collection<int, array{id: int|string, key: string, label: string, budget: int, spent: int, percent: int}>
     */
    public function usage(User $user, ?CarbonInterface $month = null): Collection
    {
        $start = CarbonImmutable::parse($month ?? today())->startOfMonth();

        $spent = $user->transactions()
            ->where('type', 'expense')
            ->whereDate('occurred_on', '>=', $start)
            ->whereDate('occurred_on', '<=', $start->endOfMonth())
            ->groupBy('category')
            ->selectRaw('category, sum(amount) as total')
            ->get()
            // Disatukan tanpa peka huruf besar/kecil: MySQL menganggap "Makan" dan "makan" sama, SQLite tidak.
            ->groupBy(fn ($row) => Str::lower($row->category))
            ->map(fn (Collection $rows) => (int) $rows->sum(fn ($row) => (int) $row->total));

        $rows = $user->budgets()->orderBy('category')->get()->map(fn ($budget) => $this->row(
            'category:'.$budget->category,
            'Anggaran '.$budget->category,
            (int) $budget->amount,
            (int) ($spent[Str::lower($budget->category)] ?? 0),
            $budget->id,
        ));

        if ($user->monthly_budget) {
            $rows->prepend($this->row('total', 'Anggaran bulanan', (int) $user->monthly_budget, (int) $spent->sum()));
        }

        return $rows->values();
    }

    /** Ambang tertinggi yang sudah dilewati (100 atau 80), atau null. */
    public static function levelFor(int $percent): ?int
    {
        foreach (self::THRESHOLDS as $threshold) {
            if ($percent >= $threshold) {
                return $threshold;
            }
        }

        return null;
    }

    /**
     * Peringatan untuk setiap anggaran yang melewati ambang. Kunci memuat bulan dan ambang,
     * jadi tiap ambang hanya diberitahukan sekali per bulan.
     *
     * @return array<int, array{key: string, title: string, body: string, url: string}>
     */
    public function alerts(User $user, CarbonInterface $now): array
    {
        return $this->usage($user, $now)
            ->filter(fn (array $row) => self::levelFor($row['percent']) !== null)
            ->map(function (array $row) use ($now) {
                $level = self::levelFor($row['percent']);
                $over = $row['spent'] - $row['budget'];

                return [
                    'key' => "budget:{$row['id']}:{$now->format('Y-m')}:{$level}",
                    'title' => $level === 100 ? "⚠️ {$row['label']} terlampaui" : "{$row['label']} sudah {$row['percent']}%",
                    'body' => Transaction::formatRupiah($row['spent']).' dari '.Transaction::formatRupiah($row['budget']).' bulan ini. '
                        .($over >= 0 ? 'Lewat '.Transaction::formatRupiah($over).'.' : 'Sisa '.Transaction::formatRupiah(-$over).'.'),
                    'url' => route('finance', absolute: false),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array{id: int|string, key: string, label: string, budget: int, spent: int, percent: int}
     */
    private function row(string $key, string $label, int $budget, int $spent, int|string $id = 'total'): array
    {
        return [
            // Dipakai di kunci pengingat; id lebih aman daripada nama karena MySQL menganggap semua emoji sama.
            'id' => $id,
            'key' => $key,
            'label' => $label,
            'budget' => $budget,
            'spent' => $spent,
            'percent' => $budget > 0 ? (int) floor($spent / $budget * 100) : 0,
        ];
    }
}
