<?php

namespace App\Application\Documentos\UseCases;

use App\Domain\Documentos\Contracts\DocumentoRepository;

final class GetDocumentoUseCase
{
    public function __construct(private readonly DocumentoRepository $documentoRepository) {}

    public function execute(int $id): array
    {
        $documento = $this->documentoRepository->findOrFail($id);

        return [
            'id' => $documento->id,
            'tipo_documento' => $documento->tipo_documento,
            'serie' => $documento->serie,
            'correlativo' => $documento->correlativo,
            'estado' => $documento->estado,
            'ticket' => $documento->ticket,
            'hash' => $documento->hash,
            'submitted_by_user_id' => $documento->submitted_by_user_id,
            'submitted_by_email' => $documento->submitted_by_email,
            'submitted_by_api_client_id' => $documento->submitted_by_api_client_id,
            'submitted_by_auth_mode' => $documento->submitted_by_auth_mode,
            'sucursal' => $documento->sucursal,
            'payload' => $documento->payload,
        ];
    }
}
