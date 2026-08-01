<?php

namespace App\Http\Controllers\Api;

use App\Application\Tenants\UseCases\ProvisionTenantFromAzurionUseCase;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AzurionTenantProvisioningController
{
    public function __construct(
        private readonly ProvisionTenantFromAzurionUseCase $provisionTenantFromAzurionUseCase,
    ) {
    }

    public function upsert(Request $request, string $externalTenantId): JsonResponse
    {
        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'country_code' => ['required', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'tax_id' => ['nullable', 'string', 'max:40'],
            'active' => ['nullable', 'boolean'],
        ]);

        return ApiResponse::success(
            $this->provisionTenantFromAzurionUseCase->execute($externalTenantId, $data),
        );
    }
}
