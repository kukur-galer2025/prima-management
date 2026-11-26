<?php

namespace App\Services;

use App\Models\Reminder;
use App\Models\ScheduleSlot;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Throwable;

/**
 * Menyusun pengingat yang jatuh tempo lalu mengirimnya sebagai push ke semua perangkat pengguna
 * dan/atau ke chat Telegram yang tersambung.
 */
class Reminders
{
    /** Berapa menit sebelum slot jadwal dimulai pengingat dikirim. */
    public const SLOT_LEAD_MINUTES = 10;

    /** Deadline target berjam diingatkan sejak 1 jam sebelumnya (atau segera bila dibuat lebih mepet). */
    public const GOAL_LEAD_MINUTES = 60;

    /** Ringkasan harian hanya dikirim dalam rentang jam ini supaya tidak muncul tengah malam. */
    public const DIGEST_FROM = '07:00';

    public const DIGEST_UNTIL = '10:00';

    /** Ajakan review mingguan dikirim Minggu malam dalam rentang jam ini. */
    public const REVIEW_FROM = '19:00';

    public const REVIEW_UNTIL = '21:00';

    /** Ajakan mengisi jurnal harian bila belum ada entri hari ini. */
    public const JOURNAL_FROM = '20:30';

    public const JOURNAL_UNTIL = '22:00';

    /** Pengingat kebiasaan masih dikirim sampai sekian menit setelah jamnya. */
    public const HABIT_WINDOW_MINUTES = 15;

    /** Mulai jam ini peringatan yang tidak mendesak (anggaran) ditahan sampai pagi. */
    public const QUIET_FROM = '21:30';

    public function __construct(private WebPush $webPush, private Telegram $telegram, private BudgetMonitor $budgetMonitor) {}

    /**
     * Kirim pengingat baru ke semua pengguna yang mengaktifkan notifikasi push atau Telegram.
     *
     * @return int jumlah pengingat baru yang dibuat
     */
    public function dispatch(CarbonInterface $now): int
    {
        $created = 0;

        User::query()
            ->whereNull('disabled_at')
            ->where(fn ($query) => $query->whereHas('pushSubscriptions')->orWhereNotNull('telegram_chat_id'))
            ->with('pushSubscriptions')
            ->each(function (User $user) use ($now, &$created) {
                // Kegagalan pada satu pengguna tidak boleh menghentikan pengingat pengguna lain.
                try {
                    $created += $this->dispatchTo($user, $now);
                } catch (Throwable $exception) {
                    report($exception);
                }
            });

        return $created;
    }

    private function dispatchTo(User $user, CarbonInterface $now): int
    {
        $newReminders = collect($this->dueFor($user, $now))
            ->map(fn (array $reminder) => $user->reminders()->firstOrCreate(['key' => $reminder['key']], [
                ...$reminder,
                // Sesuai lebar kolom; MySQL menolak teks yang kepanjangan.
                'title' => Str::limit($reminder['title'], 250),
                'body' => Str::limit($reminder['body'], 490),
            ]))
            ->filter(fn (Reminder $reminder) => $reminder->wasRecentlyCreated);

        if ($newReminders->isNotEmpty()) {
            $this->push($user);
            $newReminders->each(fn (Reminder $reminder) => $this->sendToTelegram($user, $reminder));
        }

        return $newReminders->count();
    }

    /** Kirim push tanpa payload; service worker mengambil isinya dari /pengingat/terbaru. */
    public function push(User $user): void
    {
        foreach ($user->pushSubscriptions as $subscription) {
            $this->webPush->send($subscription);
        }
    }

