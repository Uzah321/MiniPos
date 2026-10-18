<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Resolve the optional branch used by company configuration screens.
 *
 * Company-wide currency/payment configuration is represented by a null
 * branch. A stale default branch on a platform administrator must never be
 * copied into another company's configuration rows.
 */
class ConfigurationBranchScopeService
{
    public function __construct(
        private readonly BusinessModeBranchScopeService $branchScope
    ) {}

    public function resolve(Request $request, string $companyId): ?string
    {
        $middlewareBranchId = $this->scalarId(
            $request->attributes->get('company_branch_id')
        );
        if ($middlewareBranchId !== null) {
            return $middlewareBranchId;
        }

        $assertedBranchId = $this->firstScalar([
            $request->header('X-Branch-ID'),
            $request->input('branch_id'),
            app()->bound('till') ? app('till')?->branch_id : null,
        ]);
        if ($assertedBranchId !== null) {
            return $this->validatedBranchId($request, $companyId, $assertedBranchId);
        }

        $userBranchId = $this->scalarId($request->user()?->branch_id);
        if ($userBranchId === null || ! DB::table('company_branches')
            ->where('company_id', $companyId)
            ->where('id', $userBranchId)
            ->whereNull('deleted_at')
            ->exists()) {
            return null;
        }

        return $this->validatedBranchId($request, $companyId, $userBranchId);
    }

    private function validatedBranchId(
        Request $request,
        string $companyId,
        string $branchId
    ): string {
        $scope = $this->branchScope->resolve($request, $companyId, [$branchId]);

        return (string) $scope['context_branch_id'];
    }

    /** @param array<int, mixed> $values */
    private function firstScalar(array $values): ?string
    {
        foreach ($values as $value) {
            if (($id = $this->scalarId($value)) !== null) {
                return $id;
            }
        }

        return null;
    }

    private function scalarId(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
