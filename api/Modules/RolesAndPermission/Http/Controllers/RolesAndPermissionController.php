<?php

namespace Modules\RolesAndPermission\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Authentication\Models\PosUser;
use Modules\Company\Models\Company;
use Modules\RolesAndPermission\Models\Permission;
use Modules\RolesAndPermission\Models\Role;
use Modules\RolesAndPermission\Models\RolePermission;
use function App\Helpers\hasPermission;

class RolesAndPermissionController extends Controller
{
    public function index(Request $request)
    {
        $company = app('company');

        $result = hasPermission(auth()->user()->role_id, 'view_role', $company->id);
        if ($result !== true) return $result;

        $authUser = auth()->user();

        $query = Role::where('company_id', $company->id)
            ->with('permissions')
            ->latest();

        $normaliseRole = static fn ($value) => str_replace([' ', '-'], '_', strtolower(trim((string) $value)));
        $isSuperAdmin = in_array($normaliseRole($authUser->role?->name), ['super_admin', 'super_administrator'], true)
            || in_array($normaliseRole($authUser->user_type), ['super_admin', 'super_administrator'], true);

        // A non-super-admin must never be offered a role that can bypass all permissions.
        if (!$isSuperAdmin) {
            $query->whereNotIn('name', [
                'Super Admin',
                'Super Administrator',
                'super_admin',
                'super_administrator',
                'super-admin',
                'super-administrator',
            ]);
        }

        if ($request->filled('q')) {
            $q = $request->q;

            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")
                    ->orWhere('group', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%");
            });
        }

