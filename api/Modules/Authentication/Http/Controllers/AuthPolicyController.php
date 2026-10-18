<?php

namespace Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Auth\SuperAdminService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Authentication\Enums\EnterpriseScope;
use Modules\Authentication\Models\AuthPolicy;
use Modules\Authentication\Models\PosUser;
use Modules\Authentication\Services\AuthPolicyManagementService;
use Modules\Authentication\Services\AuthPolicyResolver;
use Modules\Authentication\Services\EnterpriseAuthorizationService;
use Modules\Authentication\Services\EnterpriseScopeResolver;

final class AuthPolicyController extends Controller
{
    private const POLICY_KEYS = ['authentication', 'credential', 'session'];

    public function __construct(
        private readonly AuthPolicyManagementService $policies,
        private readonly AuthPolicyResolver $resolver,
        private readonly EnterpriseScopeResolver $scopes,
        private readonly EnterpriseAuthorizationService $authorization,
        private readonly SuperAdminService $superAdmins,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $company = app('company');
        $query = AuthPolicy::query()
            ->where(function ($scope) use ($company): void {
                $scope->where('company_id', $company->id)->orWhere('scope_type', 'global');
                if ($company->country_id) {
                    $scope->orWhere(fn ($country) => $country
                        ->where('scope_type', 'country')
                        ->where('country_id', $company->country_id));
                }
            })
            ->when($request->filled('policy_key'), fn ($query) => $query
                ->where('policy_key', $request->input('policy_key')))
            ->orderBy('policy_key')
            ->orderBy('scope_type')
            ->orderByDesc('version');

        $actor = $request->user();
        if ($actor instanceof PosUser && $this->authorization->usesScopedAssignments($actor)) {
            // The effective endpoint exposes inherited policy for an
            // authorized target. The management listing is narrower: only
            // return policy layers the actor could actually change, so a
            // shop administrator cannot enumerate sibling-shop policies.
            $rows = $query->get()->filter(fn (AuthPolicy $policy): bool => $this->authorization->can(
                $actor,
                'manage_authentication_policies',
                $policy->enterpriseScope()
            ))->values();
        } else {
            $rows = $query->paginate(min(100, max(1, (int) $request->input('per_page', 25))));
        }

        return response()->json(['data' => $rows]);
    }

    public function effective(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'policy_key' => ['required', Rule::in(self::POLICY_KEYS)],
            'scope_type' => ['required', Rule::enum(EnterpriseScope::class)],
            'target_id' => ['nullable', 'string'],
        ]);
        $scopeType = EnterpriseScope::from($validated['scope_type']);
        $targetId = $scopeType === EnterpriseScope::Global
            ? null
            : trim((string) ($validated['target_id'] ?? ''));
        $scope = $this->resolveTenantScope($request, $scopeType, $targetId, false);

        return response()->json([
            'data' => $this->resolver
                ->resolve($validated['policy_key'], $scope)
                ->toArray(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'policy_key' => ['required', Rule::in(self::POLICY_KEYS)],
            'scope_type' => ['required', Rule::enum(EnterpriseScope::class)],
            'target_id' => ['nullable', 'string'],
            'settings' => ['required', 'array'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
        ]);
        $scopeType = EnterpriseScope::from($validated['scope_type']);
        $targetId = $scopeType === EnterpriseScope::Global
            ? null
            : trim((string) ($validated['target_id'] ?? ''));
        $this->resolveTenantScope($request, $scopeType, $targetId, true);
        $validated['settings'] = $this->policies->validateSettings(
            $validated['policy_key'],
            $validated['settings']
        );

        $policy = $this->policies->publish(
            $validated['policy_key'],
            $validated['settings'],
            $scopeType,
            $targetId,
            $request->user(),
            (int) ($validated['priority'] ?? 0),
            isset($validated['effective_from']) ? CarbonImmutable::parse($validated['effective_from']) : null,
            isset($validated['effective_until']) ? CarbonImmutable::parse($validated['effective_until']) : null,
        );

        return response()->json([
            'message' => 'Authentication policy version published.',
            'data' => $policy,
        ], 201);
    }

    public function disable(Request $request, string $policyId): JsonResponse
    {
        $policy = AuthPolicy::query()->findOrFail($policyId);
        $scopeType = EnterpriseScope::from($policy->scope_type);
        $targetId = $scopeType === EnterpriseScope::Global
            ? null
            : $policy->{$scopeType->column()};
        $this->resolveTenantScope($request, $scopeType, $targetId, true);

        return response()->json([
            'message' => 'Authentication policy version disabled.',
            'data' => $this->policies->retire($policy, $request->user()),
        ]);
    }

    private function resolveTenantScope(
        Request $request,
        EnterpriseScope $scopeType,
        ?string $targetId,
        bool $mutation,
    ) {
        if ($scopeType !== EnterpriseScope::Global && trim((string) $targetId) === '') {
            abort(422, 'A target is required for this enterprise scope.');
        }
        if (in_array($scopeType, [EnterpriseScope::Global, EnterpriseScope::Country], true)) {
            if ($mutation) {
                abort_unless(
                    $this->superAdmins->hasPlatformSessionAccess($request->user()),
                    403,
                    'Only the platform administrator may publish global or country policy.'
                );
            } elseif ($scopeType === EnterpriseScope::Country
                && (string) $targetId !== (string) app('company')->country_id) {
                abort(403, 'The policy target is outside the resolved company country.');
            }

            return $this->scopes->resolve($scopeType, $targetId);
        }

        $scope = $this->scopes->resolve($scopeType, $targetId);
        abort_unless(
            (string) $scope->companyId === (string) app('company')->id,
            403,
            'The policy target is outside the resolved company.'
        );
        $actor = $request->user();
        if ($actor instanceof PosUser && $this->authorization->usesScopedAssignments($actor)) {
            abort_unless(
                $this->authorization->can($actor, 'manage_authentication_policies', $scope),
                403,
                'You may not manage policy at a broader enterprise scope.'
            );
        }

        return $scope;
    }

}
