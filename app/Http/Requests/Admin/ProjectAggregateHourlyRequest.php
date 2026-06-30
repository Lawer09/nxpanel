<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ProjectAggregateHourlyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'startDate' => 'required|date',
            'endDate' => 'required|date|after_or_equal:startDate',
            'hourFrom' => 'nullable|integer|min:0|max:23',
            'hourTo' => 'nullable|integer|min:0|max:23',
            'projectId' => 'nullable|integer|min:1|exists:project_projects,id',
        ];
    }

    /**
     * Ensure the hour range is valid only when both range edges are provided.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hourFrom = $this->input('hourFrom');
            $hourTo = $this->input('hourTo');

            if ($hourFrom !== null && $hourTo !== null && (int) $hourFrom > (int) $hourTo) {
                $validator->errors()->add('hourTo', 'hourTo must be greater than or equal to hourFrom.');
            }
        });
    }
}
