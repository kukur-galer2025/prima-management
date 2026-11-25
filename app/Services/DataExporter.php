<?php

namespace App\Services;

use App\Models\StudySession;
use App\Models\User;

/**
 * Ekspor seluruh data pengguna. Formatnya dibaca kembali oleh DataImporter.
 */
class DataExporter
{
    /** @return array<string, mixed> */
    public function toArray(User $user): array
    {
        return [
            'app' => config('app.name'),
            'exported_at' => now()->toIso8601String(),
            'user' => $user->only(['name', 'email', 'weekly_target_minutes', 'monthly_budget']),
            'goals' => $user->goals()->withTrashed()->with('topics')->get()->makeHidden('user_id'),
            'study_sessions' => $user->studySessions()->get()->makeHidden('user_id'),
            'schedule_slots' => $user->scheduleSlots()->get()->makeHidden('user_id'),
            'tasks' => $user->tasks()->with('items:id,task_id,title,is_done,position')->get()->makeHidden('user_id'),
            'habits' => $user->habits()->with('logs')->get()->makeHidden('user_id'),
            'wallets' => $user->wallets()->get()->makeHidden('user_id'),
            'wallet_transfers' => $user->walletTransfers()->get()->makeHidden('user_id'),
            'transactions' => $user->transactions()->get()->makeHidden('user_id'),
            'recurring_transactions' => $user->recurringTransactions()->get()->makeHidden('user_id'),
            'budgets' => $user->budgets()->get()->makeHidden('user_id'),
            'savings_goals' => $user->savingsGoals()->with('deposits')->get()->makeHidden('user_id'),
            'debts' => $user->debts()->with('payments')->get()->makeHidden('user_id'),
            'journal_entries' => $user->journalEntries()->get()->makeHidden('user_id'),
            // File lampiran tidak ikut (bisa besar); hanya daftar namanya.
            'notes' => $user->notes()->with('attachments:id,note_id,original_name,mime_type,size')->get()->makeHidden('user_id'),
            'weekly_reviews' => $user->weeklyReviews()->get()->makeHidden('user_id'),
        ];
    }

    public function toJson(User $user): string
    {
        return json_encode($this->toArray($user), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Tulis sesi fokus sebagai CSV (dengan BOM agar Excel membaca UTF-8 dengan benar).
     *
     * @param  resource  $output
     */
    public function writeSessionsCsv(User $user, $output): void
    {
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, ['Tanggal', 'Target', 'Materi', 'Menit', 'Skor', 'Catatan'], escape: '');

        $user->studySessions()->with(['goal', 'topic'])->orderBy('studied_on')->orderBy('id')
            ->each(fn (StudySession $session) => fputcsv($output, [
                $session->studied_on->toDateString(),
                $session->goal?->name,
                $session->topic?->name,
                $session->minutes,
                is_null($session->score) ? '' : StudySession::formatScore($session->score),
                $session->notes,
            ], escape: ''));
    }
}
