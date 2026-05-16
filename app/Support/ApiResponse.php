<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    public static function success(array $data = [], ?array $meta = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'status' => true,
            'data' => $data,
            'meta' => $meta,
            'error' => null,
        ], $status);
    }

    public static function failure(string $message, int $status = 422, ?array $errors = null, ?array $meta = null): JsonResponse
    {
        return response()->json([
            'status' => false,
            'data' => null,
            'meta' => $meta,
            'error' => array_filter([
                'message' => $message,
                'errors' => $errors,
            ], static fn ($value) => $value !== null),
            'message' => $message,
            'errors' => $errors,
        ], $status);
    }
}
