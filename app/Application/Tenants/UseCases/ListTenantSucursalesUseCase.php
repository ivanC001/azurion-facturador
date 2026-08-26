<?php

namespace App\Application\Tenants\UseCases;

use App\Infrastructure\Tenant\TenantSchemaManager;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

final class ListTenantSucursalesUseCase
{
    public function __construct(private readonly TenantSchemaManager $tenantSchemaManager) {}

    public function execute(int $tenantId): array
    {
        $tenant = Tenant::query()->findOrFail($tenantId);
        $schema = (string) $tenant->schema_name;

        if (! preg_match('/^[a-zA-Z0-9_]+$/', $schema)) {
            abort(422, 'Tenant schema is invalid.');
        }

        $this->tenantSchemaManager->provision($schema);

        $sucursales = DB::table($schema.'.sucursales')
            ->orderBy('numero')
            ->orderBy('id')
            ->get()
            ->map(fn (object $sucursal): array => $this->formatSucursal($schema, $sucursal))
            ->all();

        return [
            'tenant' => [
                'tenant_id' => $tenant->id,
                'ruc' => $tenant->ruc,
                'business_name' => $tenant->business_name,
                'schema' => $tenant->schema_name,
                'sunat_mode' => $tenant->sunat_mode,
                'is_active' => (bool) $tenant->is_active,
            ],
            'total' => count($sucursales),
            'items' => $sucursales,
        ];
    }

    private function formatSucursal(string $schema, object $sucursal): array
    {
        $series = DB::table($schema.'.series')
            ->where('sucursal_codigo', $sucursal->codigo)
            ->orderBy('tipo_documento')
            ->get()
            ->map(fn (object $serie): array => [
                'tipo_documento' => $serie->tipo_documento,
                'serie' => $serie->serie,
                'siguiente_correlativo' => ((int) $serie->correlativo_actual) + 1,
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
