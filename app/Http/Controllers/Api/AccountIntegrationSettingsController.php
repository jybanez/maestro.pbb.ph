<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MaestroSettings;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AccountIntegrationSettingsController extends Controller
{
    public function show(Request $request, MaestroSettings $settings): JsonResponse
    {
        if ($forbidden = $this->authorizeAdmin($request)) {
            return $forbidden;
        }

        return ApiResponse::success([
            'accountSso' => $settings->accountSsoPayload(),
            'accountAdmin' => $settings->accountAdminPayload(),
        ]);
    }

    public function update(Request $request, MaestroSettings $settings): JsonResponse
    {
        if ($forbidden = $this->authorizeAdmin($request)) {
            return $forbidden;
        }

        $data = $request->validate([
            'account_sso_enabled' => ['required', 'boolean'],
            'account_sso_base_url' => ['required', 'url', 'max:255'],
            'account_sso_client_id' => ['required', 'string', 'max:120'],
            'account_sso_client_secret' => ['nullable', 'string', 'max:500'],
            'account_sso_redirect_uri' => ['required', 'url', 'max:255'],
            'account_sso_post_logout_redirect_uri' => ['required', 'url', 'max:255'],
            'account_sso_scopes' => ['required', 'string', 'max:120'],
            'account_sso_timeout_seconds' => ['required', 'integer', 'min:1', 'max:120'],
            'account_sso_ca_bundle' => ['nullable', 'string', 'max:500'],
            'account_admin_api_enabled' => ['required', 'boolean'],
            'account_admin_api_client' => ['required', 'string', 'max:120'],
            'account_admin_api_token' => ['nullable', 'string', 'max:500'],
            'rotate_account_admin_api_token' => ['nullable', 'boolean'],
        ]);

        foreach ([
            'account_sso_enabled',
            'account_sso_base_url',
            'account_sso_client_id',
            'account_sso_redirect_uri',
            'account_sso_post_logout_redirect_uri',
            'account_sso_scopes',
            'account_sso_timeout_seconds',
            'account_sso_ca_bundle',
            'account_admin_api_enabled',
            'account_admin_api_client',
        ] as $key) {
            $settings->put($key, $data[$key]);
        }

        $clientSecret = trim((string) ($data['account_sso_client_secret'] ?? ''));
        if ($clientSecret !== '') {
            $settings->put('account_sso_client_secret', $clientSecret);
        }

        $adminToken = trim((string) ($data['account_admin_api_token'] ?? ''));
        $rotatedToken = null;
        if ((bool) ($data['rotate_account_admin_api_token'] ?? false)) {
            $rotatedToken = 'pbb_maestro_admin_'.Str::random(48);
            $settings->put('account_admin_api_token', $rotatedToken);
        } elseif ($adminToken !== '') {
            $settings->put('account_admin_api_token', $adminToken);
        }

        return ApiResponse::success([
            'accountSso' => $settings->accountSsoPayload(),
            'accountAdmin' => $settings->accountAdminPayload(),
            'rotatedAccountAdminApiToken' => $rotatedToken,
        ]);
    }

    private function authorizeAdmin(Request $request): ?JsonResponse
    {
        if ($request->user()?->role === 'admin') {
            return null;
        }

        return ApiResponse::failure('Only Maestro admins can update Account integration settings.', 403);
    }
}
