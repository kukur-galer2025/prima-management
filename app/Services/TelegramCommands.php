<?php

namespace App\Services;

use App\Models\JournalEntry;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Str;

/**
 * Perintah bot Telegram untuk mencatat tanpa membuka aplikasi. Setiap perintah mengembalikan
 * balasan HTML yang dikirim balik ke chat.
 */
class TelegramCommands
{
    public function __construct(
        private QuickTaskParser $taskParser,
        private Reminders $reminders,
        private BudgetMonitor $budgetMonitor,
    ) {}

    public function handle(User $user, string $text): string
    {
        // Pesan tanpa perintah tidak dicatat otomatis supaya obrolan biasa tidak menjadi tugas.
        if (! preg_match('/^\/(\w+)(?:@\w+)?\s*(.*)$/su', trim($text), $matches)) {
            return $this->help();
        }

        $argument = trim($matches[2]);

        return match (strtolower($matches[1])) {
            'tugas', 'task' => $this->addTask($user, $argument),
            'keluar', 'beli', 'bayar' => $this->addTransaction($user, 'expense', $argument),
            'masuk' => $this->addTransaction($user, 'income', $argument),
            'mood', 'jurnal' => $this->logMood($user, $argument),
            'hari', 'ringkasan', 'today' => $this->today($user),
            default => $this->help(),
        };
    }

    public function help(): string
    {
        return implode("\n", [
            '<b>Perintah Prima Management</b>',
            '',
            '📝 <code>/tugas Bayar UKT besok jam 10 !penting</code>',
            '💸 <code>/keluar 25rb makan siang</code>',
            '💰 <code>/masuk 1,5jt gaji</code>',
            '😄 <code>/mood 4 capek tapi produktif</code>',
            '📅 <code>/hari</code> ringkasan hari ini',
            '',
            'Tambahkan <code>@namadompet</code> untuk memilih dompet, mis. <code>/keluar 50rb bensin @gopay</code>.',
        ]);
    }

    private function addTask(User $user, string $text): string
    {
        if ($text === '') {
            return 'Tulis tugasnya, mis. <code>/tugas Kirim CV jumat jam 9 !penting</code>';
        }

        $parsed = $this->taskParser->parse($text, $user->goals()->where('status', '!=', 'done')->get(), now());

        /** @var Task $task */
        $task = $user->tasks()->create([
            'title' => Str::limit($parsed['title'], 200, ''),
            'due_date' => $parsed['due_date'],
            'due_time' => $parsed['due_time'],
            'priority' => $parsed['priority'] ?? 2,
            'recurrence' => $parsed['recurrence'],
            'goal_id' => $parsed['goal_id'],
        ]);

        $details = array_filter([
            $task->dueLabel('D, d M'),
            $task->priority === 1 ? '❗ penting' : null,
            $task->recurrence ? '🔁 '.strtolower(Task::RECURRENCES[$task->recurrence]) : null,
            $parsed['goal_label'] ? '🎯 '.$parsed['goal_label'] : null,
        ]);

        return '✅ Tugas ditambahkan: <b>'.e($task->title).'</b>'.($details ? "\n".e(implode(' · ', $details)) : '');
    }

