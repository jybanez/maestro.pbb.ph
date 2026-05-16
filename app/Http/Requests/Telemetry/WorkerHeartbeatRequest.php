<?php

namespace App\Http\Requests\Telemetry;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WorkerHeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'app_code' => ['required', 'string', 'max:100', 'exists:maestro_applications,app_code'],
            'worker_id' => ['required', 'string', 'max:255'],
            'host_name' => ['required', 'string', 'max:255'],
            'queue_name' => ['nullable', 'string', 'max:255'],
            'process_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(['starting', 'idle', 'busy', 'stale', 'stopped'])],
            'started_at' => ['required', 'date'],
            'last_heartbeat_at' => ['required', 'date'],
            'current_job_type' => ['nullable', 'string', 'max:255'],
            'current_job_id' => ['nullable', 'string', 'max:255'],
            'processed_count' => ['required', 'integer', 'min:0'],
            'failed_count' => ['required', 'integer', 'min:0'],
            'memory_mb' => ['nullable', 'numeric', 'min:0'],
            'meta' => ['nullable', 'array'],
        ];
    }
}
