<?php

namespace App\Application\Sucursales\UseCases;

use App\Infrastructure\Tenant\TenantSchemaManager;
use App\Support\Tenants\TenantContext;
use Illuminate\Support\Facades\DB;

final class DeleteSucursalUseCase
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantSchemaManager $tenantSchemaManager,
    ) {}

    public function execute(int $id): array
    {
        $tenant = $this->tenantContext->required();
        $this->tenantSchemaManager->provision($tenant->schema);

        $sucursal = DB::table('sucursales')->where('id', $id)->first();
        if ($sucursal === null) {
            abort(404, 'Sucursal not found.');
        }

        DB::table('sucursales')->where('id', $id)->update([
            'is_active' => false,
            'updated_at' => now(),
        ]);

        DB::table('series')->where('sucursal_codigo', $sucursal->codigo)->update([
            'is_active' => false,
            'updated_at' => now(),
        ]);

        return [
            'id' => $id,
            'codigo' => $sucursal->codigo,
            'deleted' => true,
            'is_active' => false,
        ];
    }
}
