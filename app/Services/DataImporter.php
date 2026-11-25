<?php

namespace App\Services;

use App\Models\StudySession;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Memulihkan data dari file ekspor JSON Prima Management ke akun pengguna.
 *
 * ID lama dipetakan ke ID baru, jadi file dari akun/perangkat lain tetap bisa diimpor.
 * Mode "ganti" menghapus seluruh data akun terlebih dulu; mode "tambah" hanya menambahkan.
 */
class DataImporter
{
    private const SECTIONS = ['goals', 'study_sessions', 'schedule_slots', 'tasks', 'habits', 'transactions', 'recurring_transactions', 'budgets', 'notes', 'weekly_reviews'];

    /** @var array<int, int> */
    private array $goalIds = [];

    /** @var array<int, int> */
    private array $topicIds = [];

    /** @var array<int, int> */
    private array $recurringIds = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, int> jumlah baris yang diimpor per bagian
     */
    public function import(User $user, array $data, bool $replace = false): array
    {
        if (! collect(self::SECTIONS)->contains(fn (string $section) => is_array($data[$section] ?? null))) {
            throw new InvalidArgumentException('File ini bukan ekspor Prima Management.');
        }

        $this->goalIds = $this->topicIds = $this->recurringIds = [];

        return DB::transaction(function () use ($user, $data, $replace) {
            if ($replace) {
                $this->wipe($user);
            }

            $counts = array_fill_keys(self::SECTIONS, 0);

            foreach ($this->rows($data, 'goals') as $row) {
                $goal = $user->goals()->create([
                    'name' => (string) ($row['name'] ?? 'Target'),
                    'category' => $row['category'] ?? null,
                    'description' => $row['description'] ?? null,
                    'icon' => $row['icon'] ?? null,
                    'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($row['color'] ?? '')) ? $row['color'] : '#7c3aed',
                    'target_hours' => $row['target_hours'] ?? null,
                    'deadline' => $this->date($row['deadline'] ?? null),
                    'priority' => (int) ($row['priority'] ?? 2),
                    'status' => in_array($row['status'] ?? null, ['active', 'paused', 'done'], true) ? $row['status'] : 'active',
                ]);

                if (! empty($row['deleted_at'])) {
                    $goal->forceFill(['deleted_at' => $this->dateTime($row['deleted_at'])])->saveQuietly();
                }

                $this->goalIds[(int) ($row['id'] ?? 0)] = $goal->id;
                $counts['goals']++;

                foreach ((array) ($row['topics'] ?? []) as $topic) {
                    $created = $goal->topics()->create([
                        'name' => (string) ($topic['name'] ?? '-'),
                        'is_done' => (bool) ($topic['is_done'] ?? false),
                        'sort_order' => (int) ($topic['sort_order'] ?? 0),
                    ]);
                    $this->topicIds[(int) ($topic['id'] ?? 0)] = $created->id;
                }
            }

            foreach ($this->rows($data, 'study_sessions') as $row) {
                if (! $goalId = $this->goalIds[(int) ($row['goal_id'] ?? 0)] ?? null) {
                    continue;
                }

                $uuid = $row['client_uuid'] ?? null;

                $user->studySessions()->create([
                    'client_uuid' => $uuid && ! StudySession::query()->where('client_uuid', $uuid)->exists() ? $uuid : null,
                    'goal_id' => $goalId,
                    'topic_id' => $this->topicIds[(int) ($row['topic_id'] ?? 0)] ?? null,
                    'studied_on' => $this->date($row['studied_on'] ?? null) ?? today()->toDateString(),
                    'minutes' => max(1, (int) ($row['minutes'] ?? 1)),
                    'score' => $row['score'] ?? null,
                    'notes' => $row['notes'] ?? null,
                ]);
                $counts['study_sessions']++;
            }

            foreach ($this->rows($data, 'schedule_slots') as $row) {
                if (! $goalId = $this->goalIds[(int) ($row['goal_id'] ?? 0)] ?? null) {
                    continue;
                }

                $user->scheduleSlots()->create([
                    'goal_id' => $goalId,
                    'day_of_week' => (int) ($row['day_of_week'] ?? 1),
                    'start_time' => (string) ($row['start_time'] ?? '19:00'),
                    'end_time' => (string) ($row['end_time'] ?? '20:00'),
                    'notes' => $row['notes'] ?? null,
                ]);
                $counts['schedule_slots']++;
            }

            foreach ($this->rows($data, 'tasks') as $row) {
                $user->tasks()->create([
                    'goal_id' => $this->goalIds[(int) ($row['goal_id'] ?? 0)] ?? null,
                    'title' => (string) ($row['title'] ?? 'Tugas'),
                    'notes' => $row['notes'] ?? null,
                    'priority' => (int) ($row['priority'] ?? 2),
                    'due_date' => $this->date($row['due_date'] ?? null),
                    'due_time' => $row['due_time'] ?? null,
                    'recurrence' => $row['recurrence'] ?? null,
                    'is_done' => (bool) ($row['is_done'] ?? false),
                    'done_at' => $this->dateTime($row['done_at'] ?? null),
                ]);
                $counts['tasks']++;
            }

            foreach ($this->rows($data, 'habits') as $row) {
                $habit = $user->habits()->create([
                    'name' => (string) ($row['name'] ?? 'Kebiasaan'),
                    'icon' => $row['icon'] ?? null,
                    'color' => $row['color'] ?? '#059669',
                    'days' => array_values(array_filter(array_map('intval', (array) ($row['days'] ?? [1, 2, 3, 4, 5, 6, 7])), fn (int $day) => $day >= 1 && $day <= 7)),
                ]);

                if (! empty($row['created_at'])) {
                    $habit->forceFill(['created_at' => $this->dateTime($row['created_at'])])->saveQuietly();
                }

                foreach ((array) ($row['logs'] ?? []) as $log) {
                    if ($date = $this->date($log['logged_on'] ?? null)) {
                        $habit->logs()->firstOrCreate(['logged_on' => $date]);
                    }
                }
                $counts['habits']++;
            }

            foreach ($this->rows($data, 'recurring_transactions') as $row) {
                $recurring = $user->recurringTransactions()->create([
                    'type' => ($row['type'] ?? null) === 'income' ? 'income' : 'expense',
                    'amount' => max(1, (int) ($row['amount'] ?? 1)),
                    'category' => (string) ($row['category'] ?? 'Lainnya'),
                    'notes' => $row['notes'] ?? null,
                    'day_of_month' => min(31, max(1, (int) ($row['day_of_month'] ?? 1))),
                    'next_run_on' => $this->date($row['next_run_on'] ?? null) ?? today()->toDateString(),
                ]);
                $this->recurringIds[(int) ($row['id'] ?? 0)] = $recurring->id;
                $counts['recurring_transactions']++;
            }

            foreach ($this->rows($data, 'transactions') as $row) {
                $user->transactions()->create([
                    'recurring_transaction_id' => $this->recurringIds[(int) ($row['recurring_transaction_id'] ?? 0)] ?? null,
                    'type' => ($row['type'] ?? null) === 'income' ? 'income' : 'expense',
                    'amount' => max(1, (int) ($row['amount'] ?? 1)),
                    'category' => (string) ($row['category'] ?? 'Lainnya'),
                    'occurred_on' => $this->date($row['occurred_on'] ?? null) ?? today()->toDateString(),
                    'notes' => $row['notes'] ?? null,
                ]);
                $counts['transactions']++;
            }

            foreach ($this->rows($data, 'budgets') as $row) {
                $user->budgets()->updateOrCreate(['category' => (string) ($row['category'] ?? 'Lainnya')], ['amount' => max(1, (int) ($row['amount'] ?? 1))]);
                $counts['budgets']++;
            }

            foreach ($this->rows($data, 'notes') as $row) {
                $user->notes()->create([
                    'goal_id' => $this->goalIds[(int) ($row['goal_id'] ?? 0)] ?? null,
                    'title' => (string) ($row['title'] ?? 'Catatan'),
                    'body' => $row['body'] ?? null,
                    'is_pinned' => (bool) ($row['is_pinned'] ?? false),
                ]);
                $counts['notes']++;
            }

            foreach ($this->rows($data, 'weekly_reviews') as $row) {
                if (! $weekStart = $this->date($row['week_start'] ?? null)) {
                    continue;
                }

                $existing = $user->weeklyReviews()->whereDate('week_start', $weekStart)->first();
                $attributes = collect($row)->only(['rating', 'went_well', 'to_improve', 'next_focus'])->all();

                $existing ? $existing->update($attributes) : $user->weeklyReviews()->create(['week_start' => $weekStart] + $attributes);
                $counts['weekly_reviews']++;
            }

            $preferences = array_filter([
                'weekly_target_minutes' => $data['user']['weekly_target_minutes'] ?? null,
                'monthly_budget' => $data['user']['monthly_budget'] ?? null,
            ], fn ($value) => ! is_null($value));

            if ($replace) {
                $user->update($preferences);
            } else {
                $user->update(array_filter($preferences, fn ($value, $key) => is_null($user->{$key}), ARRAY_FILTER_USE_BOTH));
            }

            return $counts;
        });
    }

    /** Hapus seluruh data milik pengguna (akun dan langganan notifikasinya tetap). */
    public function wipe(User $user): void
    {
        $user->goals()->withTrashed()->get()->each->forceDelete();
        $user->studySessions()->delete();
        $user->scheduleSlots()->delete();
        $user->tasks()->delete();
        $user->habits()->delete();
        $user->transactions()->delete();
        $user->recurringTransactions()->delete();
        $user->budgets()->delete();
        $user->notes()->delete();
        $user->weeklyReviews()->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function rows(array $data, string $section): array
    {
        return array_values(array_filter((array) ($data[$section] ?? []), 'is_array'));
    }

    /** Tanggal ekspor berformat ISO UTC; ubah ke zona waktu aplikasi agar tidak bergeser sehari. */
    private function date(mixed $value): ?string
    {
        return filled($value) ? Carbon::parse($value)->setTimezone(config('app.timezone'))->toDateString() : null;
    }

    private function dateTime(mixed $value): ?string
    {
        return filled($value) ? Carbon::parse($value)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s') : null;
    }
}
