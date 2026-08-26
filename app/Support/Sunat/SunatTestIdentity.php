<?php

namespace App\Support\Sunat;

/**
 * Credenciales y certificado de prueba que publica SUNAT para el entorno beta.
 *
 * Estaban duplicadas en GreenterSunatSender, RegisterTenantUseCase y
 * UpdateTenantUseCase, igual que la deteccion de "esto es un certificado de
 * prueba". Con tres copias, relajar una comprobacion en un sitio y no en los
 * otros bastaba para acabar firmando en production con material de prueba.
 */
final class SunatTestIdentity
{
    public const RUC = '20000000001';

    public const USER = 'MODDATOS';

    public const PASSWORD = 'moddatos';

    public const CERTIFICATE_NAME = 'ejemplo123456789';

    /**
     * Marcas que identifican un certificado de prueba en una referencia.
     */
    private const TEST_CERTIFICATE_MARKERS = ['cert_test', self::CERTIFICATE_NAME];

    public static function certificatePath(): string
    {
        return storage_path('certificates/'.self::CERTIFICATE_NAME.'.pem');
    }

    public static function certificateExists(): bool
    {
        return is_file(self::certificatePath());
    }

    public static function isTestCertificateReference(string $reference): bool
    {
        $normalized = strtolower(str_replace('\\', '/', $reference));

        foreach (self::TEST_CERTIFICATE_MARKERS as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }

    public static function isTestRuc(string $ruc): bool
    {
        return trim($ruc) === self::RUC;
    }

    public static function isTestUser(string $user): bool
    {
        return strtoupper(trim($user)) === self::USER;
    }

    public static function isTestPassword(string $password): bool
    {
        return trim($password) === self::PASSWORD;
    }
}
