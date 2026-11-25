<?php

namespace Database\Factories;

use App\Models\Goal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Goal>
 */
class GoalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->randomElement(['TOEFL', 'TPA', 'SKD', 'Machine Learning', 'Kalkulus']).' '.fake()->numberBetween(1, 99),
            'category' => fake()->randomElement(['Tes', 'Bahasa', 'Skill', null]),
            'icon' => fake()->randomElement(['📘', '🧠', '🎯', null]),
            'color' => fake()->randomElement(Goal::COLORS),
            'target_hours' => fake()->optional()->numberBetween(20, 200),
            'deadline' => fake()->optional()->dateTimeBetween('+1 week', '+1 year'),
            'priority' => fake()->numberBetween(1, 3),
            'status' => 'active',
        ];
    }

    public function done(): static
    {
        return $this->state(fn (): array => ['status' => 'done']);
    }
}