    private function addTransaction(User $user, string $type, string $argument): string
    {
        $wallet = null;

        // @dompet di mana saja dalam pesan memilih dompet berdasarkan nama.
        if (preg_match('/(?:^|\s)@(\S+)/u', $argument, $walletMatch)) {
            $wallet = $user->wallets()->get()->first(fn (Wallet $item) => Str::lower(Str::replace(' ', '', $item->name)) === Str::lower($walletMatch[1]));
            $argument = trim(str_replace($walletMatch[0], ' ', $argument));

            if (! $wallet) {
                return 'Dompet <b>'.e($walletMatch[1]).'</b> tidak ditemukan. Dompetmu: '.e($user->wallets()->pluck('name')->implode(', ') ?: '(belum ada)').'.';
            }
        }

        $parts = preg_split('/\s+/u', $argument, 2);
        $amount = Transaction::parseAmount($parts[0] ?? '');
        $command = $type === 'income' ? '/masuk' : '/keluar';

        if (! $amount) {
            return "Format: <code>{$command} 25rb makan siang</code> (nominal dulu, lalu kategori/keterangan).";
        }

        [$category, $notes] = $this->categoryAndNotes($user, $type, $parts[1] ?? '');
        $wallet ??= $user->wallets()->orderBy('position')->orderBy('id')->first();

        $user->transactions()->create([
            'wallet_id' => $wallet?->id,
            'type' => $type,
            'amount' => $amount,
            'category' => $category,
            'notes' => $notes,
            'occurred_on' => today()->toDateString(),
        ]);

        $reply = ($type === 'income' ? '💰 Pemasukan ' : '💸 Pengeluaran ').'<b>'.Transaction::formatRupiah($amount).'</b> · '.e($category)
            .($notes ? ' · '.e($notes) : '')
            .($wallet ? "\nDompet ".e($wallet->name).': '.Transaction::formatRupiah((int) Wallet::balancesFor($user)[$wallet->id]) : '');

        if ($type === 'expense') {
            $budget = $this->budgetMonitor->usage($user)->firstWhere('key', 'category:'.$category);

            if ($budget) {
                $reply .= "\nAnggaran ".e($category).": {$budget['percent']}% (".Transaction::formatRupiah($budget['spent']).' / '.Transaction::formatRupiah($budget['budget']).')'
                    .(BudgetMonitor::levelFor($budget['percent']) ? ' ⚠️' : '');
            }
        }

        return $reply;
    }

    /**
     * Kata pertama menjadi kategori (dicocokkan tanpa peka huruf besar dengan kategori yang sudah ada),
     * sisanya keterangan. Tanpa teks sama sekali, kategori "Lainnya".
     *
     * @return array{0: string, 1: ?string}
     */
    private function categoryAndNotes(User $user, string $type, string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return ['Lainnya', null];
        }

        [$first, $rest] = array_pad(preg_split('/\s+/u', $text, 2), 2, null);

        $known = collect(Transaction::CATEGORIES[$type] ?? [])
            ->merge($user->transactions()->where('type', $type)->distinct()->pluck('category'))
            ->merge($user->budgets()->pluck('category'));

        $category = $known->first(fn (string $item) => Str::lower($item) === Str::lower($first)) ?? Str::ucfirst(Str::limit($first, 50, ''));

        return [$category, $rest ? Str::limit($rest, 255, '') : null];
    }

    private function logMood(User $user, string $argument): string
    {
        if (! preg_match('/^([1-5])\b\s*(.*)$/su', $argument, $matches)) {
            return 'Format: <code>/mood 4 capek tapi produktif</code> (1 = buruk, 5 = luar biasa).';
        }

        $mood = (int) $matches[1];
        $content = trim($matches[2]) !== '' ? Str::limit(trim($matches[2]), 2000, '') : null;

        $entry = $user->journalEntries()->whereDate('entry_on', today())->first();
        $entry
            ? $entry->update(['mood' => $mood, 'content' => $content ?? $entry->content])
            : $user->journalEntries()->create(['entry_on' => today()->toDateString(), 'mood' => $mood, 'content' => $content]);

        return JournalEntry::MOODS[$mood]['emoji'].' Jurnal hari ini tercatat: <b>'.JournalEntry::MOODS[$mood]['label'].'</b>'.($content ? "\n".e($content) : '');
    }

    private function today(User $user): string
    {
        $digest = $this->reminders->dailyDigest($user, now());
        $tasks = $user->tasks()->where('is_done', false)->whereDate('due_date', '<=', today())->orderBy('due_date')->orderBy('due_time')->limit(8)->get();

        $lines = ['<b>Hari ini, '.today()->translatedFormat('l d F').'</b>', e($digest ?? 'Tidak ada yang mendesak hari ini. ✨')];

        if ($tasks->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '<b>Tugas:</b>';

            foreach ($tasks as $task) {
                $lines[] = '• '.e($task->title).($task->isOverdue() ? ' (terlambat)' : ($task->due_time ? ' · '.$task->due_time : ''));
            }
        }

        return implode("\n", $lines);
    }
}
