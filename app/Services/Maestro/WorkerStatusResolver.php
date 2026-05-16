<?php

namespace App\Services\Maestro;

use App\Models\MaestroWorker;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class WorkerStatusResolver
{
    public function resolve(
        ?CarbonInterface $startedAt,
        ?CarbonInterface $lastHeartbeatAt,
        ?CarbonInterface $stoppedAt,
        ?string $currentJobId,
        ?CarbonInterface $now = null,
    ): string {
        $now = CarbonImmutable::instance($now ?? now());

        if ($stoppedAt !== null) {
            return 'stopped';
        }

        if ($lastHeartbeatAt === null) {
            return $this->isRecentlyStarted($startedAt, $now) ? 'starting' : 'stale';
        }

        if ($this->isStale($lastHeartbeatAt, $now)) {
            return 'stale';
        }

        if ($this->isRecentlyStarted($startedAt, $now) && blank($currentJobId)) {
            return 'starting';
        }

        return filled($currentJobId) ? 'busy' : 'idle';
    }

    public function resolveForWorker(MaestroWorker $worker, ?CarbonInterface $now = null): string
    {
        return $this->resolve(
            $worker->started_at,
            $worker->last_heartbeat_at,
            $worker->stopped_at,
            $worker->current_job_id,
            $now,
        );
    }

    public function isStale(CarbonInterface $lastHeartbeatAt, ?CarbonInterface $now = null): bool
    {
        $now = CarbonImmutable::instance($now ?? now());

        return $lastHeartbeatAt->diffInSeconds($now) > config('maestro.status.stale_threshold_seconds');
    }

    private function isRecentlyStarted(?CarbonInterface $startedAt, CarbonInterface $now): bool
    {
        if ($startedAt === null) {
            return false;
        }

        return $startedAt->diffInSeconds($now) <= config('maestro.status.starting_threshold_seconds');
    }
}
