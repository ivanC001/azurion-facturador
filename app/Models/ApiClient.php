<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiClient extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'name',
        'api_key_hash',
        'is_active',
        'last_used_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function getTable()
    {
        if (config('database.default') === 'pgsql') {
            return 'public.api_clients';
        }

        return 'api_clients';
    }
}
