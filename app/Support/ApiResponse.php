<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

final class ApiResponse
{
    public static function success(array $data = [], int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data,
        ], $status);
    }

    public static function accepted(array $data = []): JsonResponse
    {
        return self::success($data, 202);
    }

    public static function error(string $message, int $status = 422, array $context = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'context' => $context,
        ], $status);
    }
}
