<?php

namespace Tests\Unit;

use App\Security\ApiHmacRequestVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class ApiHmacRequestVerifierTest extends TestCase
{
    public function test_invalid_signature_does_not_consume_nonce_and_replay_is_rejected_after_success(): void
    {
        Cache::flush();
        $secret = 'integration-secret-with-enough-entropy';
        $timestamp = (string) now()->timestamp;
        $nonce = 'nonce-12345678';
        $body = '{"active":true}';

        $invalid = $this->request($timestamp, $nonce, 'invalid-signature', $body);
        $verifier = app(ApiHmacRequestVerifier::class);

        self::assertSame('Invalid HMAC signature.', $verifier->verify($invalid, $secret, 7));

        $signature = $this->signature('PUT', '/api/integrations/azurion/tenants/acme', $timestamp, $nonce, $body, $secret);
        $valid = $this->request($timestamp, $nonce, $signature, $body);

        self::assertNull($verifier->verify($valid, $secret, 7));
        self::assertSame(
            'Replay detected: nonce already used.',
            $verifier->verify($valid, $secret, 7),
        );
    }

    public function test_v2_signature_binds_request_to_tenant_and_supports_secret_rotation(): void
    {
        Cache::flush();
        $currentSecret = 'current-integration-secret-with-enough-entropy';
        $previousSecret = 'previous-integration-secret-with-enough-entropy';
        $timestamp = (string) now()->timestamp;
        $body = '{"external_id":"sale-1"}';
        $tenantId = 'tenant-a';
        $tenantRuc = '20111111111';

        $previousSignature = $this->signature(
            'POST',
            '/api/tickets',
            $timestamp,
            'nonce-v2-previous',
            $body,
            $previousSecret,
            'v2',
            $tenantId,
            $tenantRuc,
        );
        $rotatingRequest = $this->request(
            $timestamp,
            'nonce-v2-previous',
            $previousSignature,
            $body,
            'v2',
            $tenantId,
            $tenantRuc,
            '/api/tickets',
            'POST',
        );

        $verifier = app(ApiHmacRequestVerifier::class);
        self::assertNull($verifier->verify(
            $rotatingRequest,
            [$currentSecret, $previousSecret],
            'azurion-core',
            true,
        ));

        $tamperedTenant = $this->request(
            $timestamp,
            'nonce-v2-tampered',
            $this->signature(
                'POST',
                '/api/tickets',
                $timestamp,
                'nonce-v2-tampered',
                $body,
                $currentSecret,
                'v2',
                $tenantId,
                $tenantRuc,
            ),
            $body,
            'v2',
            'tenant-b',
            $tenantRuc,
            '/api/tickets',
            'POST',
        );

        self::assertSame(
            'Invalid HMAC signature.',
            $verifier->verify($tamperedTenant, $currentSecret, 'azurion-core', true),
        );
    }

    public function test_v2_requires_signed_tenant_identity(): void
    {
        Cache::flush();
        $secret = 'integration-secret-with-enough-entropy';
        $timestamp = (string) now()->timestamp;
        $body = '{}';
        $signature = $this->signature(
            'POST',
            '/api/tickets',
            $timestamp,
            'nonce-v2-no-tenant',
            $body,
            $secret,
            'v2',
            '',
            '',
        );

        $request = $this->request(
            $timestamp,
            'nonce-v2-no-tenant',
            $signature,
            $body,
            'v2',
            '',
            '',
            '/api/tickets',
            'POST',
        );

        self::assertSame(
            'Missing signed tenant identity.',
            app(ApiHmacRequestVerifier::class)->verify($request, $secret, 'azurion-core', true),
        );
    }

    private function request(
        string $timestamp,
        string $nonce,
        string $signature,
        string $body,
        string $version = 'v1',
        string $tenantId = '',
        string $tenantRuc = '',
        string $uri = '/api/integrations/azurion/tenants/acme',
        string $method = 'PUT',
    ): Request {
        return Request::create(
            $uri,
            $method,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_TIMESTAMP' => $timestamp,
                'HTTP_X_NONCE' => $nonce,
                'HTTP_X_SIGNATURE' => $signature,
                'HTTP_X_SIGNATURE_VERSION' => $version,
                'HTTP_X_AZURION_TENANT_ID' => $tenantId,
                'HTTP_X_TENANT_RUC' => $tenantRuc,
            ],
            $body,
        );
    }

    private function signature(
        string $method,
        string $uri,
        string $timestamp,
        string $nonce,
        string $body,
        string $secret,
        string $version = 'v1',
        string $tenantId = '',
        string $tenantRuc = '',
    ): string {
        $canonical = $version === 'v2'
            ? implode("\n", [
                'v2',
                $method,
                $uri,
                $timestamp,
                $nonce,
                $tenantId,
                $tenantRuc,
                hash('sha256', $body),
            ])
            : implode("\n", [
                $method,
                $uri,
                $timestamp,
                $nonce,
                hash('sha256', $body),
            ]);

        return base64_encode(hash_hmac('sha256', $canonical, $secret, true));
    }
}
