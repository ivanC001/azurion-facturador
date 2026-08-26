<?php

namespace App\Console\Commands;

use App\Models\ApiClient;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Emite una API key para un tenant.
 *
 * Sustituye a scripts/sync_test_api_key.php, que llevaba una clave fija
 * publicada en el repositorio, la daba de alta en todos los tenants a la vez
 * y ademas la guardaba en claro en configuracion_facturacion.token_api.
 * Aqui la clave es aleatoria, se muestra una unica vez y solo se persiste
 * su hash.
 */
final class IssueTenantApiKeyCommand extends Command
{
    protected $signature = 'facturador:api-key
        {ruc : RUC del tenant}
        {--name=azurion-erp : Nombre del cliente API}
        {--revoke : Desactiva la clave existente en lugar de emitir una nueva}';

    protected $description = 'Emite o revoca la API key de un tenant';

    public function handle(): int
    {
        $ruc = trim((string) $this->argument('ruc'));
        $tenant = Tenant::query()->where('ruc', $ruc)->first();

        if ($tenant === null) {
            $this->error('No existe ningun tenant con el RUC '.$ruc.'.');

            return self::FAILURE;
        }

        $name = (string) $this->option('name');

        if ($this->option('revoke')) {
            $revoked = ApiClient::query()
                ->where('tenant_id', $tenant->id)
                ->where('name', $name)
                ->update(['is_active' => false]);

            $this->info(sprintf('Claves desactivadas: %d.', $revoked));

            return self::SUCCESS;
        }

        $plainApiKey = 'azf_'.Str::random(48);

        ApiClient::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'name' => $name],
            ['api_key_hash' => hash('sha256', $plainApiKey), 'is_active' => true],
        );

        $this->info(sprintf('API key emitida para %s (%s).', $tenant->business_name, $tenant->ruc));
        $this->newLine();
        $this->warn($plainApiKey);
        $this->newLine();
        $this->line('Solo se guarda su hash: copiala ahora porque no vuelve a mostrarse.');
        $this->line('La clave anterior de este cliente API queda invalidada.');

        return self::SUCCESS;
    }
}
