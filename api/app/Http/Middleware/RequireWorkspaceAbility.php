<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\TransientToken;
use Symfony\Component\HttpFoundation\Response;

class RequireWorkspaceAbility
{
    public function handle(Request $request, Closure $next, string ...$workspaces): Response
    {
        $workspaces = array_values(array_unique($workspaces));

        if ($workspaces === [] || array_diff($workspaces, ['front-office', 'back-office']) !== []) {
            return response()->json(['message' => 'Invalid workspace policy.'], 500);
        }

        $requiredAbilities = array_map(
            fn (string $workspace): string => 'workspace:'.$workspace,
            $workspaces
        );

        $user = $request->user();
        $token = $user && method_exists($user, 'currentAccessToken')
            ? $user->currentAccessToken()
            : null;
        if (! $token
            || $token instanceof TransientToken
            || ! method_exists($token, 'can')
            || ! collect($requiredAbilities)->contains(
                fn (string $ability): bool => $token->can($ability)
            )) {
            return response()->json([
                'message' => 'This session does not have access to the requested workspace.',
                'code' => 'WORKSPACE_TOKEN_MISMATCH',
                'required_ability' => count($requiredAbilities) === 1 ? $requiredAbilities[0] : null,
                'required_abilities' => $requiredAbilities,
            ], 403);
        }

        return $next($request);
    }
}
