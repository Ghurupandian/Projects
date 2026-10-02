<?php

namespace App\Http\Middleware;

use App\Models\Merchant;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    public function __construct(
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('X-API-Key');

        if (empty($apiKey)) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Missing X-API-Key header.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $hash = Merchant::hashApiKey($apiKey);
        $merchant = Merchant::where('api_key_hash', $hash)->first();

        if (! $merchant) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Invalid API key.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $this->tenantContext->set($merchant);
        $request->attributes->set('merchant', $merchant);

        return $next($request);
    }
}
