<?php

namespace App\Application\Tenants\UseCases;

use App\Models\Tenant;
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

        if (array_key_exists('business_name', $payload)) {
            $tenantChanges['business_name'] = $payload['business_name'];
        }
        if (array_key_exists('sunat_mode', $payload)) {
            $tenantChanges['sunat_mode'] = $payload['sunat_mode'];
        }
        if (array_key_exists('is_active', $payload)) {
            $tenantChanges['is_active'] = (bool) $payload['is_active'];
        }

        $configChanges = $this->buildConfigChanges($tenant->ruc, $payload);
        $targetSunatMode = (string) ($payload['sunat_mode'] ?? $tenant->sunat_mode ?: 'beta');

        $this->assertProductionConfig($targetSunatMode, $currentConfig, $payload, $configChanges);

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

        $tokenApi = null;
        if (preg_match('/^[a-zA-Z0-9_]+$/', $schema)) {
            try {
                $tokenApi = DB::table($schema.'.configuracion_facturacion')->value('token_api');
            } catch (\Throwable) {
                $tokenApi = null;
            }
        }

        return [
            'tenant_id' => $tenant->id,
            'ruc' => $tenant->ruc,
            'business_name' => $tenant->business_name,
            'schema' => $tenant->schema_name,
            'sunat_mode' => $tenant->sunat_mode,
            'is_active' => (bool) $tenant->is_active,
            'api_key' => $tokenApi,
            'updated' => true,
        ];
    }

    private function forgetTenantCache(int $tenantId, string $ruc): void
    {
        Cache::forget('facturador:tenant:id:'.$tenantId);
        Cache::forget('facturador:tenant:ruc:'.$ruc);
    }

    private function buildConfigChanges(string $tenantRuc, array $payload): array
    {
        $changes = [];

        $stringFields = [
            'ruc_sol',
            'usuario_sol',
            'certificado_password',
            'certificado_url',
            'logo_pdf_url',
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

        if (array_key_exists('clave_sol', $payload) && is_string($payload['clave_sol']) && $payload['clave_sol'] !== '') {
            $changes['clave_sol_encrypted'] = Crypt::encryptString($payload['clave_sol']);
        }

        if (($payload['logo_file'] ?? null) instanceof UploadedFile) {
            $ext = strtolower((string) $payload['logo_file']->getClientOriginalExtension());
            $fileName = 'logo_'.now()->format('Ymd_His').'.'.$ext;
            $relative = $tenantRuc.'/logos/'.$fileName;
            Storage::disk('tenants')->putFileAs($tenantRuc.'/logos', $payload['logo_file'], $fileName);
            $changes['logo_pdf_url'] = $relative;
        }

        if (($payload['certificado_file'] ?? null) instanceof UploadedFile) {
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
            Storage::disk('tenants')->put($relative, $pemContent);
            $changes['certificado_url'] = $relative;
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
    private function assertProductionConfig(string $targetSunatMode, ?object $currentConfig, array $payload, array $configChanges): void
    {
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

        if ($certificateRef === '') {
            $messages['certificado_file'] = ['En modo production debes registrar certificado real (.pem, .pfx, .p12 o certificado_url).'];
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
            return trim($encrypted);
        }
    }

    private function isTestCertificateRef(string $certificateRef): bool
    {
        $normalized = strtolower(str_replace('\\', '/', $certificateRef));

        return str_contains($normalized, 'cert_test')
            || str_contains($normalized, 'ejemplo123456789');
    }
}
