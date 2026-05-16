<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionPingController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'csrf_token' => $request->session()->token(),
            'touched_at' => now()->toISOString(),
        ]);
    }
}
