<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSpecialRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'space_type' => ['required', Rule::in(['room', 'whole'])],
            'capacity' => ['required', 'integer', 'min:10'],
            'schedule_preset' => ['required', Rule::in(['once', 'daily', 'weekly', 'monthly', 'yearly'])],
            'schedule_count' => [
                'required_if:schedule_preset,daily,weekly,monthly,yearly',
                'nullable',
                'integer',
                'min:1',
            ],
            'preferred_time' => ['required', 'string', 'max:120'],
            'area' => ['nullable', 'string', 'max:255'],
            'amenities' => ['nullable', 'array'],
            'amenities.*' => [Rule::in([
                'internet', 'electricity', 'projector', 'ac', 'microphone', 'whiteboard',
            ])],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
