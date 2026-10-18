<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Http\Request;
use Modules\Authentication\Services\AuthSessionService;
use Symfony\Component\HttpFoundation\Response;

final class Authenticate extends Middleware
{
    public function __construct(
        Auth $auth,
        private readonly AuthSessionService $sessions,
    ) {
        parent::__construct($auth);
    }

    public function handle($request, Closure $next, ...$guards): Response
    {
        $this->authenticate($request, $guards);

        $violation = $this->sessions->violation($request);
        if ($violation !== null) {
            return response()->json($violation, Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }

    protected function redirectTo(Request $request): ?string
    {
        return null;
    }
}
