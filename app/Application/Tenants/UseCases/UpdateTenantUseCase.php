<?php

namespace App\Application\Tenants\UseCases;

use App\Infrastructure\Tenant\TenantArtifactStorage;
use App\Models\Tenant;
use App\Support\Sunat\SunatEnvironment;
use App\Support\Sunat\SunatTestIdentity;
use App\Support\Tenants\TenantPrivateFileReference;
use Greenter\XMLSecLibs\Certificate\X509Certificate;
use Greenter\XMLSecLibs\Certificate\X509ContentType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateTenantUseCase
{
    private const TEST_SOL_RUC = SunatTestIdentity::RUC;

    private const TEST_SOL_USER = SunatTestIdentity::USER;

    private const TEST_SOL_PASSWORD = SunatTestIdentity::PASSWORD;

    private const ALLOWED_LOGO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public function __construct(private readonly TenantArtifactStorage $artifactStorage) {}

    public function execute(int $tenantId, array $payload): array
    {
        $tenant = Tenant::query()->findOrFail($tenantId);
        $schema = (string) $tenant->schema_name;
        $currentConfig = $this->readBillingConfig($schema);
        $currentExternalTenantId = $tenant->external_tenant_id;

        $tenantChanges = [];

        if (array_key_exists('sunat_mode', $payload)) {
            $tenantChanges['sunat_mode'] = $payload['sunat_mode'];
        }

        $targetSunatMode = (string) ($payload['sunat_mode'] ?? ($tenant->sunat_mode ?: Tenant::SUNAT_MODE_DISABLED));

        // El orden importa: primero se valida por completo y solo despues se
        // escribe. Antes el certificado y el logo se guardaban en disco dentro
        // del propio "build", asi que una validacion fallida dejaba ficheros
        // huerfanos con la clave privada del tenant.
        $this->assertProductionConfig(
            (string) $tenant->ruc,
            $targetSunatMode,
            $currentConfig,
            $payload,
        );

        if ($targetSunatMode !== Tenant::SUNAT_MODE_DISABLED
            && strtoupper((string) ($payload['country_code'] ?? $tenant->country_code)) !== 'PE') {
            throw ValidationException::withMessages([
                'country_code' => ['La facturacion electronica SUNAT solo puede activarse para empresas de Peru.'],
            ]);
        }

        if (array_key_exists('sunat_mode', $payload)) {
            $tenantChanges['document_mode'] = $targetSunatMode === Tenant::SUNAT_MODE_DISABLED
                ? Tenant::DOCUMENT_MODE_TICKET_ONLY
                : Tenant::DOCUMENT_MODE_ELECTRONIC;
            $tenantChanges['fiscal_status'] = $targetSunatMode === Tenant::SUNAT_MODE_DISABLED
                ? Tenant::FISCAL_STATUS_NOT_CONFIGURED
                : Tenant::FISCAL_STATUS_ACTIVE;
        }

        $this->assertUsableSchema($schema);
        $this->persistChanges($tenant, $schema, $payload, $targetSunatMode, $tenantChanges);
        $this->forgetTenantCache($tenant, $currentExternalTenantId);
        $savedConfig = $this->readBillingConfig($schema);
        $environment = SunatEnvironment::describe((string) $tenant->sunat_mode);
        $usesTestData = (bool) $environment['usa_datos_prueba'];

        return [
            'tenant_id' => $tenant->id,
            'ruc' => $tenant->ruc,
            'business_name' => $tenant->business_name,
            'schema' => $tenant->schema_name,
            'sunat_mode' => $tenant->sunat_mode,
            'external_tenant_id' => $tenant->external_tenant_id,
            'country_code' => $tenant->country_code,
            'tax_id' => $tenant->tax_id,
            'document_mode' => $tenant->document_mode,
            'fiscal_status' => $tenant->fiscal_status,
            'ticket_enabled' => (bool) $tenant->is_active,
            'electronic_documents_enabled' => $tenant->allowsElectronicDocuments(),
            'is_active' => (bool) $tenant->is_active,
            'api_key' => null,
            'updated' => true,
            'entorno_sunat' => $environment,
            'configuracion' => [
                'ruc_sol' => $usesTestData ? self::TEST_SOL_RUC : ($savedConfig->ruc_sol ?? null),
                'usuario_sol' => $usesTestData ? self::TEST_SOL_USER : ($savedConfig->usuario_sol ?? null),
                'modo_sunat' => $tenant->sunat_mode,
                'usa_datos_prueba' => $usesTestData,
                'endpoint_facturacion' => $environment['endpoint_facturacion'],
                'endpoint_guias' => $environment['endpoint_guias'],
                'cola' => $environment['cola'],
                'certificado_configurado' => $usesTestData
                    ? SunatTestIdentity::certificateExists()
                    : TenantPrivateFileReference::isAvailable(
                        $tenant->ruc,
                        'certificados',
                        $savedConfig->certificado_url ?? null,
                    ),
                'certificado_produccion_configurado' => TenantPrivateFileReference::isProductionCertificateAvailable(
                    $tenant->ruc,
                    $savedConfig->certificado_url ?? null,
                ),
                'logo_pdf_configurado' => TenantPrivateFileReference::isAvailable(
                    $tenant->ruc,
                    'logos',
                    $savedConfig->logo_pdf_url ?? null,
                ),
                'serie_factura' => $savedConfig->serie_factura ?? null,
                'serie_boleta' => $savedConfig->serie_boleta ?? null,
                'serie_nc' => $savedConfig->serie_nc ?? null,
                'serie_nd' => $savedConfig->serie_nd ?? null,
                'serie_guia' => $savedConfig->serie_guia ?? null,
                'igv' => isset($savedConfig->igv) ? (float) $savedConfig->igv : null,
                'moneda' => $savedConfig->moneda ?? null,
                'cuentas_bancarias' => $this->decodeBankAccounts($savedConfig->cuentas_bancarias ?? null),
            ],
        ];
    }

    /**
     * Escribe tenant, configuracion y ficheros como una sola unidad.
     *
     * Si algo falla se revierten los cambios en base de datos y se borran los
     * ficheros recien subidos, para no dejar al tenant en production con
     * credenciales a medio guardar.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $tenantChanges
     */
    private function persistChanges(
        Tenant $tenant,
        string $schema,
        array $payload,
        string $targetSunatMode,
        array $tenantChanges,
    ): void {
        $writtenFiles = [];

        try {
            DB::transaction(function () use ($tenant, $schema, $payload, $targetSunatMode, $tenantChanges, &$writtenFiles): void {
                $configChanges = array_merge(
                    $this->buildConfigChanges($payload, $targetSunatMode),
                    $this->storeUploadedFiles((string) $tenant->ruc, $payload, $targetSunatMode, $writtenFiles),
                );

                if ($tenantChanges !== []) {
                    $tenant->fill($tenantChanges)->save();
                }

                if ($configChanges === []) {
                    return;
                }

                DB::table($schema.'.configuracion_facturacion')->updateOrInsert(
                    ['id' => 1],
                    array_merge($configChanges, ['updated_at' => now()]),
                );
            });
        } catch (\Throwable $exception) {
            foreach ($writtenFiles as $path) {
                $this->artifactStorage->disk()->delete($path);
            }

            throw $exception;
        }
    }

    /**
     * Un esquema con nombre invalido no puede consultarse. Antes se descartaba
     * en silencio y la API respondia 200 sin haber guardado nada.
     */
    private function assertUsableSchema(string $schema): void
    {
        if (preg_match('/^[a-zA-Z0-9_]+$/', $schema) !== 1) {
            throw ValidationException::withMessages([
                'schema' => ['El esquema del tenant no es valido; no se puede guardar la configuracion.'],
            ]);
        }
    }

    private function forgetTenantCache(Tenant $tenant, ?string $previousExternalTenantId): void
    {
        Cache::forget('facturador:tenant:id:'.$tenant->id);
        Cache::forget('facturador:tenant:ruc:'.$tenant->ruc);

        // Se purga tambien el identificador externo anterior: si cambio, su
        // clave seguiria devolviendo el tenant con la configuracion vieja.
        foreach ([$previousExternalTenantId, $tenant->external_tenant_id] as $externalId) {
            if (is_string($externalId) && $externalId !== '') {
                Cache::forget('facturador:tenant:external:'.$externalId);
            }
        }
    }

    /**
     * Cambios escalares de configuracion. Sin efectos secundarios.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function buildConfigChanges(array $payload, string $targetSunatMode): array
    {
        $changes = [];

        $stringFields = [
            'serie_factura',
            'serie_boleta',
            'serie_nc',
            'serie_nd',
            'serie_guia',
            'moneda',
        ];

        foreach ($stringFields as $field) {
            if (array_key_exists($field, $payload)) {
                $changes[$field] = $payload[$field];
            }
        }

        if (array_key_exists('sunat_mode', $payload)) {
            $changes['modo_sunat'] = $payload['sunat_mode'];
        }

        if (array_key_exists('igv', $payload)) {
            $changes['igv'] = $payload['igv'];
        }

        if (array_key_exists('cuentas_bancarias', $payload)) {
            $changes['cuentas_bancarias'] = json_encode(
                array_values($payload['cuentas_bancarias']),
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        }

        $acceptsProductionCredentials = $targetSunatMode === Tenant::SUNAT_MODE_PRODUCTION;
        if ($acceptsProductionCredentials) {
            foreach (['ruc_sol', 'usuario_sol'] as $field) {
                if (array_key_exists($field, $payload)) {
                    $changes[$field] = $payload[$field];
                }
            }

            if (array_key_exists('clave_sol', $payload)
                && is_string($payload['clave_sol'])
                && $payload['clave_sol'] !== '') {
                $changes['clave_sol_encrypted'] = Crypt::encryptString($payload['clave_sol']);
            }
        }

        if ($acceptsProductionCredentials && array_key_exists('certificado_password', $payload)) {
            // Nunca persistir la clave del contenedor PFX/P12.
            $changes['certificado_password'] = null;
        }

        return $changes;
    }

    /**
     * Guarda logo y certificado y devuelve las referencias a persistir.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $writtenFiles  se rellena para poder deshacer
     * @return array<string, mixed>
     */
    private function storeUploadedFiles(
        string $tenantRuc,
        array $payload,
        string $targetSunatMode,
        array &$writtenFiles,
    ): array {
        $changes = [];

        if (($payload['logo_file'] ?? null) instanceof UploadedFile) {
            $logo = $payload['logo_file'];
            $fileName = 'logo_'.now()->format('Ymd_His').'.'.self::imageExtension($logo);
            $relative = $tenantRuc.'/logos/'.$fileName;
            $this->artifactStorage->putUploadedFile($tenantRuc.'/logos', $logo, $fileName);
            $writtenFiles[] = $relative;
            $changes['logo_pdf_url'] = $relative;
        }

        if ($targetSunatMode !== Tenant::SUNAT_MODE_PRODUCTION
            || ! (($payload['certificado_file'] ?? null) instanceof UploadedFile)) {
            return $changes;
        }

        $fileName = 'cert_'.now()->format('Ymd_His').'.pem';
        $relative = $tenantRuc.'/certificados/'.$fileName;
        $this->artifactStorage->put($relative, self::certificatePem($payload));
        $writtenFiles[] = $relative;
        $changes['certificado_url'] = $relative;
        $changes['certificado_password'] = null;

        return $changes;
    }

    /**
     * Extension del logo derivada del contenido, no del nombre que envio el
     * cliente. La validacion de la request ya limita los tipos aceptados.
     */
    private static function imageExtension(UploadedFile $file): string
    {
        $guessed = strtolower((string) $file->guessExtension());

        return in_array($guessed, self::ALLOWED_LOGO_EXTENSIONS, true) ? $guessed : 'png';
    }

    /**
     * Convierte el certificado subido a PEM.
     *
     * Una clave de contenedor incorrecta es un error del cliente, no un fallo
     * del servidor: se traduce a un 422 con el campo concreto.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function certificatePem(array $payload): string
    {
        $file = $payload['certificado_file'];
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (! in_array($extension, ['pfx', 'p12'], true)) {
            return (string) $file->get();
        }

        try {
            $certificate = new X509Certificate(
                (string) $file->get(),
                (string) ($payload['certificado_password'] ?? ''),
            );

            return (string) $certificate->export(X509ContentType::PEM);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'certificado_password' => [
                    'No se pudo abrir el certificado: revisa la clave del contenedor .pfx/.p12.',
                ],
            ]);
        }
    }

    private function readBillingConfig(string $schema): ?object
    {
        if (! preg_match('/^[a-zA-Z0-9_]+$/', $schema)) {
            return null;
        }

        try {
            return DB::table($schema.'.configuracion_facturacion')->orderBy('id')->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertProductionConfig(
        string $tenantRuc,
        string $targetSunatMode,
        ?object $currentConfig,
        array $payload,
    ): void {
        if ($targetSunatMode !== Tenant::SUNAT_MODE_PRODUCTION) {
            return;
        }

        $rucSol = trim((string) ($payload['ruc_sol'] ?? ($currentConfig->ruc_sol ?? '')));
        $usuarioSol = trim((string) ($payload['usuario_sol'] ?? ($currentConfig->usuario_sol ?? '')));
        $claveSol = array_key_exists('clave_sol', $payload)
            ? trim((string) $payload['clave_sol'])
            : $this->decryptStoredPassword($currentConfig->clave_sol_encrypted ?? null);

        // Con un certificado adjunto la validacion se resuelve sobre el propio
        // fichero; sin el, sobre el que ya estuviera guardado.
        $uploadedCertificate = ($payload['certificado_file'] ?? null) instanceof UploadedFile
            ? $payload['certificado_file']
            : null;
        $certificateRef = trim((string) ($currentConfig->certificado_url ?? ''));

        $messages = [];

        if ($rucSol === '') {
            $messages['ruc_sol'] = ['En modo production debes registrar el RUC SOL real.'];
        } elseif ($rucSol === self::TEST_SOL_RUC) {
            $messages['ruc_sol'] = ['No puedes usar el RUC SOL de prueba en modo production.'];
        }

        if ($usuarioSol === '') {
            $messages['usuario_sol'] = ['En modo production debes registrar el usuario SOL real.'];
        } elseif (strtoupper($usuarioSol) === self::TEST_SOL_USER) {
            $messages['usuario_sol'] = ['No puedes usar el usuario SOL de prueba en modo production.'];
        }

        if ($claveSol === '') {
            $messages['clave_sol'] = ['En modo production debes registrar la clave SOL real.'];
        } elseif ($claveSol === self::TEST_SOL_PASSWORD) {
            $messages['clave_sol'] = ['No puedes usar la clave SOL de prueba en modo production.'];
        }

        if ($uploadedCertificate !== null) {
            if ($this->isTestCertificateRef((string) $uploadedCertificate->getClientOriginalName())) {
                $messages['certificado_file'] = ['No puedes usar el certificado de prueba en modo production.'];
            }
        } elseif ($certificateRef === ''
            || ! TenantPrivateFileReference::isAvailable($tenantRuc, 'certificados', $certificateRef)) {
            $messages['certificado_file'] = ['En modo production debes cargar o conservar un certificado real (.pem, .pfx o .p12).'];
        } elseif ($this->isTestCertificateRef($certificateRef)) {
            $messages['certificado_file'] = ['No puedes usar el certificado de prueba en modo production.'];
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }

    private function decryptStoredPassword(mixed $encrypted): string
    {
        if (! is_string($encrypted) || trim($encrypted) === '') {
            return '';
        }

        try {
            return trim(Crypt::decryptString($encrypted));
        } catch (\Throwable) {
            return '';
        }
    }

    private function isTestCertificateRef(string $certificateRef): bool
    {
        return SunatTestIdentity::isTestCertificateReference($certificateRef);
    }

    /** @return list<array<string, string>> */
    private function decodeBankAccounts(mixed $value): array
    {
        if (is_string($value) && trim($value) !== '') {
            $value = json_decode($value, true);
        }

        return is_array($value) ? array_values($value) : [];
    }
}
