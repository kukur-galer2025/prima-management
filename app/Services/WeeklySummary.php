<?php

namespace App\Services;

use App\Models\Goal;
use App\Models\Habit;
use App\Models\ScheduleSlot;
use App\Models\StudySession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Ringkasan otomatis satu minggu (Senin–Minggu) untuk halaman Review mingguan.
 */
class WeeklySummary
{
    /**
     * @return array{
     *     start: CarbonImmutable, end: CarbonImmutable, isCurrent: bool,
     *     focusMinutes: int, previousFocusMinutes: int, sessionsCount: int, activeDays: int,
     *     target: ?int, targetPercent: ?int, bestDay: ?array{date: CarbonImmutable, minutes: int},
     *     byGoal: Collection<int, array{goal: Goal, minutes: int}>,
     *     adherence: array{planned: int, done: int, percent: ?int, days: array<int, string>},
     *     tasksDone: int, tasksOverdue: int, habitRate: ?int, income: int, expense: int
     * }
     */
    public function for(User $user, CarbonInterface $anyDayInWeek): array
    {
        $start = CarbonImmutable::parse($anyDayInWeek)->startOfWeek(1)->startOfDay();
        $end = $start->endOfWeek(0);
        $cutoff = CarbonImmutable::now()->min($end);

        $sessions = $user->studySessions()
            ->with('goal')
            ->whereDate('studied_on', '>=', $start)
            ->whereDate('studied_on', '<=', $end)
            ->get();

        $focusMinutes = (int) $sessions->sum('minutes');
        $minutesByDay = $sessions
            ->groupBy(fn (StudySession $session) => $session->studied_on->toDateString())
            ->map(fn (Collection $items) => (int) $items->sum('minutes'))
            ->sortDesc();

        $target = $user->weekly_target_minutes;
        $transactions = $user->transactions()->whereDate('occurred_on', '>=', $start)->whereDate('occurred_on', '<=', $end)->get(['type', 'amount']);

        return [
            'start' => $start,
            'end' => $end,
            'isCurrent' => $start->isSameDay(CarbonImmutable::today()->startOfWeek(1)),
            'focusMinutes' => $focusMinutes,
            'previousFocusMinutes' => (int) $user->studySessions()
                ->whereDate('studied_on', '>=', $start->subWeek())
                ->whereDate('studied_on', '<', $start)
                ->sum('minutes'),
            'sessionsCount' => $sessions->count(),
            'activeDays' => $minutesByDay->count(),
            'target' => $target,
            'targetPercent' => $target ? (int) round($focusMinutes / $target * 100) : null,
            'bestDay' => $minutesByDay->isEmpty() ? null : ['date' => CarbonImmutable::parse($minutesByDay->keys()->first()), 'minutes' => $minutesByDay->first()],
            'byGoal' => $sessions->groupBy('goal_id')
                ->map(fn (Collection $items) => ['goal' => $items->first()->goal, 'minutes' => (int) $items->sum('minutes')])
                ->sortByDesc('minutes')
                ->values(),
            'adherence' => ScheduleSlot::weeklyAdherence($user, $start),
            'tasksDone' => $user->tasks()->where('is_done', true)->whereBetween('done_at', [$start, $end])->count(),
            'tasksOverdue' => $user->tasks()->where('is_done', false)->whereDate('due_date', '<', $cutoff)->count(),
            'habitRate' => $this->habitRate($user, $start, $cutoff),
            'income' => (int) $transactions->where('type', 'income')->sum('amount'),
            'expense' => (int) $transactions->where('type', 'expense')->sum('amount'),
        ];
    }

    private function habitRate(User $user, CarbonImmutable $start, CarbonImmutable $cutoff): ?int
    {
        $scheduled = 0;
        $done = 0;

        $habits = $user->habits()
            ->with(['logs' => fn ($query) => $query->whereDate('logged_on', '>=', $start)->whereDate('logged_on', '<=', $cutoff)])
            ->get();

        foreach ($habits as $habit) {
            /** @var Habit $habit */
            $logged = $habit->logs->map(fn ($log) => $log->logged_on->toDateString())->flip();

            for ($day = $start; $day->lte($cutoff); $day = $day->addDay()) {
                if ($habit->created_at && $day->lt($habit->created_at->copy()->startOfDay())) {
                    continue;
                }

                if ($habit->isScheduledOn($day)) {
                    $scheduled++;
                    $done += $logged->has($day->toDateString()) ? 1 : 0;
                }
            }
        }

        return $scheduled > 0 ? (int) round($done / $scheduled * 100) : null;
    }
}
