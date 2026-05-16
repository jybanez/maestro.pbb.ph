<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

class TelemetryTrace
{
    public static function enabled(): bool
    {
        return (bool) config('maestro.telemetry.trace_enabled', false);
    }

    public static function slowThresholdMs(): int
    {
        return max(1, (int) config('maestro.telemetry.slow_request_threshold_ms', 1000));
    }

    public static function info(string $message, array $context = []): void
    {
        if (! self::enabled()) {
            return;
        }

        Log::info($message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        Log::warning($message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        Log::error($message, $context);
    }
}
