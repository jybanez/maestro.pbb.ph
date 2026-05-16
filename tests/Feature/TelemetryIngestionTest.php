<?php

namespace Tests\Feature;

use App\Models\MaestroApplication;
use App\Models\MaestroTelemetryToken;
use App\Models\MaestroWorker;
use App\Models\MaestroWorkerEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelemetryIngestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_heartbeat_ingestion_upserts_worker_state(): void
    {
        [$application, $token] = $this->makeTelemetryContext();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/telemetry/workers/heartbeat', [
                'app_code' => $application->app_code,
                'worker_id' => 'relay-node-01:18244:2026-03-17T14:30:00Z:9f3a',
                'host_name' => 'relay-node-01',
                'queue_name' => 'relay-deliveries',
                'process_id' => 18244,
                'status' => 'idle',
                'started_at' => now()->subSeconds(10)->toISOString(),
                'last_heartbeat_at' => now()->toISOString(),
                'current_job_type' => null,
                'current_job_id' => null,
                'processed_count' => 128,
                'failed_count' => 3,
                'memory_mb' => 54.7,
                'meta' => [
                    'php_version' => '8.2.29',
                ],
            ]);

        $response->assertAccepted()
            ->assertJsonPath('data.status', 'starting');

        $this->assertDatabaseHas('maestro_workers', [
            'worker_id' => 'relay-node-01:18244:2026-03-17T14:30:00Z:9f3a',
            'processed_count' => 128,
            'failed_count' => 3,
            'queue_name' => 'relay-deliveries',
        ]);
    }

    public function test_worker_events_are_idempotent_by_event_id(): void
    {
        [$application, $token] = $this->makeTelemetryContext();

        MaestroWorker::query()->create([
            'maestro_application_id' => $application->id,
            'worker_id' => 'relay-node-01:18244:2026-03-17T14:30:00Z:9f3a',
            'host_name' => 'relay-node-01',
            'status' => 'idle',
            'started_at' => now()->subMinute(),
            'last_heartbeat_at' => now(),
        ]);

        $payload = [
            'event_id' => 'a6f447b0-79c1-4fe7-8a86-5d312d9b7f48',
            'app_code' => $application->app_code,
            'worker_id' => 'relay-node-01:18244:2026-03-17T14:30:00Z:9f3a',
            'event_type' => 'job.completed',
            'queue_name' => 'relay-deliveries',
            'job_type' => 'ProcessRelayDelivery',
            'job_id' => 'f6ebc8c9-9a22-4f1f-a88b-98eb3f77d812',
            'outcome' => 'success',
            'notes' => 'Delivery job completed successfully.',
            'occurred_at' => now()->toISOString(),
            'payload' => [
                'target_hub_id' => 'city-hub',
            ],
        ];

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/telemetry/worker-events', $payload)
            ->assertAccepted()
            ->assertJsonPath('data.duplicate', false);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/telemetry/worker-events', $payload)
            ->assertOk()
            ->assertJsonPath('data.duplicate', true);

        $this->assertSame(1, MaestroWorkerEvent::query()->count());
    }

    public function test_telemetry_token_must_match_app_code(): void
    {
        [$application, $token] = $this->makeTelemetryContext();
        $otherApp = MaestroApplication::query()->create([
            'app_code' => 'hq',
            'display_name' => 'PBB HQ',
            'environment' => 'local',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/telemetry/workers/heartbeat', [
                'app_code' => $otherApp->app_code,
                'worker_id' => 'worker-1',
                'host_name' => 'relay-node-01',
                'started_at' => now()->subMinute()->toISOString(),
                'last_heartbeat_at' => now()->toISOString(),
                'processed_count' => 0,
                'failed_count' => 0,
            ]);

        $response->assertForbidden();
    }

    private function makeTelemetryContext(): array
    {
        $application = MaestroApplication::query()->create([
            'app_code' => 'relay',
            'display_name' => 'PBB - Hub Relay Server',
            'environment' => 'local',
        ]);

        $plainTextToken = MaestroTelemetryToken::makePlainTextToken();

        MaestroTelemetryToken::query()->create([
            'maestro_application_id' => $application->id,
            'label' => 'Primary relay token',
            'token_hash' => MaestroTelemetryToken::hashToken($plainTextToken),
        ]);

        return [$application, $plainTextToken];
    }
}
