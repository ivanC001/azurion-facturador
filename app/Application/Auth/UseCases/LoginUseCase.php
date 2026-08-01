<?php

namespace App\Application\Auth\UseCases;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

final class LoginUseCase
{
    public function execute(string $email, string $password, int $tenantId): array
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            throw new \RuntimeException('Invalid credentials.');
        }

        if ($user->tenant_id !== null && (int) $user->tenant_id !== $tenantId) {
            throw new \RuntimeException('The user does not belong to the requested tenant.');
        }

        $token = JWTAuth::claims([
            'tenant_id' => $tenantId,
            'facturador_platform_admin' => $user->tenant_id === null,
        ])->fromUser($user);

        return [
            'token' => $token,
            'token_type' => 'bearer',
            'expires_in' => config('jwt.ttl') * 60,
        ];
    }
}
