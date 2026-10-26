<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        // Historical dates are retained, but are no longer writable project settings.
        return [
            'name' => 'required|string|max:150', 'client_id' => 'nullable|ulid', 'client_name' => 'required_without:client_id|string|max:150',
            'website' => 'required|url:http,https|max:2048', 'description' => 'nullable|string|max:5000',
            'status' => ['sometimes', Rule::in(['active', 'archived'])],
            'reporting_frequency' => ['sometimes', Rule::in(['monthly', 'quarterly', 'custom'])],
        ];
    }
}
