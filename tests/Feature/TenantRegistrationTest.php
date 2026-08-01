<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('facturador.auth_disabled', false);
    }

    public function test_it_registers_a_tenant_with_jwt_authentication(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        $login = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password',
            'tenant_id' => 1,
        ]);

        $login->assertOk();
        $token = $login->json('data.token');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/tenants', [
                'ruc' => '20601234567',
                'business_name' => 'AZURION SAC',
                'sunat_mode' => 'beta',
                'api_client_name' => 'erp-main',
                'logo_pdf_url' => '20601234567/logos/logo-demo.png',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.ruc', '20601234567')
            ->assertJsonPath('data.schema', 'empresa_azurion_sac_20601234567');

        $this->assertNotEmpty($response->json('data.api_key'));

        $this->assertDatabaseHas('tenants', [
            'ruc' => '20601234567',
            'schema_name' => 'empresa_azurion_sac_20601234567',
        ]);
    }

    public function test_it_is_idempotent_when_tenant_ruc_already_exists(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        $token = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password',
            'tenant_id' => 1,
        ])->json('data.token');

        $payload = [
            'ruc' => '20601234567',
            'business_name' => 'AZURION SAC',
            'sunat_mode' => 'beta',
            'api_client_name' => 'erp-main',
            'logo_pdf_url' => '20601234567/logos/logo-demo.png',
        ];

        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/tenants', $payload)->assertStatus(201);

        $second = $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/tenants', $payload);

        $second->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.ruc', '20601234567')
            ->assertJsonPath('data.already_exists', true);

        $this->assertDatabaseCount('tenants', 1);
    }

    public function test_tenant_user_cannot_request_a_session_for_another_tenant(): void
    {
        User::factory()->create([
            'email' => 'tenant@example.com',
            'password' => bcrypt('password'),
            'tenant_id' => 7,
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'tenant@example.com',
            'password' => 'password',
            'tenant_id' => 8,
        ])->assertUnauthorized();
    }

    public function test_tenant_user_only_lists_and_reads_its_own_tenant(): void
    {
        $ownTenant = Tenant::query()->create([
            'ruc' => '20600000001',
            'business_name' => 'Tenant propio',
            'schema_name' => 'tenant_propio',
            'sunat_mode' => Tenant::SUNAT_MODE_DISABLED,
            'country_code' => 'PE',
            'tax_id' => '20600000001',
            'document_mode' => Tenant::DOCUMENT_MODE_TICKET_ONLY,
            'fiscal_status' => Tenant::FISCAL_STATUS_NOT_CONFIGURED,
            'is_active' => true,
        ]);
        $otherTenant = Tenant::query()->create([
            'ruc' => '20600000002',
            'business_name' => 'Tenant ajeno',
            'schema_name' => 'tenant_ajeno',
            'sunat_mode' => Tenant::SUNAT_MODE_DISABLED,
            'country_code' => 'PE',
            'tax_id' => '20600000002',
            'document_mode' => Tenant::DOCUMENT_MODE_TICKET_ONLY,
            'fiscal_status' => Tenant::FISCAL_STATUS_NOT_CONFIGURED,
            'is_active' => true,
        ]);
        User::factory()->create([
            'email' => 'tenant@example.com',
            'password' => bcrypt('password'),
            'tenant_id' => $ownTenant->id,
        ]);

        $token = $this->postJson('/api/auth/login', [
            'email' => 'tenant@example.com',
            'password' => 'password',
            'tenant_id' => $ownTenant->id,
        ])->json('data.token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/tenants')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.tenant_id', $ownTenant->id);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/tenants/'.$otherTenant->id)
            ->assertForbidden();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/tenants/'.$ownTenant->id)
            ->assertForbidden();
    }
}
