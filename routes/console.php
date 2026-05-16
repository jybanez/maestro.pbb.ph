<?php

use App\Services\Maestro\StaleWorkerReconciler;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('maestro:reconcile-stale-workers', function (StaleWorkerReconciler $reconciler) {
    $updated = $reconciler->reconcile();

    $this->info("Reconciled {$updated} worker records.");
})->purpose('Recompute and persist stale worker states.');

Schedule::command('maestro:reconcile-stale-workers')->everyMinute();
