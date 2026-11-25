<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['actor_id', 'actor_label', 'subject_id', 'subject_label', 'action', 'details'])]
class AdminActivity extends Model
{
    public const UPDATED_AT = null;

    public const ACTIONS = [
        'created' => 'membuat akun',
        'invited' => 'mengundang lewat email',
        'updated' => 'mengubah akun',
        'password_changed' => 'mengganti kata sandi',
        'promoted' => 'menjadikan admin',
        'demoted' => 'mencabut admin',
        'disabled' => 'menonaktifkan',
        'enabled' => 'mengaktifkan lagi',
        'reset_link_sent' => 'mengirim tautan reset ke',
        'deleted' => 'menghapus akun',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_id');
    }

    /**
     * Catat satu tindakan admin terhadap akun pengguna.
     *
     * @param  array<string, mixed>|null  $details
     */
    public static function record(User $actor, User $subject, string $action, ?array $details = null): self
    {
        return self::query()->create([
            'actor_id' => $actor->id,
            'actor_label' => $actor->name,
            'subject_id' => $subject->exists ? $subject->id : null,
            'subject_label' => $subject->name.' <'.$subject->email.'>',
            'action' => $action,
            'details' => $details ?: null,
        ]);
    }

    public function description(): string
    {
        return self::ACTIONS[$this->action] ?? $this->action;
    }
}
