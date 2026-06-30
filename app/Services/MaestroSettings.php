<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MaestroSettings
{
    public const ACCOUNT_SSO_DEFAULTS = [
        'account_sso_enabled' => false,
        'account_sso_base_url' => 'https://account.pbb.ph',
        'account_sso_client_id' => 'pbb-maestro',
        'account_sso_client_secret' => '',
        'account_sso_redirect_uri' => 'https://maestro.pbb.ph/auth/account/callback',
        'account_sso_post_logout_redirect_uri' => 'https://maestro.pbb.ph',
        'account_sso_scopes' => 'openid profile',
        'account_sso_timeout_seconds' => 10,
        'account_sso_ca_bundle' => '',
    ];

    public function get(string $key, mixed $fallback = null): mixed
    {
        if (! Schema::hasTable('maestro_settings')) {
            return $fallback;
        }

        $raw = DB::table('maestro_settings')->where('key', $key)->value('value');

        if ($raw === null || $raw === '') {
            return $fallback;
        }

        $decoded = json_decode((string) $raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $fallback;
        }

        return is_array($decoded) && array_key_exists('value', $decoded)
            ? $decoded['value']
            : $decoded;
    }

    public function put(string $key, mixed $value): void
    {
        if (! Schema::hasTable('maestro_settings')) {
            return;
        }

        $payload = [
            'value' => json_encode(['value' => $value]),
            'updated_at' => now(),
        ];

        $exists = DB::table('maestro_settings')->where('key', $key)->exists();
        if (! $exists) {
            $payload['key'] = $key;
            $payload['created_at'] = now();
            DB::table('maestro_settings')->insert($payload);

            return;
        }

        DB::table('maestro_settings')->where('key', $key)->update($payload);
    }

    public function accountAdminPayload(): array
    {
        $token = trim((string) $this->get('account_admin_api_token', ''));

        return [
            'enabled' => filter_var($this->get('account_admin_api_enabled', false), FILTER_VALIDATE_BOOLEAN),
            'client' => trim((string) $this->get('account_admin_api_client', '')),
            'tokenConfigured' => $token !== '',
        ];
    }

    public function accountSsoConfig(): array
    {
        return [
            'enabled' => filter_var($this->get('account_sso_enabled', self::ACCOUNT_SSO_DEFAULTS['account_sso_enabled']), FILTER_VALIDATE_BOOLEAN),
            'base_url' => trim((string) $this->get('account_sso_base_url', self::ACCOUNT_SSO_DEFAULTS['account_sso_base_url'])),
            'client_id' => trim((string) $this->get('account_sso_client_id', self::ACCOUNT_SSO_DEFAULTS['account_sso_client_id'])),
            'client_secret' => trim((string) $this->get('account_sso_client_secret', self::ACCOUNT_SSO_DEFAULTS['account_sso_client_secret'])),
            'redirect_uri' => trim((string) $this->get('account_sso_redirect_uri', self::ACCOUNT_SSO_DEFAULTS['account_sso_redirect_uri'])),
            'post_logout_redirect_uri' => trim((string) $this->get('account_sso_post_logout_redirect_uri', self::ACCOUNT_SSO_DEFAULTS['account_sso_post_logout_redirect_uri'])),
            'scopes' => trim((string) $this->get('account_sso_scopes', self::ACCOUNT_SSO_DEFAULTS['account_sso_scopes'])),
            'timeout_seconds' => max(1, (int) $this->get('account_sso_timeout_seconds', self::ACCOUNT_SSO_DEFAULTS['account_sso_timeout_seconds'])),
            'ca_bundle' => trim((string) $this->get('account_sso_ca_bundle', self::ACCOUNT_SSO_DEFAULTS['account_sso_ca_bundle'])),
        ];
    }

    public function accountSsoPayload(): array
    {
        $config = $this->accountSsoConfig();

        return [
            'enabled' => (bool) $config['enabled'],
            'baseUrl' => $config['base_url'],
            'clientId' => $config['client_id'],
            'redirectUri' => $config['redirect_uri'],
            'postLogoutRedirectUri' => $config['post_logout_redirect_uri'],
            'scopes' => $config['scopes'],
            'timeoutSeconds' => $config['timeout_seconds'],
            'caBundle' => $config['ca_bundle'],
            'clientSecretConfigured' => $config['client_secret'] !== '',
        ];
    }
}
