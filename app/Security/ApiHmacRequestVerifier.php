<?php

namespace App\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class ApiHmacRequestVerifier
{
    /**
     * @param  string|array<int, string>  $sharedSecrets
     */
    public function verify(
        Request $request,
        string|array $sharedSecrets,
        int|string $apiClientId,
        bool $requireV2 = false,
    ): ?string {
        $signatureHeader = (string) config('facturador.security.hmac.header_signature', 'X-Signature');
        $timestampHeader = (string) config('facturador.security.hmac.header_timestamp', 'X-Timestamp');
        $nonceHeader = (string) config('facturador.security.hmac.header_nonce', 'X-Nonce');
        $versionHeader = (string) config('facturador.security.hmac.header_version', 'X-Signature-Version');
        $ttlSeconds = max(10, (int) config('facturador.security.hmac.timestamp_tolerance_seconds', 300));
        $nonceTtl = max($ttlSeconds, (int) config('facturador.security.hmac.nonce_ttl_seconds', 600));

        $providedSignature = trim((string) $request->header($signatureHeader, ''));
        $providedTimestamp = trim((string) $request->header($timestampHeader, ''));
        $providedNonce = trim((string) $request->header($nonceHeader, ''));
        $providedVersion = strtolower(trim((string) $request->header($versionHeader, 'v1')));

        if ($providedSignature === '' || $providedTimestamp === '' || $providedNonce === '') {
            return 'Missing HMAC headers. Required: '.$signatureHeader.', '.$timestampHeader.', '.$nonceHeader.'.';
        }
        if (! in_array($providedVersion, ['v1', 'v2'], true)) {
            return 'Unsupported HMAC signature version.';
        }
        if ($requireV2 && $providedVersion !== 'v2') {
            return 'HMAC signature version v2 is required.';
        }

        $timestamp = $this->parseTimestamp($providedTimestamp);
        if ($timestamp === null) {
            return 'Invalid HMAC timestamp format.';
        }

        $now = now()->timestamp;
        if (abs($now - $timestamp) > $ttlSeconds) {
            return 'Expired HMAC timestamp.';
        }

        if (! preg_match('/^[a-zA-Z0-9._:-]{8,120}$/', $providedNonce)) {
            return 'Invalid HMAC nonce format.';
        }

        $method = strtoupper($request->method());
        $uri = $request->getRequestUri();
        $bodyHash = hash('sha256', (string) $request->getContent());

        $tenantId = trim((string) $request->header('X-Azurion-Tenant-ID', ''));
        $tenantRuc = trim((string) $request->header('X-Tenant-RUC', ''));
        if ($providedVersion === 'v2' && $tenantId === '' && $tenantRuc === '') {
            return 'Missing signed tenant identity.';
        }

        $canonicalParts = $providedVersion === 'v2'
            ? ['v2', $method, $uri, $providedTimestamp, $providedNonce, $tenantId, $tenantRuc, $bodyHash]
            : [$method, $uri, $providedTimestamp, $providedNonce, $bodyHash];
        $canonicalPayload = implode("\n", $canonicalParts);

        $secrets = is_array($sharedSecrets) ? $sharedSecrets : [$sharedSecrets];
        $secrets = array_values(array_filter(
            array_map(static fn (string $secret): string => trim($secret), $secrets),
            static fn (string $secret): bool => $secret !== '',
        ));
        $valid = false;
        foreach ($secrets as $secret) {
            $expectedRaw = hash_hmac('sha256', $canonicalPayload, $secret, true);
            $expectedBase64 = base64_encode($expectedRaw);
            $expectedHex = hash_hmac('sha256', $canonicalPayload, $secret);
            if (hash_equals($expectedBase64, $providedSignature)
                || hash_equals($expectedHex, strtolower($providedSignature))) {
                $valid = true;
                break;
            }
        }

        if (! $valid) {
            return 'Invalid HMAC signature.';
        }

        $clientHash = hash('sha256', (string) $apiClientId);
        $nonceKey = sprintf('hmac_nonce:%s:%s:%d', $clientHash, $providedNonce, $timestamp);
        $isFreshNonce = Cache::add($nonceKey, 1, now()->addSeconds($nonceTtl));
        if (! $isFreshNonce) {
            return 'Replay detected: nonce already used.';
        }

        return null;
    }

    private function parseTimestamp(string $value): ?int
    {
        if (! preg_match('/^\d{10,13}$/', $value)) {
            return null;
        }

        $numeric = (int) $value;

        // Si viene en milisegundos, lo normalizamos a segundos.
        if (strlen($value) === 13) {
            $numeric = (int) floor($numeric / 1000);
        }

        return $numeric > 0 ? $numeric : null;
    }
}
