<?php

namespace Tests\Feature;

use App\Models\Debt;
use App\Models\Habit;
use App\Models\SavingsGoal;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\BudgetMonitor;
use App\Services\Reminders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardAndAlertsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_money_debts_savings_and_doing_tasks(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 10:00'));
        $user = User::factory()->create();
        Wallet::factory()->for($user)->create(['opening_balance' => 1250000]);
        Debt::factory()->for($user)->create(['person' => 'Andi', 'amount' => 300000, 'due_on' => '2026-11-27']);
        Debt::factory()->for($user)->create(['person' => 'Jauh', 'amount' => 300000, 'due_on' => '2026-12-25']);
        SavingsGoal::factory()->for($user)->create(['name' => 'Laptop baru', 'target_amount' => 8000000]);
        $task = Task::factory()->for($user)->create(['title' => 'Revisi bab 2', 'started_at' => now()]);
        $task->items()->create(['title' => 'Bagian A', 'is_done' => true]);
        $task->items()->create(['title' => 'Bagian B']);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Saldo 1 dompet')
            ->assertSee('Rp 1.250.000')
            ->assertSee('Bayar ke Andi')
            ->assertDontSee('Jauh')
            ->assertSee('Laptop baru')
            ->assertSee('● Dikerjakan')
            ->assertSee('☑ 1/2');
    }

    public function test_mood_can_be_logged_from_dashboard_in_the_evening(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 19:00'));
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertSee('Bagaimana harimu?');

        Livewire::actingAs($user)->test('pages::dashboard')->call('logMood', 4)->call('logMood', 9);

        $this->assertSame(4, $user->journalEntries()->sole()->mood);
        $this->actingAs($user)->get(route('dashboard'))->assertDontSee('Bagaimana harimu?');
    }

    public function test_budget_usage_and_alert_levels(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 10:00'));
        $user = User::factory()->create(['monthly_budget' => 2000000]);
        $budget = $user->budgets()->create(['category' => 'Makan', 'amount' => 1000000]);
        Transaction::factory()->for($user)->create(['category' => 'Makan', 'amount' => 850000, 'occurred_on' => '2026-11-20']);
        Transaction::factory()->for($user)->create(['category' => 'Makan', 'amount' => 900000, 'occurred_on' => '2026-10-20']);

        $usage = app(BudgetMonitor::class)->usage($user)->keyBy('key');
        $this->assertSame(85, $usage['category:Makan']['percent']);
        $this->assertSame(42, $usage['total']['percent']);

        $alerts = app(BudgetMonitor::class)->alerts($user, now());
        $this->assertSame(["budget:{$budget->id}:2026-11:80"], array_column($alerts, 'key'));
        $this->assertStringContainsString('Sisa Rp 150.000', $alerts[0]['body']);

        Transaction::factory()->for($user)->create(['category' => 'makan', 'amount' => 200000, 'occurred_on' => '2026-11-25']);
        $alerts = app(BudgetMonitor::class)->alerts($user, now());
        $this->assertSame("budget:{$budget->id}:2026-11:100", $alerts[0]['key'], 'Huruf besar/kecil kategori tidak dibedakan');
        $this->assertStringContainsString('terlampaui', $alerts[0]['title']);

        $this->actingAs($user)->get(route('dashboard'))->assertSee('Anggaran Makan terlampaui');
    }

    public function test_budget_alerts_are_sent_once_and_held_at_night(): void
    {
        $user = User::factory()->create(['telegram_chat_id' => '1']);
        $user->budgets()->create(['category' => 'Makan', 'amount' => 100000]);
        Transaction::factory()->for($user)->create(['category' => 'Makan', 'amount' => 90000, 'occurred_on' => '2026-11-25']);
        $reminders = app(Reminders::class);

        $this->assertSame([], collect($reminders->dueFor($user, Carbon::parse('2026-11-25 23:00')))->filter(fn ($item) => str_starts_with($item['key'], 'budget:'))->all());

        $this->assertSame(1, $reminders->dispatch(Carbon::parse('2026-11-26 08:00')));
        $this->assertSame(0, $reminders->dispatch(Carbon::parse('2026-11-26 08:01')));
    }

    public function test_saving_an_expense_warns_when_budget_is_nearly_used(): void
    {
        $user = User::factory()->create();
        $user->budgets()->create(['category' => 'Makan', 'amount' => 100000]);

        Livewire::actingAs($user)->test('pages::finance')
            ->call('openCreate', 'expense')
            ->set('amount', '85000')
            ->set('category', 'Makan')
            ->call('save')
            ->assertDispatched('toast', message: 'Pengeluaran Rp 85.000 tersimpan · ⚠️ Anggaran Makan 85%');
    }

    public function test_habit_reminder_fires_at_its_time_only_when_not_done(): void
    {
        $user = User::factory()->create();
        $habit = Habit::factory()->for($user)->create(['name' => 'Olahraga', 'days' => Habit::EVERY_DAY, 'remind_at' => '06:00']);
        $reminders = app(Reminders::class);
        $habitKeys = fn (string $time) => collect($reminders->dueFor($user, Carbon::parse("2026-11-25 {$time}")))->pluck('key')->filter(fn ($key) => str_starts_with($key, 'habit:'))->values()->all();

        $this->assertSame([], $habitKeys('05:59'));
        $this->assertSame(["habit:{$habit->id}:2026-11-25"], $habitKeys('06:00'));
        $this->assertSame(["habit:{$habit->id}:2026-11-25"], $habitKeys('06:10'));
        $this->assertSame([], $habitKeys('06:20'));

        $habit->logs()->create(['logged_on' => '2026-11-25']);
        $this->assertSame([], $habitKeys('06:05'), 'Sudah dicentang, tidak perlu diingatkan');

        // 25 Nov 2026 hari Rabu; kebiasaan khusus Senin tidak diingatkan.
        Habit::factory()->for($user)->create(['name' => 'Laporan', 'days' => [1], 'remind_at' => '06:00']);
        $this->assertSame([], $habitKeys('06:05'));
    }

    public function test_habit_reminder_time_is_saved_from_the_form(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('pages::habits')
            ->call('openCreate')
            ->set('name', 'Baca buku')
            ->set('remind_at', '21:00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('21:00', $user->habits()->sole()->remind_at);
        $this->actingAs($user)->get(route('habits'))->assertSee('⏰ 21:00');
    }

    public function test_onboarding_welcomes_brand_new_users_and_counts_telegram(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertSee('Selamat datang di Prima Management!');

        $user->forceFill(['telegram_chat_id' => '5'])->save();
        Wallet::factory()->for($user)->create();

        $onboarding = collect(Livewire::actingAs($user->fresh())->test('pages::dashboard')->instance()->onboarding);
        $this->assertTrue($onboarding->firstWhere('label', 'Aktifkan pengingat')['done']);
        $this->assertTrue($onboarding->firstWhere('label', 'Tambah dompet')['done']);
    }

    public function test_amount_parser_understands_indonesian_shorthand(): void
    {
        $this->assertSame(25000, Transaction::parseAmount('25rb'));
        $this->assertSame(25000, Transaction::parseAmount('25k'));
        $this->assertSame(25000, Transaction::parseAmount('25.000'));
        $this->assertSame(25000, Transaction::parseAmount('Rp25.000'));
        $this->assertSame(1500000, Transaction::parseAmount('1,5jt'));
        $this->assertSame(2000000, Transaction::parseAmount('2 juta'));
        $this->assertSame(1250000, Transaction::parseAmount('1.250.000'));
        $this->assertNull(Transaction::parseAmount('makan'));
        $this->assertNull(Transaction::parseAmount('0'));
    }
}
