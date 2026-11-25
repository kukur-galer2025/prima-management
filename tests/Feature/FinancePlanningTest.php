<?php

namespace Tests\Feature;

use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class FinancePlanningTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_occurrence_clamps_to_short_months(): void
    {
        $this->assertSame('2027-02-28', RecurringTransaction::occurrenceIn(Carbon::parse('2027-02-10'), 31)->toDateString());
        $this->assertSame('2028-02-29', RecurringTransaction::occurrenceIn(Carbon::parse('2028-02-01'), 31)->toDateString());

        $this->assertSame('2026-11-30', RecurringTransaction::firstRunOn(30, Carbon::parse('2026-11-25'))->toDateString());
        $this->assertSame('2026-12-05', RecurringTransaction::firstRunOn(5, Carbon::parse('2026-11-25'))->toDateString());
        $this->assertSame('2026-11-25', RecurringTransaction::firstRunOn(25, Carbon::parse('2026-11-25'))->toDateString());
    }

    public function test_due_recurring_transactions_are_posted_including_missed_months(): void
    {
        $user = User::factory()->create();
        $recurring = $user->recurringTransactions()->create([
            'type' => 'expense', 'amount' => 900000, 'category' => 'Kos', 'day_of_month' => 31, 'next_run_on' => '2026-09-30',
        ]);

        $this->assertSame(3, RecurringTransaction::postDueFor($user, Carbon::parse('2026-11-30')));
        $this->assertSame(0, RecurringTransaction::postDueFor($user, Carbon::parse('2026-11-30')));

        $this->assertSame(['2026-09-30', '2026-10-31', '2026-11-30'], $user->transactions()->orderBy('occurred_on')->get()->map(fn ($t) => $t->occurred_on->toDateString())->all());
        $this->assertSame($recurring->id, $user->transactions()->first()->recurring_transaction_id);
        $this->assertSame('2026-12-31', $recurring->fresh()->next_run_on->toDateString());
    }

    public function test_recurring_transaction_created_from_finance_page_posts_when_due_today(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 09:00'));
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::finance')
            ->call('openRecurring')
            ->set('type', 'income')
            ->set('amount', '3.500.000')
            ->set('category', 'Gaji')
            ->set('day_of_month', 25)
            ->call('saveRecurring')
            ->assertHasNoErrors()
            ->assertSee('🔁 rutin');

        $this->assertSame(3500000, (int) $user->transactions()->sum('amount'));
        $this->assertSame('2026-12-25', $user->recurringTransactions()->sole()->next_run_on->toDateString());
    }

    public function test_category_budget_can_be_set_flagged_when_exceeded_and_removed(): void
    {
        $user = User::factory()->create();
        Transaction::factory()->for($user)->create(['category' => 'Makan', 'amount' => 600000, 'occurred_on' => today()]);

        $component = Livewire::actingAs($user)
            ->test('pages::finance')
            ->call('openBudget', 'Makan')
            ->set('budgetAmount', '500.000')
            ->call('saveBudget')
            ->assertHasNoErrors()
            ->assertSee('Lewat Rp 100.000 dari anggaran');

        $this->assertSame(500000, $user->budgets()->sole()->amount);

        $component->call('openBudget', 'Makan')->set('budgetAmount', '')->call('saveBudget');
        $this->assertDatabaseCount('budgets', 0);
    }

    public function test_user_cannot_edit_another_users_recurring_transaction(): void
    {
        $other = User::factory()->create();
        $recurring = $other->recurringTransactions()->create(['type' => 'expense', 'amount' => 1, 'category' => 'X', 'day_of_month' => 1, 'next_run_on' => today()->addMonth()]);

        Livewire::actingAs(User::factory()->create())
            ->test('pages::finance')
            ->call('openRecurring', $recurring->id)
            ->assertNotFound();
    }
}
