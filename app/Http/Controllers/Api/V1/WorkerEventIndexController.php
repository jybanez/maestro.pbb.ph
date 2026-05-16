<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MaestroWorkerEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkerEventIndexController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $limit = min(max($request->integer('limit', 100), 1), 500);

        $events = MaestroWorkerEvent::query()
            ->with('worker.application')
            ->when($request->string('app_code')->isNotEmpty(), function ($builder) use ($request): void {
                $builder->whereHas('worker.application', function ($applicationQuery) use ($request): void {
                    $applicationQuery->where('app_code', $request->string('app_code')->toString());
                });
            })
            ->when($request->string('worker_id')->isNotEmpty(), function ($builder) use ($request): void {
                $builder->where('worker_id', $request->string('worker_id')->toString());
            })
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get()
            ->map(function (MaestroWorkerEvent $event): array {
                return [
                    'event_id' => $event->event_id,
                    'worker_id' => $event->worker_id,
                    'application' => [
                        'app_code' => $event->worker?->application?->app_code,
                        'display_name' => $event->worker?->application?->display_name,
                    ],
                    'event_type' => $event->event_type,
                    'queue_name' => $event->queue_name,
                    'job_type' => $event->job_type,
                    'job_id' => $event->job_id,
                    'outcome' => $event->outcome,
                    'notes' => $event->notes,
                    'occurred_at' => optional($event->occurred_at)?->toISOString(),
                    'payload' => $event->payload_json ?? [],
                ];
            })
            ->values();

        return response()->json([
            'data' => $events,
        ]);
    }
}
