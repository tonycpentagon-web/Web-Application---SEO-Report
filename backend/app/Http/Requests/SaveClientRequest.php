<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:150', 'company_name' => 'nullable|string|max:150', 'website' => 'nullable|url:http,https|max:2048',
            'contact_email' => 'nullable|email|max:254', 'contact_phone' => 'nullable|string|max:40', 'country' => 'nullable|string|max:100',
            'brand_color' => ['sometimes', 'regex:/^#[0-9a-fA-F]{6}$/'], 'status' => ['sometimes', Rule::in(['active', 'archived'])], 'notes' => 'nullable|string|max:5000',
        ];
    }
}
