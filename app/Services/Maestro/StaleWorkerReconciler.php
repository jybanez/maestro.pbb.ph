<?php

namespace App\Services\Maestro;

use App\Models\MaestroWorker;
use App\Support\DatabaseRetry;

class StaleWorkerReconciler
{
    public function __construct(
        private readonly WorkerStatusResolver $statusResolver,
    ) {
    }

    public function reconcile(): int
    {
        $updated = 0;

        MaestroWorker::query()
            ->whereNull('stopped_at')
            ->chunkById(200, function ($workers) use (&$updated): void {
                foreach ($workers as $worker) {
                    $status = $this->statusResolver->resolveForWorker($worker);

                    if ($worker->status !== $status) {
                        DatabaseRetry::run(function () use ($worker, $status): void {
                            $worker->forceFill(['status' => $status])->save();
                        });
                        $updated++;
                    }
                }
            });

        return $updated;
    }
}
