<?php

namespace Database\Factories;

use App\Models\Debt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Debt>
 */
class DebtFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'direction' => 'payable',
            'person' => fake()->firstName(),
            'amount' => fake()->numberBetween(1, 50) * 50000,
            'started_on' => today()->subDays(7),
            'due_on' => null,
            'notes' => null,
        ];
    }

    public function receivable(): static
    {
        return $this->state(fn (): array => ['direction' => 'receivable']);
    }
}
