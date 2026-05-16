<?php

return [
    'status' => [
        'starting_threshold_seconds' => (int) env('MAESTRO_STARTING_THRESHOLD_SECONDS', 15),
        'stale_threshold_seconds' => (int) env('MAESTRO_STALE_THRESHOLD_SECONDS', 45),
    ],

    'telemetry' => [
        'clock_skew_threshold_seconds' => (int) env('MAESTRO_CLOCK_SKEW_THRESHOLD_SECONDS', 60),
        'token_header' => env('MAESTRO_TELEMETRY_TOKEN_HEADER', 'X-Telemetry-Token'),
        'token_last_used_at_update_interval_seconds' => (int) env('MAESTRO_TELEMETRY_TOKEN_LAST_USED_AT_UPDATE_INTERVAL_SECONDS', 60),
        'trace_enabled' => (bool) env('MAESTRO_TELEMETRY_TRACE', false),
        'slow_request_threshold_ms' => (int) env('MAESTRO_TELEMETRY_SLOW_REQUEST_THRESHOLD_MS', 1000),
    ],
];
