<?php

namespace Tests\Unit;

use App\Http\Middleware\ApiAuthenticationMiddleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class ApiAuthenticationMiddlewareTest extends TestCase
{
    public function test_azurion_client_id_authenticates_with_v2_without_sending_secret(): void
    {
        Cache::flush();
        config()->set('facturador.auth_disabled', false);
        config()->set('facturador.integrations.azurion.inbound_client_id', 'azurion-core');
        config()->set('facturador.integrations.azurion.inbound_client_secret', 'server-only-secret');
        config()->set('facturador.integrations.azurion.inbound_previous_secret', '');

        $request = $this->signedRequest('tenant-a', 'nonce-client-v2', 'server-only-secret');
        $response = app(ApiAuthenticationMiddleware::class)->handle(
            $request,
            static fn (Request $verified) => response()->json([
                'auth_mode' => $verified->attributes->get('auth_mode'),
                'client_id' => $verified->attributes->get('integration_client_id'),
            ]),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('azurion_integration', $response->getData(true)['auth_mode']);
        self::assertSame('azurion-core', $response->getData(true)['client_id']);
        self::assertNull($request->header('X-API-Key'));
    }

    public function test_changing_tenant_after_signing_is_rejected(): void
    {
        Cache::flush();
        config()->set('facturador.auth_disabled', false);
        config()->set('facturador.integrations.azurion.inbound_client_id', 'azurion-core');
        config()->set('facturador.integrations.azurion.inbound_client_secret', 'server-only-secret');

        $request = $this->signedRequest('tenant-a', 'nonce-client-tampered', 'server-only-secret');
        $request->headers->set('X-Azurion-Tenant-ID', 'tenant-b');
        $response = app(ApiAuthenticationMiddleware::class)->handle(
            $request,
            static fn () => response()->json(['unexpected' => true]),
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Invalid HMAC signature.', $response->getData(true)['message']);
    }

    private function signedRequest(string $tenantId, string $nonce, string $secret): Request
    {
        $uri = '/api/tickets';
        $method = 'POST';
        $timestamp = (string) now()->timestamp;
        $tenantRuc = '20111111111';
        $body = '{"external_id":"sale-1"}';
        $canonical = implode("\n", [
            'v2',
            $method,
            $uri,
            $timestamp,
            $nonce,
            $tenantId,
            $tenantRuc,
            hash('sha256', $body),
        ]);
        $signature = base64_encode(hash_hmac('sha256', $canonical, $secret, true));

        return Request::create(
            $uri,
            $method,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CLIENT_ID' => 'azurion-core',
                'HTTP_X_SIGNATURE_VERSION' => 'v2',
                'HTTP_X_TIMESTAMP' => $timestamp,
                'HTTP_X_NONCE' => $nonce,
                'HTTP_X_SIGNATURE' => $signature,
                'HTTP_X_AZURION_TENANT_ID' => $tenantId,
                'HTTP_X_TENANT_RUC' => $tenantRuc,
            ],
            $body,
        );
    }
}
