<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Wallet>
 */
class WalletFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->randomElement(['Tunai', 'BCA', 'GoPay', 'Jago']),
            'type' => fake()->randomElement(array_keys(Wallet::TYPES)),
            'opening_balance' => 0,
            'position' => 0,
        ];
    }
}
