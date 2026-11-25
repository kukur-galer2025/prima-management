<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'password', 'weekly_target_minutes', 'monthly_budget'])]
#[Hidden(['password', 'remember_token', 'calendar_token', 'telegram_link_code'])]
class User extends Authenticatable
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
            'is_admin' => 'boolean',
            'disabled_at' => 'datetime',
            'last_active_at' => 'datetime',
        ];
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /** Catat waktu terakhir aktif, paling sering sekali per 5 menit agar tidak menulis di setiap request. */
    public function touchLastActive(): void
    {
        if (! $this->last_active_at || $this->last_active_at->lt(now()->subMinutes(5))) {
            // Sekadar catatan; kegagalan menulisnya tidak boleh menggagalkan halaman.
            rescue(fn () => $this->forceFill(['last_active_at' => now()])->saveQuietly());
        }
    }

    public function hasTelegram(): bool
    {
        return filled($this->telegram_chat_id);
    }

    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class);
    }

    public function walletTransfers(): HasMany
    {
        return $this->hasMany(WalletTransfer::class);
    }

    public function savingsGoals(): HasMany
    {
        return $this->hasMany(SavingsGoal::class);
    }

    public function debts(): HasMany
    {
        return $this->hasMany(Debt::class);
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'group_members')->withPivot(['role', 'share_goals'])->withTimestamps();
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
        // Grup yang dimilikinya dialihkan ke anggota lain (atau dihapus bila kosong).
        $this->groups()->get()->each(fn (Group $group) => $group->removeMember($this));

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
