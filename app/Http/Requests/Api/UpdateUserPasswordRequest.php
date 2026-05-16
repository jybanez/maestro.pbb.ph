<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'current_password:web'],
            'new_password' => ['required', 'string', 'min:8', 'different:current_password'],
            'confirm_password' => ['required', 'same:new_password'],
        ];
    }
}
