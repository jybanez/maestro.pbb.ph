<?php

use App\Http\Controllers\AccountSsoController;
use App\Support\MaestroBootstrap;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/up', function (Request $request) {
    $payload = [
        'status' => 'ok',
        'app' => config('app.name', 'PBB Maestro'),
        'timestamp' => now()->toISOString(),
    ];

    if ($request->expectsJson() || $request->wantsJson()) {
        return response()->json($payload);
    }

    return response()->view('health-up', $payload, 200, [
        'Cache-Control' => 'no-store, no-cache, must-revalidate',
    ]);
});

Route::get('/auth/account/redirect', [AccountSsoController::class, 'redirect'])->name('account.redirect');
Route::get('/auth/account/callback', [AccountSsoController::class, 'callback'])->name('account.callback');
Route::get('/auth/logout', [AccountSsoController::class, 'logout'])->name('account.logout');

Route::get('/', function (Request $request) {
    return view('welcome', [
        'bootstrap' => MaestroBootstrap::build($request),
    ]);
});
