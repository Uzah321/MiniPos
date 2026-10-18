<?php

namespace App\Providers;

use App\Services\Auth\ManagerApprovalRateLimiter;
use App\Services\Auth\PosLoginRateLimiter;
use App\Services\Sync\SyncConfigurationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(
        PosLoginRateLimiter $loginRateLimiter,
        ManagerApprovalRateLimiter $managerApprovalRateLimiter
    ): void {
        RateLimiter::for(
            PosLoginRateLimiter::LOGIN,
            fn (Request $request) => $loginRateLimiter->login($request)
        );

        RateLimiter::for(
            PosLoginRateLimiter::LOGIN_MODE,
            fn (Request $request) => $loginRateLimiter->loginMode($request)
        );

        RateLimiter::for(
            ManagerApprovalRateLimiter::VERIFY,
            fn (Request $request) => $managerApprovalRateLimiter->verify($request)
        );

        foreach (['eloquent.saved: *', 'eloquent.deleted: *', 'eloquent.restored: *'] as $event) {
            Event::listen($event, function (string $eventName, array $payload): void {
                $model = $payload[0] ?? null;
                if ($model instanceof Model) {
                    try {
                        app(SyncConfigurationService::class)->markModelDirty($model);
                    } catch (Throwable $exception) {
                        // A configuration mutation must remain durable even if
                        // the optional synchronization signal cannot be queued.
                        // The periodic full pull/Force Update path remains the
                        // recovery mechanism, while this warning is actionable.
                        Log::warning('Unable to queue configuration sync signal.', [
                            'model' => $model::class,
                            'id' => (string) $model->getKey(),
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            });
        }
    }
}
