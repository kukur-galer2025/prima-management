<?php

namespace App\Services;

use App\Models\StudySession;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonInterface;

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
    public function writeSessionsCsv(User $user, $output, ?CarbonInterface $from = null, ?CarbonInterface $to = null): void
    {
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, ['Tanggal', 'Target', 'Materi', 'Menit', 'Skor', 'Catatan'], escape: '');

        $user->studySessions()->with(['goal', 'topic'])
            ->when($from, fn ($query) => $query->whereDate('studied_on', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('studied_on', '<=', $to))
            ->orderBy('studied_on')->orderBy('id')
            ->each(fn (StudySession $session) => fputcsv($output, [
                $session->studied_on->toDateString(),
                $session->goal?->name,
                $session->topic?->name,
                $session->minutes,
                is_null($session->score) ? '' : StudySession::formatScore($session->score),
                $session->notes,
            ], escape: ''));
    }

    /**
     * Tulis transaksi sebagai CSV yang bisa dibuka di Excel (pemasukan positif, pengeluaran negatif).
     *
     * @param  resource  $output
     */
    public function writeTransactionsCsv(User $user, $output, ?CarbonInterface $from = null, ?CarbonInterface $to = null): void
    {
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, ['Tanggal', 'Jenis', 'Kategori', 'Nominal', 'Dompet', 'Keterangan', 'Rutin'], escape: '');

        $user->transactions()->with('wallet')
            ->when($from, fn ($query) => $query->whereDate('occurred_on', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('occurred_on', '<=', $to))
            ->orderBy('occurred_on')->orderBy('id')
            ->each(fn (Transaction $transaction) => fputcsv($output, [
                $transaction->occurred_on->toDateString(),
                Transaction::TYPES[$transaction->type] ?? $transaction->type,
                $transaction->category,
                $transaction->isIncome() ? $transaction->amount : -$transaction->amount,
                $transaction->wallet?->name,
                $transaction->notes,
                $transaction->recurring_transaction_id ? 'ya' : '',
            ], escape: ''));
    }
}
