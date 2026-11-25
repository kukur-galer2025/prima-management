<?php

namespace App\Models;

use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable(['goal_id', 'title', 'notes', 'priority', 'due_date', 'due_time', 'recurrence', 'is_done', 'done_at'])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    public const PRIORITIES = [1 => 'Tinggi', 2 => 'Sedang', 3 => 'Rendah'];

    public const RECURRENCES = ['daily' => 'Setiap hari', 'weekly' => 'Setiap minggu', 'monthly' => 'Setiap bulan'];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'is_done' => 'boolean',
            'done_at' => 'datetime',
            'priority' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class)->withTrashed();
    }

    /**
     * Balik status selesai. Tugas berulang yang diselesaikan otomatis membuat tugas berikutnya,
     * yang dikembalikan agar pemanggil bisa memberi tahu pengguna.
     */
    public function toggle(): ?self
    {
        $this->update([
            'is_done' => ! $this->is_done,
            'done_at' => $this->is_done ? null : now(),
        ]);

        return $this->is_done ? $this->createNextOccurrence() : null;
    }

    public function isOverdue(): bool
    {
        return ! $this->is_done && $this->due_date && $this->due_date->lt(today());
    }

    /** "Rab, 25 Nov" atau "Rab, 25 Nov · 10:00" */
    public function dueLabel(string $format = 'D, d M'): ?string
    {
        if (! $this->due_date) {
            return null;
        }

        return $this->due_date->translatedFormat($format).($this->due_time ? ' · '.$this->due_time : '');
    }

    /**
     * Tenggat kemunculan berikutnya, selalu setelah hari ini supaya tugas berulang
     * yang terlambat tidak langsung menghasilkan tugas yang juga sudah terlambat.
     */
    public function nextDueDate(): ?Carbon
    {
        if (! $this->recurrence) {
            return null;
        }

        $next = ($this->due_date ?? today())->copy();

        do {
            $next = match ($this->recurrence) {
                'daily' => $next->addDay(),
                'weekly' => $next->addWeek(),
                default => $next->addMonthNoOverflow(),
            };
        } while ($next->lte(today()));

        return $next;
    }

    private function createNextOccurrence(): ?self
    {
        $nextDue = $this->nextDueDate();

        if (! $nextDue) {
            return null;
        }

        $existing = $this->user->tasks()
            ->where('title', $this->title)
            ->where('recurrence', $this->recurrence)
            ->where('is_done', false)
            ->whereDate('due_date', $nextDue)
            ->first();

        return $existing ?? $this->user->tasks()->create([
            'goal_id' => $this->goal_id,
            'title' => $this->title,
            'notes' => $this->notes,
            'priority' => $this->priority ?? 2,
            'recurrence' => $this->recurrence,
            'due_date' => $nextDue->toDateString(),
            'due_time' => $this->due_time,
        ]);
    }
}
