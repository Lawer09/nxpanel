<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ProjectVersionRecordIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'projectId' => 'nullable|integer|min:1',
            'projectCode' => 'nullable|string|max:100',
            'keyword' => 'nullable|string|max:100',
            'releaseTimeFrom' => 'nullable|date',
            'releaseTimeTo' => 'nullable|date|after_or_equal:releaseTimeFrom',
            'page' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:200',
        ];
    }
}
