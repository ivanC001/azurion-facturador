<?php

namespace App\Domain\Documentos\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class DocumentoRecibido
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly int $documentoId) {}
}
