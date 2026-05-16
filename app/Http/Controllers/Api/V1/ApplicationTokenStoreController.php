<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TelemetryTokenStoreRequest;
use App\Models\MaestroApplication;
use App\Models\MaestroTelemetryToken;
use Illuminate\Http\JsonResponse;

class ApplicationTokenStoreController extends Controller
{
    public function __invoke(TelemetryTokenStoreRequest $request, MaestroApplication $application): JsonResponse
    {
        $validated = $request->validated();
        $plainTextToken = MaestroTelemetryToken::makePlainTextToken();

        MaestroTelemetryToken::query()->create([
            'maestro_application_id' => $application->id,
            'label' => $validated['label'],
            'token_hash' => MaestroTelemetryToken::hashToken($plainTextToken),
        ]);

        $application->load('telemetryTokens');

        return response()->json([
            'data' => [
                'application' => ApplicationPresenter::make($application),
                'plain_text_token' => $plainTextToken,
            ],
        ], 201);
    }
}
