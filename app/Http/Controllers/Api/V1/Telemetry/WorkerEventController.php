<?php

namespace App\Http\Controllers\Api\V1\Telemetry;

use App\Http\Controllers\Controller;
use App\Http\Requests\Telemetry\WorkerEventRequest;
use App\Models\MaestroApplication;
use App\Models\MaestroWorker;
use App\Models\MaestroWorkerEvent;
use App\Support\DatabaseRetry;
use App\Support\TelemetryTrace;
use App\Services\Maestro\WorkerStatusResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class WorkerEventController extends Controller
{
    public function __invoke(WorkerEventRequest $request, WorkerStatusResolver $statusResolver): JsonResponse
    {
        $traceId = $request->attributes->get('maestroTelemetryTraceId') ?: (string) str()->uuid();
        $startedAtMs = microtime(true);
        $stage = 'entry';
        $traceContext = [
            'trace_id' => $traceId,
            'path' => $request->path(),
            'kind' => 'worker_event',
            'worker_id' => $request->input('worker_id'),
            'app_code' => $request->input('app_code'),
            'event_id' => $request->input('event_id'),
            'event_type' => $request->input('event_type'),
        ];

        TelemetryTrace::info('Maestro telemetry worker event started.', $traceContext);

        try {
            $application = $this->validatedApplication($request);
            $validated = $request->validated();

            $traceContext['worker_id'] = $validated['worker_id'];
            $traceContext['app_code'] = $validated['app_code'];
            $traceContext['event_id'] = $validated['event_id'];
            $traceContext['event_type'] = $validated['event_type'];
            $traceContext['application_id'] = $application->id;

            $stage = 'payload_parsed';
            TelemetryTrace::info('Maestro telemetry worker event payload parsed.', $traceContext + [
                'elapsed_ms' => $this->elapsedMs($startedAtMs),
            ]);

            $occurredAt = CarbonImmutable::parse($validated['occurred_at']);
            $clockSkewSeconds = abs($occurredAt->diffInSeconds(now(), false));
            $payload = $validated['payload'] ?? [];
            $payload['_maestro'] = [
                'clock_skew_seconds' => $clockSkewSeconds,
                'clock_skew_flagged' => $clockSkewSeconds > config('maestro.telemetry.clock_skew_threshold_seconds'),
                'ingested_at' => now()->toISOString(),
            ];

            $stage = 'persisting_event';
            TelemetryTrace::info('Maestro telemetry worker event persisting event.', $traceContext + [
                'elapsed_ms' => $this->elapsedMs($startedAtMs),
            ]);

            $result = DatabaseRetry::run(function () use ($application, $validated, $occurredAt, $payload, $statusResolver) {
                return DB::transaction(function () use ($application, $validated, $occurredAt, $payload, $statusResolver) {
                    $existing = MaestroWorkerEvent::query()
                        ->where('event_id', $validated['event_id'])
                        ->first();

                    if ($existing !== null) {
                        return [
                            'event' => $existing,
                            'duplicate' => true,
                        ];
                    }

                    $worker = $this->findOrCreateWorkerForUpdate($application, $validated, $occurredAt);

                    try {
                        $event = MaestroWorkerEvent::query()->create([
                            'maestro_worker_id' => $worker->id,
                            'event_id' => $validated['event_id'],
                            'worker_id' => $validated['worker_id'],
                            'event_type' => $validated['event_type'],
                            'queue_name' => $validated['queue_name'] ?? null,
                            'job_type' => $validated['job_type'] ?? null,
                            'job_id' => $validated['job_id'] ?? null,
                            'outcome' => $validated['outcome'] ?? null,
                            'notes' => $validated['notes'] ?? null,
                            'payload_json' => $payload,
                            'occurred_at' => $occurredAt,
                        ]);
                    } catch (QueryException $exception) {
                        if (! $this->isUniqueConstraintViolation($exception)) {
                            throw $exception;
                        }

                        $existing = MaestroWorkerEvent::query()
                            ->where('event_id', $validated['event_id'])
                            ->firstOrFail();

                        return [
                            'event' => $existing,
                            'duplicate' => true,
                        ];
                    }

                    $attributes = [
                        'maestro_application_id' => $application->id,
                        'queue_name' => $validated['queue_name'] ?? $worker->queue_name,
                    ];

                    switch ($validated['event_type']) {
                        case 'worker.started':
                            $attributes['started_at'] = $occurredAt;
                            $attributes['stopped_at'] = null;
                            break;

                        case 'worker.heartbeat':
                            $attributes['last_heartbeat_at'] = $occurredAt;
                            break;

                        case 'worker.stopped':
                            $attributes['stopped_at'] = $occurredAt;
                            $attributes['current_job_type'] = null;
                            $attributes['current_job_id'] = null;
                            break;

                        case 'job.started':
                            $attributes['last_job_started_at'] = $occurredAt;
                            $attributes['current_job_type'] = $validated['job_type'] ?? null;
                            $attributes['current_job_id'] = $validated['job_id'] ?? null;
                            break;

                        case 'job.completed':
                            $attributes['last_job_finished_at'] = $occurredAt;
                            $attributes['current_job_type'] = null;
                            $attributes['current_job_id'] = null;
                            $attributes['processed_count'] = $worker->processed_count + 1;
                            break;

                        case 'job.failed':
                            $attributes['last_job_finished_at'] = $occurredAt;
                            $attributes['current_job_type'] = null;
                            $attributes['current_job_id'] = null;
                            $attributes['failed_count'] = $worker->failed_count + 1;
                            break;
                    }

                    $worker->fill($attributes);
                    $worker->status = $statusResolver->resolveForWorker($worker);
                    $worker->save();

                    return [
                        'event' => $event,
                        'duplicate' => false,
                    ];
                }, 1);
            }, attempts: 5, retryUniqueConstraint: true);

            $durationMs = $this->elapsedMs($startedAtMs);

            if ($durationMs >= TelemetryTrace::slowThresholdMs()) {
                TelemetryTrace::warning('Maestro telemetry worker event completed slowly.', $traceContext + [
                    'elapsed_ms' => $durationMs,
                    'duplicate' => $result['duplicate'],
                    'stage' => 'completed',
                ]);
            } else {
                TelemetryTrace::info('Maestro telemetry worker event completed.', $traceContext + [
                    'elapsed_ms' => $durationMs,
                    'duplicate' => $result['duplicate'],
                ]);
            }

            return response()->json([
                'data' => [
                    'event_id' => $result['event']->event_id,
                    'duplicate' => $result['duplicate'],
                ],
            ], $result['duplicate'] ? 200 : 202);
        } catch (Throwable $exception) {
            TelemetryTrace::error('Maestro telemetry worker event failed.', $traceContext + [
                'elapsed_ms' => $this->elapsedMs($startedAtMs),
                'stage' => $stage,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    private function validatedApplication(WorkerEventRequest $request): MaestroApplication
    {
        /** @var MaestroApplication $application */
        $application = $request->attributes->get('maestroTelemetryApplication');
        $appCode = $request->string('app_code')->toString();

        if ($application->app_code !== $appCode) {
            throw new HttpResponseException(response()->json([
                'message' => 'Telemetry token does not match the requested app.',
            ], 403));
        }

        return $application;
    }

    private function findOrCreateWorkerForUpdate(
        MaestroApplication $application,
        array $validated,
        CarbonImmutable $occurredAt,
    ): MaestroWorker {
        $worker = MaestroWorker::query()
            ->where('worker_id', $validated['worker_id'])
            ->lockForUpdate()
            ->first();

        if ($worker !== null) {
            return $worker;
        }

        try {
            return MaestroWorker::query()->create([
                'maestro_application_id' => $application->id,
                'worker_id' => $validated['worker_id'],
                'queue_name' => $validated['queue_name'] ?? null,
                'status' => 'starting',
                'started_at' => str_starts_with($validated['event_type'], 'worker.') ? $occurredAt : null,
            ]);
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            return MaestroWorker::query()
                ->where('worker_id', $validated['worker_id'])
                ->lockForUpdate()
                ->firstOrFail();
        }
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) $exception->getCode();
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = strtolower($exception->getMessage());

        return $sqlState === '23000'
            || $driverCode === 1062
            || str_contains($message, 'duplicate entry');
    }

    private function elapsedMs(float $startedAtMs): float
    {
        return round((microtime(true) - $startedAtMs) * 1000, 3);
    }
}
