<?php

namespace Database\Factories;

use App\Models\Goal;
use App\Models\Topic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Topic>
 */
class TopicFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'goal_id' => Goal::factory(),
            'name' => fake()->words(3, true),
            'is_done' => false,
            'sort_order' => 0,
        ];
    }

    public function done(): static
    {
        return $this->state(fn (): array => ['is_done' => true]);
    }
}
