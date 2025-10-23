<?php

namespace App\Services;

use App\Models\ImportJob;
use App\Models\ImportRow;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Facades\Excel;

class RosterImportService
{
    public const MAX_ROWS = 5000;

    /**
     * Persist an uploaded roster file and create (or reuse) the import job.
     *
     * @return array{job: ImportJob, duplicate: bool, columns: array<int, string>}
     */
    public function storeUpload(UploadedFile $file, User $user, Team $team): array
    {
        $fileHash = hash_file('sha256', $file->getRealPath());

        $existing = ImportJob::query()
            ->where('team_id', $team->id)
            ->where('file_hash', $fileHash)
            ->first();

        if ($existing) {
            return [
                'job' => $existing->loadMissing('user'),
                'duplicate' => true,
                'columns' => $this->availableColumns($existing),
            ];
        }

        $storedPath = $file->storeAs(
            sprintf('imports/%d', $team->id),
            Str::uuid()->toString().'_'.$file->getClientOriginalName()
        );

        $job = ImportJob::query()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $storedPath,
            'file_hash' => $fileHash,
            'status' => ImportJob::STATUS_PENDING,
        ]);

        $job->load('user');

        return [
            'job' => $job,
            'duplicate' => false,
            'columns' => $this->availableColumns($job),
        ];
    }

    /**
     * Execute the dry-run analysis and persist row-level feedback.
     *
     * @param  array<string, string|int|null>  $columnMap
     */
    public function performDryRun(ImportJob $job, array $columnMap): ImportJob
    {
        $job->loadMissing('team', 'rows');

        $rows = $this->loadSpreadsheetRows($job);

        if (empty($rows)) {
            throw ValidationException::withMessages([
                'file' => 'The uploaded file is empty.',
            ]);
        }

        $columnSelectors = $this->buildColumnSelectors($columnMap, $rows);

        $existingPlayers = Player::query()
            ->where('team_id', $job->team_id)
            ->get()
            ->keyBy(fn (Player $player) => strtolower($player->email));

        $now = now();
        $rowPayloads = [];
        $createdCount = 0;
        $updatedCount = 0;
        $errorCount = 0;
        $seenEmails = [];
        $processedRows = 0;

        // Remove previous dry-run data.
        $job->rows()->delete();

        foreach ($rows as $index => $rawRow) {
            $rowNumber = $index + 2;
            $payload = $this->extractPayload($rawRow, $columnSelectors);

            if ($payload === null) {
                continue;
            }

            $processedRows++;

            if ($processedRows > self::MAX_ROWS) {
                throw ValidationException::withMessages([
                    'file' => sprintf('The roster is limited to %d rows.', self::MAX_ROWS),
                ]);
            }

            $errors = $this->validatePayload($payload, $seenEmails);

            $action = ImportRow::ACTION_CREATE;
            $playerId = null;

            if (!empty($errors)) {
                $action = ImportRow::ACTION_ERROR;
                $errorCount++;
            } else {
                $emailKey = strtolower($payload['email']);

                if (isset($existingPlayers[$emailKey])) {
                    $action = ImportRow::ACTION_UPDATE;
                    $playerId = $existingPlayers[$emailKey]->id;
                    $updatedCount++;
                } else {
                    $createdCount++;
                }
            }

            $rowPayloads[] = [
                'import_job_id' => $job->id,
                'player_id' => $playerId,
                'row_number' => $rowNumber,
                'payload' => $payload,
                'action' => $action,
                'errors' => $errors ?: null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (!empty($rowPayloads)) {
            $encodedRows = array_map(function (array $row) {
                $row['payload'] = isset($row['payload']) ? json_encode($row['payload']) : null;
                $row['errors'] = isset($row['errors']) ? json_encode($row['errors']) : null;

                return $row;
            }, $rowPayloads);

            ImportRow::query()->insert($encodedRows);
        }

        $job->fill([
            'column_map' => $columnMap,
            'status' => ImportJob::STATUS_READY,
            'total_rows' => $processedRows,
            'created_count' => $createdCount,
            'updated_count' => $updatedCount,
            'error_count' => $errorCount,
            'error_report_path' => null,
            'processed_at' => null,
        ])->save();

        return $job->refresh()->load('rows', 'user');
    }

    /**
     * Return the available columns detected in the uploaded file.
     *
     * @return array<int, string>
     */
    public function availableColumns(ImportJob $job): array
    {
        $rows = $this->loadSpreadsheetRows($job);

        if (empty($rows)) {
            return [];
        }

        $firstRow = $rows[0];

        if ($this->rowHasStringKeys($firstRow)) {
            $columns = [];

            foreach ($firstRow as $key => $_value) {
                if (!is_string($key)) {
                    continue;
                }

                $clean = trim(preg_replace('/^[\xEF\xBB\xBF]+/u', '', $key) ?? $key);

                if ($clean === '') {
                    continue;
                }

                $columns[] = $clean;
            }

            return array_values(array_unique($columns));
        }

        $header = array_shift($rows) ?? [];

        $columns = array_map(function ($heading) {
            if (is_array($heading)) {
                $heading = implode(' ', array_map(fn ($value) => trim((string) $value), Arr::flatten($heading)));
            }

            $heading = preg_replace('/^[\xEF\xBB\xBF]+/u', '', (string) $heading) ?? (string) $heading;

            return trim($heading);
        }, $header);

        return array_values(array_filter($columns, fn ($column) => $column !== ''));
    }

    /**
     * Apply a validated import job inside a transaction.
     */
    public function applyImport(ImportJob $job): ImportJob
    {
        if ($job->status !== ImportJob::STATUS_READY) {
            throw ValidationException::withMessages([
                'import' => 'Dry-run must be completed before applying the import.',
            ]);
        }

        $job->loadMissing('rows', 'team');

        DB::transaction(function () use ($job): void {
            $job->rows()
                ->whereIn('action', [ImportRow::ACTION_CREATE, ImportRow::ACTION_UPDATE])
                ->orderBy('row_number')
                ->each(function (ImportRow $row) use ($job): void {
                    $payload = $row->payload ?? [];

                    if ($row->action === ImportRow::ACTION_UPDATE) {
                        $player = Player::query()
                            ->where('team_id', $job->team_id)
                            ->where('email', $payload['email'])
                            ->first();

                        if (!$player) {
                            $row->action = ImportRow::ACTION_CREATE;
                            $player = new Player([
                                'team_id' => $job->team_id,
                            ]);
                        }
                    } else {
                        $player = new Player([
                            'team_id' => $job->team_id,
                        ]);
                    }

                    $player->fill([
                        'full_name' => Arr::get($payload, 'full_name'),
                        'email' => Arr::get($payload, 'email'),
                        'jersey' => Arr::get($payload, 'jersey'),
                        'position' => Arr::get($payload, 'position'),
                    ])->save();

                    $row->player()->associate($player);
                    $row->save();
                });

            $job->update([
                'status' => ImportJob::STATUS_COMPLETED,
                'processed_at' => now(),
            ]);
        });

        $job->refresh()->load('rows', 'user');

        $this->updateCounts($job);
        $this->generateErrorReport($job);

        return $job->refresh()->load('rows', 'user');
    }

    /**
     * Load all rows from the stored spreadsheet.
     *
     * @return array<int, array<int|string, mixed>>
     */
    private function loadSpreadsheetRows(ImportJob $job): array
    {
        $import = new class implements ToCollection {
            /**
             * @var Collection<int, array<int, mixed>>
             */
            public Collection $rows;

            public function collection(Collection $rows): void
            {
                $this->rows = $rows;
            }
        };

        try {
            Excel::import($import, $job->stored_path, 'local');
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages([
                'file' => 'Failed to read the uploaded file. Please ensure it is a valid CSV or XLSX.',
            ]);
        }

        if (!isset($import->rows)) {
            return [];
        }

        return $import->rows->map(function ($row) {
            if ($row instanceof Collection) {
                return $row->toArray();
            }

            if ($row instanceof Arrayable) {
                return $row->toArray();
            }

            return is_array($row) ? $row : (array) $row;
        })->toArray();
    }

    /**
     * Build column selectors that support both indexed and associative rows.
     *
     * @param  array<string, string|int|null>  $columnMap
     * @param  array<int, array<int|string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function buildColumnSelectors(array $columnMap, array &$rows): array
    {
        $firstRow = $rows[0] ?? [];

        if ($this->rowHasStringKeys($firstRow)) {
            return $this->resolveColumnSelectorsFromKeys($columnMap, $rows);
        }

        $header = array_shift($rows);

        if (!is_array($header)) {
            throw ValidationException::withMessages([
                'file' => 'Unable to read the header row from the upload.',
            ]);
        }

        return $this->resolveColumnSelectorsFromHeader($columnMap, $header);
    }

    /**
     * Determine if the provided row uses string keys.
     */
    private function rowHasStringKeys(array $row): bool
    {
        foreach (array_keys($row) as $key) {
            if (is_string($key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve selectors when the spreadsheet uses a header row.
     *
     * @param  array<string, string|int|null>  $columnMap
     * @param  array<int, mixed>  $headerRow
     * @return array<string, array<string, mixed>>
     */
    private function resolveColumnSelectorsFromHeader(array $columnMap, array $headerRow): array
    {
        $lookup = [];

        foreach ($headerRow as $index => $heading) {
            if ($heading === null) {
                continue;
            }

            if (is_array($heading)) {
                $heading = implode(' ', array_map(fn ($value) => trim((string) $value), Arr::flatten($heading)));
            }

            $normalizedHeading = $this->normalizeColumnIdentifier((string) $heading);

            if ($normalizedHeading === '') {
                continue;
            }

            $lookup[$normalizedHeading] = (int) $index;
        }

        return $this->buildSelectorsFromLookup($columnMap, $lookup, 'index');
    }

    /**
     * Resolve selectors when the spreadsheet rows already contain header keys.
     *
     * @param  array<string, string|int|null>  $columnMap
     * @param  array<int, array<int|string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function resolveColumnSelectorsFromKeys(array $columnMap, array $rows): array
    {
        $lookup = [];

        foreach ($rows as $row) {
            foreach ($row as $key => $_value) {
                if (!is_string($key)) {
                    continue;
                }

                $normalized = $this->normalizeColumnIdentifier($key);

                if ($normalized === '') {
                    continue;
                }

                $lookup[$normalized] = $key;
            }

            if (!empty($lookup)) {
                break;
            }
        }

        return $this->buildSelectorsFromLookup($columnMap, $lookup, 'key');
    }

    /**
     * Create normalized selectors based on the resolved lookup.
     *
     * @param  array<string, string|int|null>  $columnMap
     * @param  array<string, string|int>  $lookup
     * @param  string  $mode  Either 'index' or 'key'.
     * @return array<string, array<string, mixed>>
     */
    private function buildSelectorsFromLookup(array $columnMap, array $lookup, string $mode): array
    {
        $selectors = [];

        foreach ($columnMap as $field => $column) {
            if ($column === null || $column === '') {
                continue;
            }

            if (is_numeric($column)) {
                $selectors[$field] = [
                    'type' => 'index',
                    'value' => (int) $column,
                    'normalized' => null,
                ];

                continue;
            }

            if (is_array($column)) {
                $column = implode(' ', array_map(fn ($value) => trim((string) $value), Arr::flatten($column)));
            }

            $normalized = $this->normalizeColumnIdentifier((string) $column);

            if (!array_key_exists($normalized, $lookup)) {
                $available = implode(', ', array_map(static fn ($key) => $key, array_keys($lookup)));

                throw ValidationException::withMessages([
                    'column_map.'.$field => sprintf(
                        "Column '%s' was not found in the file header. Available columns: %s",
                        $column,
                        $available ?: 'none'
                    ),
                ]);
            }

            $selectors[$field] = [
                'type' => $mode,
                'value' => $lookup[$normalized],
                'normalized' => $normalized,
            ];
        }

        foreach (['full_name', 'email'] as $required) {
            if (!array_key_exists($required, $selectors)) {
                throw ValidationException::withMessages([
                    'column_map.'.$required => 'This field is required.',
                ]);
            }
        }

        return $selectors;
    }

    /**
     * Resolve the column indexes based on the supplied map.
     *
     * @param  array<string, string|int|null>  $columnMap
     * @param  array<int, mixed>  $headerRow
     * @return array<string, int>
     */
    /**
     * Extract a normalized payload for validation / persistence.
     *
     * @param  array<int|string, mixed>  $row
     * @param  array<string, array<string, mixed>>  $selectors
     * @return array<string, string|null>|null
     */
    private function extractPayload(array $row, array $selectors): ?array
    {
        $payload = [];

        foreach ($selectors as $field => $selector) {
            $value = $this->getColumnValue($row, $selector);
            $value = is_string($value) ? trim($value) : (is_numeric($value) ? (string) $value : null);

            if ($field === 'jersey' && $value === '') {
                $value = null;
            }

            if (in_array($field, ['full_name', 'email', 'position'], true)) {
                $value = $value !== null ? trim((string) $value) : null;
            }

            $payload[$field] = $value;
        }

        if (
            empty($payload['full_name']) &&
            empty($payload['email']) &&
            empty($payload['jersey']) &&
            empty($payload['position'])
        ) {
            return null;
        }

        if (!empty($payload['email'])) {
            $payload['email'] = strtolower($payload['email']);
        }

        return $payload;
    }

    /**
     * Retrieve a cell value based on the column selector.
     *
     * @param  array<int|string, mixed>  $row
     * @param  array<string, mixed>  $selector
     */
    private function getColumnValue(array $row, array $selector): mixed
    {
        if (($selector['type'] ?? null) === 'index') {
            return $row[$selector['value']] ?? null;
        }

        $target = $selector['normalized'] ?? $this->normalizeColumnIdentifier((string) ($selector['value'] ?? ''));

        foreach ($row as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if ($this->normalizeColumnIdentifier($key) === $target) {
                return $value;
            }
        }

        return null;
    }

    private function normalizeColumnIdentifier(string $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/u', '', $value) ?? $value;
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? $value;
        $value = trim($value);

        return strtolower($value);
    }

    /**
     * Validate the normalized payload and return any row-level errors.
     *
     * @param  array<string, string|null>  $payload
     * @param  array<string, bool>  $seenEmails
     * @return array<string, array<int, string>>
     */
    private function validatePayload(array $payload, array &$seenEmails): array
    {
        $errors = [];

        $name = $payload['full_name'] ?? '';
        $email = $payload['email'] ?? '';
        $jersey = $payload['jersey'] ?? null;

        if ($name === null || trim($name) === '') {
            $errors['full_name'][] = 'Player name is required.';
        } elseif (mb_strlen($name) > 255) {
            $errors['full_name'][] = 'Player name must be less than 255 characters.';
        }

        if ($email === null || trim($email) === '') {
            $errors['email'][] = 'Email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'][] = 'Email format is invalid.';
        } elseif (isset($seenEmails[$email])) {
            $errors['email'][] = 'Duplicate email found in upload.';
        }

        if ($jersey !== null && $jersey !== '') {
            if (!preg_match('/^\d{1,5}$/', $jersey)) {
                $errors['jersey'][] = 'Jersey must be numeric.';
            }
        }

        if (empty($errors) && $email !== null) {
            $seenEmails[$email] = true;
        }

        return $errors;
    }

    /**
     * Refresh counts on the import job to match the stored rows.
     */
    private function updateCounts(ImportJob $job): void
    {
        $job->update([
            'total_rows' => $job->rows()->count(),
            'created_count' => $job->rows()->where('action', ImportRow::ACTION_CREATE)->count(),
            'updated_count' => $job->rows()->where('action', ImportRow::ACTION_UPDATE)->count(),
            'error_count' => $job->rows()->where('action', ImportRow::ACTION_ERROR)->count(),
        ]);
    }

    /**
     * Generate (or clear) the downloadable error CSV for the job.
     */
    private function generateErrorReport(ImportJob $job): void
    {
        $disk = Storage::disk('local');

        if ($job->error_report_path && $disk->exists($job->error_report_path)) {
            $disk->delete($job->error_report_path);
        }

        $errorRows = $job->rows()->where('action', ImportRow::ACTION_ERROR)->get();

        if ($errorRows->isEmpty()) {
            $job->forceFill(['error_report_path' => null])->save();

            return;
        }

        $headers = ['row_number', 'full_name', 'email', 'jersey', 'position', 'errors'];

        $csvLines = [];
        $csvLines[] = implode(',', $headers);

        foreach ($errorRows as $row) {
            $payload = $row->payload ?? [];

            $line = [
                $row->row_number,
                $this->escapeForCsv($payload['full_name'] ?? ''),
                $this->escapeForCsv($payload['email'] ?? ''),
                $this->escapeForCsv($payload['jersey'] ?? ''),
                $this->escapeForCsv($payload['position'] ?? ''),
                $this->escapeForCsv(implode('; ', Arr::flatten($row->errors ?? []))),
            ];

            $csvLines[] = implode(',', $line);
        }

        $path = sprintf('imports/%d/reports/import_%d_errors.csv', $job->team_id, $job->id);
        $disk->put($path, implode("\n", $csvLines));

        $job->forceFill(['error_report_path' => $path])->save();
    }

    private function escapeForCsv(string $value): string
    {
        $needsQuotes = str_contains($value, '"') || str_contains($value, ',') || str_contains($value, "\n");

        $escaped = str_replace('"', '""', $value);

        return $needsQuotes ? sprintf('"%s"', $escaped) : $escaped;
    }
}
