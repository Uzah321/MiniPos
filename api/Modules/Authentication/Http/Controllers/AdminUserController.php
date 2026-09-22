<?php

namespace Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Authentication\Data\AccessContext;
use Modules\Authentication\Services\AccessService;
use Modules\Authentication\Services\CredentialPolicyService;

class AdminUserController extends Controller
{
    public function __construct(
        private AccessService $access,
        private CredentialPolicyService $policies,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = User::query()->with(['roles', 'activeAccessAssignments.role'])->orderBy('name');
        if (! $request->user()->isSuperAdmin()) {
            $storeIds = $this->access->scopeStores(Store::query(), $request->user(), 'users.view')->pluck('id');
            $query->where(fn ($scope) => $scope
                ->whereIn('store_id', $storeIds)
                ->orWhereHas('activeAccessAssignments', fn ($assignments) => $assignments->whereIn('store_id', $storeIds)));
        }

        return response()->json($query->paginate());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'authentication_mode' => ['required', Rule::in(['back_office', 'pos', 'both'])],
            'email' => ['nullable', 'email', 'max:190', 'unique:users,email'],
            'employee_code' => ['nullable', 'string', 'max:100', 'unique:users,employee_code'],
            'password' => ['nullable', 'string', 'confirmed'],
            'pin' => ['nullable', 'string'],
            'store_id' => ['nullable', 'uuid', 'exists:stores,id'],
            'status' => ['sometimes', Rule::in(['active', 'suspended', 'disabled'])],
        ]);

        $backOffice = in_array($data['authentication_mode'], ['back_office', 'both'], true);
        $pos = in_array($data['authentication_mode'], ['pos', 'both'], true);
        Validator::make($data, [
            'email' => [Rule::requiredIf($backOffice)],
            'password' => [Rule::requiredIf($backOffice)],
            'employee_code' => [Rule::requiredIf($pos)],
            'pin' => [Rule::requiredIf($pos)],
        ])->validate();

        $store = isset($data['store_id']) ? Store::findOrFail($data['store_id']) : null;
        $context = $store ? AccessContext::forStore($store) : AccessContext::global();
        abort_unless($this->access->hasPermission($request->user(), 'users.create', $context), 403);
        $policy = $this->policies->resolve($context);

        if ($backOffice) {
            Validator::make($data, ['password' => ['required', ...$this->policies->passwordRules($policy)]])->validate();
        }

        $user = DB::transaction(function () use ($data, $pos, $policy): User {
            $user = new User;
            if ($pos) $this->policies->assertValidPin($user, $data['pin'], $policy);
            $user->fill([
                'name' => $data['name'],
                'authentication_mode' => $data['authentication_mode'],
                'email' => isset($data['email']) ? mb_strtolower($data['email']) : null,
                'employee_code' => $data['employee_code'] ?? null,
                'password' => isset($data['password']) ? Hash::make($data['password']) : null,
                'pin_hash' => isset($data['pin']) ? Hash::make($data['pin']) : null,
                'store_id' => $data['store_id'] ?? null,
                'status' => $data['status'] ?? 'active',
                'active' => ($data['status'] ?? 'active') === 'active',
                'password_changed_at' => isset($data['password']) ? now() : null,
                'pin_changed_at' => isset($data['pin']) ? now() : null,
                'must_change_password' => isset($data['password']),
                'must_change_pin' => isset($data['pin']),
            ]);
            $user->save();

            return $user;
        });

        return response()->json($user->load('roles'), 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $store = $user->store;
        $context = $store ? AccessContext::forStore($store) : AccessContext::global();
        abort_unless($this->access->hasPermission($request->user(), 'users.update', $context), 403);
        $user->update($request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:190', Rule::unique('users')->ignore($user)],
            'employee_code' => ['nullable', 'string', 'max:100', Rule::unique('users')->ignore($user)],
            'authentication_mode' => ['sometimes', Rule::in(['back_office', 'pos', 'both'])],
            'store_id' => ['nullable', 'uuid', 'exists:stores,id'],
            'status' => ['sometimes', Rule::in(['active', 'suspended', 'disabled'])],
        ]));

        if ($request->has('status')) $user->update(['active' => $request->input('status') === 'active']);

        return response()->json($user->fresh());
    }
}
