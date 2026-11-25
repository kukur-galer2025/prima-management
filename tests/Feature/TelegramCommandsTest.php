<?php

namespace Tests\Feature;

use App\Models\Goal;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Reminders;
use App\Services\Telegram;
use App\Services\TelegramCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramCommandsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-25 09:00'));
        config(['services.telegram.bot_token' => '123:ABC', 'services.telegram.bot_username' => 'PrimaBot']);
        $this->app->forgetInstance(Telegram::class);
        $this->app->forgetInstance(Reminders::class);
        Http::fake(fn () => Http::response(['ok' => true]));

        $this->user = User::factory()->create(['telegram_chat_id' => '777']);
    }

    public function test_task_command_uses_the_indonesian_parser(): void
    {
        Goal::factory()->for($this->user)->create(['name' => 'TOEFL']);

        $reply = $this->command('/tugas Daftar tes besok jam 10 !penting #toefl');

        $task = $this->user->tasks()->sole();
        $this->assertSame('Daftar tes', $task->title);
        $this->assertSame('2026-11-26', $task->due_date->toDateString());
        $this->assertSame('10:00', $task->due_time);
        $this->assertSame(1, $task->priority);
        $this->assertNotNull($task->goal_id);
        $this->assertStringContainsString('Tugas ditambahkan', $reply);
    }

    public function test_expense_command_with_shorthand_amount_category_and_wallet(): void
    {
        Wallet::factory()->for($this->user)->create(['name' => 'Tunai', 'opening_balance' => 100000, 'position' => 0]);
        $gopay = Wallet::factory()->for($this->user)->create(['name' => 'GoPay', 'opening_balance' => 200000, 'position' => 1]);
        $this->user->budgets()->create(['category' => 'Makan', 'amount' => 50000]);

        $reply = $this->command('/keluar 45rb makan siang di kantin @gopay');

        $transaction = $this->user->transactions()->sole();
        $this->assertSame(45000, $transaction->amount);
        $this->assertSame('Makan', $transaction->category);
        $this->assertSame('siang di kantin', $transaction->notes);
        $this->assertSame($gopay->id, $transaction->wallet_id);
        $this->assertStringContainsString('Rp 155.000', $reply);
        $this->assertStringContainsString('Anggaran Makan: 90%', $reply);
        $this->assertStringContainsString('⚠️', $reply);
    }

    public function test_income_command_defaults_to_first_wallet_and_new_category(): void
    {
        $wallet = Wallet::factory()->for($this->user)->create(['name' => 'BCA']);

        $this->command('/masuk 1,5jt honor');

        $transaction = $this->user->transactions()->sole();
        $this->assertSame('income', $transaction->type);
        $this->assertSame(1500000, $transaction->amount);
        $this->assertSame('Honor', $transaction->category);
        $this->assertSame($wallet->id, $transaction->wallet_id);
    }

    public function test_invalid_amount_or_unknown_wallet_explains_the_format(): void
    {
        $this->assertStringContainsString('Format', $this->command('/keluar makan 25rb'));
        $this->assertStringContainsString('tidak ditemukan', $this->command('/keluar 25rb makan @dana'));
        $this->assertSame(0, $this->user->transactions()->count());
    }

    public function test_mood_command_writes_todays_journal(): void
    {
        $this->command('/mood 4 capek tapi produktif');
        $this->command('/mood 5');

        $entry = $this->user->journalEntries()->sole();
        $this->assertSame(5, $entry->mood);
        $this->assertSame('capek tapi produktif', $entry->content);
        $this->assertStringContainsString('Format', $this->command('/mood 9'));
    }

    public function test_today_command_lists_tasks_and_plain_text_shows_help(): void
    {
        $this->user->tasks()->create(['title' => 'Kumpul laporan', 'priority' => 2, 'due_date' => '2026-11-25']);

        $this->assertStringContainsString('Kumpul laporan', $this->command('/hari'));
        $this->assertStringContainsString('Perintah Prima Management', $this->command('halo bot'));
        $this->assertSame(1, $this->user->tasks()->count(), 'Obrolan biasa tidak menjadi tugas');
    }

    public function test_commands_from_linked_chat_are_handled_through_the_webhook_flow(): void
    {
        app(Telegram::class)->handleUpdate(['message' => ['chat' => ['id' => 777], 'text' => '/keluar 10rb parkir']]);

        $this->assertSame('Parkir', $this->user->transactions()->sole()->category);
        Http::assertSent(fn (Request $request) => $request['chat_id'] === '777' && str_contains($request['text'], 'Rp 10.000'));
    }

    public function test_disabled_users_cannot_use_commands(): void
    {
        $this->user->forceFill(['disabled_at' => now()])->save();

        app(Telegram::class)->handleUpdate(['message' => ['chat' => ['id' => 777], 'text' => '/keluar 10rb parkir']]);

        $this->assertSame(0, $this->user->transactions()->count());
    }

    private function command(string $text): string
    {
        return app(TelegramCommands::class)->handle($this->user->fresh(), $text);
    }
}
