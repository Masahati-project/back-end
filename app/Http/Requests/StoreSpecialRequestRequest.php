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
            'description' => ['required', 'string'],
            'space_type' => ['required', Rule::in(['room', 'whole'])],
            'capacity' => ['required', 'integer', 'min:1'],
            'schedule_preset' => ['nullable', Rule::in(['once', 'daily', 'weekly', 'monthly', 'yearly'])],
            // The frontend requires a repeat count for any recurring preset.
            'schedule_count' => [
                'required_if:schedule_preset,daily,weekly,monthly,yearly',
                'nullable',
                'integer',
                'min:1',
            ],
            // The docs show a human range such as "10:00 ص – 1:00 م", so only
            // validate the clock form when the value looks like HH:MM.
            'preferred_time' => ['nullable', 'string', 'max:120'],
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
