<?php

namespace Database\Factories;

use App\Models\Goal;
use App\Models\StudySession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudySession>
 */
class StudySessionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'goal_id' => Goal::factory(),
            'user_id' => fn (array $attributes): int => Goal::find($attributes['goal_id'])->user_id,
            'studied_on' => today(),
            'minutes' => fake()->randomElement([25, 45, 60, 90]),
            'score' => null,
        ];
    }
}
