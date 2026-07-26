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

        $token = JWTAuth::claims([
            'tenant_id' => $tenantId,
        ])->fromUser($user);

        return [
            'token' => $token,
            'token_type' => 'bearer',
            'expires_in' => config('jwt.ttl') * 60,
        ];
    }
}