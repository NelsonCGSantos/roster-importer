<?php

namespace Database\Factories;

use App\Models\ImportJob;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ImportJob>
 */
class ImportJobFactory extends Factory
{
    protected $model = ImportJob::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $filename = sprintf('roster-%s.csv', fake()->date('Ymd-His'));

        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'original_filename' => $filename,
            'stored_path' => sprintf('imports/%s', $filename),
            'file_hash' => Str::uuid()->toString(),
            'status' => ImportJob::STATUS_PENDING,
            'total_rows' => fake()->numberBetween(5, 50),
            'created_count' => fake()->numberBetween(0, 20),
            'updated_count' => fake()->numberBetween(0, 20),
            'error_count' => fake()->numberBetween(0, 10),
            'column_map' => [
                'full_name' => 'Name',
                'email' => 'Email',
                'jersey' => 'Jersey',
                'position' => 'Position',
            ],
        ];
    }
}
