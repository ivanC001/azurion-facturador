<?php

namespace App\Domain\Sunat\Contracts;

use App\Models\Documento;

interface SunatSender
{
    /**
     * @return array{estado:string,ticket?:string,hash?:string,mensaje?:string,codigo_error?:string,xml?:string,pdf?:string,cdr?:string}
     */
    public function send(Documento $documento): array;
}