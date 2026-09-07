<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Resolves and verifies the manager PIN used to authorise operations that
 * exceed a cashier's threshold (till open, basket line removal, and so on).
 */
class ManagerVerifier
{
    /**
     * @throws ValidationException when the PIN is missing or does not match an active manager.
     */
    public function verify(?string $pin, string $field = 'manager_pin'): User
    {
        if (empty($pin)) {
            throw ValidationException::withMessages([
                $field => ['Manager verification is required.'],
            ]);
        }

        $manager = User::role('manager')
            ->get()
            ->first(fn (User $candidate) => $candidate->active && $candidate->pin_hash && Hash::check($pin, $candidate->pin_hash));

        if (! $manager) {
            throw ValidationException::withMessages([
                $field => ['Manager PIN could not be verified.'],
            ]);
        }

        return $manager;
    }
}
