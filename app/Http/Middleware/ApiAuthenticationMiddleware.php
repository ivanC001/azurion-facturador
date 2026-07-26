<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use App\Security\ApiHmacRequestVerifier;
use Closure;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\HttpFoundation\Response;

final class ApiAuthenticationMiddleware
{
    public function __construct(private readonly ApiHmacRequestVerifier $hmacRequestVerifier)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ((bool) config('facturador.auth_disabled', false)) {
            return $next($request);
        }

        $apiKey = $request->header('X-API-Key');

        if ($apiKey !== null && $apiKey !== '') {
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

            return $next($request);
        } catch (\Throwable) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required.',
            ], 401);
        }
    }
}
