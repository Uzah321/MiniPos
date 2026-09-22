<?php

namespace Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Authentication\Models\AuthSession;

class SessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(AuthSession::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate());
    }

    public function destroy(Request $request, AuthSession $session): JsonResponse
    {
        abort_unless($session->user_id === $request->user()->id, 404);
        $session->update(['revoked_at' => now()]);
        if ($session->personal_access_token_id !== null) {
            $request->user()->tokens()->whereKey($session->personal_access_token_id)->delete();
        }

        return response()->json(['message' => 'Session revoked.']);
    }

    public function destroyOthers(Request $request): JsonResponse
    {
        $currentId = $request->user()->currentAccessToken()?->id;
        $request->user()->tokens()->when($currentId, fn ($query) => $query->where('id', '!=', $currentId))->delete();
        AuthSession::query()
            ->where('user_id', $request->user()->id)
            ->when($currentId, fn ($query) => $query->where('personal_access_token_id', '!=', $currentId))
            ->update(['revoked_at' => now()]);

        return response()->json(['message' => 'Other sessions revoked.']);
    }
}
