<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class TicketSave extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'subject' => 'required',
            'level' => 'required|in:0,1,2',
            'message' => 'required',
            'personal_email' => 'nullable|email:strict|max:255'
        ];
    }

    public function messages()
    {
        return [
            'subject.required' => __('Ticket subject cannot be empty'),
            'level.required' => __('Ticket level cannot be empty'),
            'level.in' => __('Incorrect ticket level format'),
            'message.required' => __('Message cannot be empty'),
            'personal_email.email' => __('Email format is incorrect'),
            'personal_email.max' => __('Email is too long')
        ];
    }
}
