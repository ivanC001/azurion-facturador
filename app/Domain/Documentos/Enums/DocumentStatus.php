<?php

namespace App\Domain\Documentos\Enums;

enum DocumentStatus: string
{
    case RECEIVED = 'RECIBIDO';
    case REGISTERED = 'REGISTRADO';
    case IN_PROCESS = 'EN_PROCESO';
    case ACCEPTED = 'ACEPTADO';
    case REJECTED = 'RECHAZADO';
    case ERROR = 'ERROR';
}
