<?php

namespace Tests\Unit;

use App\Application\Documentos\Services\DocumentEmissionPolicy;
use App\Models\Tenant;
use App\Support\Tenants\TenantContext;
use App\Support\Tenants\TenantIdentity;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class DocumentEmissionPolicyTest extends TestCase
{
    public function test_ticket_is_allowed_for_a_foreign_ticket_only_tenant(): void
    {
        $policy = $this->policy('US', Tenant::DOCUMENT_MODE_TICKET_ONLY, Tenant::FISCAL_STATUS_NOT_CONFIGURED, Tenant::SUNAT_MODE_DISABLED);

        $policy->assertAllowed('TK');

        $this->assertTrue(true);
    }

    #[DataProvider('electronicBlockedCases')]
    public function test_electronic_documents_require_peru_and_active_fiscal_configuration(
        string $country,
        string $documentMode,
        string $fiscalStatus,
        string $sunatMode,
    ): void {
        $policy = $this->policy($country, $documentMode, $fiscalStatus, $sunatMode);

        $this->expectException(ValidationException::class);

        $policy->assertAllowed('03');
    }

    public function test_electronic_document_is_allowed_for_an_active_peruvian_tenant(): void
    {
        $policy = $this->policy('PE', Tenant::DOCUMENT_MODE_ELECTRONIC, Tenant::FISCAL_STATUS_ACTIVE, Tenant::SUNAT_MODE_PRODUCTION);

        $policy->assertAllowed('01');

        $this->assertTrue(true);
    }

    public static function electronicBlockedCases(): array
    {
        return [
            'foreign tenant' => ['US', Tenant::DOCUMENT_MODE_ELECTRONIC, Tenant::FISCAL_STATUS_ACTIVE, Tenant::SUNAT_MODE_PRODUCTION],
            'ticket only' => ['PE', Tenant::DOCUMENT_MODE_TICKET_ONLY, Tenant::FISCAL_STATUS_NOT_CONFIGURED, Tenant::SUNAT_MODE_DISABLED],
            'suspended fiscal config' => ['PE', Tenant::DOCUMENT_MODE_ELECTRONIC, Tenant::FISCAL_STATUS_SUSPENDED, Tenant::SUNAT_MODE_PRODUCTION],
            'sunat disabled' => ['PE', Tenant::DOCUMENT_MODE_ELECTRONIC, Tenant::FISCAL_STATUS_ACTIVE, Tenant::SUNAT_MODE_DISABLED],
        ];
    }

    private function policy(
        string $country,
        string $documentMode,
        string $fiscalStatus,
        string $sunatMode,
    ): DocumentEmissionPolicy {
        $context = new TenantContext;
        $context->set(new TenantIdentity(
            tenantId: 1,
            ruc: 'TAX-001',
            schema: 'tenant_test',
            sunatMode: $sunatMode,
            countryCode: $country,
            documentMode: $documentMode,
            fiscalStatus: $fiscalStatus,
        ));

        return new DocumentEmissionPolicy($context);
    }
}
