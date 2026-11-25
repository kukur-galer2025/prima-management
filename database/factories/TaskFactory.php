<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'goal_id' => null,
            'title' => fake()->sentence(4),
            'due_date' => fake()->optional()->dateTimeBetween('-1 week', '+1 month'),
            'is_done' => false,
        ];
    }

    public function done(): static
    {
        return $this->state(fn (): array => ['is_done' => true, 'done_at' => now()]);
    }
}
