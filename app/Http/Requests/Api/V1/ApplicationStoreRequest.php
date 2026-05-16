<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApplicationStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'app_code' => [
                'required',
                'string',
                'max:100',
                'alpha_dash',
                Rule::unique('maestro_applications', 'app_code'),
            ],
            'display_name' => ['required', 'string', 'max:255'],
            'environment' => ['required', 'string', 'max:100'],
            'base_url' => ['nullable', 'url', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'issue_telemetry_token' => ['sometimes', 'boolean'],
            'token_label' => ['nullable', 'string', 'max:255'],
        ];
    }
}
