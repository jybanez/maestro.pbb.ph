<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrentUserController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'account' => $request->user(),
            'csrf_token' => $request->session()->token(),
        ]);
    }
}
