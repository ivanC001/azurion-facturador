<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'ruc',
        'business_name',
        'schema_name',
        'sunat_mode',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function apiClients(): HasMany
    {
        return $this->hasMany(ApiClient::class);
    }

    public function getTable()
    {
        if (config('database.default') === 'pgsql') {
            return 'public.tenants';
        }

        return 'tenants';
    }
}
