<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireAzurionIntegrationMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get('auth_mode') !== 'azurion_integration') {
            return response()->json([
                'success' => false,
                'message' => 'Azurion integration credentials are required.',
            ], 403);
        }

        return $next($request);
    }
}
