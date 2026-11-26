<?php

namespace Tests\Feature;

use App\Models\Goal;
use App\Models\Habit;
use App\Models\PushSubscription;
use App\Models\ScheduleSlot;
use App\Models\Task;
use App\Models\User;
use App\Services\Reminders;
use App\Services\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RemindersTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{public_key: string, private_key: string} */
    private array $keys;

    protected function setUp(): void
    {
        parent::setUp();

        $this->keys = WebPush::generateKeys();
        $this->app->instance(WebPush::class, new WebPush($this->keys['public_key'], $this->keys['private_key'], 'mailto:test@example.com'));
    }

    public function test_vapid_token_is_a_valid_es256_signature(): void
    {
        $token = app(WebPush::class)->vapidToken('https://fcm.googleapis.com/fcm/send/abc');
        [$header, $claims, $signature] = explode('.', $token);

        $payload = json_decode(WebPush::base64UrlDecode($claims), true);
        $this->assertSame('https://fcm.googleapis.com', $payload['aud']);
        $this->assertSame('mailto:test@example.com', $payload['sub']);
        $this->assertGreaterThan(time(), $payload['exp']);

        $raw = WebPush::base64UrlDecode($signature);
        $this->assertSame(64, strlen($raw));
        $this->assertSame(1, openssl_verify("{$header}.{$claims}", $this->rawSignatureToDer($raw), $this->publicKeyPem(), OPENSSL_ALGO_SHA256));
    }

    public function test_slot_reminder_is_sent_ten_minutes_before_start_and_only_once(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 18:52')); // Rabu
        Http::fake(['push.example.test/*' => Http::response('', 201)]);

        $goal = Goal::factory()->create(['name' => 'TOEFL', 'icon' => null]);
        ScheduleSlot::factory()->for($goal)->create(['day_of_week' => 3, 'start_time' => '19:00', 'end_time' => '20:00']);
        ScheduleSlot::factory()->for($goal)->create(['day_of_week' => 3, 'start_time' => '21:00', 'end_time' => '22:00']);
        PushSubscription::factory()->for($goal->user)->count(2)->create();

        $reminders = app(Reminders::class);

        $this->assertSame(1, $reminders->dispatch(now()));
        $this->assertSame(0, $reminders->dispatch(now()));

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => str_starts_with($request->header('Authorization')[0], 'vapid t=')
            && str_ends_with($request->header('Authorization')[0], 'k='.$this->keys['public_key'])
            && $request->header('TTL')[0] === '3600');

        $reminder = $goal->user->reminders()->sole();
        $this->assertSame('TOEFL mulai 19:00', $reminder->title);
        $this->assertSame('/fokus?goal='.$goal->id, $reminder->url);
    }

    public function test_morning_digest_summarises_the_day(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 07:30'));
        $user = User::factory()->create();
        Task::factory()->for($user)->create(['due_date' => '2026-11-25']);
        Task::factory()->for($user)->create(['due_date' => '2026-11-20']);
        Habit::factory()->for($user)->create();
        Goal::factory()->for($user)->create(['name' => 'SKD', 'deadline' => '2026-11-28']);

        $digest = collect(app(Reminders::class)->dueFor($user, now()))->firstWhere('key', 'digest:2026-11-25');

        $this->assertSame('1 tugas hari ini · 1 tugas terlambat · 1 kebiasaan · SKD tinggal 3 hari', $digest['body']);
        $this->assertEmpty(app(Reminders::class)->dueFor($user, Carbon::parse('2026-11-25 13:00')));
    }

    public function test_goal_deadline_with_time_is_reminded_within_the_hour_before(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 16:30'));
        $user = User::factory()->create();
        $soon = Goal::factory()->for($user)->create(['name' => 'TOEFL', 'icon' => null, 'deadline' => '2026-11-25', 'deadline_time' => '17:03']);
        Goal::factory()->for($user)->create(['deadline' => '2026-11-25', 'deadline_time' => '18:00']);
        Goal::factory()->for($user)->create(['deadline' => '2026-11-25', 'deadline_time' => '16:00']);
        Goal::factory()->for($user)->create(['deadline' => '2026-11-25', 'deadline_time' => '17:00', 'status' => 'paused']);
        Goal::factory()->for($user)->create(['deadline' => '2026-11-25', 'deadline_time' => null]);

        $goalReminders = collect(app(Reminders::class)->dueFor($user, now()))->filter(fn ($reminder) => str_starts_with($reminder['key'], 'goal:'));

        $this->assertSame(["goal:{$soon->id}:2026-11-25"], $goalReminders->pluck('key')->all());
        $this->assertSame('Deadline hari ini pukul 17:03 (33 menit lagi).', $goalReminders->first()['body']);
        $this->assertStringContainsString('TOEFL', $goalReminders->first()['title']);

        $this->assertFalse($soon->isOverdue());
        $this->travelTo(Carbon::parse('2026-11-25 17:04'));
        $this->assertTrue($soon->fresh()->isOverdue());
        $this->actingAs($user)->get(route('goals.index'))->assertSee('Lewat pukul 17:03');
    }

    public function test_task_is_reminded_again_right_at_its_due_time_even_when_created_last_minute(): void
    {
        $this->travelTo(Carbon::parse('2026-11-26 17:08'));
        $user = User::factory()->create();
        $justDue = Task::factory()->for($user)->create(['title' => 'Nana', 'due_date' => '2026-11-26', 'due_time' => '17:07']);
        Task::factory()->for($user)->create(['due_date' => '2026-11-26', 'due_time' => '16:30']);
        Task::factory()->for($user)->create(['due_date' => '2026-11-26', 'due_time' => '17:05', 'is_done' => true]);
        $goal = Goal::factory()->for($user)->create(['deadline' => '2026-11-26', 'deadline_time' => '17:00']);

        $keys = collect(app(Reminders::class)->dueFor($user, now()))->pluck('key')->all();

        $this->assertContains("task-due:{$justDue->id}:2026-11-26", $keys);
        $this->assertContains("goal-due:{$goal->id}:2026-11-26", $keys);
        $this->assertCount(2, $keys, 'tugas yang lewat >15 menit atau sudah selesai tidak diingatkan');
        $this->assertTrue($justDue->isOverdue(), 'tugas yang jamnya sudah lewat ditandai terlambat');
    }

    public function test_reminder_status_command_reports_delivery_setup(): void
    {
        $user = User::factory()->create(['email' => 'cek@example.test', 'telegram_chat_id' => '555']);
        $user->reminders()->create(['key' => 'x', 'title' => 'Uji pengingat', 'body' => '-', 'url' => '/']);
        PushSubscription::factory()->for($user)->create(['endpoint' => 'https://web.push.apple.com/abc']);

        $this->artisan('app:reminder-status')
            ->expectsOutputToContain('BELUM PERNAH')
            ->expectsOutputToContain('Apple (iPhone/iPad/Mac)')
            ->expectsOutputToContain('Uji pengingat')
            ->assertSuccessful();
    }

    public function test_goal_deadline_reminder_is_sent_to_telegram(): void
    {
        config(['services.telegram.bot_token' => '123:abc', 'services.telegram.bot_username' => 'PrimaBot']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->travelTo(Carbon::parse('2026-11-25 16:58'));
        $user = User::factory()->create(['telegram_chat_id' => '555']);
        Goal::factory()->for($user)->create(['name' => 'Angkat jemuran', 'deadline' => '2026-11-25', 'deadline_time' => '17:00']);

        $this->assertSame(1, app(Reminders::class)->dispatch(now()));
        $this->assertSame(0, app(Reminders::class)->dispatch(now()), 'tidak dikirim dua kali');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/sendMessage')
            && $request['chat_id'] === '555'
            && str_contains($request['text'], 'Angkat jemuran')
            && str_contains($request['text'], '2 menit lagi'));
    }

    public function test_expired_subscription_is_removed(): void
    {
        Http::fake(['*' => Http::response('', 410)]);
        $subscription = PushSubscription::factory()->create();

        $this->assertFalse(app(WebPush::class)->send($subscription));
        $this->assertModelMissing($subscription);
    }

    public function test_service_worker_endpoint_returns_only_recent_reminders_of_the_user(): void
    {
        $user = User::factory()->create();
        $user->reminders()->create(['key' => 'baru', 'title' => 'Baru', 'body' => 'Isi', 'url' => '/']);
        $old = $user->reminders()->create(['key' => 'lama', 'title' => 'Lama', 'body' => 'Isi', 'url' => '/']);
        $old->forceFill(['created_at' => now()->subHour()])->save();
        User::factory()->create()->reminders()->create(['key' => 'orang-lain', 'title' => 'Orang lain', 'body' => 'Isi', 'url' => '/']);

        $this->actingAs($user)
            ->getJson(route('reminders.latest'))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.title', 'Baru');

        auth()->logout();
        $this->getJson(route('reminders.latest'))->assertUnauthorized();
    }

    private function publicKeyPem(): string
    {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').WebPush::base64UrlDecode($this->keys['public_key']);

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private function rawSignatureToDer(string $raw): string
    {
        $integer = function (string $bytes): string {
            $bytes = ltrim($bytes, "\x00");
            if ($bytes === '' || ord($bytes[0]) > 0x7F) {
                $bytes = "\x00".$bytes;
            }

            return "\x02".chr(strlen($bytes)).$bytes;
        };

        $body = $integer(substr($raw, 0, 32)).$integer(substr($raw, 32));

        return "\x30".chr(strlen($body)).$body;
    }
}
