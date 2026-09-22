<?php

namespace Modules\Authentication\Http\Middleware;

use App\Models\Store;
use Closure;
use Illuminate\Http\Request;
use Modules\Authentication\Data\AccessContext;
use Modules\Authentication\Models\Brand;
use Modules\Authentication\Models\Complex;
use Modules\Authentication\Models\Country;
use Modules\Authentication\Services\AccessService;
use Modules\Terminals\Terminal;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function __construct(private AccessService $access) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $context = $this->routeContext($request);
        if (! $this->access->hasPermission($user, $permission, $context)) {
            return response()->json([
                'message' => 'You do not have permission to perform this action.',
                'required_permission' => $permission,
            ], 403);
        }

        return $next($request);
    }

    private function routeContext(Request $request): ?AccessContext
    {
        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof Terminal) return AccessContext::forTerminal($parameter);
            if ($parameter instanceof Store) return AccessContext::forStore($parameter);
            if ($parameter instanceof Complex) {
                $parameter->loadMissing('brand');
                return new AccessContext($parameter->brand->country_id, $parameter->brand_id, $parameter->id);
            }
            if ($parameter instanceof Brand) return new AccessContext($parameter->country_id, $parameter->id);
            if ($parameter instanceof Country) return new AccessContext(countryId: $parameter->id);
        }

        return null;
    }
}
