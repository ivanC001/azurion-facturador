<?php

namespace App\Application\Tenants\UseCases;

use App\Models\Tenant;
use App\Support\Sunat\SunatEnvironment;
use App\Support\Tenants\TenantPrivateFileReference;
use Greenter\XMLSecLibs\Certificate\X509Certificate;
use Greenter\XMLSecLibs\Certificate\X509ContentType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class UpdateTenantUseCase
{
    private const TEST_SOL_RUC = '20000000001';
    private const TEST_SOL_USER = 'MODDATOS';
    private const TEST_SOL_PASSWORD = 'moddatos';

    public function execute(int $tenantId, array $payload): array
    {
        $tenant = Tenant::query()->findOrFail($tenantId);
        $schema = (string) $tenant->schema_name;
        $currentConfig = $this->readBillingConfig($schema);

        $tenantChanges = [];

        if (array_key_exists('sunat_mode', $payload)) {
            $tenantChanges['sunat_mode'] = $payload['sunat_mode'];
        }

        $targetSunatMode = (string) ($payload['sunat_mode'] ?? $tenant->sunat_mode ?: Tenant::SUNAT_MODE_DISABLED);
        $configChanges = $this->buildConfigChanges($tenant->ruc, $payload, $targetSunatMode);

        $this->assertProductionConfig(
            (string) $tenant->ruc,
            $targetSunatMode,
            $currentConfig,
            $payload,
            $configChanges,
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

        if ($tenantChanges !== []) {
            $tenant->fill($tenantChanges)->save();
        }

        if ($configChanges !== [] && preg_match('/^[a-zA-Z0-9_]+$/', $schema)) {
            DB::table($schema.'.configuracion_facturacion')->updateOrInsert(
                ['id' => 1],
                array_merge($configChanges, [
                    'updated_at' => now(),
                    'created_at' => now(),
                ]),
            );
        }
        $this->forgetTenantCache($tenant->id, $tenant->ruc);
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
                    ? is_file(storage_path('certificates/ejemplo123456789.pem'))
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
            ],
        ];
    }

    private function forgetTenantCache(int $tenantId, string $ruc): void
    {
        Cache::forget('facturador:tenant:id:'.$tenantId);
        Cache::forget('facturador:tenant:ruc:'.$ruc);
        $tenant = Tenant::query()->find($tenantId);
        if ($tenant?->external_tenant_id) {
            Cache::forget('facturador:tenant:external:'.$tenant->external_tenant_id);
        }
    }

    private function buildConfigChanges(string $tenantRuc, array $payload, string $targetSunatMode): array
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

        if (($payload['logo_file'] ?? null) instanceof UploadedFile) {
            $ext = strtolower((string) $payload['logo_file']->getClientOriginalExtension());
            $fileName = 'logo_'.now()->format('Ymd_His').'.'.$ext;
            $relative = $tenantRuc.'/logos/'.$fileName;
            Storage::disk(config('facturador.storage.disk', 'tenants'))->putFileAs($tenantRuc.'/logos', $payload['logo_file'], $fileName);
            $changes['logo_pdf_url'] = $relative;
        }

        if ($acceptsProductionCredentials && ($payload['certificado_file'] ?? null) instanceof UploadedFile) {
            $ext = strtolower((string) $payload['certificado_file']->getClientOriginalExtension());
            $pemContent = null;

            if (in_array($ext, ['pfx', 'p12'], true)) {
                $password = (string) ($payload['certificado_password'] ?? '');
                $certificate = new X509Certificate((string) $payload['certificado_file']->get(), $password);
                $pemContent = (string) $certificate->export(X509ContentType::PEM);
            } else {
                $pemContent = (string) $payload['certificado_file']->get();
            }

            $fileName = 'cert_'.now()->format('Ymd_His').'.pem';
            $relative = $tenantRuc.'/certificados/'.$fileName;
            Storage::disk(config('facturador.storage.disk', 'tenants'))->put($relative, $pemContent);
            $changes['certificado_url'] = $relative;
            $changes['certificado_password'] = null;
        } elseif ($acceptsProductionCredentials && array_key_exists('certificado_password', $payload)) {
            // Nunca persistir la clave del contenedor PFX/P12.
            $changes['certificado_password'] = null;
        }

        return $changes;
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
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $configChanges
     */
    private function assertProductionConfig(
        string $tenantRuc,
        string $targetSunatMode,
        ?object $currentConfig,
        array $payload,
        array $configChanges,
    ): void {
        if ($targetSunatMode !== 'production') {
            return;
        }

        $rucSol = trim((string) ($configChanges['ruc_sol'] ?? ($currentConfig->ruc_sol ?? '')));
        $usuarioSol = trim((string) ($configChanges['usuario_sol'] ?? ($currentConfig->usuario_sol ?? '')));
        $claveSol = array_key_exists('clave_sol', $payload)
            ? trim((string) $payload['clave_sol'])
            : $this->decryptStoredPassword($currentConfig->clave_sol_encrypted ?? null);
        $certificateRef = trim((string) ($configChanges['certificado_url'] ?? ($currentConfig->certificado_url ?? '')));

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

        if ($certificateRef === ''
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
        $normalized = strtolower(str_replace('\\', '/', $certificateRef));

        return str_contains($normalized, 'cert_test')
            || str_contains($normalized, 'ejemplo123456789');
    }

}
