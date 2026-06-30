<?php

namespace App\Support;

use App\Services\Account\AccountClientFactory;
use App\Services\MaestroSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;

class MaestroBootstrap
{
    public static function build(Request $request): array
    {
        $user = $request->user();
        $settings = app(MaestroSettings::class);
        $accountAdmin = $settings->accountAdminPayload();
        $accountSso = $settings->accountSsoPayload();
        $accountSsoEnabled = (bool) $accountSso['enabled'];
        $accountSsoReady = false;

        if ($accountSsoEnabled) {
            $accountSsoReady = Cache::remember('pbb_account_ready', 30, function () use ($request): bool {
                return app(AccountClientFactory::class)->make($request)->isReady();
            });
        }

        return [
            'app' => [
                'name' => config('app.name', 'PBB Maestro'),
                'page' => 'dashboard',
                'theme' => 'helpers-dark',
                'helpers' => [
                    'status' => 'vendored',
                ],
                'accountSso' => [
                    'enabled' => $accountSsoEnabled,
                    'ready' => $accountSsoReady,
                    'loginUrl' => route('account.redirect'),
                    'logoutUrl' => route('account.logout'),
                    'baseUrl' => $accountSso['baseUrl'],
                    'clientId' => $accountSso['clientId'],
                ],
            ],
            'auth' => [
                'authenticated' => $user !== null,
                'accountSso' => [
                    'success' => (bool) $request->session()->pull('account_login_success', false),
                    'error' => $request->session()->pull('account_login_error'),
                ],
                'account' => $user ? [
                    'id' => $user->id,
                    'pbbUserId' => $user->pbb_user_id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'status' => $user->status,
                ] : null,
                'accountAdmin' => $user?->role === 'admin' ? $accountAdmin : null,
                'settings' => $user?->role === 'admin' ? [
                    'accountSso' => $accountSso,
                    'accountAdmin' => $accountAdmin,
                ] : null,
            ],
            'security' => [
                'csrfToken' => $request->session()->token(),
                'sessionLifetimeSeconds' => (int) config('session.lifetime', 120) * 60,
            ],
            'routes' => [
                'bootstrap' => route('api.bootstrap'),
                'csrfToken' => route('api.csrf-token'),
                'login' => route('api.login'),
                'logout' => route('api.logout'),
                'user' => route('api.user'),
                'userUpdate' => route('api.user.update'),
                'userPassword' => route('api.user.password'),
                'sessionPing' => route('api.session.ping'),
                'accountIntegrationSettings' => route('api.settings.account-integration'),
                'applications' => url('/api/v1/applications'),
                'applicationTokensBase' => url('/api/v1/applications'),
                'workers' => url('/api/v1/workers'),
                'workerEvents' => url('/api/v1/worker-events'),
            ],
        ];
    }
}
