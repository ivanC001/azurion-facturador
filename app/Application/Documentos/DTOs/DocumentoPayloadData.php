<?php

namespace App\Application\Documentos\DTOs;

final class DocumentoPayloadData
{
    public function __construct(
        public readonly array $empresa,
        public readonly array $cliente,
        public readonly array $documento,
        public readonly array $detalles,
        public readonly array $sucursal = [],
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            empresa: $payload['empresa'] ?? [],
            cliente: $payload['cliente'] ?? [],
            documento: $payload['documento'] ?? [],
            detalles: $payload['detalles'] ?? [],
            sucursal: $payload['sucursal'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'empresa' => $this->empresa,
            'cliente' => $this->cliente,
            'documento' => $this->documento,
            'detalles' => $this->detalles,
            'sucursal' => $this->sucursal,
        ];
    }
}
