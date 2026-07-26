<?php

namespace App\Application\Sunat\UseCases;

use App\Models\Documento;

final class GetSunatStatusUseCase
{
    public function execute(?int $documentoId): array
    {
        if ($documentoId === null) {
            throw new \InvalidArgumentException('documento_id is required');
        }

        $documento = Documento::query()->findOrFail($documentoId);

        return [
            'documento_id' => $documento->id,
            'tipo_documento' => $documento->tipo_documento,
            'serie' => $documento->serie,
            'correlativo' => $documento->correlativo,
            'empresa_ruc' => (string) data_get($documento->empresa, 'ruc', data_get($documento->payload, 'empresa.ruc', '00000000000')),
            'estado' => $documento->estado,
            'ticket' => $documento->ticket,
            'hash' => $documento->hash,
        ];
    }
}
