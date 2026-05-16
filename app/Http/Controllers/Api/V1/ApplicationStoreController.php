<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ApplicationStoreRequest;
use App\Models\MaestroApplication;
use App\Models\MaestroTelemetryToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ApplicationStoreController extends Controller
{
    public function __invoke(ApplicationStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();

        [$application, $plainTextToken] = DB::transaction(function () use ($validated) {
            $application = MaestroApplication::query()->create([
                'app_code' => $validated['app_code'],
                'display_name' => $validated['display_name'],
                'environment' => $validated['environment'],
                'base_url' => $validated['base_url'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
            ]);

            $plainTextToken = null;

            if (($validated['issue_telemetry_token'] ?? true) === true) {
                $plainTextToken = MaestroTelemetryToken::makePlainTextToken();

                MaestroTelemetryToken::query()->create([
                    'maestro_application_id' => $application->id,
                    'label' => ($validated['token_label'] ?? null) ?: 'Primary token',
                    'token_hash' => MaestroTelemetryToken::hashToken($plainTextToken),
                ]);
            }

            return [$application, $plainTextToken];
        });

        $application->load('telemetryTokens');

        return response()->json([
            'data' => [
                'application' => ApplicationPresenter::make($application),
                'plain_text_token' => $plainTextToken,
            ],
        ], 201);
    }
}
