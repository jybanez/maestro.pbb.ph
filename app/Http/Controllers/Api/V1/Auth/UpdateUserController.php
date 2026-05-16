<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateUserRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class UpdateUserController extends Controller
{
    public function __invoke(UpdateUserRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->fill($request->validated());
        $user->save();

        return ApiResponse::success([
            'account' => $user->fresh(),
            'csrf_token' => $request->session()->token(),
        ]);
    }
}
