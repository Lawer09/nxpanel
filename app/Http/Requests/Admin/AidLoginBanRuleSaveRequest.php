<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AidLoginBanRuleSaveRequest extends FormRequest
{
    /**
     * Allow admin users to create AID login ban rules.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validate rule creation payload.
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:191',
            'enabled' => 'nullable|boolean',
            'cutoffAt' => 'required|date',
            'weeklyWindows' => 'required|array|min:1',
            'weeklyWindows.*.weekday' => 'required|integer|min:1|max:7',
            'weeklyWindows.*.start' => 'required|date_format:H:i',
            'weeklyWindows.*.end' => 'required|date_format:H:i',
            'packageNames' => 'nullable|array',
            'packageNames.*' => 'required|string|max:191|distinct',
            'countries' => 'nullable|array',
            'countries.*' => 'required|string|max:16|distinct',
            'reason' => 'nullable|string|max:500',
        ];
    }

    /**
     * Reject cross-day or zero-length weekly windows.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateWeeklyWindows($validator);
        });
    }

    protected function validateWeeklyWindows(Validator $validator): void
    {
        foreach ((array) $this->input('weeklyWindows', []) as $index => $window) {
            $start = is_array($window) ? ($window['start'] ?? null) : null;
            $end = is_array($window) ? ($window['end'] ?? null) : null;

            if (!is_string($start) || !is_string($end)) {
                continue;
            }

            if ($start >= $end) {
                $validator->errors()->add(
                    "weeklyWindows.{$index}.end",
                    'end must be later than start and cross-day windows are not supported.'
                );
            }
        }
    }
}
