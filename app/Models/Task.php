<?php

namespace App\Models;

use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable(['goal_id', 'title', 'notes', 'priority', 'due_date', 'due_time', 'recurrence', 'is_done', 'done_at', 'started_at', 'position'])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    public const PRIORITIES = [1 => 'Tinggi', 2 => 'Sedang', 3 => 'Rendah'];

    /** Kolom tampilan kanban. */
    public const COLUMNS = ['todo' => 'Belum', 'doing' => 'Dikerjakan', 'done' => 'Selesai'];

    public const RECURRENCES = ['daily' => 'Setiap hari', 'weekly' => 'Setiap minggu', 'monthly' => 'Setiap bulan'];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'is_done' => 'boolean',
            'done_at' => 'datetime',
            'started_at' => 'datetime',
            'position' => 'integer',
            'priority' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Subtugas (checklist) berurutan. */
    public function items(): HasMany
    {
        return $this->hasMany(TaskItem::class)->orderBy('position')->orderBy('id');
    }

    /** Kolom kanban tempat tugas ini berada. */
    public function column(): string
    {
        return match (true) {
            $this->is_done => 'done',
            $this->started_at !== null => 'doing',
            default => 'todo',
        };
    }

    /**
     * Pindahkan tugas ke kolom kanban. Mengembalikan kemunculan berikutnya bila tugas berulang diselesaikan.
     */
    public function moveToColumn(string $column): ?self
    {
        if ($column === 'done') {
            return $this->is_done ? null : $this->toggle();
        }

        $this->update([
            'is_done' => false,
            'done_at' => null,
            'started_at' => $column === 'doing' ? ($this->started_at ?? now()) : null,
        ]);

        return null;
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
        if ($this->is_done || ! $this->due_date) {
            return false;
        }

        return $this->due_date->lt(today())
            || ($this->due_date->isToday() && $this->due_time !== null && now()->format('H:i') > $this->due_time);
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

        if ($existing) {
            return $existing;
        }

        $next = $this->user->tasks()->create([
            'goal_id' => $this->goal_id,
            'title' => $this->title,
            'notes' => $this->notes,
            'priority' => $this->priority ?? 2,
            'recurrence' => $this->recurrence,
            'due_date' => $nextDue->toDateString(),
            'due_time' => $this->due_time,
        ]);

        // Checklist ikut disalin dalam keadaan belum dicentang.
        $next->items()->createMany($this->items->map(fn (TaskItem $item) => $item->only(['title', 'position']))->all());

        return $next;
    }
}
