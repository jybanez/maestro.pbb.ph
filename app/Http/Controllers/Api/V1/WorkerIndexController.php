<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MaestroWorker;
use App\Services\Maestro\WorkerStatusResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkerIndexController extends Controller
{
    public function __invoke(Request $request, WorkerStatusResolver $statusResolver): JsonResponse
    {
        $query = MaestroWorker::query()
            ->with('application')
            ->when($request->string('app_code')->isNotEmpty(), function ($builder) use ($request): void {
                $builder->whereHas('application', function ($applicationQuery) use ($request): void {
                    $applicationQuery->where('app_code', $request->string('app_code')->toString());
                });
            })
            ->when($request->string('queue_name')->isNotEmpty(), function ($builder) use ($request): void {
                $builder->where('queue_name', $request->string('queue_name')->toString());
            })
            ->orderByDesc('last_heartbeat_at');

        $workers = $query->get()
            ->map(function (MaestroWorker $worker) use ($statusResolver): array {
                $computedStatus = $statusResolver->resolveForWorker($worker);

                return [
                    'worker_id' => $worker->worker_id,
                    'application' => [
                        'app_code' => $worker->application?->app_code,
                        'display_name' => $worker->application?->display_name,
                    ],
                    'host_name' => $worker->host_name,
                    'queue_name' => $worker->queue_name,
                    'process_id' => $worker->process_id,
                    'status' => $computedStatus,
                    'stored_status' => $worker->status,
                    'started_at' => optional($worker->started_at)?->toISOString(),
                    'last_heartbeat_at' => optional($worker->last_heartbeat_at)?->toISOString(),
                    'last_job_started_at' => optional($worker->last_job_started_at)?->toISOString(),
                    'last_job_finished_at' => optional($worker->last_job_finished_at)?->toISOString(),
                    'current_job_type' => $worker->current_job_type,
                    'current_job_id' => $worker->current_job_id,
                    'processed_count' => $worker->processed_count,
                    'failed_count' => $worker->failed_count,
                    'memory_mb' => $worker->memory_mb !== null ? (float) $worker->memory_mb : null,
                    'stopped_at' => optional($worker->stopped_at)?->toISOString(),
                    'meta' => $worker->meta_json ?? [],
                ];
            });

        if ($request->string('status')->isNotEmpty()) {
            $status = $request->string('status')->toString();
            $workers = $workers->filter(fn (array $worker): bool => $worker['status'] === $status)->values();
        } else {
            $workers = $workers->values();
        }

        return response()->json([
            'data' => $workers,
        ]);
    }
}
