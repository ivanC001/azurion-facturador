<?php

namespace App\Http\Middleware;

use App\Support\Tenants\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class SetTenantSearchPathMiddleware
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->tenantContext->required();

        if (! Str::contains((string) DB::connection()->getDriverName(), 'pgsql')) {
            try {
                return $next($request);
            } finally {
                $this->tenantContext->clear();
            }
        }

        if (! preg_match('/^[a-zA-Z0-9_]+$/', $tenant->schema)) {
            $this->tenantContext->clear();

            return response()->json([
                'success' => false,
                'message' => 'Invalid tenant schema.',
            ], 400);
        }

        DB::statement(sprintf('SET search_path TO "%s", public', $tenant->schema));

        try {
            return $next($request);
        } finally {
            try {
                DB::statement('SET search_path TO public');
            } finally {
                $this->tenantContext->clear();
            }
        }
    }
}
