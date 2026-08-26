<?php

namespace App\Infrastructure\Sunat;

use App\Infrastructure\Tenant\TenantArtifactStorage;
use App\Support\Sunat\SunatEnvironment;
use App\Support\Sunat\SunatTestIdentity;
use App\Support\Tenants\TenantPrivateFileReference;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Resuelve con que identidad y certificado se firma un envio a SUNAT.
 *
 * Estaba dentro de GreenterSunatSender, mezclado con la construccion del XML.
 * Separarlo deja en un unico sitio todas las reglas que impiden firmar en
 * production con material de prueba, que es la parte critica del modulo.
 */
final class SunatEmissionConfigResolver
{
    private const MODOS_VALIDOS = ['beta', 'production'];

    public function __construct(private readonly TenantArtifactStorage $artifactStorage) {}

    /**
     * @return array{
     *     mode: string,
     *     service: string,
     *     sol_ruc: string,
     *     sol_user: string,
     *     sol_password: string,
     *     certificate_pem: string,
     *     uses_test_credentials: bool,
     *     logo_pdf_url: string,
     *     cuentas_bancarias: array<int, mixed>
     * }
     */
    public function resolve(string $tenantRuc, string $tenantSunatMode, string $documentType): array
    {
        $row = DB::table('configuracion_facturacion')->orderBy('id')->first();

        $serviceMode = strtolower(trim($tenantSunatMode));
        if (! in_array($serviceMode, self::MODOS_VALIDOS, true)) {
            throw new \RuntimeException('Modo SUNAT invalido o deshabilitado para el tenant.');
        }

        // El modo del tenant manda sobre el guardado en su configuracion; una
        // discrepancia se registra pero no puede decidir contra que entorno de
        // SUNAT se emite.
        $storedMode = strtolower(trim((string) ($row->modo_sunat ?? '')));
        if ($storedMode !== '' && $storedMode !== $serviceMode) {
            Log::channel('sunat')->warning('Se ignoro un modo SUNAT interno inconsistente.', [
                'modo_tenant' => $serviceMode,
                'modo_configuracion' => $storedMode,
            ]);
        }

        $service = $this->resolveEndpoint($serviceMode, $documentType);

        $usesTestCredentials = $serviceMode === 'beta';
        $certificateField = trim((string) ($row->certificado_url ?? ''));

        if ($usesTestCredentials) {
            $solRuc = SunatTestIdentity::RUC;
            $solUser = SunatTestIdentity::USER;
            $solPassword = SunatTestIdentity::PASSWORD;
        } else {
            $solRuc = trim((string) ($row->ruc_sol ?? ''));
            $solUser = trim((string) ($row->usuario_sol ?? ''));
            $solPassword = $this->decryptSolPassword($row->clave_sol_encrypted ?? null);
            $this->assertProductionConfig($solRuc, $solUser, $solPassword, $certificateField);
            if (! TenantPrivateFileReference::isProductionCertificateAvailable($tenantRuc, $certificateField)) {
                throw new \RuntimeException('Modo production requiere un certificado digital real del tenant.');
            }
        }

        return [
            'mode' => $serviceMode,
            'service' => $service,
            'sol_ruc' => $solRuc,
            'sol_user' => $solUser,
            'sol_password' => $solPassword,
            'certificate_pem' => $this->resolveCertificatePem($tenantRuc, $certificateField, $usesTestCredentials),
            'uses_test_credentials' => $usesTestCredentials,
            'logo_pdf_url' => is_string($row->logo_pdf_url ?? null) ? trim((string) $row->logo_pdf_url) : '',
            'cuentas_bancarias' => $this->decodeBankAccounts($row->cuentas_bancarias ?? null),
        ];
    }

    /**
     * Ninguna credencial de prueba puede colarse en production: un comprobante
     * firmado con la identidad de pruebas no tiene validez fiscal.
     */
    private function assertProductionConfig(string $solRuc, string $solUser, string $solPassword, string $certificateField): void
    {
        if (trim($solRuc) === '' || SunatTestIdentity::isTestRuc($solRuc)) {
            throw new \RuntimeException('Modo production requiere RUC SOL real; no se permite RUC SOL de prueba.');
        }

        if (trim($solUser) === '' || SunatTestIdentity::isTestUser($solUser)) {
            throw new \RuntimeException('Modo production requiere usuario SOL real; no se permite MODDATOS.');
        }

        if (trim($solPassword) === '' || SunatTestIdentity::isTestPassword($solPassword)) {
            throw new \RuntimeException('Modo production requiere clave SOL real; no se permite clave de prueba.');
        }

        if (trim($certificateField) === '') {
            throw new \RuntimeException('Modo production requiere certificado digital real.');
        }

        if (SunatTestIdentity::isTestCertificateReference($certificateField)) {
            throw new \RuntimeException('Modo production no permite certificado de prueba.');
        }
    }

    private function resolveEndpoint(string $serviceMode, string $documentType): string
    {
        return SunatEnvironment::endpoint($serviceMode, $documentType)
            ?? throw new \RuntimeException('No existe un endpoint SUNAT para el modo solicitado.');
    }

    /**
     * Una clave que no se puede descifrar se trata como ausente: la validacion
     * de production la rechaza despues con un mensaje accionable, en lugar de
     * intentar firmar con basura.
     */
    private function decryptSolPassword(mixed $encrypted): string
    {
        if (! is_string($encrypted) || $encrypted === '') {
            return '';
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return '';
        }
    }

    private function resolveCertificatePem(string $tenantRuc, string $certRef, bool $useTestCertificate): string
    {
        if ($useTestCertificate) {
            if (SunatTestIdentity::certificateExists()) {
                return (string) file_get_contents(SunatTestIdentity::certificatePath());
            }

            throw new \RuntimeException('No se encontro el certificado de prueba SUNAT del servidor.');
        }

        if ($certRef !== '') {
            $safeKey = TenantPrivateFileReference::safeKey($tenantRuc, 'certificados', $certRef);
            $pem = $safeKey === null ? null : $this->artifactStorage->get($safeKey);
            if ($pem !== null) {
                return $pem;
            }
        }

        throw new \RuntimeException('No certificate PEM found. Upload a certificate for the current tenant.');
    }

    /**
     * @return array<int, mixed>
     */
    private function decodeBankAccounts(mixed $value): array
    {
        if (is_string($value) && trim($value) !== '') {
            $value = json_decode($value, true);
        }

        return is_array($value) ? array_values($value) : [];
    }
}
