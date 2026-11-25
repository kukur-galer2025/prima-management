<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['path', 'original_name', 'mime_type', 'size'])]
class NoteAttachment extends Model
{
    /** Ekstensi yang boleh diunggah. SVG/HTML sengaja tidak diizinkan (bisa berisi skrip). */
    public const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'zip'];

    /** Tipe yang aman ditampilkan langsung di browser. */
    public const INLINE_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    protected static function booted(): void
    {
        static::deleting(fn (self $attachment) => Storage::disk('local')->delete($attachment->path));
    }

    public function note(): BelongsTo
    {
        return $this->belongsTo(Note::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/') && in_array($this->mime_type, self::INLINE_TYPES, true);
    }

    public function canPreview(): bool
    {
        return in_array($this->mime_type, self::INLINE_TYPES, true);
    }

    public static function formatSize(int $bytes): string
    {
        return match (true) {
            $bytes >= 1048576 => round($bytes / 1048576, 1).' MB',
            $bytes >= 1024 => round($bytes / 1024).' KB',
            default => $bytes.' B',
        };
    }
}
