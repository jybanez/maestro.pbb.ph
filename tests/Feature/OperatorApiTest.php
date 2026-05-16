<?php

namespace Tests\Feature;

use App\Models\MaestroApplication;
use App\Models\MaestroTelemetryToken;
use App\Models\MaestroWorker;
use App\Models\MaestroWorkerEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OperatorApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_endpoint_returns_shell_payload(): void
    {
        $response = $this->getJson('/api/bootstrap');

        $response->assertOk()
            ->assertJsonPath('app.theme', 'helpers-dark')
            ->assertJsonPath('routes.user', url('/api/user'))
            ->assertJsonPath('routes.bootstrap', url('/api/bootstrap'));
    }

    public function test_login_user_and_logout_flow_returns_json(): void
    {
        $user = User::factory()->create([
            'email' => 'operator@example.com',
            'password' => Hash::make('secret-password'),
        ]);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.account.email', $user->email)
            ->assertJsonStructure(['status', 'data' => ['account', 'csrf_token'], 'meta', 'error']);

        $this->actingAs($user)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.account.email', $user->email)
            ->assertJsonStructure(['status', 'data' => ['account', 'csrf_token'], 'meta', 'error']);

        $this->actingAs($user)
            ->postJson('/api/logout')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonStructure(['status', 'data' => ['csrf_token'], 'meta', 'error']);
    }

    public function test_authenticated_user_can_ping_session_keepalive(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/session/ping')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonStructure(['status', 'data' => ['csrf_token', 'touched_at'], 'meta', 'error']);
    }

    public function test_authenticated_user_can_update_account_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Old Name',
            'email' => 'old@example.com',
        ]);

        $this->actingAs($user)
            ->postJson('/api/user', [
                'name' => 'Updated User',
                'email' => 'updated@example.com',
            ])
            ->assertOk()
            ->assertJsonPath('data.account.name', 'Updated User')
            ->assertJsonPath('data.account.email', 'updated@example.com');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated User',
            'email' => 'updated@example.com',
        ]);
    }

    public function test_authenticated_user_can_change_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);

        $this->actingAs($user)
            ->postJson('/api/user/password', [
                'current_password' => 'old-password',
                'new_password' => 'new-password-123',
                'confirm_password' => 'new-password-123',
            ])
            ->assertOk()
            ->assertJsonPath('data.account.id', $user->id);

        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
    }

    public function test_operator_endpoints_require_authenticated_session(): void
    {
        $this->getJson('/api/session/ping')->assertUnauthorized();
        $this->getJson('/api/v1/applications')->assertUnauthorized();
        $this->postJson('/api/v1/applications', [])->assertUnauthorized();
        $this->getJson('/api/v1/workers')->assertUnauthorized();
        $this->getJson('/api/v1/worker-events')->assertUnauthorized();
    }

    public function test_operator_endpoints_return_application_worker_and_event_data(): void
    {
        $user = User::factory()->create();
        $application = MaestroApplication::query()->create([
            'app_code' => 'relay',
            'display_name' => 'PBB - Hub Relay Server',
            'environment' => 'local',
        ]);

        $worker = MaestroWorker::query()->create([
            'maestro_application_id' => $application->id,
            'worker_id' => 'relay-node-01:18244:2026-03-17T14:30:00Z:9f3a',
            'host_name' => 'relay-node-01',
            'queue_name' => 'relay-deliveries',
            'status' => 'idle',
            'started_at' => now()->subMinutes(2),
            'last_heartbeat_at' => now(),
            'processed_count' => 8,
            'failed_count' => 1,
        ]);

        MaestroWorkerEvent::query()->create([
            'maestro_worker_id' => $worker->id,
            'event_id' => 'a6f447b0-79c1-4fe7-8a86-5d312d9b7f48',
            'worker_id' => $worker->worker_id,
            'event_type' => 'job.completed',
            'queue_name' => 'relay-deliveries',
            'job_type' => 'ProcessRelayDelivery',
            'job_id' => 'job-1',
            'outcome' => 'success',
            'occurred_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/applications')
            ->assertOk()
            ->assertJsonPath('data.0.app_code', 'relay')
            ->assertJsonPath('data.0.telemetry_tokens_count', 0);

        $this->actingAs($user)
            ->getJson('/api/v1/workers')
            ->assertOk()
            ->assertJsonPath('data.0.worker_id', $worker->worker_id);

        $this->actingAs($user)
            ->getJson('/api/v1/worker-events')
            ->assertOk()
            ->assertJsonPath('data.0.event_id', 'a6f447b0-79c1-4fe7-8a86-5d312d9b7f48');
    }

    public function test_authenticated_user_can_create_application_with_initial_token(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/applications', [
                'app_code' => 'relay',
                'display_name' => 'PBB Relay',
                'environment' => 'local',
                'base_url' => 'https://relay.pbb.ph',
                'issue_telemetry_token' => true,
                'token_label' => 'Primary relay token',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.application.app_code', 'relay')
            ->assertJsonPath('data.application.telemetry_tokens_count', 1);

        $plainTextToken = $response->json('data.plain_text_token');

        $this->assertNotEmpty($plainTextToken);
        $this->assertDatabaseHas('maestro_applications', [
            'app_code' => 'relay',
            'display_name' => 'PBB Relay',
        ]);
        $this->assertDatabaseHas('maestro_telemetry_tokens', [
            'label' => 'Primary relay token',
            'token_hash' => MaestroTelemetryToken::hashToken($plainTextToken),
        ]);
    }

    public function test_authenticated_user_can_issue_additional_application_token(): void
    {
        $user = User::factory()->create();
        $application = MaestroApplication::query()->create([
            'app_code' => 'relay',
            'display_name' => 'PBB Relay',
            'environment' => 'local',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/v1/applications/{$application->app_code}/tokens", [
                'label' => 'Worker host token',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.application.app_code', 'relay')
            ->assertJsonPath('data.application.telemetry_tokens_count', 1)
            ->assertJsonPath('data.application.telemetry_tokens.0.label', 'Worker host token');

        $this->assertNotEmpty($response->json('data.plain_text_token'));
    }
}
