<?php

namespace App\Services\Shifts;

use Modules\Authentication\Models\PosUser;
use Modules\Authentication\Models\PosUserShiftSession;

/**
 * A cashier signed in on a registered till always works inside an open shift
 * session. Shops without shift rules get an "all day" session, so sales,
 * account payments and cash-up never stop for a missing shift.
 */
class ShiftSessionOpener
{
    public const ALL_DAY_SHIFT = 'all day';

    public function __construct(private readonly ShiftResolverService $resolver)
    {
    }

    public function findOpen(string $companyId, string $branchId, string $tillId, string $userId): ?PosUserShiftSession
    {
        return PosUserShiftSession::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('till_id', $tillId)
            ->where('pos_user_id', $userId)
            ->where('status', 'open')
            ->whereNull('closed_at')
            ->latest('opened_at')
            ->first();
    }

    /** Return the cashier's open session on this till, opening one when none is open. */
    public function openFor(
        string $companyId,
        string $branchId,
        string $tillId,
        string $userId,
        ?string $shiftName = null,
        mixed $openingFloat = 0
    ): PosUserShiftSession {
        // Serialise per cashier so two quick requests cannot open two sessions.
        PosUser::query()->whereKey($userId)->lockForUpdate()->first();

        if ($open = $this->findOpen($companyId, $branchId, $tillId, $userId)) {
            return $open;
        }

        $config = $this->resolver->getConfig($companyId, $branchId);
        $usesShiftRules = (bool) $config?->enable_shifts;
        if ($usesShiftRules) {
            $shiftName = $shiftName ?: ($this->resolver->detect($companyId, $branchId) ?? 'unscheduled');
        }

        return PosUserShiftSession::create([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'till_id' => $tillId,
            'shift_configuration_id' => $usesShiftRules ? $config->id : null,
            'pos_user_id' => $userId,
            'shift_name' => $usesShiftRules ? strtolower((string) $shiftName) : self::ALL_DAY_SHIFT,
            'opened_at' => now(),
            'opening_float' => $openingFloat,
            'status' => 'open',
        ]);
    }
}
