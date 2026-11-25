<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_record_an_expense_with_formatted_amount(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::finance')
            ->call('openCreate', 'expense')
            ->set('amount', 'Rp 1.250.000')
            ->set('category', 'Tagihan')
            ->call('save')
            ->assertHasNoErrors();

        $transaction = $user->transactions()->sole();
        $this->assertSame(1250000, $transaction->amount);
        $this->assertSame('expense', $transaction->type);
        $this->assertTrue($transaction->occurred_on->isToday());
    }

    public function test_amount_and_category_are_required(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test('pages::finance')
            ->call('openCreate', 'income')
            ->set('amount', 'abc')
            ->call('save')
            ->assertHasErrors(['amount', 'category']);
    }

    public function test_monthly_summary_and_budget_only_count_the_selected_month(): void
    {
        $user = User::factory()->create(['monthly_budget' => 1000000]);
        Transaction::factory()->for($user)->income()->create(['amount' => 3000000, 'occurred_on' => '2026-11-01']);
        Transaction::factory()->for($user)->create(['amount' => 250000, 'category' => 'Makan', 'occurred_on' => '2026-11-10']);
        Transaction::factory()->for($user)->create(['amount' => 500000, 'category' => 'Transport', 'occurred_on' => '2026-11-20']);
        Transaction::factory()->for($user)->create(['amount' => 999000, 'occurred_on' => '2026-10-31']);
        Transaction::factory()->create(['amount' => 777000, 'occurred_on' => '2026-11-15']);

        $component = Livewire::actingAs($user)->withQueryParams(['month' => '2026-11'])->test('pages::finance');
        $summary = $component->instance()->summary;

        $this->assertSame(3000000, $summary['income']);
        $this->assertSame(750000, $summary['expense']);
        $this->assertSame(2250000, $summary['balance']);
        $this->assertSame(75, $summary['budgetPercent']);
        $this->assertSame(['Transport' => 500000, 'Makan' => 250000], $summary['categories']->all());

        $component->assertSee('Rp 750.000')->call('shiftMonth', -1)->assertSet('month', '2026-10');
    }

    public function test_user_cannot_edit_another_users_transaction(): void
    {
        $transaction = Transaction::factory()->create();

        Livewire::actingAs(User::factory()->create())
            ->test('pages::finance')
            ->call('openEdit', $transaction->id)
            ->assertNotFound();
    }

    public function test_rupiah_formatting(): void
    {
        $this->assertSame('Rp 1.250.000', Transaction::formatRupiah(1250000));
        $this->assertSame('-Rp 5.000', Transaction::formatRupiah(-5000));
    }
}
