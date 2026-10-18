<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Repair rows created while a platform owner was viewing one company but
     * still carried the primary branch of another company on their user row.
     *
     * A foreign branch can never be valid for the owning company. When the
     * company has exactly one active branch we can recover the intended scope;
     * otherwise NULL is the safe company-wide configuration scope.
     */
    public function up(): void
    {
        if (! Schema::hasTable('company_branches')) {
            return;
        }

        $branchColumns = ['id', 'company_id'];
        if (Schema::hasColumn('company_branches', 'status')) {
            $branchColumns[] = 'status';
        }

        $branches = DB::table('company_branches')
            ->select($branchColumns)
            ->when(
                Schema::hasColumn('company_branches', 'deleted_at'),
                fn ($query) => $query->whereNull('deleted_at')
            )
            ->get();

        $ownedPairs = $branches
            ->mapWithKeys(fn ($branch) => [
                (string) $branch->company_id.'|'.(string) $branch->id => true,
            ])
            ->all();

        $soleActiveBranchByCompany = $branches
            ->filter(fn ($branch): bool => strtolower(trim((string) ($branch->status ?? 'active'))) === 'active')
            ->groupBy(fn ($branch): string => (string) $branch->company_id)
            ->map(fn ($companyBranches) => $companyBranches->count() === 1
                ? (string) $companyBranches->first()->id
                : null)
            ->all();

        foreach (['currencies', 'currency_rates', 'payment_types'] as $table) {
            if (! Schema::hasTable($table)
                || ! Schema::hasColumn($table, 'company_id')
                || ! Schema::hasColumn($table, 'branch_id')) {
                continue;
            }

            $hasUpdatedAt = Schema::hasColumn($table, 'updated_at');

            DB::table($table)
                ->select(['id', 'company_id', 'branch_id'])
                ->whereNotNull('branch_id')
                ->orderBy('id')
                ->chunkById(500, function ($rows) use (
                    $table,
                    $ownedPairs,
                    $soleActiveBranchByCompany,
                    $hasUpdatedAt
                ): void {
                    foreach ($rows as $row) {
                        $companyId = (string) $row->company_id;
                        $branchId = (string) $row->branch_id;

                        if (isset($ownedPairs[$companyId.'|'.$branchId])) {
                            continue;
                        }

                        $replacement = $soleActiveBranchByCompany[$companyId] ?? null;
                        $updates = ['branch_id' => $replacement];
                        if ($hasUpdatedAt) {
                            $updates['updated_at'] = now();
                        }

                        DB::table($table)
                            ->where('id', $row->id)
                            ->where('company_id', $row->company_id)
                            ->where('branch_id', $row->branch_id)
                            ->update($updates);
                    }
                }, 'id');
        }
    }

    /**
     * The original foreign branch was invalid and cannot be restored safely.
     */
    public function down(): void
    {
        // Intentionally irreversible data repair.
    }
};
