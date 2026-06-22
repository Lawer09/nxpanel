<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AidLoginBanRuleUpdateRequest extends FormRequest
{
    /**
     * Allow admin users to update AID login ban rules.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validate rule update payload.
     */
    public function rules(): array
    {
        return [
            'id' => 'required|integer|exists:aid_login_ban_rules,id',
            'name' => 'sometimes|required|string|max:191',
            'enabled' => 'sometimes|required|boolean',
            'cutoffAt' => 'sometimes|nullable|date',
            'weeklyWindows' => 'sometimes|nullable|array',
            'weeklyWindows.*.weekday' => 'required_with:weeklyWindows|integer|min:1|max:7',
            'weeklyWindows.*.start' => 'required_with:weeklyWindows|date_format:H:i',
            'weeklyWindows.*.end' => 'required_with:weeklyWindows|date_format:H:i',
            'packageNames' => 'sometimes|nullable|array',
            'packageNames.*' => 'required|string|max:191|distinct',
            'projectCodes' => 'sometimes|nullable|array',
            'projectCodes.*' => 'required|string|max:100|distinct',
            'countries' => 'sometimes|nullable|array',
            'countries.*' => 'required|string|max:16|distinct',
            'reason' => 'sometimes|nullable|string|max:500',
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
        if (!$this->has('weeklyWindows')) {
            return;
        }

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
