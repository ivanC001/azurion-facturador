<?php

namespace App\Application\Tenants\UseCases;

use App\Models\ApiClient;
use App\Models\Tenant;
use App\Infrastructure\Tenant\TenantSchemaManager;
use App\Support\Sunat\SunatEnvironment;
use App\Support\Tenants\TenantPrivateFileReference;
use Greenter\XMLSecLibs\Certificate\X509Certificate;
use Greenter\XMLSecLibs\Certificate\X509ContentType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RegisterTenantUseCase
{
    private const TEST_SOL_RUC = '20000000001';
    private const TEST_SOL_USER = 'MODDATOS';
    private const TEST_SOL_PASSWORD = 'moddatos';

    public function __construct(private readonly TenantSchemaManager $tenantSchemaManager)
    {
    }

    public function execute(array $payload): array
    {
        $schema = $this->schemaFromBusinessName(
            (string) $payload['business_name'],
            (string) $payload['ruc'],
        );

        $tenant = Tenant::query()->where('ruc', $payload['ruc'])->first();

        if ($tenant !== null) {
            return [
                'tenant_id' => $tenant->id,
                'ruc' => $tenant->ruc,
                'schema' => $tenant->schema_name,
                'already_exists' => true,
                'message' => 'Tenant already exists.',
                'api_key' => null,
                'modo_sunat' => $tenant->sunat_mode,
                'document_mode' => $tenant->document_mode,
                'fiscal_status' => $tenant->fiscal_status,
                'ticket_enabled' => $tenant->is_active,
                'electronic_documents_enabled' => $tenant->allowsElectronicDocuments(),
                'entorno_sunat' => SunatEnvironment::describe((string) $tenant->sunat_mode),
            ];
        }

        $tenant = Tenant::query()->create([
            'ruc' => $payload['ruc'],
            'business_name' => $payload['business_name'],
            'schema_name' => $schema,
            'sunat_mode' => $payload['sunat_mode'] ?? Tenant::SUNAT_MODE_DISABLED,
            'external_tenant_id' => $payload['external_tenant_id'] ?? null,
            'country_code' => strtoupper((string) ($payload['country_code'] ?? 'PE')),
            'tax_id' => $payload['tax_id'] ?? $payload['ruc'],
            'document_mode' => ($payload['sunat_mode'] ?? Tenant::SUNAT_MODE_DISABLED) === Tenant::SUNAT_MODE_DISABLED
                ? Tenant::DOCUMENT_MODE_TICKET_ONLY
                : Tenant::DOCUMENT_MODE_ELECTRONIC,
            'fiscal_status' => ($payload['sunat_mode'] ?? Tenant::SUNAT_MODE_DISABLED) === Tenant::SUNAT_MODE_DISABLED
                ? Tenant::FISCAL_STATUS_NOT_CONFIGURED
                : Tenant::FISCAL_STATUS_ACTIVE,
            'is_active' => true,
        ]);

        $this->tenantSchemaManager->provision($schema);

        $plainApiKey = 'azf_'.Str::random(48);

        ApiClient::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $payload['api_client_name'] ?? 'default-client',
            'api_key_hash' => hash('sha256', $plainApiKey),
            'is_active' => true,
        ]);

        $config = $this->buildConfigPayload($payload, $tenant->ruc, $tenant->sunat_mode);
        $this->persistBillingConfig($schema, $config);
        $this->forgetTenantCache($tenant->id, $tenant->ruc);

        return [
            'tenant_id' => $tenant->id,
            'ruc' => $tenant->ruc,
            'schema' => $schema,
            'already_exists' => false,
            'api_key' => $plainApiKey,
            'modo_sunat' => $config['modo_sunat'],
            'certificado_configurado' => TenantPrivateFileReference::isAvailable(
                $tenant->ruc,
                'certificados',
                $config['certificado_url'],
            ),
            'certificado_produccion_configurado' => TenantPrivateFileReference::isProductionCertificateAvailable(
                $tenant->ruc,
                $config['certificado_url'],
            ),
            'logo_pdf_configurado' => TenantPrivateFileReference::isAvailable(
                $tenant->ruc,
                'logos',
                $config['logo_pdf_url'],
            ),
            'sol_usuario' => $config['usuario_sol'],
            'usa_datos_prueba' => $config['usa_datos_prueba'],
            'entorno_sunat' => SunatEnvironment::describe((string) $config['modo_sunat']),
        ];
    }

    private function forgetTenantCache(int $tenantId, string $ruc): void
    {
        Cache::forget('facturador:tenant:id:'.$tenantId);
        Cache::forget('facturador:tenant:ruc:'.$ruc);
        $externalTenantId = Tenant::query()->whereKey($tenantId)->value('external_tenant_id');
        if (is_string($externalTenantId) && $externalTenantId !== '') {
            Cache::forget('facturador:tenant:external:'.$externalTenantId);
        }
    }

    private function schemaFromBusinessName(string $businessName, string $ruc): string
    {
        $digits = preg_replace('/\D+/', '', $ruc) ?? '';
        $nameSlug = (string) Str::of($businessName)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_');

        if ($nameSlug === '') {
            $nameSlug = 'empresa';
        }

        $base = 'empresa_'.$nameSlug;
        if (strlen($base) > 50) {
            $base = substr($base, 0, 50);
            $base = rtrim($base, '_');
        }

        return $base.'_'.$digits;
    }

    private function buildConfigPayload(array $payload, string $tenantRuc, string $tenantSunatMode): array
    {
        $rucSol = trim((string) ($payload['ruc_sol'] ?? ''));
        $usuarioSol = trim((string) ($payload['usuario_sol'] ?? ''));
        $claveSol = (string) ($payload['clave_sol'] ?? '');
        $sunatMode = (string) ($payload['sunat_mode'] ?? $tenantSunatMode ?: Tenant::SUNAT_MODE_DISABLED);

        $usesTestData = false;

        if ($sunatMode === Tenant::SUNAT_MODE_PRODUCTION) {
            $this->assertProductionConfig($rucSol, $usuarioSol, $claveSol, $payload);
        } elseif ($sunatMode === Tenant::SUNAT_MODE_BETA) {
            $rucSol = self::TEST_SOL_RUC;
            $usuarioSol = self::TEST_SOL_USER;
            $claveSol = self::TEST_SOL_PASSWORD;
            $usesTestData = true;
        } elseif ($sunatMode === Tenant::SUNAT_MODE_DISABLED) {
            $rucSol = '';
            $usuarioSol = '';
            $claveSol = '';
        }

        $logoPath = $this->resolveLogo($tenantRuc, $payload);
        $certificatePath = match ($sunatMode) {
            Tenant::SUNAT_MODE_DISABLED => null,
            Tenant::SUNAT_MODE_BETA => $this->resolveCertificatePem($tenantRuc, $payload, true)['path'],
            default => $this->resolveCertificatePem($tenantRuc, $payload, false)['path'],
        };

        return [
            'ruc_sol' => $rucSol !== '' ? $rucSol : null,
            'usuario_sol' => $usuarioSol !== '' ? $usuarioSol : null,
            'clave_sol_encrypted' => $claveSol !== '' ? Crypt::encryptString($claveSol) : null,
            'certificado_url' => $certificatePath,
            // La clave PFX/P12 solo se usa en memoria durante la conversion a PEM.
            'certificado_password' => null,
            'modo_sunat' => $sunatMode,
            'logo_pdf_url' => $logoPath,
            'serie_factura' => $payload['serie_factura'] ?? 'F001',
            'serie_boleta' => $payload['serie_boleta'] ?? 'B001',
            'serie_nc' => $payload['serie_nc'] ?? 'FC01',
            'serie_nd' => $payload['serie_nd'] ?? 'FD01',
            'serie_guia' => $payload['serie_guia'] ?? 'T001',
            'igv' => $payload['igv'] ?? 18,
            'moneda' => $payload['moneda'] ?? 'PEN',
            'token_api' => null,
            'usa_datos_prueba' => $usesTestData,
            'cuentas_bancarias' => array_values($payload['cuentas_bancarias'] ?? []),
        ];
    }

    private function persistBillingConfig(string $schema, array $config): void
    {
        DB::table($schema.'.configuracion_facturacion')->updateOrInsert(
            ['id' => 1],
            [
                'ruc_sol' => $config['ruc_sol'],
                'usuario_sol' => $config['usuario_sol'],
                'clave_sol_encrypted' => $config['clave_sol_encrypted'],
                'certificado_url' => $config['certificado_url'],
                'certificado_password' => $config['certificado_password'],
                'modo_sunat' => $config['modo_sunat'],
                'logo_pdf_url' => $config['logo_pdf_url'],
                'serie_factura' => $config['serie_factura'],
                'serie_boleta' => $config['serie_boleta'],
                'serie_nc' => $config['serie_nc'],
                'serie_nd' => $config['serie_nd'],
                'serie_guia' => $config['serie_guia'],
                'igv' => $config['igv'],
                'moneda' => $config['moneda'],
                'token_api' => $config['token_api'],
                'cuentas_bancarias' => json_encode(
                    $config['cuentas_bancarias'],
                    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                ),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    private function resolveLogo(string $tenantRuc, array $payload): ?string
    {
        if (($payload['logo_file'] ?? null) instanceof UploadedFile) {
            $ext = strtolower((string) $payload['logo_file']->getClientOriginalExtension());
            $fileName = 'logo_'.now()->format('Ymd_His').'.'.$ext;
            $path = $tenantRuc.'/logos/'.$fileName;
            Storage::disk(config('facturador.storage.disk', 'tenants'))->putFileAs($tenantRuc.'/logos', $payload['logo_file'], $fileName);

            return $path;
        }

        return null;
    }

    private function assertProductionConfig(string $rucSol, string $usuarioSol, string $claveSol, array $payload): void
    {
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

        if (trim($claveSol) === '') {
            $messages['clave_sol'] = ['En modo production debes registrar la clave SOL real.'];
        } elseif ($claveSol === self::TEST_SOL_PASSWORD) {
            $messages['clave_sol'] = ['No puedes usar la clave SOL de prueba en modo production.'];
        }

        if (! (($payload['certificado_file'] ?? null) instanceof UploadedFile)) {
            $messages['certificado_file'] = ['En modo production debes cargar un certificado real (.pem, .pfx o .p12).'];
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }

    private function resolveCertificatePem(string $tenantRuc, array $payload, bool $useTestCertificate): array
    {
        if ($useTestCertificate) {
            $fallbackPem = storage_path('certificates/ejemplo123456789.pem');
            if (! is_file($fallbackPem)) {
                throw new \RuntimeException('No se encontro el certificado de prueba SUNAT del servidor.');
            }

            $relative = $tenantRuc.'/certificados/cert_test.pem';
            Storage::disk(config('facturador.storage.disk', 'tenants'))->put($relative, (string) file_get_contents($fallbackPem));

            return ['path' => $relative];
        }

        $certFile = $payload['certificado_file'] ?? null;

        if ($certFile instanceof UploadedFile) {
            $ext = strtolower((string) $certFile->getClientOriginalExtension());
            $pemContent = null;

            if (in_array($ext, ['pfx', 'p12'], true)) {
                $password = (string) ($payload['certificado_password'] ?? '');
                $pemContent = $this->convertPfxToPem((string) $certFile->get(), $password);
            } else {
                $pemContent = (string) $certFile->get();
            }

            $fileName = 'cert_'.now()->format('Ymd_His').'.pem';
            $relative = $tenantRuc.'/certificados/'.$fileName;
            Storage::disk(config('facturador.storage.disk', 'tenants'))->put($relative, $pemContent);

            return ['path' => $relative];
        }

        return ['path' => ''];
    }

    private function convertPfxToPem(string $pfxContent, string $password): string
    {
        $certificate = new X509Certificate($pfxContent, $password);

        return (string) $certificate->export(X509ContentType::PEM);
    }
}
