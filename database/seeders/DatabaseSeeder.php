<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Akun demo + target awal Prima Management.
     * Ganti email/password lewat: php artisan app:create-user
     */
    public function run(): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'demo@primamanagement.test'],
            ['name' => 'Pengguna Prima', 'password' => 'password'],
        );

        $this->call(StarterGoalsSeeder::class, parameters: ['user' => $user]);
    }
}
