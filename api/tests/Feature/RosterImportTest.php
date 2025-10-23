<?php

use App\Models\ImportJob;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

it('runs a full roster import workflow', function () {
    Storage::fake('local');

    $team = Team::factory()->create();
    $user = User::factory()->create();

    Sanctum::actingAs($user);

    $file = UploadedFile::fake()->createWithContent('roster.csv', <<<'CSV'
Name,Email,Jersey,Position
Alex Morgan,alex@example.com,13,Forward
Sam Kerr,sam@example.com,9,Forward
CSV);

    $uploadResponse = $this->postJson('/api/imports', [
        'file' => $file,
    ]);

    $uploadResponse->assertCreated();
    $uploadResponse->assertJsonPath('meta.duplicate', false);
    $columns = $uploadResponse->json('meta.columns');
    expect($columns)->toContain('Name', 'Email', 'Jersey', 'Position');

    $importId = $uploadResponse->json('data.id');

    $dryRunResponse = $this->postJson("/api/imports/{$importId}/dry-run", [
        'column_map' => [
            'full_name' => 'Name',
            'email' => 'Email',
            'jersey' => 'Jersey',
            'position' => 'Position',
        ],
    ]);

    $dryRunResponse->assertOk();
    $dryRunResponse->assertJsonPath('data.counts.created', 2);
    $dryRunResponse->assertJsonPath('data.counts.updated', 0);
    $dryRunResponse->assertJsonPath('data.counts.errors', 0);

    $applyResponse = $this->postJson("/api/imports/{$importId}/apply");

    $applyResponse->assertOk();
    $applyResponse->assertJsonPath('data.status', ImportJob::STATUS_COMPLETED);
    $applyResponse->assertJsonPath('data.error_report_available', false);

    expect(Player::query()->count())->toBe(2);
});

it('flags problematic rows during dry run and generates an error report', function () {
    Storage::fake('local');

    $team = Team::factory()->create();
    $user = User::factory()->create();

    $existingPlayer = Player::factory()->create([
        'team_id' => $team->id,
        'email' => 'sam@example.com',
        'full_name' => 'Sam Kerr',
    ]);

    Sanctum::actingAs($user);

    $file = UploadedFile::fake()->createWithContent('problem-roster.csv', <<<'CSV'
Name,Email,Jersey,Position
Alex Morgan,alex@example.com,13,Forward
Sam Kerr,sam@example.com,9,Forward
, ,abc,Defender
Another,alex@example.com,10,Midfielder
CSV);

    $uploadResponse = $this->postJson('/api/imports', [
        'file' => $file,
    ]);

    $uploadResponse->assertCreated();
    $uploadResponse->assertJsonPath('meta.duplicate', false);
    $columns = $uploadResponse->json('meta.columns');
    expect($columns)->toContain('Name', 'Email', 'Jersey', 'Position');

    $importId = $uploadResponse->json('data.id');

    $dryRunResponse = $this->postJson("/api/imports/{$importId}/dry-run", [
        'column_map' => [
            'full_name' => 'Name',
            'email' => 'Email',
            'jersey' => 'Jersey',
            'position' => 'Position',
        ],
    ]);

    $dryRunResponse->assertOk();
    $dryRunResponse->assertJsonPath('data.counts.created', 1);
    $dryRunResponse->assertJsonPath('data.counts.updated', 1);
    $dryRunResponse->assertJsonPath('data.counts.errors', 2);

    $rows = collect($dryRunResponse->json('data.rows'))
        ->keyBy('row_number');

    expect($rows[3]['action'])->toBe('update');
    expect($rows[4]['action'])->toBe('error');
    expect($rows[5]['action'])->toBe('error');
    expect($rows[5]['errors']['email'][0])->toContain('Duplicate email');

    $applyResponse = $this->postJson("/api/imports/{$importId}/apply");

    $applyResponse->assertOk();
    $applyResponse->assertJsonPath('data.status', ImportJob::STATUS_COMPLETED);
    $applyResponse->assertJsonPath('data.counts.errors', 2);
    $applyResponse->assertJsonPath('data.error_report_available', true);

    $importJob = ImportJob::find($importId);
    expect($importJob->error_report_path)->not->toBeNull();
    expect(Storage::disk('local')->exists($importJob->error_report_path))->toBeTrue();
});
