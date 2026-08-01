<?php

namespace App\Application\Tenants\UseCases;

use App\Infrastructure\Tenant\TenantSchemaManager;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ProvisionTenantFromAzurionUseCase
{
    public function __construct(private readonly TenantSchemaManager $tenantSchemaManager)
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function execute(string $externalTenantId, array $payload): array
    {
        $this->acquireProvisioningLock($externalTenantId);
        $schemaToProvision = null;
        try {
            $tenant = DB::transaction(function () use ($externalTenantId, $payload, &$schemaToProvision): Tenant {
                $taxId = trim((string) ($payload['tax_id'] ?? ''));
                $tenant = Tenant::query()
                    ->where('external_tenant_id', $externalTenantId)
                    ->lockForUpdate()
                    ->first();

                if ($tenant === null && $taxId !== '') {
                    $tenant = Tenant::query()
                        ->where(function ($query) use ($taxId): void {
                            $query->where('tax_id', $taxId)->orWhere('ruc', $taxId);
                        })
                        ->lockForUpdate()
                        ->first();
                }

                $countryCode = strtoupper(trim((string) ($payload['country_code'] ?? 'PE')));
                $legacyTaxId = $taxId !== ''
                    ? $taxId
                    : 'EXT-'.strtoupper(substr(hash('sha256', $externalTenantId), 0, 24));

                if ($tenant === null) {
                    $tenant = new Tenant();
                    $tenant->external_tenant_id = $externalTenantId;
                    $tenant->schema_name = $this->schemaName($externalTenantId);
                    $tenant->sunat_mode = Tenant::SUNAT_MODE_DISABLED;
                    $tenant->document_mode = Tenant::DOCUMENT_MODE_TICKET_ONLY;
                    $tenant->fiscal_status = Tenant::FISCAL_STATUS_NOT_CONFIGURED;
                }

                $tenant->external_tenant_id = $externalTenantId;
                $tenant->ruc = $legacyTaxId;
                $tenant->tax_id = $taxId !== '' ? $taxId : null;
                $tenant->business_name = trim((string) $payload['business_name']);
                $tenant->country_code = $countryCode;
                $tenant->is_active = (bool) ($payload['active'] ?? true);
                if ($countryCode !== 'PE') {
                    $tenant->sunat_mode = Tenant::SUNAT_MODE_DISABLED;
                    $tenant->document_mode = Tenant::DOCUMENT_MODE_TICKET_ONLY;
                    $tenant->fiscal_status = Tenant::FISCAL_STATUS_NOT_CONFIGURED;
                }
                $tenant->save();
                $schemaToProvision = (string) $tenant->schema_name;

                // PostgreSQL DDL participa en esta misma transaccion. Si cualquier tabla o
                // indice falla, tampoco queda una fila de tenant apuntando a un schema parcial.
                $this->tenantSchemaManager->provision($schemaToProvision);

                return $tenant;
            }, 3);
        } catch (\Throwable $exception) {
            if (is_string($schemaToProvision) && $schemaToProvision !== '') {
                Cache::forget('facturador:schema:provisioned:v2:'.$schemaToProvision);
            }

            throw $exception;
        } finally {
            $this->releaseProvisioningLock($externalTenantId);
        }
        $this->forgetCache($tenant);
        $tenant->refresh();

        return $this->response($tenant);
    }

    private function acquireProvisioningLock(string $externalTenantId): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_lock(hashtext(?))', [$externalTenantId]);
        }
    }

    private function releaseProvisioningLock(string $externalTenantId): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_unlock(hashtext(?))', [$externalTenantId]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function response(Tenant $tenant): array
    {
        return [
            'tenant_id' => $tenant->id,
            'external_tenant_id' => $tenant->external_tenant_id,
            'country_code' => $tenant->country_code,
            'tax_id' => $tenant->tax_id,
            'schema' => $tenant->schema_name,
            'document_mode' => $tenant->document_mode,
            'fiscal_status' => $tenant->fiscal_status,
            'sunat_mode' => $tenant->sunat_mode,
            'ticket_enabled' => $tenant->is_active,
            'electronic_documents_enabled' => $tenant->allowsElectronicDocuments(),
        ];
    }

    private function schemaName(string $externalTenantId): string
    {
        $slug = (string) Str::of($externalTenantId)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_');

        return 'azurion_'.substr($slug !== '' ? $slug : 'tenant', 0, 42).'_'.substr(hash('sha256', $externalTenantId), 0, 10);
    }

    private function forgetCache(Tenant $tenant): void
    {
        Cache::forget('facturador:tenant:id:'.$tenant->id);
        Cache::forget('facturador:tenant:ruc:'.$tenant->ruc);
        Cache::forget('facturador:tenant:external:'.$tenant->external_tenant_id);
    }
}
