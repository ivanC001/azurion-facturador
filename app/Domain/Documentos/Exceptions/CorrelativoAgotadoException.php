<?php

namespace App\Domain\Documentos\Exceptions;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * La serie agoto el rango de correlativos que SUNAT admite (8 digitos).
 *
 * Nunca debe resolverse reiniciando la numeracion: emitir de nuevo el
 * correlativo 1 duplicaria comprobantes ya declarados. La unica salida
 * valida es dar de alta una serie nueva para ese tipo de documento.
 */
final class CorrelativoAgotadoException extends RuntimeException implements HttpExceptionInterface
{
    public function __construct(
        private readonly string $tipoDocumento,
        private readonly string $serie,
        private readonly int $correlativo,
        private readonly int $maximo,
    ) {
        parent::__construct(sprintf(
            'La serie %s del tipo de documento %s agoto su rango de correlativos (%d). '
            .'Registra una serie nueva para seguir emitiendo.',
            $serie,
            $tipoDocumento,
            $maximo,
        ));
    }

    public function getStatusCode(): int
    {
        return 422;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'tipo_documento' => $this->tipoDocumento,
            'serie' => $this->serie,
            'correlativo' => $this->correlativo,
            'maximo' => $this->maximo,
        ];
    }
}
