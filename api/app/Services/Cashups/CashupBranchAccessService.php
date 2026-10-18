<?php

namespace App\Services\Cashups;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashupBranchAccessService
{
    /**
     * Resolve the branches a cashup report request may read.
     *
     * An empty array is intentionally returned only for an unrestricted super
     * administrator request; the company predicate on each report query still
     * provides the tenant boundary.
     *
     * @return array<int, string>
     */
    public function resolve(Request $request, string $companyId): array
    {
        $user = $request->user() ?? auth()->user();
        abort_if(!$user, 401, 'Unauthenticated.');

        $requestedBranchId = $request->filled('branch_id')
            ? (string) $request->input('branch_id')
            : null;

        if ($this->isSuperAdmin($user)) {
            if (!$requestedBranchId) {
                return [];
            }

            $belongsToCompany = DB::table('company_branches')
                ->where('company_id', $companyId)
                ->where('id', $requestedBranchId)
                ->exists();

            abort_unless($belongsToCompany, 403, 'The selected branch does not belong to this company.');

            return [$requestedBranchId];
        }

        $branchIds = collect();
        if (method_exists($user, 'branches')) {
            $branchIds = $user->branches()
                ->where('company_branches.company_id', $companyId)
                ->pluck('company_branches.id');
        }

        if (data_get($user, 'branch_id')) {
            $branchIds->push((string) data_get($user, 'branch_id'));
        }

        $branchIds = $branchIds
            ->map(fn ($id) => (string) $id)
            ->filter()
            ->unique()
            ->values();

        abort_if($branchIds->isEmpty(), 403, 'No branches are allocated to this user.');

        if ($requestedBranchId) {
            abort_unless(
                $branchIds->contains($requestedBranchId),
                403,
                'You are not allocated to the selected branch.'
            );

            return [$requestedBranchId];
        }

        return $branchIds->all();
    }

    private function isSuperAdmin(object $user): bool
    {
        $normalise = static fn ($value) => str_replace(
            [' ', '-'],
            '_',
            strtolower(trim((string) $value))
        );
        $superAdminNames = ['super_admin', 'super_administrator'];

        return in_array($normalise(data_get($user, 'user_type')), $superAdminNames, true)
            || in_array($normalise(data_get($user, 'role.name')), $superAdminNames, true);
    }
}
