<?php

namespace App\Domain\Documentos\Contracts;

use App\Models\Documento;

interface DocumentoRepository
{
    public function create(array $payload): Documento;

    public function findOrFail(int $id): Documento;

    public function markProcessing(Documento $documento): void;

    public function markResult(Documento $documento, string $estado, ?string $ticket = null, ?string $hash = null): void;
}
