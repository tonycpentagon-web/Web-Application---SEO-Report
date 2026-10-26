<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class SaveReportingPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])$/', (string) $this->input('reporting_month')) && $this->input('kind', 'monthly') === 'monthly') {
            $start = Carbon::createFromFormat('!Y-m', $this->input('reporting_month'));
            $this->merge(['kind' => 'monthly', 'start_date' => $start->format('Y-m-d'), 'end_date' => $start->endOfMonth()->format('Y-m-d')]);
        }
    }

    public function rules(): array
    {
        return ['title' => 'required|string|max:180', 'reporting_month' => ['required', 'date_format:Y-m', 'regex:/^[1-9][0-9]{3}-(0[1-9]|1[0-2])$/'], 'kind' => 'required|in:monthly,custom', 'start_date' => 'required|date_format:Y-m-d|after_or_equal:1000-01-01|before_or_equal:9999-12-31', 'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date|before_or_equal:9999-12-31', 'version' => 'sometimes|integer|min:1'];
    }
}
