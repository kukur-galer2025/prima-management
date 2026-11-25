<?php

namespace App\Services;

use App\Models\Habit;
use App\Models\JournalEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Menghubungkan mood di jurnal dengan data lain: menit fokus, kebiasaan, dan hari dalam seminggu.
 * Perbandingan hanya ditampilkan bila kedua kelompok punya cukup hari agar tidak menyesatkan.
 */
class MoodInsights
{
    /** Jumlah hari minimal di setiap kelompok perbandingan. */
    public const MIN_DAYS_PER_GROUP = 3;

    /** Batas "hari produktif" dalam menit fokus. */
    public const FOCUS_THRESHOLD_MINUTES = 60;

    /**
     * @return array{
     *     count: int,
     *     average: ?float,
     *     focus: ?array{with: float, without: float, withDays: int, withoutDays: int},
     *     habits: ?array{with: float, without: float, withDays: int, withoutDays: int},
     *     bestWeekday: ?array{day: string, average: float},
     *     trend: array<int, array{date: string, mood: ?int}>
     * }
     */
    public function forUser(User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        $from = CarbonImmutable::parse($from)->startOfDay();
        $to = CarbonImmutable::parse($to)->startOfDay();

        /** @var Collection<string, int> $moods tanggal → mood */
        $moods = $user->journalEntries()
            ->whereDate('entry_on', '>=', $from)
            ->whereDate('entry_on', '<=', $to)
            ->get(['entry_on', 'mood'])
            ->mapWithKeys(fn (JournalEntry $entry) => [$entry->entry_on->toDateString() => $entry->mood]);

        $focusMinutes = $user->studySessions()
            ->whereDate('studied_on', '>=', $from)
            ->whereDate('studied_on', '<=', $to)
            ->get(['studied_on', 'minutes'])
            ->groupBy(fn ($session) => $session->studied_on->toDateString())
            ->map(fn (Collection $sessions) => (int) $sessions->sum('minutes'));

        $trend = [];

        for ($cursor = $from; $cursor->lte($to); $cursor = $cursor->addDay()) {
            $trend[] = ['date' => $cursor->toDateString(), 'mood' => $moods[$cursor->toDateString()] ?? null];
        }

        return [
            'count' => $moods->count(),
            'average' => $moods->isEmpty() ? null : round($moods->avg(), 1),
            'focus' => $this->compare($moods, fn (string $date) => ($focusMinutes[$date] ?? 0) >= self::FOCUS_THRESHOLD_MINUTES),
            'habits' => $this->compareHabits($user, $moods),
            'bestWeekday' => $this->bestWeekday($moods),
            'trend' => $trend,
        ];
    }

    /**
     * @param  Collection<string, int>  $moods
     * @return array{with: float, without: float, withDays: int, withoutDays: int}|null
     */
    private function compareHabits(User $user, Collection $moods): ?array
    {
        if ($moods->isEmpty()) {
            return null;
        }

        $habits = $user->habits()->with(['logs' => fn ($query) => $query
            ->whereDate('logged_on', '>=', $moods->keys()->min())
            ->whereDate('logged_on', '<=', $moods->keys()->max())])->get();

        if ($habits->isEmpty()) {
            return null;
        }

        $loggedByDate = $habits->flatMap(fn (Habit $habit) => $habit->logs->map(fn ($log) => $log->logged_on->toDateString().'|'.$habit->id))->flip();

        // Hari "kebiasaan beres" = semua kebiasaan yang dijadwalkan hari itu sudah dicentang.
        return $this->compare($moods, function (string $date) use ($habits, $loggedByDate) {
            $day = CarbonImmutable::parse($date);
            $scheduled = $habits->filter(fn (Habit $habit) => $habit->isScheduledOn($day) && $habit->created_at?->startOfDay()->lte($day));

            return $scheduled->isNotEmpty() && $scheduled->every(fn (Habit $habit) => $loggedByDate->has($date.'|'.$habit->id));
        });
    }

    /**
     * Rata-rata mood hari yang memenuhi syarat vs yang tidak.
     *
     * @param  Collection<string, int>  $moods
     * @param  callable(string): bool  $condition
     * @return array{with: float, without: float, withDays: int, withoutDays: int}|null
     */
    private function compare(Collection $moods, callable $condition): ?array
    {
        [$with, $without] = $moods->partition(fn (int $mood, string $date) => $condition($date));

        if ($with->count() < self::MIN_DAYS_PER_GROUP || $without->count() < self::MIN_DAYS_PER_GROUP) {
            return null;
        }

        return [
            'with' => round($with->avg(), 1),
            'without' => round($without->avg(), 1),
            'withDays' => $with->count(),
            'withoutDays' => $without->count(),
        ];
    }

    /**
     * @param  Collection<string, int>  $moods
     * @return array{day: string, average: float}|null
     */
    private function bestWeekday(Collection $moods): ?array
    {
        $best = $moods
            ->groupBy(fn (int $mood, string $date) => CarbonImmutable::parse($date)->dayOfWeekIso, preserveKeys: true)
            ->filter(fn (Collection $group) => $group->count() >= 2)
            ->map(fn (Collection $group) => round($group->avg(), 1))
            ->sortDesc();

        if ($best->count() < 2) {
            return null;
        }

        return ['day' => CarbonImmutable::now()->startOfWeek(1)->addDays($best->keys()->first() - 1)->translatedFormat('l'), 'average' => $best->first()];
    }
}
