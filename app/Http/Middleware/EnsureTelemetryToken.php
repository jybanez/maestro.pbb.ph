<?php

namespace App\Http\Middleware;

use App\Models\MaestroTelemetryToken;
use App\Support\TelemetryTrace;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EnsureTelemetryToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);
        $traceId = $request->attributes->get('maestroTelemetryTraceId') ?: (string) str()->uuid();
        $request->attributes->set('maestroTelemetryTraceId', $traceId);

        $context = [
            'trace_id' => $traceId,
            'path' => $request->path(),
            'worker_id' => $request->input('worker_id'),
            'app_code' => $request->input('app_code'),
            'event_id' => $request->input('event_id'),
            'event_type' => $request->input('event_type'),
        ];

        TelemetryTrace::info('Maestro telemetry token middleware started.', $context + [
            'bootstrap_elapsed_ms' => $this->bootstrapElapsedMs(),
            'middleware_elapsed_ms' => 0.0,
        ]);

        try {
            $plainTextToken = $request->bearerToken() ?: $request->header(config('maestro.telemetry.token_header'));

            if (blank($plainTextToken)) {
                TelemetryTrace::warning('Maestro telemetry token missing.', $context + [
                    'middleware_elapsed_ms' => $this->elapsedMs($startedAt),
                ]);

                return $this->unauthorized('Telemetry token is required.');
            }

            TelemetryTrace::info('Maestro telemetry token lookup started.', $context + [
                'middleware_elapsed_ms' => $this->elapsedMs($startedAt),
            ]);

            $token = MaestroTelemetryToken::query()
                ->with('application')
                ->where('token_hash', MaestroTelemetryToken::hashToken($plainTextToken))
                ->whereNull('revoked_at')
                ->first();

            if ($token === null || $token->application === null || ! $token->application->is_active) {
                TelemetryTrace::warning('Maestro telemetry token invalid.', $context + [
                    'middleware_elapsed_ms' => $this->elapsedMs($startedAt),
                ]);

                return $this->unauthorized('Telemetry token is invalid.');
            }

            $this->refreshLastUsedAt($token, $context, $startedAt);

            $request->attributes->set('maestroTelemetryToken', $token);
            $request->attributes->set('maestroTelemetryApplication', $token->application);

            $response = $next($request);

            TelemetryTrace::info('Maestro telemetry token middleware completed.', $context + [
                'middleware_elapsed_ms' => $this->elapsedMs($startedAt),
                'application_id' => $token->application->id,
                'token_id' => $token->id,
                'status_code' => $response->getStatusCode(),
            ]);

            return $response;
        } catch (Throwable $exception) {
            TelemetryTrace::error('Maestro telemetry token middleware failed.', $context + [
                'middleware_elapsed_ms' => $this->elapsedMs($startedAt),
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    private function unauthorized(string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
        ], 401);
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

    private function refreshLastUsedAt(MaestroTelemetryToken $token, array $context, float $startedAt): void
    {
        $intervalSeconds = max(0, (int) config('maestro.telemetry.token_last_used_at_update_interval_seconds', 60));
        $now = now();

        if (! $this->shouldRefreshLastUsedAt($token->last_used_at, $now, $intervalSeconds)) {
            return;
        }

        TelemetryTrace::info('Maestro telemetry token updating last_used_at.', $context + [
            'middleware_elapsed_ms' => $this->elapsedMs($startedAt),
            'application_id' => $token->application->id,
            'token_id' => $token->id,
            'update_interval_seconds' => $intervalSeconds,
        ]);

        $token->forceFill(['last_used_at' => $now])->save();
    }

    private function shouldRefreshLastUsedAt(?Carbon $lastUsedAt, Carbon $now, int $intervalSeconds): bool
    {
        if ($lastUsedAt === null) {
            return true;
        }

        if ($intervalSeconds === 0) {
            return true;
        }

        return $lastUsedAt->diffInSeconds($now) >= $intervalSeconds;
    }
}
