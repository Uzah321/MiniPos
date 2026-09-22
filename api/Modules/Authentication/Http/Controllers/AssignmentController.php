<?php

namespace Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Authentication\Models\UserAccessAssignment;
use Modules\Authentication\Services\AccessService;
use Modules\Authentication\Services\ScopeValidationService;

class AssignmentController extends Controller
{
    public function __construct(
        private AccessService $access,
        private ScopeValidationService $scopes,
    ) {}

    public function store(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'role_id' => ['required', 'uuid', 'exists:roles,id'],
            'scope_type' => ['required', Rule::in(['global', 'country', 'brand', 'complex', 'store', 'terminal'])],
            'country_id' => ['nullable', 'uuid'], 'brand_id' => ['nullable', 'uuid'],
            'complex_id' => ['nullable', 'uuid'], 'store_id' => ['nullable', 'uuid'],
            'terminal_id' => ['nullable', 'uuid'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);

        $context = $this->scopes->context($data);
        abort_unless($this->access->hasPermission($request->user(), 'users.assign', $context), 403);
        $role = Role::findOrFail($data['role_id']);
        if ($role->is_super_admin && ! $request->user()->isSuperAdmin()) abort(403);

        $assignment = UserAccessAssignment::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            ...$this->scopes->columns($context, $data['scope_type']),
            'status' => 'active',
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'granted_by' => $request->user()->id,
        ]);
        if (! $user->hasRole($role)) $user->assignRole($role);

        return response()->json($assignment->load('role'), 201);
    }

    public function destroy(Request $request, User $user, UserAccessAssignment $assignment): JsonResponse
    {
        abort_unless($assignment->user_id === $user->id, 404);
        $context = $this->scopes->context($assignment->toArray());
        abort_unless($this->access->hasPermission($request->user(), 'users.assign', $context), 403);
        $role = $assignment->role;
        $assignment->update(['status' => 'revoked', 'ends_at' => now()]);
        $hasAnotherAssignment = $user->activeAccessAssignments()->where('role_id', $role->id)->exists();
        if (! $hasAnotherAssignment && $user->hasRole($role)) $user->removeRole($role);

        return response()->json(['message' => 'Assignment revoked.']);
    }
}
