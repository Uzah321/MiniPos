<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\GeneralSettings\Models\PosTill;


class ResolveTillFromToken
{
    public function handle(Request $request, Closure $next, string $mode = 'required')
    {
        if ($mode === 'front-office-only' && $this->usesBackOfficeOnlyToken($request)) {
            return $next($request);
        }

        $rawToken = $request->header('X-Till-Token');

        if (!$rawToken) {
            return response()->json([
                'message' => 'Till is not registered on this browser/device.',
                'code' => 'TILL_NOT_REGISTERED',
            ], 403);
        }

        $company = app('company');

        $hashedToken = hash('sha256', $rawToken);

        $till = PosTill::where('company_id', $company->id)
            ->where('registration_token', $hashedToken)
            ->where('is_registered', true)
            ->where('is_active', true)
            ->with('branch')
            ->first();

        if (!$till) {
            return response()->json([
                'message' => 'Invalid or inactive till registration.',
                'code' => 'INVALID_TILL_TOKEN',
            ], 403);
        }

        if (!$till->branch) {
            return response()->json([
                'message' => 'Till branch could not be resolved.',
                'code' => 'TILL_BRANCH_MISSING',
            ], 422);
        }

        $seenAt = now();
        $presenceCutoff = $seenAt->copy()->subSeconds(30);
        $till->recordPresence([
            'last_seen_at' => $seenAt,
            'last_ip_address' => $request->ip(),
            'last_user_agent' => $request->userAgent(),
        ], $presenceCutoff);

        app()->instance('till', $till);
        $request->attributes->set('resolved_till', $till);

        return $next($request);
    }

    private function usesBackOfficeOnlyToken(Request $request): bool
    {
        $user = $request->user();
        $token = $user && method_exists($user, 'currentAccessToken')
            ? $user->currentAccessToken()
            : null;

        if (! $token || ! method_exists($token, 'can')) {
            return false;
        }

        return $token->can('workspace:back-office')
            && ! $token->can('workspace:front-office');
    }
}
