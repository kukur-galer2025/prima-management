<?php

namespace App\Models;

use Database\Factories\NoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['goal_id', 'title', 'body', 'is_pinned'])]
class Note extends Model
{
    /** @use HasFactory<NoteFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['is_pinned' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class)->withTrashed();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(NoteAttachment::class);
    }

    protected static function booted(): void
    {
        // Hapus lewat model agar file di penyimpanan ikut terhapus (cascade database tidak menyentuh file).
        static::deleting(fn (self $note) => $note->attachments->each->delete());
    }
}
