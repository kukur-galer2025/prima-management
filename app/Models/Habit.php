<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\HabitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable(['name', 'icon', 'color', 'days'])]
class Habit extends Model
{
    /** @use HasFactory<HabitFactory> */
    use HasFactory;

    public const EVERY_DAY = [1, 2, 3, 4, 5, 6, 7];

    protected function casts(): array
    {
        return ['days' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(HabitLog::class);
    }

    public function isScheduledOn(CarbonInterface $date): bool
    {
        return in_array($date->dayOfWeekIso, array_map('intval', $this->days ?? []), true);
    }

    /**
     * Hari berturut-turut kebiasaan ini dijalankan. Hari yang memang tidak dijadwalkan dilewati,
     * dan hari ini yang belum dicentang tidak memutus streak.
     *
     * @param  Collection<int, string>  $loggedDates  tanggal Y-m-d yang sudah dicentang
     */
    public function streak(Collection $loggedDates): int
    {
        $logged = $loggedDates->flip();
        $cursor = today()->toImmutable();

        if (! $logged->has($cursor->toDateString())) {
            $cursor = $cursor->subDay();
        }

        $streak = 0;

        for ($i = 0; $i < 366; $i++, $cursor = $cursor->subDay()) {
            if ($logged->has($cursor->toDateString())) {
                $streak++;
            } elseif ($this->isScheduledOn($cursor)) {
                break;
            }
        }

        return $streak;
    }

    /**
     * Persentase hari terjadwal yang dicentang dalam N hari terakhir. Hari sebelum kebiasaan dibuat
     * tidak dihitung, begitu juga hari ini selama belum dicentang.
     *
     * @param  Collection<int, string>  $loggedDates  tanggal Y-m-d yang sudah dicentang
     */
    public function completionRate(Collection $loggedDates, int $days = 30): ?int
    {
        $logged = $loggedDates->flip();
        $since = $this->created_at?->copy()->startOfDay();
        $scheduled = 0;
        $done = 0;

        for ($offset = 0; $offset < $days; $offset++) {
            $date = today()->toImmutable()->subDays($offset);

            if ($since && $date->lt($since)) {
                break;
            }

            $isLogged = $logged->has($date->toDateString());

            if (! $this->isScheduledOn($date) || ($offset === 0 && ! $isLogged)) {
                continue;
            }

            $scheduled++;
            $done += $isLogged ? 1 : 0;
        }

        return $scheduled > 0 ? (int) round($done / $scheduled * 100) : null;
    }

    public function scheduleLabel(): string
    {
        $days = collect($this->days ?? [])->map(fn ($day) => (int) $day)->sort()->values();

        return match (true) {
            $days->count() === 7 => 'Setiap hari',
            $days->all() === [1, 2, 3, 4, 5] => 'Hari kerja',
            $days->all() === [6, 7] => 'Akhir pekan',
            default => $days->map(fn (int $day) => mb_substr(ScheduleSlot::DAYS[$day], 0, 3))->implode(', '),
        };
    }

    public function label(): string
    {
        return trim(($this->icon ? $this->icon.' ' : '').$this->name);
    }
}
