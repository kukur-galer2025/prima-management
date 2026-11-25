<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'password', 'weekly_target_minutes', 'monthly_budget'])]
#[Hidden(['password', 'remember_token', 'calendar_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'weekly_target_minutes' => 'integer',
            'monthly_budget' => 'integer',
            'onboarding_dismissed_at' => 'datetime',
        ];
    }

    public function recurringTransactions(): HasMany
    {
        return $this->hasMany(RecurringTransaction::class);
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    public function weeklyReviews(): HasMany
    {
        return $this->hasMany(WeeklyReview::class);
    }

    public function noteAttachments(): HasMany
    {
        return $this->hasMany(NoteAttachment::class);
    }

    /** Folder lampiran milik pengguna di disk "local" (privat). */
    public function attachmentDirectory(): string
    {
        return 'attachments/'.$this->id;
    }

    /** Sisa kuota lampiran dalam byte. */
    public function remainingAttachmentQuota(): int
    {
        return max(0, config('app.attachments.quota_mb') * 1048576 - (int) $this->noteAttachments()->sum('size'));
    }

    /**
     * Hapus akun beserta semua datanya. Baris di database ikut terhapus lewat foreign key cascade;
     * file lampiran dihapus di sini karena cascade database tidak menyentuh penyimpanan.
     */
    public function deleteAccount(): void
    {
        Storage::disk('local')->deleteDirectory($this->attachmentDirectory());
        $this->delete();
    }

    /** Token rahasia untuk tautan langganan kalender (iCal); dibuat saat pertama diminta. */
    public function calendarToken(bool $regenerate = false): string
    {
        if ($regenerate || ! $this->calendar_token) {
            $this->forceFill(['calendar_token' => Str::random(48)])->save();
        }

        return $this->calendar_token;
    }

    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function studySessions(): HasMany
    {
        return $this->hasMany(StudySession::class);
    }

    public function scheduleSlots(): HasMany
    {
        return $this->hasMany(ScheduleSlot::class);
    }

    public function habits(): HasMany
    {
        return $this->hasMany(Habit::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    /** Total menit fokus sejak Senin minggu ini. */
    public function focusMinutesThisWeek(): int
    {
        return (int) $this->studySessions()
            ->whereDate('studied_on', '>=', today()->startOfWeek(1))
            ->whereDate('studied_on', '<=', today())
            ->sum('minutes');
    }
}
