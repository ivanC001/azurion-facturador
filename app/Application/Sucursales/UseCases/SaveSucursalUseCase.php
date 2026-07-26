<?php

namespace App\Application\Sucursales\UseCases;

use App\Infrastructure\Tenant\TenantSchemaManager;
use App\Support\Tenants\TenantContext;
use Illuminate\Support\Facades\DB;

final class SaveSucursalUseCase
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantSchemaManager $tenantSchemaManager,
    ) {}

    public function create(array $payload): array
    {
        $tenant = $this->tenantContext->required();
        $this->tenantSchemaManager->provision($tenant->schema);

        return DB::transaction(function () use ($payload): array {
            $numero = $this->nextSucursalNumber();

            $id = DB::table('sucursales')->insertGetId([
                'codigo' => $payload['codigo'],
                'numero' => $numero,
                'nombre' => $payload['nombre'],
                'direccion' => $payload['direccion'] ?? null,
                'ubigeo' => $payload['ubigeo'] ?? null,
                'departamento' => $payload['departamento'] ?? null,
                'provincia' => $payload['provincia'] ?? null,
                'distrito' => $payload['distrito'] ?? null,
                'cod_local' => $payload['cod_local'] ?? '0000',
                'is_active' => $payload['is_active'] ?? true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->syncSeries(
                (int) $id,
                (string) $payload['codigo'],
                $this->buildInitialSeries($numero, (array) ($payload['series'] ?? [])),
            );

            return $this->find((int) $id);
        });
    }

    public function update(int $id, array $payload): array
    {
        $tenant = $this->tenantContext->required();
        $this->tenantSchemaManager->provision($tenant->schema);

        return DB::transaction(function () use ($id, $payload): array {
            $sucursal = DB::table('sucursales')->where('id', $id)->first();
            if ($sucursal === null) {
                abort(404, 'Sucursal not found.');
            }

            $changes = [];
            foreach (['nombre', 'direccion', 'ubigeo', 'departamento', 'provincia', 'distrito', 'cod_local', 'is_active'] as $field) {
                if (array_key_exists($field, $payload)) {
                    $changes[$field] = $payload[$field];
                }
            }

            if ($changes !== []) {
                $changes['updated_at'] = now();
                DB::table('sucursales')->where('id', $id)->update($changes);
            }

            if (array_key_exists('series', $payload)) {
                $this->syncSeries($id, (string) $sucursal->codigo, (array) $payload['series']);
            }

            return $this->find($id);
        });
    }

    public function find(int $id): array
    {
        $sucursal = DB::table('sucursales')->where('id', $id)->first();
        if ($sucursal === null) {
            abort(404, 'Sucursal not found.');
        }

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

    private function syncSeries(int $sucursalId, string $sucursalCodigo, array $series): void
    {
        foreach ($series as $serie) {
            if (! is_array($serie)) {
                continue;
            }

            DB::table('series')->updateOrInsert(
                [
                    'sucursal_codigo' => $sucursalCodigo,
                    'tipo_documento' => $serie['tipo_documento'],
                ],
                [
                    'sucursal_id' => $sucursalId,
                    'serie' => $serie['serie'],
                    'correlativo_actual' => $serie['correlativo_actual'] ?? 0,
                    'is_active' => $serie['is_active'] ?? true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    private function nextSucursalNumber(): int
    {
        $last = DB::table('sucursales')
            ->orderByDesc('numero')
            ->lockForUpdate()
            ->first();

        return ((int) ($last->numero ?? 0)) + 1;
    }

    /**
     * @param array<int, array<string, mixed>> $overrides
     * @return array<int, array<string, mixed>>
     */
    private function buildInitialSeries(int $sucursalNumber, array $overrides): array
    {
        $series = $this->defaultSeries($sucursalNumber);

        foreach ($overrides as $override) {
            if (! is_array($override) || ! isset($override['tipo_documento'])) {
                continue;
            }

            $series[(string) $override['tipo_documento']] = array_merge(
                $series[(string) $override['tipo_documento']] ?? [],
                $override,
            );
        }

        return array_values($series);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function defaultSeries(int $sucursalNumber): array
    {
        $three = str_pad((string) $sucursalNumber, 3, '0', STR_PAD_LEFT);
        $two = str_pad((string) $sucursalNumber, 2, '0', STR_PAD_LEFT);

        return [
            '01' => ['tipo_documento' => '01', 'serie' => 'F'.$three, 'correlativo_actual' => 0, 'is_active' => true],
            '03' => ['tipo_documento' => '03', 'serie' => 'B'.$three, 'correlativo_actual' => 0, 'is_active' => true],
            '07' => ['tipo_documento' => '07', 'serie' => 'FC'.$two, 'correlativo_actual' => 0, 'is_active' => true],
            '08' => ['tipo_documento' => '08', 'serie' => 'FD'.$two, 'correlativo_actual' => 0, 'is_active' => true],
            '09' => ['tipo_documento' => '09', 'serie' => 'T'.$three, 'correlativo_actual' => 0, 'is_active' => true],
            'TK' => ['tipo_documento' => 'TK', 'serie' => 'TK'.$two, 'correlativo_actual' => 0, 'is_active' => true],
        ];
    }
}
