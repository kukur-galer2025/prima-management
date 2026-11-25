<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Reminders;
use App\Services\Telegram;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class TelegramTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array<string, mixed>> update yang dikembalikan getUpdates palsu */
    private array $pendingUpdates = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => '123:ABC',
            'services.telegram.bot_username' => 'PrimaBot',
            'services.telegram.webhook_secret' => 'rahasia-webhook',
        ]);
        $this->app->forgetInstance(Telegram::class);
        $this->app->forgetInstance(Reminders::class);

        Http::fake(fn (Request $request) => Http::response([
            'ok' => true,
            'result' => str_contains($request->url(), 'getUpdates') ? $this->pendingUpdates : [],
        ]));
    }

    public function test_user_links_telegram_through_start_code(): void
    {
        $user = User::factory()->create(['name' => 'Sinta']);

        $component = Livewire::actingAs($user)->test('pages::account')
            ->assertSee('Sambungkan Telegram')
            ->call('connectTelegram');

        $code = $user->fresh()->telegram_link_code;
        $this->assertSame(24, strlen($code));
        $component->assertSet('telegramLink', "https://t.me/PrimaBot?start={$code}");

        $this->postJson(route('telegram.webhook'), $this->update("/start {$code}", 555), ['X-Telegram-Bot-Api-Secret-Token' => 'rahasia-webhook'])
            ->assertOk();

        $user->refresh();
        $this->assertSame('555', $user->telegram_chat_id);
        $this->assertNull($user->telegram_link_code);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'sendMessage') && $request['chat_id'] === '555' && str_contains($request['text'], 'Sinta'));

        $component->call('refreshTelegram')->assertSet('telegramLink', null)->assertSee('Tersambung ke @PrimaBot');
    }

    public function test_webhook_rejects_wrong_secret(): void
    {
        $user = User::factory()->create(['telegram_link_code' => str_repeat('a', 24)]);

        $this->postJson(route('telegram.webhook'), $this->update('/start '.str_repeat('a', 24), 1), ['X-Telegram-Bot-Api-Secret-Token' => 'salah'])
            ->assertForbidden();

        $this->assertNull($user->fresh()->telegram_chat_id);
    }

    public function test_invalid_code_and_stop_command(): void
    {
        $user = User::factory()->create(['telegram_chat_id' => '777']);
        $telegram = app(Telegram::class);

        $telegram->handleUpdate($this->update('/start '.str_repeat('Z', 24), 999));
        Http::assertSent(fn (Request $request) => $request['chat_id'] === '999' && str_contains($request['text'], 'tidak valid'));

        $telegram->handleUpdate($this->update('/stop', 777));
        $this->assertNull($user->fresh()->telegram_chat_id);
    }

    public function test_reminders_are_sent_to_telegram_without_push_subscriptions(): void
    {
        $user = User::factory()->create(['telegram_chat_id' => '4242']);
        $user->tasks()->create(['title' => 'Kumpul tugas', 'priority' => 2, 'due_date' => '2026-11-25', 'due_time' => '10:05']);

        $created = app(Reminders::class)->dispatch(Carbon::parse('2026-11-25 10:00'));

        $this->assertSame(1, $created);
        Http::assertSent(fn (Request $request) => $request['chat_id'] === '4242' && str_contains($request['text'], 'Kumpul tugas'));
    }

    public function test_disabled_users_get_no_reminders(): void
    {
        $user = User::factory()->disabled()->create(['telegram_chat_id' => '4242']);
        $user->tasks()->create(['title' => 'Kumpul tugas', 'priority' => 2, 'due_date' => '2026-11-25', 'due_time' => '10:05']);

        $this->assertSame(0, app(Reminders::class)->dispatch(Carbon::parse('2026-11-25 10:00')));
        Http::assertNothingSent();
    }

    public function test_user_can_disconnect_and_send_test(): void
    {
        $user = User::factory()->create(['telegram_chat_id' => '31337']);

        Livewire::actingAs($user)->test('pages::account')
            ->call('sendTestTelegram')
            ->assertDispatched('toast', message: 'Pesan tes dikirim ke Telegram')
            ->call('disconnectTelegram');

        $this->assertNull($user->fresh()->telegram_chat_id);
    }

    public function test_polling_command_processes_updates(): void
    {
        $this->pendingUpdates = [['update_id' => 10] + $this->update('/start '.str_repeat('b', 24), 808)];

        $user = User::factory()->create(['telegram_link_code' => str_repeat('b', 24)]);

        $this->artisan('app:telegram-poll')->assertSuccessful();

        $this->assertSame('808', $user->fresh()->telegram_chat_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function update(string $text, int $chatId): array
    {
        return ['message' => ['chat' => ['id' => $chatId], 'text' => $text]];
    }
}
