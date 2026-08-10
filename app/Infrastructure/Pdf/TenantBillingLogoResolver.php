<?php

namespace App\Infrastructure\Pdf;

use Illuminate\Support\Facades\DB;

final class TenantBillingLogoResolver
{
    /**
     * @param  array<string, mixed>  $company
     * @return array<string, mixed>
     */
    public function resolve(array $company): array
    {
        $needsLogo = trim((string) ($company['logo_pdf_url'] ?? $company['logo_url'] ?? '')) === '';
        $needsBankAccounts = ! is_array($company['cuentas_bancarias'] ?? null);
        if (! $needsLogo && ! $needsBankAccounts) {
            return $company;
        }

        try {
            $config = DB::table('configuracion_facturacion')->orderBy('id')->first([
                'logo_pdf_url',
                'cuentas_bancarias',
            ]);
        } catch (\Throwable) {
            try {
                $logo = DB::table('configuracion_facturacion')->orderBy('id')->value('logo_pdf_url');
                $config = (object) ['logo_pdf_url' => $logo];
            } catch (\Throwable) {
                $config = null;
            }
        }

        if ($needsLogo && is_string($config->logo_pdf_url ?? null) && trim($config->logo_pdf_url) !== '') {
            $company['logo_pdf_url'] = trim($config->logo_pdf_url);
        }

        if ($needsBankAccounts) {
            $accounts = $config->cuentas_bancarias ?? null;
            if (is_string($accounts) && trim($accounts) !== '') {
                $accounts = json_decode($accounts, true);
            }
            $company['cuentas_bancarias'] = is_array($accounts) ? array_values($accounts) : [];
        }

        return $company;
    }
}
