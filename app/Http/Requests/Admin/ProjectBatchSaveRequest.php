<?php

namespace App\Http\Requests\Admin;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ProjectBatchSaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => 'required|array|min:1',
            'items.*.projectCode' => 'required|string|max:100|distinct',
            'items.*.projectName' => 'sometimes|required|string|max:100',
            'items.*.ownerName' => 'nullable|string|max:100',
            'items.*.department' => 'nullable|string|max:100',
            'items.*.status' => 'nullable|string|in:active,inactive,archived',
            'items.*.adStatus' => 'nullable|string|max:50',
            'items.*.appPlatform' => 'nullable|string|max:50',
            'items.*.adspowerEnv' => 'nullable|string|max:100',
            'items.*.developerGmail' => 'nullable|string|max:191',
            'items.*.appName' => 'nullable|string|max:191',
            'items.*.packageName' => 'nullable|string|max:191',
            'items.*.domainInfoStatus' => 'nullable|string|max:50',
            'items.*.admobPubId' => 'nullable|string|max:100',
            'items.*.domainUrl' => 'nullable|string|max:255',
            'items.*.privacyPolicyUrl' => 'nullable|string|max:255',
            'items.*.termsUrl' => 'nullable|string|max:255',
            'items.*.facebookInfoStatus' => 'nullable|string|max:50',
            'items.*.facebookAppId' => 'nullable|string|max:100',
            'items.*.facebookAppToken' => 'nullable|string|max:255',
            'items.*.facebookKeyHash' => 'nullable|string|max:255',
            'items.*.facebookClassName' => 'nullable|string|max:191',
            'items.*.admobAccountStatus' => 'nullable|string|max:50',
            'items.*.admobAppId' => 'nullable|string|max:100',
            'items.*.admobAdIds' => 'nullable|string',
            'items.*.admobAppAdsTxt' => 'nullable|string',
            'items.*.firebaseConfigNote' => 'nullable|string',
            'items.*.yandexAccount' => 'nullable|string|max:191',
            'items.*.yandexAdIds' => 'nullable|string',
            'items.*.yandexAppAdsTxt' => 'nullable|string',
            'items.*.storePageUrl' => 'nullable|string|max:255',
            'items.*.remark' => 'nullable|string|max:255',
        ];
    }

    /**
     * Require projectName only when the projectCode does not exist yet.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $items = $this->input('items');
            if (!is_array($items)) {
                return;
            }

            $codes = collect($items)
                ->pluck('projectCode')
                ->filter(fn ($code) => is_string($code) && trim($code) !== '')
                ->map(fn ($code) => trim((string) $code))
                ->unique()
                ->values();

            if ($codes->isEmpty()) {
                return;
            }

            $duplicatedCodes = collect($items)
                ->pluck('projectCode')
                ->filter(fn ($code) => is_string($code) && trim($code) !== '')
                ->map(fn ($code) => trim((string) $code))
                ->duplicates()
                ->unique();

            foreach ($duplicatedCodes as $duplicatedCode) {
                $validator->errors()->add('items', "projectCode {$duplicatedCode} cannot be duplicated");
            }

            $existingCodes = collect();
            foreach ($codes->chunk(100) as $codeChunk) {
                $existingCodes = $existingCodes->merge(
                    Project::query()
                        ->whereIn('project_code', $codeChunk->all())
                        ->pluck('project_code')
                );
            }
            $existingCodes = $existingCodes->mapWithKeys(fn ($code) => [(string) $code => true]);

            foreach ($items as $index => $item) {
                if (!is_array($item)) {
                    continue;
                }

                $projectCode = trim((string) ($item['projectCode'] ?? ''));
                if ($projectCode === '' || isset($existingCodes[$projectCode])) {
                    continue;
                }

                $projectName = $item['projectName'] ?? null;
                if (!is_string($projectName) || trim($projectName) === '') {
                    $validator->errors()->add("items.{$index}.projectName", 'projectName is required when creating a new project');
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'items.required' => 'items are required',
            'items.array' => 'items must be an array',
            'items.min' => 'items cannot be empty',
            'items.*.projectCode.required' => 'projectCode is required',
            'items.*.projectCode.distinct' => 'projectCode cannot be duplicated',
            'items.*.status.in' => 'status must be active, inactive, or archived',
        ];
    }
}
