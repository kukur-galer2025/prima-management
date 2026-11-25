<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'invite_code'])]
class Group extends Model
{
    /** Batas anggota per grup agar papan peringkat tetap bermakna. */
    public const MAX_MEMBERS = 30;

    public function memberships(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'group_members')->withPivot(['role', 'share_goals'])->withTimestamps();
    }

    /** Kode undangan 8 karakter tanpa huruf yang mudah tertukar (0/O, 1/I/L). */
    public static function newInviteCode(): string
    {
        do {
            $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
            $code = collect(range(1, 8))->map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)])->implode('');
        } while (self::query()->where('invite_code', $code)->exists());

        return $code;
    }

    public static function createFor(User $owner, string $name): self
    {
        return DB::transaction(function () use ($owner, $name) {
            $group = self::query()->create(['name' => $name, 'invite_code' => self::newInviteCode()]);
            $group->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

            return $group;
        });
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->memberships()->where('user_id', $user->id)->where('role', 'owner')->exists();
    }

    /**
     * Keluarkan anggota. Bila pemilik keluar, kepemilikan pindah ke anggota terlama;
     * bila tidak ada anggota tersisa, grup dihapus.
     */
    public function removeMember(User $user): void
    {
        DB::transaction(function () use ($user) {
            $membership = $this->memberships()->where('user_id', $user->id)->first();

            if (! $membership) {
                return;
            }

            $wasOwner = $membership->role === 'owner';
            $membership->delete();

            $next = $this->memberships()->orderBy('created_at')->orderBy('id')->first();

            if (! $next) {
                $this->delete();
            } elseif ($wasOwner && ! $this->memberships()->where('role', 'owner')->exists()) {
                $next->update(['role' => 'owner']);
            }
        });
    }

    /**
     * Papan peringkat minggu ini: menit fokus sejak Senin, hari aktif, dan streak fokus.
     *
     * @return Collection<int, array{user: User, minutes: int, activeDays: int, streak: int, role: string, shareGoals: bool}>
     */
    public function leaderboard(): Collection
    {
        $members = $this->members()->get();
        $weekStart = today()->startOfWeek(1);

        $sessions = StudySession::query()
            ->whereIn('user_id', $members->modelKeys())
            ->whereDate('studied_on', '>=', today()->subDays(60))
            ->get(['user_id', 'studied_on', 'minutes'])
            ->groupBy('user_id');

        return $members->map(function (User $member) use ($sessions, $weekStart) {
            $days = collect($sessions->get($member->id, []))
                ->groupBy(fn (StudySession $session) => $session->studied_on->toDateString())
                ->map(fn (Collection $items) => (int) $items->sum('minutes'));

            $thisWeek = $days->filter(fn (int $minutes, string $date) => $date >= $weekStart->toDateString());

            $streak = 0;
            $cursor = today();

            if (! $days->has($cursor->toDateString())) {
                $cursor = $cursor->subDay();
            }

            while ($days->has($cursor->toDateString())) {
                $streak++;
                $cursor = $cursor->subDay();
            }

            return [
                'user' => $member,
                'minutes' => (int) $thisWeek->sum(),
                'activeDays' => $thisWeek->count(),
                'streak' => $streak,
                'role' => $member->pivot->role,
                'shareGoals' => (bool) $member->pivot->share_goals,
            ];
        })->sortByDesc('minutes')->values();
    }
}
