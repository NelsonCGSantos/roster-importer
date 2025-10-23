<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DryRunImportRequest;
use App\Http\Requests\StoreImportRequest;
use App\Http\Resources\ImportJobResource;
use App\Models\ImportJob;
use App\Models\Team;
use App\Services\RosterImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class ImportController extends Controller
{
    public function __construct(
        private readonly RosterImportService $service,
    ) {
    }

    /**
     * List recent imports for the authenticated coach.
     */
    public function index(Request $request)
    {
        $imports = ImportJob::query()
            ->with('user')
            ->latest()
            ->limit(10)
            ->get();

        return ImportJobResource::collection($imports);
    }

    /**
     * Persist an uploaded roster file and start a new import job.
     */
    public function store(StoreImportRequest $request)
    {
        $result = $this->service->storeUpload(
            $request->file('file'),
            $request->user(),
            $this->resolveTeam()
        );

        $resource = (new ImportJobResource($result['job']))
            ->additional([
                'meta' => [
                    'duplicate' => $result['duplicate'],
                    'columns' => $result['columns'],
                ],
            ]);

        $status = $result['duplicate'] ? Response::HTTP_OK : Response::HTTP_CREATED;

        return $resource->response()->setStatusCode($status);
    }

    /**
     * Execute and return the dry-run validation results for an import job.
     */
    public function dryRun(DryRunImportRequest $request, ImportJob $importJob)
    {
        $importJob = $this->service->performDryRun(
            $importJob,
            $request->validated('column_map')
        );

        return new ImportJobResource($importJob->load('rows'));
    }

    /**
     * Apply a validated import job.
     */
    public function apply(Request $request, ImportJob $importJob)
    {
        $importJob = $this->service->applyImport($importJob);

        return new ImportJobResource($importJob->load('rows'));
    }

    /**
     * Download a CSV containing the failed rows for an import.
     */
    public function downloadErrors(Request $request, ImportJob $importJob)
    {
        if (!$importJob->error_report_path || !Storage::disk('local')->exists($importJob->error_report_path)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return Storage::disk('local')->download($importJob->error_report_path);
    }

    private function resolveTeam(): Team
    {
        return Team::query()->firstOrFail();
    }
}
