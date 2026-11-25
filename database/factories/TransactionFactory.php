<?php

namespace Database\Factories;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => 'expense',
            'amount' => fake()->numberBetween(5, 500) * 1000,
            'category' => fake()->randomElement(Transaction::CATEGORIES['expense']),
            'occurred_on' => fake()->dateTimeBetween('-20 days', 'now'),
            'notes' => fake()->optional()->sentence(3),
        ];
    }

    public function income(): static
    {
        return $this->state(fn (): array => [
            'type' => 'income',
            'category' => fake()->randomElement(Transaction::CATEGORIES['income']),
        ]);
    }
}
