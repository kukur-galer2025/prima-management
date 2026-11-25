<?php

namespace App\Models;

use Database\Factories\JournalEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['entry_on', 'mood', 'content'])]
class JournalEntry extends Model
{
    /** @use HasFactory<JournalEntryFactory> */
    use HasFactory;

    /** Skala mood 1–5. */
    public const MOODS = [
        1 => ['emoji' => '😣', 'label' => 'Buruk'],
        2 => ['emoji' => '😕', 'label' => 'Kurang'],
        3 => ['emoji' => '😐', 'label' => 'Biasa'],
        4 => ['emoji' => '🙂', 'label' => 'Baik'],
        5 => ['emoji' => '😄', 'label' => 'Luar biasa'],
    ];

    protected function casts(): array
    {
        return [
            'entry_on' => 'date',
            'mood' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function moodEmoji(): string
    {
        return self::MOODS[$this->mood]['emoji'] ?? '😐';
    }

    public function moodLabel(): string
    {
        return self::MOODS[$this->mood]['label'] ?? '';
    }

    public static function emojiFor(float $mood): string
    {
        return self::MOODS[(int) max(1, min(5, round($mood)))]['emoji'];
    }
}