    public function sendToTelegram(User $user, Reminder $reminder): bool
    {
        if (! $user->hasTelegram()) {
            return false;
        }

        return $this->telegram->sendMessage(
            $user->telegram_chat_id,
            '<b>'.e($reminder->title).'</b>'.'
'.e($reminder->body),
            url($reminder->url),
        );
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

        $goalLeadTime = $now->copy()->addMinutes(self::GOAL_LEAD_MINUTES)->format('H:i');
        $goalsDueSoon = $user->goals()
            ->where('status', 'active')
            ->whereDate('deadline', $now)
            ->whereNotNull('deadline_time')
            ->where('deadline_time', '>', $currentTime)
            ->when($goalLeadTime > $currentTime, fn ($query) => $query->where('deadline_time', '<=', $goalLeadTime))
            ->get();

        foreach ($goalsDueSoon as $goal) {
            $minutesLeft = (int) ceil($now->diffInMinutes($now->copy()->setTimeFromTimeString($goal->deadline_time), false));
            $reminders[] = [
                'key' => "goal:{$goal->id}:{$date}",
                'title' => "🎯 {$goal->label()}",
                'body' => "Deadline hari ini pukul {$goal->deadline_time} ({$minutesLeft} menit lagi).",
                'url' => route('goals.show', $goal, false),
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

        // Hanya untuk pengguna yang sudah mulai menulis jurnal, supaya yang tidak memakainya tidak terganggu.
        if ($currentTime >= self::JOURNAL_FROM && $currentTime < self::JOURNAL_UNTIL
            && $user->journalEntries()->exists()
            && ! $user->journalEntries()->whereDate('entry_on', $date)->exists()) {
            $reminders[] = [
                'key' => "journal:{$date}",
                'title' => 'Bagaimana harimu? 📔',
                'body' => 'Catat mood dan satu kalimat tentang hari ini di jurnal.',
                'url' => route('journal', absolute: false),
            ];
        }

        // Kebiasaan dengan jam pengingat; jendela 15 menit agar tidak terlewat bila scheduler sempat tertunda.
        $habitWindowStart = $now->copy()->subMinutes(self::HABIT_WINDOW_MINUTES)->format('H:i');
        $dueHabits = $user->habits()
            ->whereNotNull('remind_at')
            ->where('remind_at', '<=', $currentTime)
            ->when($habitWindowStart < $currentTime, fn ($query) => $query->where('remind_at', '>', $habitWindowStart))
            ->whereDoesntHave('logs', fn ($query) => $query->whereDate('logged_on', $date))
            ->get()
            ->filter(fn ($habit) => $habit->isScheduledOn($now));

        foreach ($dueHabits as $habit) {
            $reminders[] = [
                'key' => "habit:{$habit->id}:{$date}",
                'title' => trim(($habit->icon ?? '').' '.$habit->name),
                'body' => "Waktunya {$habit->name}. Centang setelah selesai supaya streak-mu terjaga.",
                'url' => route('habits', absolute: false),
            ];
        }

        // Peringatan anggaran tidak dikirim tengah malam; yang tertunda terkirim mulai pagi.
        if ($currentTime >= self::DIGEST_FROM && $currentTime < self::QUIET_FROM) {
            array_push($reminders, ...$this->budgetMonitor->alerts($user, $now));
        }

        return $reminders;
    }

    /** Ringkasan rencana hari ini (dipakai ringkasan pagi dan perintah /hari di Telegram). */
    public function dailyDigest(User $user, CarbonInterface $now): ?string
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

        $debtsDue = $user->debts()
            ->whereNull('settled_at')
            ->whereDate('due_on', '<=', $now)
            ->get()
            ->map(fn ($debt) => ($debt->isPayable() ? "Bayar utang ke {$debt->person}" : "Tagih {$debt->person}").($debt->due_on->isSameDay($now) ? ' hari ini' : ' (lewat tempo)'));

        $parts = array_filter([
            $dueToday ? "{$dueToday} tugas hari ini" : null,
            $overdue ? "{$overdue} tugas terlambat" : null,
            $habitsToday ? "{$habitsToday} kebiasaan" : null,
            ...$deadlines->all(),
            ...$debtsDue->all(),
        ]);

        return $parts ? implode(' · ', $parts) : null;
    }
}
