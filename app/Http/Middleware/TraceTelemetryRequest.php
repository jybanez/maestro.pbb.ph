<?php

namespace App\Http\Middleware;

use App\Support\TelemetryTrace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class TraceTelemetryRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $traceId = (string) str()->uuid();
        $startedAt = microtime(true);

        $request->attributes->set('maestroTelemetryTraceId', $traceId);
        $request->attributes->set('maestroTelemetryRouteStartedAt', $startedAt);

        $context = [
            'trace_id' => $traceId,
            'path' => $request->path(),
            'method' => $request->method(),
            'bootstrap_elapsed_ms' => $this->bootstrapElapsedMs(),
            'route_elapsed_ms' => 0.0,
            'worker_id' => $request->input('worker_id'),
            'app_code' => $request->input('app_code'),
            'event_id' => $request->input('event_id'),
            'event_type' => $request->input('event_type'),
        ];

        TelemetryTrace::info('Maestro telemetry route entered.', $context);

        try {
            $response = $next($request);
            $durationMs = $this->elapsedMs($startedAt);

            if ($durationMs >= TelemetryTrace::slowThresholdMs()) {
                TelemetryTrace::warning('Maestro telemetry route completed slowly.', $context + [
                    'route_elapsed_ms' => $durationMs,
                    'status_code' => $response->getStatusCode(),
                ]);
            } else {
                TelemetryTrace::info('Maestro telemetry route completed.', $context + [
                    'route_elapsed_ms' => $durationMs,
                    'status_code' => $response->getStatusCode(),
                ]);
            }

            return $response;
        } catch (Throwable $exception) {
            TelemetryTrace::error('Maestro telemetry route failed.', $context + [
                'route_elapsed_ms' => $this->elapsedMs($startedAt),
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    private function elapsedMs(float $startedAt): float
    {
        return round((microtime(true) - $startedAt) * 1000, 3);
    }

    private function bootstrapElapsedMs(): float
    {
        if (! defined('LARAVEL_START')) {
            return 0.0;
        }

        return round((microtime(true) - LARAVEL_START) * 1000, 3);
    }
}
