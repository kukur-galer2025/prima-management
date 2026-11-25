<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Bot Telegram untuk pengingat. Pengguna menyambungkan akun dengan membuka tautan
 * t.me/<bot>?start=<kode>; bot menerima "/start <kode>" lalu menyimpan chat id-nya.
 */
class Telegram
{
    public function __construct(
        private ?string $botToken,
        private ?string $botUsername,
        private ?string $webhookSecret,
    ) {}

    public function isConfigured(): bool
    {
        return filled($this->botToken) && filled($this->botUsername);
    }

    public function botUsername(): ?string
    {
        return $this->botUsername ? ltrim($this->botUsername, '@') : null;
    }

    /** Tautan untuk menyambungkan akun; kode lama diganti setiap kali tautan dibuat. */
    public function linkUrl(User $user): string
    {
        $code = Str::random(24);
        $user->forceFill(['telegram_link_code' => $code])->save();

        return 'https://t.me/'.$this->botUsername().'?start='.$code;
    }

    public function isValidWebhookSecret(?string $secret): bool
    {
        return filled($this->webhookSecret) && is_string($secret) && hash_equals($this->webhookSecret, $secret);
    }

    /**
     * Kirim pesan (HTML). Tombol "Buka aplikasi" hanya ditambahkan untuk alamat https karena Telegram menolak http.
     */
    public function sendMessage(string $chatId, string $html, ?string $url = null): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $payload = ['chat_id' => $chatId, 'text' => $html, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];

        if ($url && Str::startsWith($url, 'https://')) {
            $payload['reply_markup'] = ['inline_keyboard' => [[['text' => 'Buka Prima Management', 'url' => $url]]]];
        }

        try {
            return $this->call('sendMessage', $payload)['ok'] ?? false;
        } catch (Throwable $exception) {
            Log::warning('Gagal mengirim pesan Telegram', ['error' => $exception->getMessage()]);

            return false;
        }
    }

    /**
     * Proses satu update dari Telegram (lewat webhook atau polling).
     *
     * @param  array<string, mixed>  $update
     */
    public function handleUpdate(array $update): void
    {
        // Telegram mengirim ulang update yang lambat dibalas; tanpa ini transaksi bisa tercatat dua kali.
        $updateId = data_get($update, 'update_id');

        if ($updateId && ! Cache::add('telegram.update.'.$updateId, true, now()->addDay())) {
            return;
        }

        $chatId = data_get($update, 'message.chat.id');
        $text = trim((string) data_get($update, 'message.text', ''));

        if (! $chatId || $text === '') {
            return;
        }

        $chatId = (string) $chatId;

        if (preg_match('/^\/start(?:@\w+)?\s+([A-Za-z0-9]{24})$/', $text, $matches)) {
            $user = User::query()->where('telegram_link_code', $matches[1])->first();

            if (! $user) {
                $this->sendMessage($chatId, 'Kode sambungan tidak valid atau sudah kedaluwarsa. Buat tautan baru dari halaman <b>Akun</b> di Prima Management.');

                return;
            }

            // Satu chat Telegram hanya terhubung ke satu akun.
            User::query()->where('telegram_chat_id', $chatId)->whereKeyNot($user->id)->update(['telegram_chat_id' => null]);
            $user->forceFill(['telegram_chat_id' => $chatId, 'telegram_link_code' => null])->save();

            $this->sendMessage($chatId, 'Halo, <b>'.e($user->name).'</b>! 👋 Akun Prima Management kamu sudah tersambung. Pengingat jadwal, tugas, dan ringkasan pagi akan dikirim ke sini.'."\n\nKamu juga bisa mencatat langsung dari sini, ketik /bantuan untuk melihat caranya. Ketik /stop untuk berhenti.");

            return;
        }

        if (preg_match('/^\/stop(?:@\w+)?$/', $text)) {
            $updated = User::query()->where('telegram_chat_id', $chatId)->update(['telegram_chat_id' => null]);
            $this->sendMessage($chatId, $updated ? 'Pengingat Telegram dimatikan. Sambungkan lagi kapan saja dari halaman Akun.' : 'Chat ini belum tersambung ke akun mana pun.');

            return;
        }

        $user = User::query()->where('telegram_chat_id', $chatId)->whereNull('disabled_at')->first();

        if ($user) {
            try {
                $reply = app(TelegramCommands::class)->handle($user, $text);
            } catch (Throwable $exception) {
                report($exception);
                $reply = 'Maaf, terjadi kesalahan saat memproses perintah itu. Coba lagi sebentar lagi.';
            }

            $this->sendMessage($chatId, $reply);

            return;
        }

        $this->sendMessage($chatId, 'Untuk menyambungkan, buka <b>Akun → Pengingat lewat Telegram</b> di Prima Management lalu ketuk <b>Sambungkan</b>.');
    }

    /** Daftarkan alamat webhook ke Telegram. */
    public function setWebhook(string $url): bool
    {
        return (bool) ($this->call('setWebhook', array_filter([
            'url' => $url,
            'secret_token' => $this->webhookSecret,
            'allowed_updates' => ['message'],
        ]))['ok'] ?? false);
    }

    /** Daftar perintah yang muncul di menu "/" aplikasi Telegram. */
    public function registerCommands(): bool
    {
        return (bool) ($this->call('setMyCommands', ['commands' => [
            ['command' => 'tugas', 'description' => 'Tambah tugas, mis. Bayar UKT besok jam 10'],
            ['command' => 'keluar', 'description' => 'Catat pengeluaran, mis. 25rb makan'],
            ['command' => 'masuk', 'description' => 'Catat pemasukan, mis. 1,5jt gaji'],
            ['command' => 'mood', 'description' => 'Isi jurnal, mis. 4 capek tapi produktif'],
            ['command' => 'hari', 'description' => 'Ringkasan hari ini'],
            ['command' => 'bantuan', 'description' => 'Daftar perintah'],
            ['command' => 'stop', 'description' => 'Berhenti menerima pengingat'],
        ]])['ok'] ?? false);
    }

    /**
     * Ambil update lewat polling (untuk pengembangan lokal tanpa alamat https publik).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getUpdates(int $offset): array
    {
        $this->call('deleteWebhook', []);

        return (array) ($this->call('getUpdates', ['offset' => $offset, 'timeout' => 0, 'allowed_updates' => ['message']])['result'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function call(string $method, array $payload): array
    {
        return (array) Http::timeout(10)
            ->asJson()
            ->post("https://api.telegram.org/bot{$this->botToken}/{$method}", $payload)
            ->json();
    }
}
