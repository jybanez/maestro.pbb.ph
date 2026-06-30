<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MaestroSettings
{
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
}
