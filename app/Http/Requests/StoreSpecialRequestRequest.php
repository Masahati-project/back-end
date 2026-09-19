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
            'schedule_preset' => ['nullable', 'string'],
            'schedule_count' => ['nullable', 'integer'],
            'preferred_time' => ['nullable', 'date_format:H:i'],
            'area' => ['nullable', 'string'],
            'amenities' => ['nullable', 'array'],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
