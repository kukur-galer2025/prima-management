<?php

namespace Tests\Feature;

use App\Models\Debt;
use App\Models\SavingsGoal;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WalletsSavingsDebtsTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_wallet_adopts_existing_transactions(): void
    {
        $user = User::factory()->create();
        Transaction::factory()->for($user)->income()->create(['amount' => 500000, 'occurred_on' => today()]);

        Livewire::actingAs($user)
            ->test('pages::finance')
            ->call('openWallet')
            ->set('walletName', 'BCA')
            ->set('walletType', 'bank')
            ->set('walletOpening', '1.000.000')
            ->call('saveWallet')
            ->assertHasNoErrors();

        $wallet = $user->wallets()->sole();
        $this->assertSame(1000000, $wallet->opening_balance);
        $this->assertSame($wallet->id, $user->transactions()->sole()->wallet_id);
        $this->assertSame(1500000, Wallet::balancesFor($user)[$wallet->id]);
    }

    public function test_transactions_require_a_wallet_once_wallets_exist_and_can_be_filtered(): void
    {
        $user = User::factory()->create();
        $cash = Wallet::factory()->for($user)->create(['name' => 'Tunai']);
        $bank = Wallet::factory()->for($user)->create(['name' => 'BCA']);
        $foreign = Wallet::factory()->create();

        $component = Livewire::actingAs($user)->test('pages::finance')
            ->call('openCreate', 'expense')
            ->assertSet('wallet_id', $cash->id)
            ->set('amount', '25.000')
            ->set('category', 'Makan')
            ->set('notes', 'Nasi padang')
            ->set('wallet_id', $foreign->id)
            ->call('save')
            ->assertHasErrors('wallet_id')
            ->set('wallet_id', $bank->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($bank->id, $user->transactions()->sole()->wallet_id);

        $component->call('filterWallet', $cash->id)->assertDontSee('Nasi padang')
            ->call('filterWallet', $bank->id)->assertSee('Nasi padang');
    }

    public function test_transfer_moves_money_between_wallets_without_counting_as_income(): void
    {
        $user = User::factory()->create();
        $cash = Wallet::factory()->for($user)->create(['opening_balance' => 300000]);
        $bank = Wallet::factory()->for($user)->create(['opening_balance' => 0]);

        Livewire::actingAs($user)->test('pages::finance')
            ->call('openTransfer')
            ->set('fromWalletId', $cash->id)
            ->set('toWalletId', $cash->id)
            ->set('transferAmount', '100.000')
            ->call('saveTransfer')
            ->assertHasErrors('toWalletId')
            ->set('toWalletId', $bank->id)
            ->call('saveTransfer')
            ->assertHasNoErrors()
            ->assertSee('Transfer antar-dompet');

        $balances = Wallet::balancesFor($user);
        $this->assertSame(200000, $balances[$cash->id]);
        $this->assertSame(100000, $balances[$bank->id]);
        $this->assertSame(0, $user->transactions()->count());
    }

    public function test_recurring_transactions_post_into_their_wallet(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->for($user)->create();
        $user->recurringTransactions()->create([
            'wallet_id' => $wallet->id, 'type' => 'expense', 'amount' => 750000, 'category' => 'Kos',
            'day_of_month' => today()->day, 'next_run_on' => today(),
        ]);

        Livewire::actingAs($user)->test('pages::finance');

        $this->assertSame($wallet->id, $user->transactions()->sole()->wallet_id);
        $this->assertSame(-750000, Wallet::balancesFor($user)[$wallet->id]);
    }

    public function test_savings_deposits_and_withdrawals_update_goal_and_wallet(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->for($user)->create(['opening_balance' => 5000000]);

        $component = Livewire::actingAs($user)->test('pages::savings')
            ->call('openCreate')
            ->set('name', 'Laptop baru')
            ->set('target_amount', '8.000.000')
            ->set('deadline', today()->addMonths(4)->toDateString())
            ->call('save')
            ->assertHasNoErrors();

        $goal = $user->savingsGoals()->sole();

        $component->call('openDeposit', $goal->id)
            ->set('depositAmount', '2.000.000')
            ->set('depositWalletId', $wallet->id)
            ->call('saveDeposit')
            ->assertHasNoErrors()
            ->call('openDeposit', $goal->id, 'withdraw')
            ->set('depositAmount', '3.000.000')
            ->call('saveDeposit')
            ->assertHasErrors('depositAmount')
            ->set('depositAmount', '500.000')
            ->set('depositWalletId', $wallet->id)
            ->call('saveDeposit')
            ->assertHasNoErrors()
            ->assertSee('Laptop baru');

        $goal->refresh();
        $this->assertSame(1500000, $goal->savedAmount());
        $this->assertSame(6500000, $goal->remainingAmount());
        $this->assertSame(18, $goal->percent());
        $this->assertSame(3500000, Wallet::balancesFor($user)[$wallet->id]);
        $this->assertSame(1625000, $goal->monthlyNeeded(today()));
    }

    public function test_reaching_a_savings_goal_is_celebrated(): void
    {
        $user = User::factory()->create();
        $goal = SavingsGoal::factory()->for($user)->create(['target_amount' => 100000]);

        Livewire::actingAs($user)->test('pages::savings')
            ->call('openDeposit', $goal->id)
            ->set('depositAmount', '100000')
            ->set('depositWalletId', null)
            ->call('saveDeposit')
            ->assertDispatched('toast', message: 'Target '.$goal->name.' tercapai! 🎉')
            ->assertSee('Tercapai');
    }

    public function test_debt_installments_settle_and_affect_wallets(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->for($user)->create(['opening_balance' => 0]);

        $component = Livewire::actingAs($user)->test('pages::debts')
            ->call('openCreate')
            ->set('direction', 'payable')
            ->set('person', 'Andi')
            ->set('amount', '1.000.000')
            ->set('wallet_id', $wallet->id)
            ->set('due_on', today()->addWeek()->toDateString())
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Andi');

        $debt = $user->debts()->sole();
        $this->assertSame(1000000, Wallet::balancesFor($user)[$wallet->id], 'Uang pinjaman masuk ke dompet');

        $component->call('openPayment', $debt->id)
            ->assertSet('paymentAmount', '1.000.000')
            ->set('paymentAmount', '400.000')
            ->call('savePayment')
            ->assertHasNoErrors()
            ->call('openPayment', $debt->id)
            ->set('paymentAmount', '700.000')
            ->call('savePayment')
            ->assertHasErrors('paymentAmount')
            ->set('paymentAmount', '600.000')
            ->call('savePayment')
            ->assertHasNoErrors();

        $this->assertTrue($debt->fresh()->isSettled());
        $this->assertSame(0, Wallet::balancesFor($user)[$wallet->id]);

        // Menghapus cicilan membuka lagi status lunas.
        $component->call('deletePayment', $debt->payments()->latest('id')->first()->id);
        $this->assertFalse($debt->fresh()->isSettled());
        $this->assertSame(600000, $debt->fresh()->remainingAmount());
    }

    public function test_receivables_are_shown_separately_with_totals(): void
    {
        $user = User::factory()->create();
        Debt::factory()->for($user)->create(['person' => 'Pemberi', 'amount' => 200000]);
        Debt::factory()->for($user)->receivable()->create(['person' => 'Peminjam', 'amount' => 300000, 'due_on' => today()->subDay()]);

        Livewire::actingAs($user)->test('pages::debts')
            ->assertSee('Pemberi')
            ->assertDontSee('Peminjam')
            ->set('tab', 'receivable')
            ->assertSee('Peminjam')
            ->assertSee('Lewat tempo')
            ->assertSee('Rp 300.000');
    }

    public function test_finance_pages_only_touch_own_records(): void
    {
        $user = User::factory()->create();
        $otherGoal = SavingsGoal::factory()->create();
        $otherDebt = Debt::factory()->create();

        Livewire::actingAs($user)->test('pages::savings')->call('delete', $otherGoal->id)->assertNotFound();
        Livewire::actingAs($user)->test('pages::debts')->call('delete', $otherDebt->id)->assertNotFound();

        $this->assertModelExists($otherGoal);
        $this->assertModelExists($otherDebt);
    }
}
