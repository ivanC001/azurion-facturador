<?php

namespace App\Support\Tenants;

use App\Infrastructure\Tenant\TenantArtifactStorage;
use App\Support\Sunat\SunatTestIdentity;

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

        return $path !== null && self::storage()->exists($path);
    }

    public static function isProductionCertificateAvailable(string $tenantRuc, mixed $reference): bool
    {
        $path = self::safeKey($tenantRuc, 'certificados', $reference);
        if ($path === null) {
            return false;
        }

        $contents = self::storage()->get($path);
        if ($contents === null) {
            return false;
        }

        if (SunatTestIdentity::isTestCertificateReference($path)) {
            return false;
        }

        // Un certificado de prueba renombrado sigue siendo de prueba: se
        // compara el contenido, no solo el nombre del fichero.
        if (SunatTestIdentity::certificateExists()) {
            $testHash = hash_file('sha256', SunatTestIdentity::certificatePath());
            if (is_string($testHash) && hash_equals($testHash, hash('sha256', $contents))) {
                return false;
            }
        }

        return true;
    }

    private static function storage(): TenantArtifactStorage
    {
        return app(TenantArtifactStorage::class);
    }
}
