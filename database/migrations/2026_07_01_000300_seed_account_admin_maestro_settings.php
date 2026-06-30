<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $enabled = filter_var(env('PBB_MAESTRO_ACCOUNT_ADMIN_API_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
        $client = trim((string) env('PBB_MAESTRO_ACCOUNT_ADMIN_API_CLIENT', 'pbb-account'));
        $token = trim((string) env('PBB_MAESTRO_ACCOUNT_ADMIN_API_TOKEN', ''));

        $this->insertIfMissing('account_admin_api_enabled', $enabled);
        $this->insertIfMissing('account_admin_api_client', $client !== '' ? $client : 'pbb-account');

        if ($token !== '') {
            $this->insertIfMissing('account_admin_api_token', $token);
        }
    }

    public function down(): void
    {
        DB::table('maestro_settings')
            ->whereIn('key', [
                'account_admin_api_enabled',
                'account_admin_api_client',
                'account_admin_api_token',
            ])
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
