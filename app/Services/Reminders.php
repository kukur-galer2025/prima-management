<?php

namespace App\Services;

use App\Models\Reminder;
use App\Models\ScheduleSlot;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Menyusun pengingat yang jatuh tempo lalu mengirimnya sebagai push ke semua perangkat pengguna.
 */
class Reminders
{
    /** Berapa menit sebelum slot jadwal dimulai pengingat dikirim. */
    public const SLOT_LEAD_MINUTES = 10;

    /** Ringkasan harian hanya dikirim dalam rentang jam ini supaya tidak muncul tengah malam. */
    public const DIGEST_FROM = '07:00';

    public const DIGEST_UNTIL = '10:00';

    /** Ajakan review mingguan dikirim Minggu malam dalam rentang jam ini. */
    public const REVIEW_FROM = '19:00';

    public const REVIEW_UNTIL = '21:00';

    public function __construct(private WebPush $webPush) {}

    /**
     * Kirim pengingat baru ke semua pengguna yang mengaktifkan notifikasi.
     *
     * @return int jumlah pengingat baru yang dibuat
     */
    public function dispatch(CarbonInterface $now): int
    {
        $created = 0;

        User::query()->whereHas('pushSubscriptions')->with('pushSubscriptions')->each(function (User $user) use ($now, &$created) {
            $newReminders = collect($this->dueFor($user, $now))
                ->map(fn (array $reminder) => $user->reminders()->firstOrCreate(['key' => $reminder['key']], $reminder))
                ->filter(fn (Reminder $reminder) => $reminder->wasRecentlyCreated);

            if ($newReminders->isNotEmpty()) {
                $created += $newReminders->count();
                $this->push($user);
            }
        });

        return $created;
    }

    public function push(User $user): void
    {
        foreach ($user->pushSubscriptions as $subscription) {
            $this->webPush->send($subscription);
        }
    }

    /**
     * @return array<int, array{key: string, title: string, body: string, url: string}>
     */
    public function dueFor(User $user, CarbonInterface $now): array
    {
        $reminders = [];
        $date = $now->toDateString();
        $currentTime = $now->format('H:i');
        $leadTime = $now->copy()->addMinutes(self::SLOT_LEAD_MINUTES)->format('H:i');

        $upcomingSlots = $user->scheduleSlots()
            ->with('goal')
            ->where('day_of_week', $now->dayOfWeekIso)
            ->where('start_time', '>', $currentTime)
            ->where('start_time', '<=', $leadTime)
            ->get();

        foreach ($upcomingSlots as $slot) {
            /** @var ScheduleSlot $slot */
            $reminders[] = [
                'key' => "slot:{$slot->id}:{$date}",
                'title' => "{$slot->goal->label()} mulai {$slot->start_time}",
                'body' => "Sesi fokus {$slot->start_time}–{$slot->end_time}".($slot->notes ? " · {$slot->notes}" : '').'. Siapkan dirimu!',
                'url' => route('study', ['goal' => $slot->goal_id], false),
            ];
        }

        $timedTasks = $user->tasks()
            ->with('goal')
            ->where('is_done', false)
            ->whereDate('due_date', $now)
            ->where('due_time', '>', $currentTime)
            ->where('due_time', '<=', $leadTime)
            ->get();

        foreach ($timedTasks as $task) {
            $reminders[] = [
                'key' => "task:{$task->id}:{$date}",
                'title' => "⏰ {$task->title}",
                'body' => "Tenggat pukul {$task->due_time}".($task->goal ? " · {$task->goal->name}" : ''),
                'url' => route('tasks', absolute: false),
            ];
        }

        if ($currentTime >= self::DIGEST_FROM && $currentTime < self::DIGEST_UNTIL && ($digest = $this->dailyDigest($user, $now))) {
            $reminders[] = ['key' => "digest:{$date}", 'title' => 'Rencana hari ini', 'body' => $digest, 'url' => route('dashboard', absolute: false)];
        }

        $weekStart = $now->copy()->startOfWeek(1)->toDateString();

        if ($now->dayOfWeekIso === 7 && $currentTime >= self::REVIEW_FROM && $currentTime < self::REVIEW_UNTIL
            && ! $user->weeklyReviews()->whereDate('week_start', $weekStart)->exists()) {
            $reminders[] = [
                'key' => "review:{$weekStart}",
                'title' => 'Waktunya review mingguan 📝',
                'body' => 'Lihat ringkasan minggu ini dan tulis refleksi singkat untuk minggu depan.',
                'url' => route('review', absolute: false),
            ];
        }

        return $reminders;
    }

    private function dailyDigest(User $user, CarbonInterface $now): ?string
    {
        $openTasks = $user->tasks()->where('is_done', false)->whereNotNull('due_date');
        $dueToday = (clone $openTasks)->whereDate('due_date', $now)->count();
        $overdue = (clone $openTasks)->whereDate('due_date', '<', $now)->count();
        $habitsToday = $user->habits()->get()->filter(fn ($habit) => $habit->isScheduledOn($now))->count();

        $deadlines = $user->goals()
            ->where('status', '!=', 'done')
            ->whereDate('deadline', '>=', $now)
            ->whereDate('deadline', '<=', $now->copy()->addDays(7))
            ->orderBy('deadline')
            ->get()
            ->map(function ($goal) use ($now) {
                $days = (int) $now->copy()->startOfDay()->diffInDays($goal->deadline, false);

                return $days === 0 ? "{$goal->name} hari ini!" : "{$goal->name} tinggal {$days} hari";
            });

        $parts = array_filter([
            $dueToday ? "{$dueToday} tugas hari ini" : null,
            $overdue ? "{$overdue} tugas terlambat" : null,
            $habitsToday ? "{$habitsToday} kebiasaan" : null,
            ...$deadlines->all(),
        ]);

        return $parts ? implode(' · ', $parts) : null;
    }
}
