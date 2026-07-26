<?php

namespace App\Application\Sucursales\UseCases;

use App\Infrastructure\Tenant\TenantSchemaManager;
use App\Support\Tenants\TenantContext;
use Illuminate\Support\Facades\DB;

final class ListSucursalesUseCase
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantSchemaManager $tenantSchemaManager,
    ) {}

    public function execute(): array
    {
        $tenant = $this->tenantContext->required();
        $this->tenantSchemaManager->provision($tenant->schema);

        $items = DB::table('sucursales')
            ->orderBy('id')
            ->get()
            ->map(function (object $sucursal): array {
                return $this->formatSucursal($sucursal);
            })
            ->all();

        return [
            'total' => count($items),
            'items' => $items,
        ];
    }

    private function formatSucursal(object $sucursal): array
    {
        $series = DB::table('series')
            ->where('sucursal_codigo', $sucursal->codigo)
            ->orderBy('tipo_documento')
            ->get()
            ->map(fn (object $serie): array => [
                'tipo_documento' => $serie->tipo_documento,
                'serie' => $serie->serie,
                'correlativo_actual' => (int) $serie->correlativo_actual,
                'is_active' => (bool) $serie->is_active,
            ])
            ->all();

        return [
            'id' => $sucursal->id,
            'codigo' => $sucursal->codigo,
            'numero' => (int) $sucursal->numero,
            'nombre' => $sucursal->nombre,
            'direccion' => $sucursal->direccion,
            'ubigeo' => $sucursal->ubigeo,
            'departamento' => $sucursal->departamento,
            'provincia' => $sucursal->provincia,
            'distrito' => $sucursal->distrito,
            'cod_local' => $sucursal->cod_local,
            'is_active' => (bool) $sucursal->is_active,
            'series' => $series,
        ];
    }
}
