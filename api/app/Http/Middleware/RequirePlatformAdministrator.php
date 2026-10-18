<?php

namespace App\Http\Middleware;

use App\Services\Auth\SuperAdminService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePlatformAdministrator
{
    public function __construct(private readonly SuperAdminService $superAdmins) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (config('salexa_sync.deployment_mode') === 'edge'
            || (bool) config('salexa_sync.local_hub', false)) {
            return response()->json([
                'message' => 'Platform administration is unavailable on this local installation.',
                'code' => 'DEPLOYMENT_WORKSPACE_DENIED',
            ], 403);
        }

        if (! $this->superAdmins->isPlatformAdministrator($request->user())) {
            return response()->json([
                'message' => 'Super Admin platform access is required.',
                'code' => 'PLATFORM_ADMIN_REQUIRED',
            ], 403);
        }
        $token = $request->user()?->currentAccessToken();
        if (! $token
            || ! method_exists($token, 'can')
            || ! $token->can('platform:admin')
            || ! $token->can('workspace:back-office')) {
            return response()->json([
                'message' => 'This session was not issued for Back Office platform administration.',
                'code' => 'PLATFORM_TOKEN_REQUIRED',
            ], 403);
        }

        return $next($request);
    }
}
