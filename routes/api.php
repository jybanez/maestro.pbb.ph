<?php

use App\Http\Controllers\Api\BootstrapController;
use App\Http\Controllers\Api\V1\ApplicationIndexController;
use App\Http\Controllers\Api\V1\ApplicationStoreController;
use App\Http\Controllers\Api\V1\ApplicationTokenStoreController;
use App\Http\Controllers\Api\V1\Auth\CurrentUserController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\SessionPingController;
use App\Http\Controllers\Api\V1\Auth\UpdateUserController;
use App\Http\Controllers\Api\V1\Auth\UpdateUserPasswordController;
use App\Http\Controllers\Api\V1\Telemetry\WorkerEventController;
use App\Http\Controllers\Api\V1\Telemetry\WorkerHeartbeatController;
use App\Http\Controllers\Api\V1\WorkerEventIndexController;
use App\Http\Controllers\Api\V1\WorkerIndexController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function (): void {
    Route::get('/bootstrap', BootstrapController::class)->name('api.bootstrap');

    Route::get('/csrf-token', function (Request $request) {
        $request->session()->regenerateToken();

        return response()->json([
            'data' => [
                'csrf_token' => $request->session()->token(),
            ],
        ]);
    })->name('api.csrf-token');

    Route::post('/login', LoginController::class)->name('api.login');

    Route::middleware('auth')->group(function (): void {
        Route::get('/user', CurrentUserController::class)->name('api.user');
        Route::post('/user', UpdateUserController::class)->name('api.user.update');
        Route::post('/user/password', UpdateUserPasswordController::class)->name('api.user.password');
        Route::get('/session/ping', SessionPingController::class)->name('api.session.ping');
        Route::post('/logout', LogoutController::class)->name('api.logout');
    });
});

Route::prefix('v1')->group(function (): void {
    Route::middleware(['web', 'auth'])->group(function (): void {
        Route::get('/applications', ApplicationIndexController::class);
        Route::post('/applications', ApplicationStoreController::class);
        Route::post('/applications/{application:app_code}/tokens', ApplicationTokenStoreController::class);
        Route::get('/workers', WorkerIndexController::class);
        Route::get('/worker-events', WorkerEventIndexController::class);
    });

    Route::prefix('telemetry')
        ->middleware(['telemetry.trace', 'telemetry.token'])
        ->group(function (): void {
            Route::post('/workers/heartbeat', WorkerHeartbeatController::class);
            Route::post('/worker-events', WorkerEventController::class);
        });
});
