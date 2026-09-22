<?php

namespace Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Authentication\Data\AccessContext;
use Modules\Authentication\Services\AccessService;

class RolePermissionController extends Controller
{
    public function __construct(private AccessService $access) {}

    public function roles(): JsonResponse
    {
        return response()->json(Role::query()->with('permissions')->orderBy('name')->paginate());
    }

    public function storeRole(Request $request): JsonResponse
    {
        $this->authorizeGlobal($request, 'roles.create');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:roles,name'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_super_admin' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'permission_ids' => ['sometimes', 'array'],
            'permission_ids.*' => ['uuid', 'exists:permissions,id'],
        ]);
        if (($data['is_super_admin'] ?? false) && ! $request->user()->isSuperAdmin()) abort(403);
        $role = Role::create([
            'name' => $data['name'], 'guard_name' => 'web',
            'description' => $data['description'] ?? null,
            'is_super_admin' => $data['is_super_admin'] ?? false,
            'status' => $data['status'] ?? 'active',
        ]);
        $role->syncPermissions(Permission::query()->whereIn('id', $data['permission_ids'] ?? [])->get());

        return response()->json($role->load('permissions'), 201);
    }

    public function updateRole(Request $request, Role $role): JsonResponse
    {
        $this->authorizeGlobal($request, 'roles.update');
        if ($role->is_super_admin && ! $request->user()->isSuperAdmin()) abort(403);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100', Rule::unique('roles')->ignore($role)],
            'description' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'permission_ids' => ['sometimes', 'array'],
            'permission_ids.*' => ['uuid', 'exists:permissions,id'],
        ]);
        $role->update(collect($data)->except('permission_ids')->all());
        if (array_key_exists('permission_ids', $data)) {
            $role->syncPermissions(Permission::query()->whereIn('id', $data['permission_ids'])->get());
        }

        return response()->json($role->fresh()->load('permissions'));
    }

    public function permissions(): JsonResponse
    {
        return response()->json(Permission::query()->orderBy('module')->orderBy('name')->paginate(100));
    }

    public function storePermission(Request $request): JsonResponse
    {
        $this->authorizeGlobal($request, 'permissions.create');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:permissions,name'],
            'description' => ['nullable', 'string', 'max:255'],
            'module' => ['required', 'string', 'max:80'],
        ]);

        return response()->json(Permission::create([...$data, 'guard_name' => 'web', 'status' => 'active']), 201);
    }

    private function authorizeGlobal(Request $request, string $permission): void
    {
        abort_unless($this->access->hasPermission($request->user(), $permission, AccessContext::global()), 403);
    }
}
