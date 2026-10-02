<?php

namespace App\Providers;

use App\Events\PlanChanged;
use App\Listeners\InvalidatePlanCacheListener;
use App\Models\Merchant;
use App\Services\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class, function () {
            return new TenantContext;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register Plan changed cache invalidation listener
        Event::listen(PlanChanged::class, InvalidatePlanCacheListener::class);

        // Configure Rate Limiter: 120 requests per minute per merchant/API key
        RateLimiter::for('api-key', function (Request $request) {
            /** @var Merchant|null $merchant */
            $merchant = $request->attributes->get('merchant') ?? $this->app->make(TenantContext::class)->get();
            $key = $merchant ? 'merchant:'.$merchant->id : ($request->header('X-API-Key') ?? $request->ip());

            return Limit::perMinute(120)
                ->by($key)
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'error' => 'Too Many Requests',
                        'message' => 'Rate limit of 120 requests per minute exceeded.',
                    ], Response::HTTP_TOO_MANY_REQUESTS, $headers);
                });
        });
    }
}
