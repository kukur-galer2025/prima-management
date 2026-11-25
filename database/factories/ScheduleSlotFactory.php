<?php

namespace Database\Factories;

use App\Models\Goal;
use App\Models\ScheduleSlot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleSlot>
 */
class ScheduleSlotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'goal_id' => Goal::factory(),
            'user_id' => fn (array $attributes): int => Goal::find($attributes['goal_id'])->user_id,
            'day_of_week' => fake()->numberBetween(1, 7),
            'start_time' => '19:00',
            'end_time' => '20:30',
        ];
    }
}
