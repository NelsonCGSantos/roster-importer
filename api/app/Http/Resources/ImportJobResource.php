<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ImportJob
 */
class ImportJobResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'original_filename' => $this->original_filename,
            'file_hash' => $this->file_hash,
            'counts' => [
                'total' => $this->total_rows,
                'created' => $this->created_count,
                'updated' => $this->updated_count,
                'errors' => $this->error_count,
            ],
            'column_map' => $this->column_map,
            'processed_at' => optional($this->processed_at)?->toIso8601String(),
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'error_report_available' => (bool) $this->error_report_path,
            'error_report_url' => $this->error_report_path ? route('imports.errors', $this->resource) : null,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'rows' => ImportRowResource::collection($this->whenLoaded('rows')),
        ];
    }
}
