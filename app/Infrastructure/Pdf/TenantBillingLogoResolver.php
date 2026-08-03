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
        if (trim((string) ($company['logo_pdf_url'] ?? $company['logo_url'] ?? '')) !== '') {
            return $company;
        }

        try {
            $logo = DB::table('configuracion_facturacion')->orderBy('id')->value('logo_pdf_url');
        } catch (\Throwable) {
            $logo = null;
        }

        if (is_string($logo) && trim($logo) !== '') {
            $company['logo_pdf_url'] = trim($logo);
        }

        return $company;
    }
}
