<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['week_start', 'rating', 'went_well', 'to_improve', 'next_focus'])]
class WeeklyReview extends Model
{
    public const RATINGS = [1 => '😫', 2 => '😕', 3 => '😐', 4 => '🙂', 5 => '🤩'];

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'rating' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
