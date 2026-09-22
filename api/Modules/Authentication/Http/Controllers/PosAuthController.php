<?php

namespace Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Authentication\Data\AccessContext;
use Modules\Authentication\Services\AccessService;
use Modules\Authentication\Services\AuthenticationService;
use Modules\Authentication\Services\CredentialPolicyService;
use Modules\Terminals\Terminal;

class PosAuthController extends Controller
{
    public function __construct(
        private AuthenticationService $authentication,
        private CredentialPolicyService $policies,
        private AccessService $access,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_code' => ['required', 'string', 'max:100'],
            'pin' => ['required', 'string', 'max:20'],
            'terminal_id' => ['required', 'uuid', 'exists:terminals,id'],
            'device_identifier' => ['nullable', 'string', 'max:200'],
        ]);

        $terminal = Terminal::query()->with('store.complex.brand')->findOrFail($data['terminal_id']);
        if ($terminal->status->value !== 'active' || $terminal->revoked_at !== null) {
            throw ValidationException::withMessages(['terminal_id' => ['This terminal is not active.']]);
        }

        $user = User::query()->where('employee_code', $data['employee_code'])->first();
        $policy = $this->policies->resolve(AccessContext::forTerminal($terminal));

        if (! $user || ! $user->supportsPosLogin()
            || ! $this->authentication->credentialsMatch($user, $user->pin_hash, $data['pin'])) {
            $this->authentication->recordFailure($user, $policy, $request, $data['employee_code'], 'pos');
            throw ValidationException::withMessages(['employee_code' => ['The provided credentials are incorrect.']]);
        }

        $this->authentication->assertAvailable($user);
        $hasStoreAccess = $this->access->canAccessTerminal($user, $terminal)
            || $user->store_id === $terminal->store_id;
        if (! $hasStoreAccess) {
            throw ValidationException::withMessages([
                'employee_code' => ['This account is not assigned to the terminal store.'],
            ]);
        }

        $this->authentication->recordSuccess($user, $request, $data['employee_code'], 'pos');

        $pinExpired = $user->must_change_pin
            || ($user->pin_changed_at !== null && $policy->pin_expiry_days > 0
                && $user->pin_changed_at->addDays($policy->pin_expiry_days)->isPast());
        if ($pinExpired) {
            return response()->json([
                ...$this->authentication->issueSession(
                    $user, $request, 'pos', $policy, $terminal->store, $terminal, ['pin:update']
                ),
                'pin_change_required' => true,
            ], 202);
        }

        return response()->json([
            ...$this->authentication->issueSession(
                $user, $request, 'pos', $policy, $terminal->store, $terminal, ['pos:*']
            ),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'employee_code' => $user->employee_code,
                'store_id' => $terminal->store_id,
                'terminal_id' => $terminal->id,
                'roles' => $user->getRoleNames(),
            ],
        ]);
    }
}
