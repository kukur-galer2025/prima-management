<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Menerima sesi fokus yang dicatat saat perangkat offline lalu dikirim ulang ketika online.
 * client_uuid membuat pengiriman ulang aman: sesi yang sama tidak tersimpan dua kali.
 */
class OfflineSessionSyncController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'sessions' => ['required', 'array', 'max:50'],
            'sessions.*.client_uuid' => ['required', 'uuid'],
            'sessions.*.goal_id' => ['required', 'integer', Rule::exists('goals', 'id')->where('user_id', $user->id)->whereNull('deleted_at')],
            'sessions.*.topic_id' => ['nullable', 'integer'],
            'sessions.*.studied_on' => ['required', 'date', 'before_or_equal:today'],
            'sessions.*.minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'sessions.*.score' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'sessions.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $synced = [];

        foreach ($validated['sessions'] as $item) {
            $goal = $user->goals()->findOrFail($item['goal_id']);
            $topicId = filled($item['topic_id'] ?? null) && $goal->topics()->whereKey($item['topic_id'])->exists()
                ? (int) $item['topic_id']
                : null;

            $user->studySessions()->firstOrCreate(['client_uuid' => $item['client_uuid']], [
                'goal_id' => $goal->id,
                'topic_id' => $topicId,
                'studied_on' => $item['studied_on'],
                'minutes' => (int) $item['minutes'],
                'score' => filled($item['score'] ?? null) ? $item['score'] : null,
                'notes' => ($item['notes'] ?? null) ?: null,
            ]);

            $synced[] = $item['client_uuid'];
        }

        return response()->json(['synced' => $synced]);
    }
}
