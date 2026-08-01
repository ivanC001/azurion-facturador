<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireFacturadorManagementMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if ((bool) config('facturador.auth_disabled', false)) {
            return $next($request);
        }

        if ($request->attributes->get('auth_mode') !== 'jwt') {
            return response()->json([
                'success' => false,
                'message' => 'Facturador management credentials are required.',
            ], 403);
        }

        return $next($request);
    }
}
