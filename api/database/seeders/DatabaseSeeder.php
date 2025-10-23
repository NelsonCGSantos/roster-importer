<?php

namespace Database\Seeders;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Team::query()->firstOrCreate(['name' => 'Skyline FC']);

        User::query()->updateOrCreate(
            ['email' => 'coach@example.com'],
            [
                'name' => 'Head Coach',
                'password' => Hash::make('password'),
            ]
        );
    }
}
