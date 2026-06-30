<?php

namespace App\Http\Middleware;

use App\Services\MaestroSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyAccountAdminService
{
    public function __construct(private readonly MaestroSettings $settings)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $enabled = filter_var($this->settings->get('account_admin_api_enabled', false), FILTER_VALIDATE_BOOLEAN);
        if (! $enabled) {
            return $this->fail('account_admin_disabled', 'Account admin API is disabled.', 503);
        }

        $configuredClient = trim((string) $this->settings->get('account_admin_api_client', ''));
        $providedClient = trim((string) $request->header('X-PBB-Account-Client'));
        if ($configuredClient === '' || $providedClient !== $configuredClient) {
            return $this->fail('invalid_account_client', 'The Account client header is missing or invalid.', 401);
        }

        $configuredToken = trim((string) $this->settings->get('account_admin_api_token', ''));
        $providedToken = trim((string) $request->bearerToken());
        if ($providedToken === '') {
            $providedToken = trim((string) $request->header('X-PBB-Account-Admin-Token'));
        }

        if ($configuredToken === '' || $providedToken === '' || ! hash_equals($configuredToken, $providedToken)) {
            Log::warning('Maestro account-admin token rejected', [
                'configured_enabled' => $enabled,
                'configured_client' => $configuredClient,
                'provided_client' => $providedClient,
                'configured_token_length' => strlen($configuredToken),
                'configured_token_sha12' => $configuredToken !== '' ? substr(hash('sha256', $configuredToken), 0, 12) : null,
                'provided_token_length' => strlen($providedToken),
                'provided_token_sha12' => $providedToken !== '' ? substr(hash('sha256', $providedToken), 0, 12) : null,
            ]);

            return $this->fail('invalid_app_admin_token', 'The app-admin token is missing or invalid.', 401);
        }

        return $next($request);
    }

    private function fail(string $code, string $message, int $status): Response
    {
        return response()->json([
            'message' => $message,
            'error' => [
                'code' => $code,
            ],
        ], $status, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
