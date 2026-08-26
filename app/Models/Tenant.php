<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory;

    public const DOCUMENT_MODE_TICKET_ONLY = 'ticket_only';

    public const DOCUMENT_MODE_ELECTRONIC = 'electronic';

    public const FISCAL_STATUS_NOT_CONFIGURED = 'not_configured';

    public const FISCAL_STATUS_ACTIVE = 'active';

    public const FISCAL_STATUS_SUSPENDED = 'suspended';

    public const SUNAT_MODE_DISABLED = 'disabled';

    public const SUNAT_MODE_BETA = 'beta';

    public const SUNAT_MODE_PRODUCTION = 'production';

    protected $fillable = [
        'ruc',
        'business_name',
        'schema_name',
        'sunat_mode',
        'external_tenant_id',
        'country_code',
        'tax_id',
        'document_mode',
        'fiscal_status',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function apiClients(): HasMany
    {
        return $this->hasMany(ApiClient::class);
    }

    public function allowsElectronicDocuments(): bool
    {
        return strtoupper((string) $this->country_code) === 'PE'
            && $this->document_mode === self::DOCUMENT_MODE_ELECTRONIC
            && $this->fiscal_status === self::FISCAL_STATUS_ACTIVE
            && in_array($this->sunat_mode, [self::SUNAT_MODE_BETA, self::SUNAT_MODE_PRODUCTION], true);
    }

    public function getTable()
    {
        if (config('database.default') === 'pgsql') {
            return 'public.tenants';
        }

        return 'tenants';
    }
}
