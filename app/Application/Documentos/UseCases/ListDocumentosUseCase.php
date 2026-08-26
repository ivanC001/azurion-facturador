<?php

namespace App\Application\Documentos\UseCases;

use App\Models\Documento;
use Illuminate\Database\Eloquent\Builder;

final class ListDocumentosUseCase
{
    /**
     * @param array{
     *   external_ids?: array<int, string>,
     *   q?: string|null,
     *   estado?: string|null,
     *   tipo_documento?: string|null,
     *   limit?: int|null
     * } $filters
     * @return array{
     *   total: int,
     *   items: array<int, array<string, mixed>>
     * }
     */
    public function execute(array $filters = []): array
    {
        $limit = max(1, min(200, (int) ($filters['limit'] ?? 100)));

        $query = Documento::query()
            ->with('sunat')
            ->orderByDesc('id');

        $this->applyExternalIdsFilter($query, (array) ($filters['external_ids'] ?? []));
        $this->applyEstadoFilter($query, $filters['estado'] ?? null);
        $this->applyTipoFilter($query, $filters['tipo_documento'] ?? null);
        $this->applySearchFilter($query, $filters['q'] ?? null);

        // Se piden $limit + 1 filas: si vuelven menos, ya se conoce el total y
        // se evita repetir todo el filtrado en un COUNT aparte, que es el caso
        // habitual. Solo cuando hay mas paginas hace falta contar.
        $rows = (clone $query)->limit($limit + 1)->get();
        $total = $rows->count() > $limit ? $query->count() : $rows->count();
        $rows = $rows->take($limit);

        return [
            'total' => $total,
            'items' => $rows->map(fn (Documento $documento): array => $this->toRow($documento))->values()->all(),
        ];
    }

    /**
     * @param  array<int, string>  $externalIds
     */
    private function applyExternalIdsFilter(Builder $query, array $externalIds): void
    {
        $ids = collect($externalIds)
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->take(200)
            ->values()
            ->all();

        if ($ids === []) {
            return;
        }

        // Se filtra por la columna dedicada, que tiene indice unico, en vez de
        // por la expresion JSON del payload.
        $query->whereIn('external_id', $ids);
    }

    /**
     * Los estados se persisten siempre en mayusculas (DocumentStatus), asi que
     * comparar la columna tal cual permite usar documentos_estado_id_idx.
     * Envolverla en UPPER() descartaba el indice en cada listado.
     */
    private function applyEstadoFilter(Builder $query, ?string $estado): void
    {
        $normalized = strtoupper(trim((string) $estado));
        if ($normalized === '') {
            return;
        }

        $query->where('estado', $normalized);
    }

    private function applyTipoFilter(Builder $query, ?string $tipoDocumento): void
    {
        $normalized = strtoupper(trim((string) $tipoDocumento));
        if ($normalized === '') {
            return;
        }

        $query->where('tipo_documento', $normalized);
    }

    private function applySearchFilter(Builder $query, ?string $search): void
    {
        $needle = mb_strtolower(trim((string) $search));
        if ($needle === '') {
            return;
        }

        // Busqueda libre: no puede aprovechar indices por el comodin inicial,
        // pero se ejecuta ya acotada al esquema del tenant.
        $like = '%'.$needle.'%';
        $query->where(function (Builder $inner) use ($like): void {
            $inner->whereRaw("LOWER(COALESCE(external_id, '')) LIKE ?", [$like])
                ->orWhereRaw("LOWER(COALESCE(serie, '')) LIKE ?", [$like])
                ->orWhereRaw("LOWER(COALESCE(correlativo, '')) LIKE ?", [$like])
                ->orWhereRaw("LOWER(COALESCE(estado, '')) LIKE ?", [$like])
                ->orWhereRaw("LOWER(COALESCE(cliente->>'razon_social', cliente->>'nombre', '')) LIKE ?", [$like])
                ->orWhereRaw("LOWER(COALESCE(cliente->>'num_doc', '')) LIKE ?", [$like]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(Documento $documento): array
    {
        $externalId = trim((string) ($documento->external_id ?? data_get($documento->payload, 'documento.external_id', '')));
        $clienteNombre = (string) (data_get($documento->cliente, 'razon_social') ?? data_get($documento->cliente, 'nombre') ?? '');
        $clienteDocumento = (string) (data_get($documento->cliente, 'num_doc') ?? '');

        $sunatEstado = $documento->sunat?->estado;
        $sunatMensaje = $documento->sunat?->mensaje;
        $sunatCodigoError = $documento->sunat?->codigo_error;
        $empresaRuc = (string) data_get($documento->empresa, 'ruc', data_get($documento->payload, 'empresa.ruc', ''));

        return [
            'id' => $documento->id,
            'external_id' => $externalId !== '' ? $externalId : null,
            'tipo_documento' => $documento->tipo_documento,
            'serie' => $documento->serie,
            'correlativo' => $documento->correlativo,
            'empresa_ruc' => $empresaRuc !== '' ? $empresaRuc : null,
            'comprobante' => sprintf('%s-%s', (string) $documento->serie, (string) $documento->correlativo),
            'estado' => $documento->estado,
            'sunat_estado' => $sunatEstado,
            'sunat_mensaje' => $sunatMensaje,
            'sunat_codigo_error' => $sunatCodigoError,
            'ticket' => $documento->ticket,
            'hash' => $documento->hash,
            'cliente_nombre' => $clienteNombre !== '' ? $clienteNombre : null,
            'cliente_documento' => $clienteDocumento !== '' ? $clienteDocumento : null,
            'moneda' => data_get($documento->payload, 'documento.moneda'),
            'total' => data_get($documento->payload, 'documento.total'),
            'fecha_emision' => data_get($documento->payload, 'documento.fecha_emision'),
            'created_at' => $documento->created_at?->toIso8601String(),
            'updated_at' => $documento->updated_at?->toIso8601String(),
        ];
    }
}
