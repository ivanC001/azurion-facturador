<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentoSunat extends Model
{
    protected $table = 'documento_sunat';

    protected $fillable = [
        'documento_id',
        'estado',
        'codigo_error',
        'mensaje',
    ];
}