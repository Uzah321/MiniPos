<?php

namespace App\Http\Middleware;

use App\Services\Auth\SuperAdminService;
use App\Services\License\LicenseAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckCompanyLicense
{
    public function __construct(
        private readonly LicenseAccessService $access,
        private readonly SuperAdminService $superAdmins,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->bound('company')) {
            if ($request->attributes->getBoolean('platform_context_required')
                && $this->superAdmins->hasPlatformSessionAccess($request->user())) {
                return $next($request);
            }

            return response()->json([
                'message' => 'Company not resolved.',
                'code' => 'COMPANY_NOT_RESOLVED',
                'force_logout' => false,
                'server_date' => now()->toDateString(),
            ], 400);
        }

        $company = app('company');
        $status = $this->access->enforce($company, $request->user());

        if (! $status['valid']) {
            return response()->json($this->access->responsePayload($status), 423);
        }

        $license = $company->licenses()
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->latest('created_at')
            ->first();

        if ($license) {
            app()->instance('license', $license);
        }
        app()->instance('license.status', $status);

        return $next($request);
    }
}
