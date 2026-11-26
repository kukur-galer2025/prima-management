<?php

namespace App\Models;

use Database\Factories\GoalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'category', 'description', 'icon', 'color', 'target_hours', 'deadline', 'deadline_time', 'priority', 'status'])]
class Goal extends Model
{
    /** @use HasFactory<GoalFactory> */
    use HasFactory, SoftDeletes;

    public const STATUSES = ['active' => 'Aktif', 'paused' => 'Ditunda', 'done' => 'Selesai'];

    public const PRIORITIES = [1 => 'Tinggi', 2 => 'Sedang', 3 => 'Rendah'];

    public const COLORS = ['#7c3aed', '#2563eb', '#0891b2', '#059669', '#65a30d', '#d97706', '#dc2626', '#db2777'];

    protected function casts(): array
    {
        return [
            'deadline' => 'date',
            'target_hours' => 'integer',
            'priority' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function topics(): HasMany
    {
        return $this->hasMany(Topic::class)->orderBy('sort_order')->orderBy('id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(StudySession::class);
    }

    public function slots(): HasMany
    {
        return $this->hasMany(ScheduleSlot::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    /**
     * Pindahkan target ke "Terhapus". Log fokus, tugas, dan catatan tetap tersimpan;
     * hanya slot jadwal mingguan yang dibuang karena tidak relevan lagi.
     */
    public function archive(): void
    {
        $this->slots()->delete();
        $this->delete();
    }

    /**
     * Berapa menit fokus per hari yang dibutuhkan agar target jam tercapai sebelum deadline,
     * dibandingkan dengan rata-rata 14 hari terakhir. Null kalau tidak relevan.
     *
     * @return array{remainingMinutes: int, daysLeft: int, perDay: int, recentPerDay: int, onTrack: bool}|null
     */
    public function pace(): ?array
    {
        $daysLeft = $this->daysLeft();

        if (! $this->target_hours || $daysLeft === null || $daysLeft < 1 || $this->status === 'done') {
            return null;
        }

        $logged = array_key_exists('minutes_total', $this->attributes)
            ? (int) $this->minutes_total
            : (int) $this->sessions()->sum('minutes');
        $remaining = $this->target_hours * 60 - $logged;

        if ($remaining <= 0) {
            return null;
        }

        $perDay = (int) ceil($remaining / $daysLeft);
        // Pakai agregat dari scopeWithStats bila sudah dimuat, agar daftar target tidak memicu N+1 query.
        $recentMinutes = array_key_exists('recent_minutes', $this->attributes)
            ? (int) $this->recent_minutes
            : (int) $this->sessions()->whereDate('studied_on', '>=', today()->subDays(13))->sum('minutes');
        $recentPerDay = (int) round($recentMinutes / 14);

        return [
            'remainingMinutes' => $remaining,
            'daysLeft' => $daysLeft,
            'perDay' => $perDay,
            'recentPerDay' => $recentPerDay,
            'onTrack' => $recentPerDay >= $perDay,
        ];
    }

    /** "20 Des 2026" atau "20 Des 2026 · 08:00". */
    public function deadlineLabel(string $format = 'd M Y'): ?string
    {
        return $this->deadline ? $this->deadline->translatedFormat($format).($this->deadline_time ? ' · '.$this->deadline_time : '') : null;
    }

    public function isOverdue(): bool
    {
        return $this->status !== 'done' && $this->deadline !== null && $this->deadline->lt(today());
    }

    /** Muat agregat yang dipakai progress() & hoursLogged() dalam satu query. */
    public function scopeWithStats(Builder $query): Builder
    {
        return $query
            ->withCount(['topics', 'topics as topics_done_count' => fn ($q) => $q->where('is_done', true)])
            ->withSum('sessions as minutes_total', 'minutes')
            ->withSum(['sessions as recent_minutes' => fn ($q) => $q->whereDate('studied_on', '>=', today()->subDays(13))], 'minutes');
    }

    public function hoursLogged(): float
    {
        $minutes = $this->minutes_total ?? $this->sessions()->sum('minutes');

        return round($minutes / 60, 1);
    }

    /** Progres: dari checklist materi kalau ada, kalau tidak dari jam belajar vs target jam. */
    public function progress(): int
    {
        $total = $this->topics_count ?? $this->topics()->count();

        if ($total > 0) {
            $done = $this->topics_done_count ?? $this->topics()->where('is_done', true)->count();

            return (int) round($done / $total * 100);
        }

        if ($this->target_hours) {
            return (int) min(100, round($this->hoursLogged() / $this->target_hours * 100));
        }

        return $this->status === 'done' ? 100 : 0;
    }

    public function daysLeft(): ?int
    {
        return $this->deadline ? (int) today()->diffInDays($this->deadline, false) : null;
    }

    public function label(): string
    {
        return trim(($this->icon ? $this->icon.' ' : '').$this->name);
    }
}
