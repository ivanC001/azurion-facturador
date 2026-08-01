<?php

namespace App\Support\Tenants;

use Illuminate\Support\Facades\Storage;

final class TenantPrivateFileReference
{
    public static function safeKey(string $tenantRuc, string $directory, mixed $reference): ?string
    {
        if (! is_string($reference)
            || ! preg_match('/^[A-Za-z0-9._-]{3,40}$/', $tenantRuc)
            || ! in_array($directory, ['certificados', 'logos'], true)) {
            return null;
        }

        $path = str_replace('\\', '/', trim($reference));
        $expectedPrefix = $tenantRuc.'/'.$directory.'/';
        if (! str_starts_with($path, $expectedPrefix)
            || str_contains($path, '..')
            || str_contains($path, ':')
            || preg_match('#^[A-Za-z0-9._-]+/(certificados|logos)/[A-Za-z0-9._-]+$#', $path) !== 1) {
            return null;
        }

        return $path;
    }

    public static function isAvailable(string $tenantRuc, string $directory, mixed $reference): bool
    {
        $path = self::safeKey($tenantRuc, $directory, $reference);

        return $path !== null && Storage::disk(config('facturador.storage.disk', 'tenants'))->exists($path);
    }

    public static function isProductionCertificateAvailable(string $tenantRuc, mixed $reference): bool
    {
        $path = self::safeKey($tenantRuc, 'certificados', $reference);
        if ($path === null || ! Storage::disk(config('facturador.storage.disk', 'tenants'))->exists($path)) {
            return false;
        }

        $normalized = strtolower($path);
        if (str_contains($normalized, 'cert_test') || str_contains($normalized, 'ejemplo123456789')) {
            return false;
        }

        $testCertificate = storage_path('certificates/ejemplo123456789.pem');
        if (is_file($testCertificate)) {
            $storedHash = hash('sha256', (string) Storage::disk(config('facturador.storage.disk', 'tenants'))->get($path));
            $testHash = hash_file('sha256', $testCertificate);
            if (is_string($testHash) && hash_equals($testHash, $storedHash)) {
                return false;
            }
        }

        return true;
    }
}
