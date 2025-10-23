<?php

namespace Database\Factories;

use App\Models\ImportJob;
use App\Models\ImportRow;
use App\Models\Player;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportRow>
 */
class ImportRowFactory extends Factory
{
    protected $model = ImportRow::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'import_job_id' => ImportJob::factory(),
            'player_id' => null,
            'row_number' => fake()->numberBetween(2, 100),
            'payload' => [
                'full_name' => fake()->name(),
                'email' => fake()->safeEmail(),
                'jersey' => (string) fake()->numberBetween(1, 99),
                'position' => fake()->randomElement(['GK', 'DF', 'MF', 'FW']),
            ],
            'action' => ImportRow::ACTION_CREATE,
            'errors' => [],
        ];
    }

    /**
     * Indicate that the row references an existing player update.
     */
    public function updateAction(): self
    {
        return $this->state(function () {
            return [
                'action' => ImportRow::ACTION_UPDATE,
                'player_id' => Player::factory(),
            ];
        });
    }

    /**
     * Indicate that the row represents an error.
     */
    public function errorAction(): self
    {
        return $this->state(function () {
            return [
                'action' => ImportRow::ACTION_ERROR,
                'errors' => ['email' => ['Duplicate email']],
            ];
        });
    }
}
