<?php

namespace Database\Factories;

use App\Models\Player;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Player>
 */
class PlayerFactory extends Factory
{
    protected $model = Player::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'jersey' => (string) fake()->numberBetween(1, 99),
            'position' => fake()->randomElement(['Goalkeeper', 'Defender', 'Midfielder', 'Forward']),
        ];
    }
}
