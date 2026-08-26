<?php

namespace App\Application\Auth\UseCases;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

final class LoginUseCase
{
    /**
     * Hash bcrypt valido pero inalcanzable, usado solo para igualar el coste
     * de un intento contra un correo inexistente.
     */
    private const DUMMY_HASH = '$2y$12$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ak.i';

    public function execute(string $email, string $password, int $tenantId): array
    {
        $user = User::query()->where('email', $email)->first();

        // Se verifica siempre un hash, exista el usuario o no: si no se hiciera,
        // el tiempo de respuesta delataria que correos estan registrados.
        $hash = (string) ($user?->password ?? self::DUMMY_HASH);
        if (! Hash::check($password, $hash) || $user === null) {
            throw new \RuntimeException('Invalid credentials.');
        }

        // Solo un administrador de plataforma puede abrir sesion contra un
        // tenant que no es el suyo; el resto queda atado a su propio tenant.
        if (! $user->isPlatformAdmin() && (int) $user->tenant_id !== $tenantId) {
            throw new \RuntimeException('The user does not belong to the requested tenant.');
        }

        $token = JWTAuth::claims([
            'tenant_id' => $tenantId,
            'facturador_platform_admin' => $user->isPlatformAdmin(),
        ])->fromUser($user);

        return [
            'token' => $token,
            'token_type' => 'bearer',
            'expires_in' => config('jwt.ttl') * 60,
        ];
    }
}
