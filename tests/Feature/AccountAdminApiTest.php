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
            ->assertJsonPath('data.statuses.1.value', 'disabled')
            ->assertJsonPath('data.capabilities.removeUser', true);
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

    public function test_account_admin_can_remove_account_access_idempotently(): void
    {
        $this->enableAccountAdmin();
        $user = User::factory()->create([
            'pbb_user_id' => '01KW8MMWBOAPFV1XN2N7N275TS',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->accountAdminRequest()
            ->deleteJson("/api/account-admin/users/{$user->pbb_user_id}", [
                'reason' => 'Removed from Account.',
            ])
            ->assertOk()
            ->assertJsonPath('data.removed', true)
            ->assertJsonPath('data.user.pbbUserId', null)
            ->assertJsonPath('data.user.status', 'disabled');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'pbb_user_id' => null,
            'status' => 'disabled',
        ]);

        $this->accountAdminRequest()
            ->deleteJson('/api/account-admin/users/01KW8MMWBOAPFV1XN2N7N275TS')
            ->assertOk()
            ->assertJsonPath('data.removed', true)
            ->assertJsonPath('data.user', null);
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

    public function test_admin_can_view_and_update_account_integration_settings(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/settings/account-integration')
            ->assertOk()
            ->assertJsonPath('data.accountSso.clientId', 'pbb-maestro')
            ->assertJsonPath('data.accountAdmin.client', 'pbb-account');

        $response = $this->actingAs($admin)
            ->postJson('/api/settings/account-integration', [
                'account_sso_enabled' => true,
                'account_sso_base_url' => 'https://account.pbb.ph',
                'account_sso_client_id' => 'pbb-maestro',
                'account_sso_client_secret' => 'oauth-secret',
                'account_sso_redirect_uri' => 'https://maestro.pbb.ph/auth/account/callback',
                'account_sso_post_logout_redirect_uri' => 'https://maestro.pbb.ph',
                'account_sso_scopes' => 'openid profile',
                'account_sso_timeout_seconds' => 10,
                'account_sso_ca_bundle' => 'C:\\wamp64\\certs\\pbb.ph\\pbb.ph.fullchain.crt',
                'account_admin_api_enabled' => true,
                'account_admin_api_client' => 'pbb-account',
                'account_admin_api_token' => '',
                'rotate_account_admin_api_token' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.accountSso.enabled', true)
            ->assertJsonPath('data.accountSso.clientSecretConfigured', true)
            ->assertJsonPath('data.accountAdmin.enabled', true)
            ->assertJsonPath('data.accountAdmin.tokenConfigured', true);

        $this->assertIsString($response->json('data.rotatedAccountAdminApiToken'));
        $this->assertDatabaseHas('maestro_settings', [
            'key' => 'account_sso_client_secret',
        ]);
        $this->assertDatabaseHas('maestro_settings', [
            'key' => 'account_admin_api_token',
        ]);
    }

    public function test_non_admin_cannot_update_account_integration_settings(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->postJson('/api/settings/account-integration', [])
            ->assertForbidden()
            ->assertJsonPath('message', 'Only Maestro admins can update Account integration settings.');
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
