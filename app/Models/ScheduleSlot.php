<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\ScheduleSlotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['goal_id', 'day_of_week', 'start_time', 'end_time', 'notes'])]
class ScheduleSlot extends Model
{
    /** @use HasFactory<ScheduleSlotFactory> */
    use HasFactory;

    public const DAYS = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

    protected function casts(): array
    {
        return ['day_of_week' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    public function durationMinutes(): int
    {
        [$sh, $sm] = array_map('intval', explode(':', $this->start_time));
        [$eh, $em] = array_map('intval', explode(':', $this->end_time));

        return max(0, ($eh * 60 + $em) - ($sh * 60 + $sm));
    }

    public function overlaps(string $startTime, string $endTime): bool
    {
        return $this->start_time < $endTime && $this->end_time > $startTime;
    }

    /**
     * Seberapa banyak jadwal minggu ini (Senin sampai saat ini) yang benar-benar dijalankan.
     * Menit fokus per target per hari dibandingkan dengan menit yang dijadwalkan, dibatasi
     * maksimal sebesar jadwalnya, jadi lembur di satu hari tidak menutupi hari yang bolong.
     *
     * Untuk minggu yang sudah lewat, seluruh jadwal minggu itu dihitung.
     *
     * @return array{planned: int, done: int, percent: ?int, days: array<int, string>}
     */
    public static function weeklyAdherence(User $user, ?CarbonInterface $weekStart = null): array
    {
        $weekStart = ($weekStart ? $weekStart->copy() : now())->startOfWeek(1)->startOfDay();
        $now = now()->min($weekStart->copy()->endOfWeek(0));

        if ($weekStart->gt(now())) {
            return ['planned' => 0, 'done' => 0, 'percent' => null, 'days' => []];
        }

        $today = $now->dayOfWeekIso;
        $currentTime = $now->format('H:i');

        $minutesByDayAndGoal = $user->studySessions()
            ->whereDate('studied_on', '>=', $weekStart)
            ->whereDate('studied_on', '<=', $now)
            ->get(['goal_id', 'studied_on', 'minutes'])
            ->groupBy(fn (StudySession $session) => $session->studied_on->dayOfWeekIso.'-'.$session->goal_id)
            ->map(fn ($sessions) => (int) $sessions->sum('minutes'));

        $planned = 0;
        $done = 0;
        $days = [];

        foreach ($user->scheduleSlots()->get()->groupBy('day_of_week') as $day => $daySlots) {
            if ($day > $today) {
                continue;
            }

            $dayPlanned = 0;
            $dayDone = 0;

            foreach ($daySlots->groupBy('goal_id') as $goalId => $goalSlots) {
                $plannedMinutes = $goalSlots
                    ->filter(fn (self $slot) => $day < $today || $slot->end_time <= $currentTime)
                    ->sum(fn (self $slot) => $slot->durationMinutes());

                $dayPlanned += $plannedMinutes;
                $dayDone += min($plannedMinutes, $minutesByDayAndGoal->get("{$day}-{$goalId}", 0));
            }

            if ($dayPlanned > 0) {
                $days[$day] = match (true) {
                    $dayDone >= $dayPlanned => 'done',
                    $dayDone > 0 => 'partial',
                    default => 'missed',
                };
            }

            $planned += $dayPlanned;
            $done += $dayDone;
        }

        return [
            'planned' => $planned,
            'done' => $done,
            'percent' => $planned > 0 ? (int) round($done / $planned * 100) : null,
            'days' => $days,
        ];
    }
}
