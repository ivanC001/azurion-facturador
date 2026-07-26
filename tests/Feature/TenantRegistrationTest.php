<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantRegistrationTest extends TestCase
{
    use RefreshDatabase;

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
}
