<?php

namespace App\Http\Requests\Telemetry;

use Illuminate\Foundation\Http\FormRequest;

class WorkerEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event_id' => ['required', 'uuid'],
            'app_code' => ['required', 'string', 'max:100', 'exists:maestro_applications,app_code'],
            'worker_id' => ['required', 'string', 'max:255'],
            'event_type' => ['required', 'string', 'max:100'],
            'queue_name' => ['nullable', 'string', 'max:255'],
            'job_type' => ['nullable', 'string', 'max:255'],
            'job_id' => ['nullable', 'string', 'max:255'],
            'outcome' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
            'occurred_at' => ['required', 'date'],
            'payload' => ['nullable', 'array'],
        ];
    }
}
