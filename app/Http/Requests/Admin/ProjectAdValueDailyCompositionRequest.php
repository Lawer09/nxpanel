<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ProjectAdValueDailyCompositionRequest extends FormRequest
{
    /**
     * Normalize project code before validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('projectCode') && is_string($this->input('projectCode'))) {
            $this->merge([
                'projectCode' => trim($this->input('projectCode')),
            ]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'projectCode' => 'required|string|max:100',
            'dateFrom' => 'required|date_format:Y-m-d',
            'dateTo' => 'required|date_format:Y-m-d|after_or_equal:dateFrom',
        ];
    }
}
