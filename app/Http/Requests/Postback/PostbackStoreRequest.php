<?php

namespace App\Http\Requests\Postback;

use Illuminate\Foundation\Http\FormRequest;

class PostbackStoreRequest extends FormRequest
{
    /**
     * Allow unauthenticated third-party postback requests.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validate the click attribution parameters from the third-party callback.
     */
    public function rules(): array
    {
        return [
            'clickid' => ['required', 'string', 'max:255'],
            'deviceid' => ['required', 'string', 'max:255'],
        ];
    }
}
