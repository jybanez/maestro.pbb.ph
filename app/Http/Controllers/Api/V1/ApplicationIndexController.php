<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MaestroApplication;
use Illuminate\Http\JsonResponse;

class ApplicationIndexController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $applications = MaestroApplication::query()
            ->with(['workers', 'telemetryTokens'])
            ->orderBy('display_name')
            ->get()
            ->map(fn (MaestroApplication $application): array => ApplicationPresenter::make($application))
            ->values();

        return response()->json([
            'data' => $applications,
        ]);
    }
}
