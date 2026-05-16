<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateUserPasswordRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class UpdateUserPasswordController extends Controller
{
    public function __invoke(UpdateUserPasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->forceFill([
            'password' => Hash::make($request->validated('new_password')),
        ])->save();

        return ApiResponse::success([
            'account' => $user->fresh(),
            'csrf_token' => $request->session()->token(),
        ]);
    }
}
