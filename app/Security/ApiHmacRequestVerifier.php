<?php

namespace App\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class ApiHmacRequestVerifier
{
    public function verify(Request $request, string $sharedSecret, int $apiClientId): ?string
    {
        $signatureHeader = (string) config('facturador.security.hmac.header_signature', 'X-Signature');
        $timestampHeader = (string) config('facturador.security.hmac.header_timestamp', 'X-Timestamp');
        $nonceHeader = (string) config('facturador.security.hmac.header_nonce', 'X-Nonce');
        $ttlSeconds = max(10, (int) config('facturador.security.hmac.timestamp_tolerance_seconds', 300));
        $nonceTtl = max($ttlSeconds, (int) config('facturador.security.hmac.nonce_ttl_seconds', 600));

        $providedSignature = trim((string) $request->header($signatureHeader, ''));
        $providedTimestamp = trim((string) $request->header($timestampHeader, ''));
        $providedNonce = trim((string) $request->header($nonceHeader, ''));

        if ($providedSignature === '' || $providedTimestamp === '' || $providedNonce === '') {
            return 'Missing HMAC headers. Required: '.$signatureHeader.', '.$timestampHeader.', '.$nonceHeader.'.';
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

        $nonceKey = sprintf('hmac_nonce:%d:%s:%d', $apiClientId, $providedNonce, $timestamp);
        $isFreshNonce = Cache::add($nonceKey, 1, now()->addSeconds($nonceTtl));
        if (! $isFreshNonce) {
            return 'Replay detected: nonce already used.';
        }

        $method = strtoupper($request->method());
        $uri = $request->getRequestUri();
        $bodyHash = hash('sha256', (string) $request->getContent());

        $canonicalPayload = implode("\n", [
            $method,
            $uri,
            $providedTimestamp,
            $providedNonce,
            $bodyHash,
        ]);

        $expectedRaw = hash_hmac('sha256', $canonicalPayload, $sharedSecret, true);
        $expectedBase64 = base64_encode($expectedRaw);
        $expectedHex = hash_hmac('sha256', $canonicalPayload, $sharedSecret);

        $valid = hash_equals($expectedBase64, $providedSignature)
            || hash_equals($expectedHex, strtolower($providedSignature));

        if (! $valid) {
            return 'Invalid HMAC signature.';
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
