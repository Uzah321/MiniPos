<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Resolves and verifies the manager PIN used to authorise operations that
 * exceed a cashier's threshold (till open, basket line removal, and so on).
 *
 * Scoped to a single store: a manager PIN only authorises actions at the
 * store that manager belongs to, per the guide's "store scope must be
 * enforced by the server" control. Also rate-limited per store, since a
 * 4-digit PIN is otherwise brute-forceable in a few thousand requests.
 */
class ManagerVerifier
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    /**
     * @throws ValidationException when the PIN is missing, locked out, or does not match an active manager at the given store.
     */
    public function verify(?string $pin, int $storeId, string $field = 'manager_pin'): User
    {
        $rateLimitKey = "manager-pin:{$storeId}";

        if (RateLimiter::tooManyAttempts($rateLimitKey, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                $field => ['Too many failed manager PIN attempts. Try again in a minute.'],
            ]);
        }

        if (empty($pin)) {
            throw ValidationException::withMessages([
                $field => ['Manager verification is required.'],
            ]);
        }

        $manager = User::role('manager')
            ->where('store_id', $storeId)
            ->get()
            ->first(fn (User $candidate) => $candidate->active && $candidate->pin_hash && Hash::check($pin, $candidate->pin_hash));

        if (! $manager) {
            RateLimiter::hit($rateLimitKey, self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                $field => ['Manager PIN could not be verified.'],
            ]);
        }

        RateLimiter::clear($rateLimitKey);

        return $manager;
    }
}
