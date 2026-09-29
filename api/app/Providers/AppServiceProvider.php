<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Modules\Payments\Gateways\FakePaymentGateway;
use Modules\Payments\Gateways\PaymentGatewayInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentGatewayInterface::class, FakePaymentGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // PINs have a smaller search space than passwords, so login needs both
        // an IP-wide limit and a tighter per-identifier limit.
        RateLimiter::for('login', function (Request $request) {
            $identifier = mb_strtolower((string) ($request->input('email')
                ?? $request->input('employee_code')
                ?? $request->input('login')
                ?? $request->input('challenge')
                ?? 'unknown'));

            return [
                Limit::perMinute(30)->by('login-ip:'.$request->ip()),
                Limit::perMinute(10)->by('login-account:'.hash('sha256', $request->ip().'|'.$identifier)),
            ];
        });

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });
    }
}
