<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['files' => 'required|array|min:1|max:'.config('documents.max_batch'), 'files.*' => 'required|file|max:'.(config('documents.max_file_mb') * 1024), 'batch_id' => 'nullable|uuid'];
    }
}
