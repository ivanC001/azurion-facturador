<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use App\Models\User;
use App\Security\ApiHmacRequestVerifier;
use Closure;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\HttpFoundation\Response;

final class ApiAuthenticationMiddleware
{
    public function __construct(private readonly ApiHmacRequestVerifier $hmacRequestVerifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ((bool) config('facturador.auth_disabled', false)) {
            if (! app()->environment(['local', 'testing'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unsafe authentication configuration.',
                ], 503);
            }

            return $next($request);
        }

        $clientIdHeader = (string) config(
            'facturador.integrations.azurion.header_client_id',
            'X-Client-Id',
        );
        $providedClientId = trim((string) $request->header($clientIdHeader, ''));
        $integrationClientId = trim((string) config(
            'facturador.integrations.azurion.inbound_client_id',
            'azurion-core',
        ));

        if ($providedClientId !== '') {
            if (! $this->hasSharedReplayStore()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Shared replay protection is not configured.',
                ], 503);
            }
            if ($integrationClientId === '' || ! hash_equals($integrationClientId, $providedClientId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid integration client.',
                ], 401);
            }

            $secrets = [
                trim((string) config('facturador.integrations.azurion.inbound_client_secret', '')),
                trim((string) config('facturador.integrations.azurion.inbound_previous_secret', '')),
            ];
            $secrets = array_values(array_filter($secrets, static fn (string $secret): bool => $secret !== ''));
            if ($secrets === []) {
                return response()->json([
                    'success' => false,
                    'message' => 'Integration credentials are not configured.',
                ], 503);
            }

            $hmacError = $this->hmacRequestVerifier->verify(
                $request,
                $secrets,
                'azurion:'.$providedClientId,
                true,
            );
            if ($hmacError !== null) {
                return response()->json([
                    'success' => false,
                    'message' => $hmacError,
                ], 401);
            }

            $request->attributes->set('auth_mode', 'azurion_integration');
            $request->attributes->set('integration_client_id', $providedClientId);

            return $next($request);
        }

        $apiKey = $request->header('X-API-Key');

        if ($apiKey !== null && $apiKey !== '') {
            $integrationKey = trim((string) config('facturador.integrations.azurion.inbound_api_key', ''));
            $allowLegacyIntegration = (bool) config(
                'facturador.integrations.azurion.allow_legacy_api_key',
                false,
            );
            if ($allowLegacyIntegration && $integrationKey !== '' && hash_equals($integrationKey, $apiKey)) {
                $hmacError = $this->hmacRequestVerifier->verify($request, $integrationKey, 0);
                if ($hmacError !== null) {
                    return response()->json([
                        'success' => false,
                        'message' => $hmacError,
                    ], 401);
                }

                $request->attributes->set('auth_mode', 'azurion_integration');

                return $next($request);
            }

            $client = ApiClient::query()
                ->with('tenant')
                ->where('api_key_hash', hash('sha256', $apiKey))
                ->where('is_active', true)
                ->first();

            if ($client === null || $client->tenant === null || ! $client->tenant->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid API key.',
                ], 401);
            }

            if ((bool) config('facturador.security.hmac.required_with_api_key', true)) {
                // La proteccion anti-replay se apoya en el nonce cacheado. Con
                // un store por proceso (array o file) cada worker tiene su
                // propia lista y el replay pasa, asi que se exige un store
                // compartido tambien en esta ruta, no solo en la de Azurion.
                if (! $this->hasSharedReplayStore()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Shared replay protection is not configured.',
                    ], 503);
                }

                $hmacError = $this->hmacRequestVerifier->verify($request, $apiKey, (int) $client->id);

                if ($hmacError !== null) {
                    return response()->json([
                        'success' => false,
                        'message' => $hmacError,
                    ], 401);
                }
            }

            $client->forceFill(['last_used_at' => now()])->save();

            $request->attributes->set('auth_mode', 'api_key');
            $request->attributes->set('tenant_id', $client->tenant_id);
            $request->attributes->set('api_client_id', $client->id);

            return $next($request);
        }

        try {
            $user = JWTAuth::parseToken()->authenticate();

            if ($user === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid JWT token.',
                ], 401);
            }

            $payload = JWTAuth::parseToken()->getPayload();

            $request->attributes->set('auth_mode', 'jwt');
            $request->attributes->set('tenant_id', $payload->get('tenant_id'));
            $request->attributes->set('auth_user_id', $user->getAuthIdentifier());
            // El claim solo se acepta si el usuario sigue siendo administrador:
            // asi, revocar el rol invalida de inmediato los tokens ya emitidos.
            $request->attributes->set(
                'facturador_platform_admin',
                $user instanceof User && $user->isPlatformAdmin(),
            );

            return $next($request);
        } catch (\Throwable) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required.',
            ], 401);
        }
    }

    private function hasSharedReplayStore(): bool
    {
        if (! app()->environment('production')) {
            return true;
        }

        return in_array(
            strtolower((string) config('cache.default', '')),
            ['redis', 'database', 'dynamodb', 'memcached'],
            true,
        );
    }
}
