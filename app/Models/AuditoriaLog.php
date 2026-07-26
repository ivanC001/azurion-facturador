<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditoriaLog extends Model
{
    protected $table = 'auditoria';

    protected $fillable = [
        'action',
        'documento_id',
        'payload',
        'performed_at',
    ];

    public $timestamps = false;

    protected $casts = [
        'payload' => 'array',
        'performed_at' => 'datetime',
    ];
}