<?php

namespace Tests\Feature;

use App\Models\AdminActivity;
use App\Models\Goal;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Preflight;
use App\Services\Reminders;
use App\Services\Telegram;
use App\Services\TelegramCommands;
use App\Services\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class ResilienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_broken_smtp_does_not_break_admin_or_password_reset_pages(): void
    {
        $this->useBrokenMailer();
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['email' => 'ada@example.com']);

        Livewire::actingAs($admin)->test('pages::admin')
            ->call('openCreate')
            ->set('name', 'Citra')
            ->set('email', 'citra@example.com')
            ->call('save')
            ->assertOk()
            ->assertDispatched('toast', message: 'Akun dibuat, tapi email undangan GAGAL terkirim. Buka akun itu lalu isi kata sandinya.')
            ->call('sendResetLink', $user->id)
            ->assertDispatched('toast', message: 'Email gagal terkirim. Periksa pengaturan SMTP.');

        $this->assertDatabaseHas('users', ['email' => 'citra@example.com']);
        $this->assertDatabaseHas('admin_activities', ['action' => 'created']);
        $this->assertDatabaseMissing('admin_activities', ['action' => 'invited']);

        $this->post(route('logout'));
        Livewire::test('pages::auth.forgot-password')->set('email', 'ada@example.com')->call('sendLink')->assertOk()->assertHasErrors('email');
    }

    public function test_one_failing_user_does_not_stop_reminders_for_others(): void
    {
        config(['services.telegram.bot_token' => '1:A', 'services.telegram.bot_username' => 'Bot']);
        $this->app->forgetInstance(Telegram::class);
        Http::fake(fn () => Http::response(['ok' => true]));

        $broken = User::factory()->create(['telegram_chat_id' => '1']);
        $healthy = User::factory()->create(['telegram_chat_id' => '2']);

        foreach ([$broken, $healthy] as $user) {
            $user->tasks()->create(['title' => 'Tugas', 'priority' => 2, 'due_date' => '2026-11-25', 'due_time' => '10:05']);
        }

        // Pengguna pertama punya data rusak yang membuat penyusunan pengingatnya gagal.
        DB::table('habits')->insert(['user_id' => $broken->id, 'name' => 'Rusak', 'color' => '#000000', 'days' => '"bukan-array"', 'remind_at' => '10:00', 'created_at' => now(), 'updated_at' => now()]);

        app(Reminders::class)->dispatch(Carbon::parse('2026-11-25 10:00'));

        $this->assertSame(0, $broken->reminders()->count());
        $this->assertSame(1, $healthy->reminders()->count());
    }

    public function test_repeated_telegram_updates_are_recorded_once(): void
    {
        config(['services.telegram.bot_token' => '1:A', 'services.telegram.bot_username' => 'Bot']);
        $this->app->forgetInstance(Telegram::class);
        Http::fake(fn () => Http::response(['ok' => true]));
        $user = User::factory()->create(['telegram_chat_id' => '9']);
        $update = ['update_id' => 501, 'message' => ['chat' => ['id' => 9], 'text' => '/keluar 10rb parkir']];

        app(Telegram::class)->handleUpdate($update);
        app(Telegram::class)->handleUpdate($update);

        $this->assertSame(1, $user->transactions()->count());
    }

    public function test_failing_telegram_command_replies_instead_of_crashing_the_webhook(): void
    {
        config(['services.telegram.bot_token' => '1:A', 'services.telegram.bot_username' => 'Bot', 'services.telegram.webhook_secret' => 's']);
        $this->app->forgetInstance(Telegram::class);
        Http::fake(fn () => Http::response(['ok' => true]));
        User::factory()->create(['telegram_chat_id' => '9']);
        $this->mock(TelegramCommands::class)->shouldReceive('handle')->andThrow(new RuntimeException('rusak'));

        $this->postJson(route('telegram.webhook'), ['message' => ['chat' => ['id' => 9], 'text' => '/hari']], ['X-Telegram-Bot-Api-Secret-Token' => 's'])->assertOk();

        Http::assertSent(fn ($request) => str_contains($request['text'], 'terjadi kesalahan'));
    }

    public function test_health_check_fails_when_the_database_is_broken(): void
    {
        $this->get('/up')->assertOk();

        Schema::disableForeignKeyConstraints();
        Schema::drop('users');

        $this->get('/up')->assertStatus(500);
    }

    public function test_branded_error_pages_hide_details(): void
    {
        config(['app.debug' => false]);
        Route::get('/uji-error', fn () => throw new RuntimeException('rahasia internal'));
        Route::get('/uji-503', fn () => abort(503));

        $this->get('/tidak-ada')->assertNotFound()->assertSee('Halaman tidak ditemukan');
        $this->get('/uji-error')->assertStatus(500)->assertSee('Ada yang tidak beres')->assertDontSee('rahasia internal');
        $this->get('/uji-503')->assertStatus(503)->assertSee('Sedang diperbarui');
        $this->actingAs(User::factory()->create())->get(route('admin'))->assertForbidden()->assertSee('Tidak punya akses');
    }

    public function test_sqlite_is_configured_for_concurrent_access(): void
    {
        $this->assertSame('WAL', config('database.connections.sqlite.journal_mode'));
        $this->assertGreaterThanOrEqual(5000, config('database.connections.sqlite.busy_timeout'));
        $this->assertSame('IMMEDIATE', config('database.connections.sqlite.transaction_mode'));
    }

    public function test_preflight_blocks_unsafe_configuration(): void
    {
        User::factory()->admin()->create(['email' => 'bos@example.com', 'password' => 'password']);
        config(['app.debug' => true, 'app.url' => 'http://localhost']);

        $errors = collect(app(Preflight::class)->run())->where('level', 'error')->pluck('message')->implode("\n");

        $this->assertStringContainsString('APP_DEBUG', $errors);
        $this->assertStringContainsString('APP_URL', $errors);
        $this->assertStringContainsString('bos@example.com masih memakai kata sandi contoh', $errors);
        $this->artisan('app:preflight')->assertFailed();
    }

    public function test_scheduler_survives_unreachable_push_service(): void
    {
        $keys = WebPush::generateKeys();
        config(['services.webpush' => [...$keys, 'subject' => 'mailto:a@example.com']]);
        $this->app->forgetInstance(WebPush::class);
        Http::fake(fn () => Http::failedConnection());
        $this->travelTo(Carbon::parse('2026-11-25 10:00'));

        $user = User::factory()->create();
        PushSubscription::factory()->for($user)->create();
        $user->tasks()->create(['title' => 'Tugas', 'priority' => 2, 'due_date' => '2026-11-25', 'due_time' => '10:05']);

        $this->artisan('app:send-reminders')->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame(1, $user->reminders()->count());
    }

    public function test_preflight_detects_pending_migrations(): void
    {
        User::factory()->admin()->create(['password' => 'sandi-yang-kuat-9']);
        $messages = fn () => collect(app(Preflight::class)->run())->where('level', 'error')->pluck('message')->implode("\n");

        $this->assertStringNotContainsString('migrasi', $messages());

        DB::table('migrations')->latest('id')->limit(1)->delete();
        $this->assertStringContainsString('Ada migrasi yang belum dijalankan', $messages());
    }

    public function test_warm_up_compiles_every_page_and_release_header_is_sent(): void
    {
        $this->artisan('app:warm-up')->assertSuccessful()->expectsOutputToContain('halaman dikompilasi');
        $this->assertTrue(config('view.check_cache_timestamps'), 'Bawaan tetap memeriksa waktu ubah (pengembangan)');

        $this->get(route('login'))->assertHeaderMissing('X-Release');
        config(['app.release' => '20261125']);
        $this->get(route('login'))->assertHeader('X-Release', '20261125');
    }

    public function test_oversized_values_never_cause_a_server_error(): void
    {
        config(['services.telegram.bot_token' => '1:A', 'services.telegram.bot_username' => 'Bot']);
        $this->app->forgetInstance(Telegram::class);
        Http::fake(fn () => Http::response(['ok' => true]));
        $user = User::factory()->create(['telegram_chat_id' => '9']);

        // Ringkasan pagi dengan banyak deadline jauh melebihi kolom body.
        Goal::factory()->count(12)->for($user)->create(['name' => str_repeat('Target panjang ', 6), 'status' => 'active', 'deadline' => '2026-11-27']);
        app(Reminders::class)->dispatch(Carbon::parse('2026-11-25 08:00'));
        $this->assertSame(1, $user->reminders()->where('key', 'like', 'digest:%')->count());

        Livewire::test('pages::auth.login')->set('email', str_repeat('a', 60).'@'.str_repeat('b.', 150).'id')->set('password', 'x')->call('login')->assertOk()->assertHasErrors('email');

        $json = json_encode(['goals' => [['id' => 1, 'name' => str_repeat('x', 5000), 'icon' => str_repeat('y', 500)]]]);
        Livewire::actingAs($user)->test('pages::account')
            ->set('importFile', UploadedFile::fake()->createWithContent('b.json', $json))
            ->call('importData')
            ->assertOk();

        $long = User::factory()->make(['name' => str_repeat('n', 100), 'email' => str_repeat('e', 240).'@contoh.id']);
        AdminActivity::record($user, $long, 'deleted');
        $this->assertDatabaseCount('admin_activities', 1);
    }

    private function useBrokenMailer(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);
    }
}
