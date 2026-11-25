<?php

namespace Database\Factories;

use App\Models\SavingsGoal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavingsGoal>
 */
class SavingsGoalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->randomElement(['Laptop baru', 'Dana darurat', 'Liburan', 'Biaya S2']),
            'emoji' => fake()->randomElement(['💻', '🛟', '🏖️', '🎓']),
            'target_amount' => fake()->numberBetween(10, 100) * 100000,
            'deadline' => null,
        ];
    }
}
