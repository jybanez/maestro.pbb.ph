<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    public function __invoke(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        if (! Auth::attempt($credentials, remember: false)) {
            return ApiResponse::failure('The provided credentials are incorrect.');
        }

        $request->session()->regenerate();

        return ApiResponse::success([
            'account' => $request->user(),
            'csrf_token' => $request->session()->token(),
        ]);
    }
}
