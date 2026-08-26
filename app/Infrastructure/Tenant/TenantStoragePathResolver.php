<?php

namespace App\Infrastructure\Tenant;

use App\Support\Tenants\TenantContext;

/**
 * Rutas de los ficheros privados del tenant, relativas al disco "tenants".
 *
 * El disco ya tiene su raiz en storage/app/tenants, asi que la ruta correcta
 * empieza directamente por el RUC. Durante un tiempo este resolver antepuso
 * ademas "tenants/", lo que dejo los comprobantes en tenants/tenants/{ruc}
 * mientras certificados y logos vivian en tenants/{ruc}. Se conserva la ruta
 * antigua solo como lectura de respaldo hasta ejecutar
 * `php artisan facturador:storage:normalizar`.
 */
final class TenantStoragePathResolver
{
    private const LEGACY_PREFIX = 'tenants/';

    public function __construct(private readonly TenantContext $tenantContext) {}

    public function basePath(): string
    {
        return $this->tenantContext->required()->ruc;
    }

    public function xmlPath(string $fileName): string
    {
        return $this->basePath().'/xml/'.$fileName;
    }

    public function pdfPath(string $fileName): string
    {
        return $this->basePath().'/pdf/'.$fileName;
    }

    public function cdrPath(string $fileName): string
    {
        return $this->basePath().'/cdr/'.$fileName;
    }

    public function certPath(string $fileName): string
    {
        return $this->basePath().'/certificados/'.$fileName;
    }

    /**
     * Ubicacion anterior del mismo fichero, para leer lo ya generado.
     */
    public static function legacyPathFor(string $canonicalPath): string
    {
        return self::LEGACY_PREFIX.ltrim($canonicalPath, '/');
    }

    public static function legacyPrefix(): string
    {
        return self::LEGACY_PREFIX;
    }
}
