<?php

namespace Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Authentication\Models\PasswordPolicy;
use Modules\Authentication\Services\AccessService;
use Modules\Authentication\Services\ScopeValidationService;

class PasswordPolicyController extends Controller
{
    public function __construct(
        private AccessService $access,
        private ScopeValidationService $scopes,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = PasswordPolicy::query()->orderByDesc('priority');
        if (! $request->user()->isSuperAdmin()) {
            $assignments = $this->access->assignments($request->user(), 'password-policies.view')->get();
            if (! $assignments->contains('scope_type', 'global')) {
                $query->where(function ($scope) use ($assignments): void {
                    $scope
                    ->where('scope_type', 'global')
                    ->orWhereIn('country_id', $assignments->pluck('country_id')->filter())
                    ->orWhereIn('brand_id', $assignments->pluck('brand_id')->filter())
                    ->orWhereIn('complex_id', $assignments->pluck('complex_id')->filter())
                    ->orWhereIn('store_id', $assignments->pluck('store_id')->filter());
                });
            }
        }

        return response()->json($query->paginate());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $context = $this->scopes->context($data);
        abort_unless($this->access->hasPermission($request->user(), 'password-policies.manage', $context), 403);

        return response()->json(PasswordPolicy::create([
            ...$data, ...$this->scopes->columns($context, $data['scope_type']),
        ]), 201);
    }

    public function update(Request $request, PasswordPolicy $passwordPolicy): JsonResponse
    {
        $data = $this->validated($request, true);
        $scopeData = array_merge($passwordPolicy->toArray(), $data);
        $context = $this->scopes->context($scopeData);
        abort_unless($this->access->hasPermission($request->user(), 'password-policies.manage', $context), 403);
        $passwordPolicy->update([
            ...$data,
            ...$this->scopes->columns($context, $scopeData['scope_type']),
        ]);

        return response()->json($passwordPolicy->fresh());
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $sometimes = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$sometimes, 'string', 'max:120'],
            'scope_type' => [$sometimes, Rule::in(['global', 'country', 'brand', 'complex', 'store'])],
            'country_id' => ['nullable', 'uuid'], 'brand_id' => ['nullable', 'uuid'],
            'complex_id' => ['nullable', 'uuid'], 'store_id' => ['nullable', 'uuid'],
            'priority' => ['sometimes', 'integer', 'between:0,100'],
            'password_min_length' => ['sometimes', 'integer', 'between:8,128'],
            'password_require_uppercase' => ['sometimes', 'boolean'],
            'password_require_lowercase' => ['sometimes', 'boolean'],
            'password_require_number' => ['sometimes', 'boolean'],
            'password_require_symbol' => ['sometimes', 'boolean'],
            'password_expiry_days' => ['sometimes', 'integer', 'between:0,3650'],
            'password_history_count' => ['sometimes', 'integer', 'between:0,24'],
            'pin_length' => ['sometimes', 'integer', 'between:4,12'],
            'pin_disallow_sequential' => ['sometimes', 'boolean'],
            'pin_disallow_repeated' => ['sometimes', 'boolean'],
            'pin_expiry_days' => ['sometimes', 'integer', 'between:0,3650'],
            'pin_history_count' => ['sometimes', 'integer', 'between:0,24'],
            'max_failed_attempts' => ['sometimes', 'integer', 'between:3,20'],
            'lockout_minutes' => ['sometimes', 'integer', 'between:1,1440'],
            'back_office_session_minutes' => ['sometimes', 'integer', 'between:5,1440'],
            'pos_session_minutes' => ['sometimes', 'integer', 'between:5,1440'],
            'require_mfa_for_back_office' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ]);
    }
}