        return response()->json([
            'message' => 'Roles fetched successfully.',
            'data' => $query->paginate($request->per_page ?? 15),
        ], 200);
    }

    public function store(Request $request)
    {
        $company = app('company');

        $result = hasPermission(auth()->user()->role_id, 'create_role', $company->id);
        if ($result !== true) return $result;

        $validator = Validator::make($request->all(), [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('roles', 'name')->where(function ($query) use ($company) {
                    return $query->where('company_id', $company->id);
                }),
            ],
            'group' => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $companyModel = Company::find($company->id);

        if (!$companyModel) {
            return response()->json([
                'message' => 'Company not found.',
            ], 404);
        }

        DB::beginTransaction();

        try {
            $role = Role::create([
                'company_id' => $company->id,
                'name' => $request->name,
                'group' => $request->group,
                'description' => $request->description,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Role created successfully.',
                'data' => $role,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong while creating role.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(string $id)
    {
        $company = app('company');

        $result = hasPermission(auth()->user()->role_id, 'view_role', $company->id);
        if ($result !== true) return $result;

        $role = Role::where('company_id', $company->id)
            ->where('id', $id)
            ->first();

        if (!$role) {
            return response()->json([
                'message' => 'Role not found.',
            ], 404);
        }

        return response()->json([
            'message' => 'Role fetched successfully.',
            'data' => $role,
        ], 200);
    }

    public function update(Request $request, string $id)
    {
        $company = app('company');

        $result = hasPermission(auth()->user()->role_id, 'update_role', $company->id);
        if ($result !== true) return $result;

        $role = Role::where('company_id', $company->id)
            ->where('id', $id)
            ->first();

        if (!$role) {
            return response()->json([
                'message' => 'Role not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('roles', 'name')
                    ->ignore($role->id)
                    ->where(function ($query) use ($company) {
                        return $query->where('company_id', $company->id);
                    }),
            ],
            'group' => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            $role->update([
                'name' => $request->name,
                'group' => $request->group,
                'description' => $request->description,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Role updated successfully.',
                'data' => $role->fresh(),
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong while updating role.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    public function destroy(string $id)
    {
        $company = app('company');

        $result = hasPermission(auth()->user()->role_id, 'delete_role', $company->id);
        if ($result !== true) return $result;

        $role = Role::where('company_id', $company->id)
            ->where('id', $id)
            ->first();

        if (!$role) {
            return response()->json([
                'message' => 'Role not found.',
            ], 404);
        }

        DB::beginTransaction();

        try {
            $role->delete();

            DB::commit();

            return response()->json([
                'message' => 'Role deleted successfully.',
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong while deleting role.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }




    public function getPermission(Request $request)
    {
        $company = app('company');

        $result = hasPermission(auth()->user()->role_id, 'view_permission', $company->id);
        if ($result !== true) return $result;

        $query = Permission::query()->orderBy('group')->orderBy('name');

        if ($request->filled('q')) {
            $q = $request->q;

            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")
                    ->orWhere('code', 'like', "%{$q}%")
                    ->orWhere('group', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%");
            });
        }

        return response()->json([
            'message' => 'Permissions fetched successfully.',
            'data' => $query->get(),
        ], 200);
    }


    public function rolePermissions(Request $request, string $roleId)
    {
        $company = app('company');

        $result = hasPermission(auth()->user()->role_id, 'view_role_permission', $company->id);
        if ($result !== true) return $result;

        $role = Role::where('company_id', $company->id)
            ->where('id', $roleId)
            ->with('permissions')
            ->first();

        if (!$role) {
            return response()->json(['message' => 'Role not found.'], 404);
        }

        return response()->json([
            'message' => 'Role permissions fetched successfully.',
            'data' => $role,
        ], 200);
    }

    public function assignRolePermission(Request $request, string $roleId)
    {
        $company = app('company');

        $result = hasPermission(auth()->user()->role_id, 'assign_role_permission', $company->id);
        if ($result !== true) return $result;

        $validator = Validator::make($request->all(), [
            'permission_id' => 'required|exists:permissions,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $role = Role::where('company_id', $company->id)
            ->where('id', $roleId)
            ->first();

        if (!$role) {
            return response()->json(['message' => 'Role not found.'], 404);
        }

        $existing = RolePermission::where('role_id', $roleId)
            ->where('permission_id', $request->permission_id)
            ->first();

        if ($existing) {
            // Older pivots may pre-date the company_id column. The role was
            // resolved inside this tenant, so repairing that legacy null is
            // safe and prevents a duplicate natural-key insert.
            if ((string) $existing->company_id !== (string) $company->id) {
                $existing->update(['company_id' => $company->id]);
            }

            return response()->json([
                'message' => 'Permission already assigned to this role.',
            ], 409);
        }

        $rolePermission = RolePermission::create([
            'company_id' => $company->id,
            'role_id' => $roleId,
            'permission_id' => $request->permission_id,
        ]);

        return response()->json([
            'message' => 'Permission assigned successfully.',
            'data' => $rolePermission,
        ], 201);
    }

    public function syncRolePermissions(Request $request, string $roleId)
    {
        $company = app('company');

        $result = hasPermission(auth()->user()->role_id, 'sync_role_permission', $company->id);
        if ($result !== true) return $result;

        $validator = Validator::make($request->all(), [
            // present, not required: an empty list removes every permission.
            'permission_ids' => 'present|array',
            'permission_ids.*' => 'exists:permissions,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $role = Role::where('company_id', $company->id)
            ->where('id', $roleId)
            ->first();

        if (!$role) {
            return response()->json(['message' => 'Role not found.'], 404);
        }

        DB::beginTransaction();

        try {
            $permissionIds = collect($request->permission_ids)
                ->map(static fn ($permissionId) => (string) $permissionId)
                ->unique()
                ->values();
            $existing = RolePermission::where('role_id', $roleId)
                ->get()
                ->keyBy(static fn (RolePermission $pivot) => (string) $pivot->permission_id);

            $stale = RolePermission::where('role_id', $roleId);
            $permissionIds->isEmpty()
                ? $stale->delete()
                : $stale->whereNotIn('permission_id', $permissionIds->all())->delete();

            foreach ($permissionIds as $permissionId) {
                $pivot = $existing->get($permissionId);
                if ($pivot) {
                    // Preserve the pivot UUID so cloud-to-edge tombstones and
                    // upserts have stable identities across repeated saves.
                    if ((string) $pivot->company_id !== (string) $company->id) {
                        $pivot->update(['company_id' => $company->id]);
                    }

                    continue;
                }

                RolePermission::create([
                    'company_id' => $company->id,
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Role permissions synced successfully.',
                'data' => $role->load('permissions'),
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong while syncing permissions.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function removeRolePermission(string $roleId, string $permissionId)
    {
        $company = app('company');

        $result = hasPermission(auth()->user()->role_id, 'remove_role_permission', $company->id);
        if ($result !== true) return $result;

        $role = Role::where('company_id', $company->id)
            ->where('id', $roleId)
            ->first();
        if (! $role) {
            return response()->json(['message' => 'Role not found.'], 404);
        }

        // The tenant-scoped role is authoritative. Delete by the relationship
        // natural key so a legacy null/stale pivot company_id cannot make an
        // assignment impossible to remove.
        $deleted = RolePermission::where('role_id', $role->id)
            ->where('permission_id', $permissionId)
            ->delete();

        if (!$deleted) {
            return response()->json([
                'message' => 'Role permission not found.',
            ], 404);
        }

        return response()->json([
            'message' => 'Permission removed from role successfully.',
        ], 200);
    }
}
