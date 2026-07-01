<?php

use App\Http\Controllers\Api\BootstrapController;
use App\Http\Controllers\Api\AccountAdminController;
use App\Http\Controllers\Api\AccountIntegrationSettingsController;
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
use App\Http\Middleware\VerifyAccountAdminService;
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
        Route::get('/settings/account-integration', [AccountIntegrationSettingsController::class, 'show'])->name('api.settings.account-integration');
        Route::post('/settings/account-integration', [AccountIntegrationSettingsController::class, 'update'])->name('api.settings.account-integration.update');
    });
});

Route::prefix('account-admin')
    ->middleware([VerifyAccountAdminService::class, 'throttle:120,1'])
    ->group(function (): void {
        Route::get('/meta', [AccountAdminController::class, 'meta']);
        Route::get('/users/{pbbUserId}', [AccountAdminController::class, 'show']);
        Route::put('/users/{pbbUserId}', [AccountAdminController::class, 'provision']);
        Route::delete('/users/{pbbUserId}', [AccountAdminController::class, 'removeAccess']);
        Route::patch('/users/{pbbUserId}/role', [AccountAdminController::class, 'updateRole']);
        Route::patch('/users/{pbbUserId}/status', [AccountAdminController::class, 'updateStatus']);
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
