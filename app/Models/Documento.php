<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Documento extends Model
{
    protected $table = 'documentos';

    protected $fillable = [
        'tipo_documento',
        'external_id',
        'serie',
        'correlativo',
        'estado',
        'payload',
        'empresa',
        'cliente',
        'sucursal',
        'submitted_by_user_id',
        'submitted_by_email',
        'submitted_by_api_client_id',
        'submitted_by_auth_mode',
        'hash',
        'ticket',
    ];

    protected $casts = [
        'payload' => 'array',
        'empresa' => 'array',
        'cliente' => 'array',
        'sucursal' => 'array',
    ];

    public function sunat(): HasOne
    {
        return $this->hasOne(DocumentoSunat::class, 'documento_id');
    }
}
