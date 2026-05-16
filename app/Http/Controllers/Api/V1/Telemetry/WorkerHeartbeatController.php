<?php

namespace App\Http\Controllers\Api\V1\Telemetry;

use App\Http\Controllers\Controller;
use App\Http\Requests\Telemetry\WorkerHeartbeatRequest;
use App\Models\MaestroApplication;
use App\Models\MaestroWorker;
use App\Support\DatabaseRetry;
use App\Support\TelemetryTrace;
use App\Services\Maestro\WorkerStatusResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class WorkerHeartbeatController extends Controller
{
    public function __invoke(WorkerHeartbeatRequest $request, WorkerStatusResolver $statusResolver): JsonResponse
    {
        $traceId = $request->attributes->get('maestroTelemetryTraceId') ?: (string) str()->uuid();
        $startedAtMs = microtime(true);
        $stage = 'entry';
        $traceContext = [
            'trace_id' => $traceId,
            'path' => $request->path(),
            'kind' => 'heartbeat',
            'worker_id' => $request->input('worker_id'),
            'app_code' => $request->input('app_code'),
        ];

        TelemetryTrace::info('Maestro telemetry heartbeat started.', $traceContext);

        try {
            $application = $this->validatedApplication($request);
            $validated = $request->validated();

            $traceContext['worker_id'] = $validated['worker_id'];
            $traceContext['app_code'] = $validated['app_code'];
            $traceContext['application_id'] = $application->id;

            $stage = 'payload_parsed';
            TelemetryTrace::info('Maestro telemetry heartbeat payload parsed.', $traceContext + [
                'elapsed_ms' => $this->elapsedMs($startedAtMs),
            ]);

            $reportedHeartbeatAt = CarbonImmutable::parse($validated['last_heartbeat_at']);
            $startedAt = CarbonImmutable::parse($validated['started_at']);
            $clockSkewSeconds = abs($reportedHeartbeatAt->diffInSeconds(now(), false));

            $meta = $validated['meta'] ?? [];
            $meta['_maestro'] = [
                'clock_skew_seconds' => $clockSkewSeconds,
                'clock_skew_flagged' => $clockSkewSeconds > config('maestro.telemetry.clock_skew_threshold_seconds'),
                'last_ingested_at' => now()->toISOString(),
            ];

            $attributes = [
                'maestro_application_id' => $application->id,
                'host_name' => $validated['host_name'],
                'queue_name' => $validated['queue_name'] ?? null,
                'process_id' => $validated['process_id'] ?? null,
                'status' => $statusResolver->resolve(
                    $startedAt,
                    $reportedHeartbeatAt,
                    null,
                    $validated['current_job_id'] ?? null,
                ),
                'started_at' => $startedAt,
                'last_heartbeat_at' => $reportedHeartbeatAt,
                'current_job_type' => $validated['current_job_type'] ?? null,
                'current_job_id' => $validated['current_job_id'] ?? null,
                'processed_count' => $validated['processed_count'],
                'failed_count' => $validated['failed_count'],
                'memory_mb' => $validated['memory_mb'] ?? null,
                'stopped_at' => null,
                'meta_json' => $meta,
            ];

            $stage = 'persisting_worker';
            TelemetryTrace::info('Maestro telemetry heartbeat persisting worker.', $traceContext + [
                'elapsed_ms' => $this->elapsedMs($startedAtMs),
            ]);

            $worker = DatabaseRetry::run(function () use ($validated, $attributes) {
                $timestamp = now();
                $databaseAttributes = $attributes;
                $databaseAttributes['meta_json'] = json_encode($attributes['meta_json'], JSON_UNESCAPED_SLASHES);

                DB::table('maestro_workers')->upsert([
                    array_merge(
                        ['worker_id' => $validated['worker_id']],
                        $databaseAttributes,
                        ['created_at' => $timestamp, 'updated_at' => $timestamp],
                    ),
                ], ['worker_id'], [
                    'maestro_application_id',
                    'host_name',
                    'queue_name',
                    'process_id',
                    'status',
                    'started_at',
                    'last_heartbeat_at',
                    'current_job_type',
                    'current_job_id',
                    'processed_count',
                    'failed_count',
                    'memory_mb',
                    'stopped_at',
                    'meta_json',
                    'updated_at',
                ]);

                return MaestroWorker::query()
                    ->where('worker_id', $validated['worker_id'])
                    ->firstOrFail();
            }, attempts: 5, retryUniqueConstraint: true);

            $durationMs = $this->elapsedMs($startedAtMs);

            if ($durationMs >= TelemetryTrace::slowThresholdMs()) {
                TelemetryTrace::warning('Maestro telemetry heartbeat completed slowly.', $traceContext + [
                    'elapsed_ms' => $durationMs,
                    'stage' => 'completed',
                ]);
            } else {
                TelemetryTrace::info('Maestro telemetry heartbeat completed.', $traceContext + [
                    'elapsed_ms' => $durationMs,
                    'status' => $worker->status,
                ]);
            }

            return response()->json([
                'data' => [
                    'worker_id' => $worker->worker_id,
                    'status' => $worker->status,
                    'last_heartbeat_at' => optional($worker->last_heartbeat_at)?->toISOString(),
                ],
            ], 202);
        } catch (Throwable $exception) {
            TelemetryTrace::error('Maestro telemetry heartbeat failed.', $traceContext + [
                'elapsed_ms' => $this->elapsedMs($startedAtMs),
                'stage' => $stage,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    private function validatedApplication(WorkerHeartbeatRequest $request): MaestroApplication
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

    private function elapsedMs(float $startedAtMs): float
    {
        return round((microtime(true) - $startedAtMs) * 1000, 3);
    }
}
