<?php

use App\Services\MaestroSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $values = [
            'account_sso_enabled' => filter_var(env('PBB_ACCOUNT_SSO_ENABLED', MaestroSettings::ACCOUNT_SSO_DEFAULTS['account_sso_enabled']), FILTER_VALIDATE_BOOLEAN),
            'account_sso_base_url' => env('PBB_ACCOUNT_BASE_URL', MaestroSettings::ACCOUNT_SSO_DEFAULTS['account_sso_base_url']),
            'account_sso_client_id' => env('PBB_ACCOUNT_CLIENT_ID', MaestroSettings::ACCOUNT_SSO_DEFAULTS['account_sso_client_id']),
            'account_sso_client_secret' => env('PBB_ACCOUNT_CLIENT_SECRET', MaestroSettings::ACCOUNT_SSO_DEFAULTS['account_sso_client_secret']),
            'account_sso_redirect_uri' => env('PBB_ACCOUNT_REDIRECT_URI', MaestroSettings::ACCOUNT_SSO_DEFAULTS['account_sso_redirect_uri']),
            'account_sso_post_logout_redirect_uri' => env('PBB_ACCOUNT_POST_LOGOUT_REDIRECT_URI', MaestroSettings::ACCOUNT_SSO_DEFAULTS['account_sso_post_logout_redirect_uri']),
            'account_sso_scopes' => env('PBB_ACCOUNT_SCOPES', MaestroSettings::ACCOUNT_SSO_DEFAULTS['account_sso_scopes']),
            'account_sso_timeout_seconds' => (int) env('PBB_ACCOUNT_TIMEOUT_SECONDS', MaestroSettings::ACCOUNT_SSO_DEFAULTS['account_sso_timeout_seconds']),
            'account_sso_ca_bundle' => env('PBB_ACCOUNT_CA_BUNDLE', env('PBB_CA_BUNDLE', MaestroSettings::ACCOUNT_SSO_DEFAULTS['account_sso_ca_bundle'])),
        ];

        foreach ($values as $key => $value) {
            $this->insertIfMissing($key, $value);
        }
    }

    public function down(): void
    {
        DB::table('maestro_settings')
            ->whereIn('key', array_keys(MaestroSettings::ACCOUNT_SSO_DEFAULTS))
            ->delete();
    }

    private function insertIfMissing(string $key, mixed $value): void
    {
        if (DB::table('maestro_settings')->where('key', $key)->exists()) {
            return;
        }

        DB::table('maestro_settings')->insert([
            'key' => $key,
            'value' => json_encode(['value' => $value]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
