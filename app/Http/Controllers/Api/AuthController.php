<?php

namespace App\Http\Controllers\Api;

use App\Application\Auth\UseCases\LoginUseCase;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuthController
{
    public function __construct(private readonly LoginUseCase $loginUseCase)
    {
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'tenant_id' => ['required', 'integer'],
        ]);

        try {
            return ApiResponse::success($this->loginUseCase->execute(
                email: $data['email'],
                password: $data['password'],
                tenantId: (int) $data['tenant_id'],
            ));
        } catch (\RuntimeException $exception) {
            return ApiResponse::error($exception->getMessage(), 401);
        }
    }
}
