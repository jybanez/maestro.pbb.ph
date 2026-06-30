<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MaestroSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountAdminApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_admin_meta_returns_maestro_roles_and_statuses(): void
    {
        $this->enableAccountAdmin();

        $this->accountAdminRequest()
            ->getJson('/api/account-admin/meta')
            ->assertOk()
            ->assertJsonPath('data.app.id', 'pbb-maestro')
            ->assertJsonPath('data.roles.0.value', 'admin')
            ->assertJsonPath('data.roles.1.value', 'user')
            ->assertJsonPath('data.statuses.0.value', 'active')
            ->assertJsonPath('data.statuses.1.value', 'disabled');
    }

    public function test_account_admin_rejects_wrong_token(): void
    {
        $this->enableAccountAdmin();

        $this->withHeaders([
            'Authorization' => 'Bearer wrong-token',
            'X-PBB-Account-Client' => 'pbb-account',
        ])->getJson('/api/account-admin/meta')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_app_admin_token');
    }

    public function test_account_admin_can_provision_and_update_user_role_and_status(): void
    {
        $this->enableAccountAdmin();
        $pbbUserId = '01KW8MMWBOAPFV1XN2N7N275TS';

        $this->accountAdminRequest()
            ->putJson("/api/account-admin/users/{$pbbUserId}", [
                'name' => 'PBB Test User',
                'email' => 'account-user@example.com',
                'defaultRole' => 'user',
            ])
            ->assertCreated()
            ->assertJsonPath('data.user.pbbUserId', $pbbUserId)
            ->assertJsonPath('data.user.role', 'user')
            ->assertJsonPath('data.user.status', 'active');

        $this->assertDatabaseHas('users', [
            'pbb_user_id' => $pbbUserId,
            'email' => 'account-user@example.com',
            'role' => 'user',
            'status' => 'active',
        ]);

        $this->accountAdminRequest()
            ->patchJson("/api/account-admin/users/{$pbbUserId}/role", [
                'role' => 'admin',
            ])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'admin');

        $this->accountAdminRequest()
            ->patchJson("/api/account-admin/users/{$pbbUserId}/status", [
                'status' => 'disabled',
            ])
            ->assertOk()
            ->assertJsonPath('data.user.status', 'disabled');

        $this->assertDatabaseHas('users', [
            'pbb_user_id' => $pbbUserId,
            'role' => 'admin',
            'status' => 'disabled',
        ]);
    }

    public function test_account_admin_rejects_invalid_role(): void
    {
        $this->enableAccountAdmin();
        $user = User::factory()->create([
            'pbb_user_id' => '01KW8MMWBOAPFV1XN2N7N275TS',
            'role' => 'user',
            'status' => 'active',
        ]);

        $this->accountAdminRequest()
            ->patchJson("/api/account-admin/users/{$user->pbb_user_id}/role", [
                'role' => 'observer',
            ])
            ->assertUnprocessable();
    }

    public function test_disabled_maestro_user_cannot_log_in_locally(): void
    {
        $user = User::factory()->create([
            'email' => 'disabled@example.com',
            'password' => bcrypt('secret-password'),
            'status' => 'disabled',
        ]);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->assertForbidden()
            ->assertJsonPath('message', 'This Maestro account is disabled.');
    }

    private function enableAccountAdmin(): void
    {
        $settings = app(MaestroSettings::class);
        $settings->put('account_admin_api_enabled', true);
        $settings->put('account_admin_api_client', 'pbb-account');
        $settings->put('account_admin_api_token', 'maestro-account-admin-token');
    }

    private function accountAdminRequest(): self
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer maestro-account-admin-token',
            'X-PBB-Account-Client' => 'pbb-account',
        ]);
    }
}
