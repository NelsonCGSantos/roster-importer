<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
class DryRunImportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'column_map' => ['required', 'array'],
            'column_map.full_name' => ['required', 'string'],
            'column_map.email' => ['required', 'string'],
            'column_map.jersey' => ['nullable', 'string'],
            'column_map.position' => ['nullable', 'string'],
        ];
    }
}
