<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\MaestroApplication;
use App\Services\Maestro\WorkerStatusResolver;

class ApplicationPresenter
{
    public static function make(MaestroApplication $application): array
    {
        $statusResolver = app(WorkerStatusResolver::class);
        $workers = $application->workers ?? $application->workers()->get();
        $tokens = $application->telemetryTokens ?? $application->telemetryTokens()->latest()->get();
        $computedStatuses = $workers->map(fn ($worker) => $statusResolver->resolveForWorker($worker));
        $lastSeen = $workers->max('last_heartbeat_at');
        $activeTokens = $tokens->filter(fn ($token) => $token->revoked_at === null);
        $lastTokenUsedAt = $activeTokens->max('last_used_at');

        return [
            'app_code' => $application->app_code,
            'display_name' => $application->display_name,
            'environment' => $application->environment,
            'base_url' => $application->base_url,
            'is_active' => $application->is_active,
            'workers_count' => $workers->count(),
            'busy_workers_count' => $computedStatuses->filter(fn ($status) => $status === 'busy')->count(),
            'stale_workers_count' => $computedStatuses->filter(fn ($status) => $status === 'stale')->count(),
            'last_seen_at' => optional($lastSeen)?->toISOString(),
            'telemetry_tokens_count' => $tokens->count(),
            'active_telemetry_tokens_count' => $activeTokens->count(),
            'last_token_used_at' => optional($lastTokenUsedAt)?->toISOString(),
            'telemetry_tokens' => $tokens
                ->sortByDesc('created_at')
                ->values()
                ->map(fn ($token) => [
                    'id' => $token->id,
                    'label' => $token->label,
                    'last_used_at' => optional($token->last_used_at)?->toISOString(),
                    'revoked_at' => optional($token->revoked_at)?->toISOString(),
                    'created_at' => optional($token->created_at)?->toISOString(),
                ])->all(),
        ];
    }
}
