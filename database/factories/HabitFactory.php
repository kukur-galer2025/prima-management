<?php

namespace Database\Factories;

use App\Models\Goal;
use App\Models\Habit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Habit>
 */
class HabitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->randomElement(['Baca 20 halaman', 'Olahraga', 'Hafal 10 kosakata', 'Tidur sebelum 23.00']),
            'icon' => fake()->randomElement(['📖', '🏃', '🧠', null]),
            'color' => fake()->randomElement(Goal::COLORS),
            'days' => Habit::EVERY_DAY,
        ];
    }

    /**
     * @param  array<int, int>  $days
     */
    public function on(array $days): static
    {
        return $this->state(fn (): array => ['days' => $days]);
    }
}
