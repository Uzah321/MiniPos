<?php

namespace App\Services\Company;

use App\Services\Auth\SuperAdminService;
use App\Services\License\LicenseAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Company\Models\Company;

/**
 * Deleting a company removes everything under it: branches, hospitality
 * structure, tills, users, catalogue, settings and documents are soft-deleted
 * (rows stay for history and audit), its devices stop syncing, and its
 * licences, sessions and access grants are revoked. Tables without a
 * deleted_at column hold history only and become unreachable with the company.
 *
 * Platform administrators keep their accounts even when their user row
 * belongs to the deleted company.
 */
class CompanyDeletionService
{
    /** @var array<string, array<int, string>> */
    private array $columns = [];

    public function __construct(
        private readonly SuperAdminService $superAdmins,
        private readonly LicenseAccessService $licenseAccess,
    ) {}

    /** @return array<string, int> rows affected per table */
    public function delete(Company $company): array
    {
        $companyId = (string) $company->id;
        $platformAdminIds = $this->superAdmins->platformAdministratorIds();
        $now = now();
        $affected = [];

        if (Schema::hasTable('pos_users')) {
            $this->licenseAccess->revokeNonPlatformAdministratorTokens($company);
        }

        DB::transaction(function () use ($company, $companyId, $platformAdminIds, $now, &$affected): void {
            $affected += $this->revokeAccess($companyId, $platformAdminIds, $now);

            foreach ($this->softDeletableCompanyTables() as $table) {
                $query = DB::table($table)
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at');

                $values = ['deleted_at' => $now];
                if ($this->hasColumn($table, 'updated_at')) {
                    $values['updated_at'] = $now;
                }

                if ($table === 'pos_users') {
                    $query->whereNotIn('id', $platformAdminIds);
                    // Deleted users can never sign in again; free their PINs.
                    foreach (['login_code', 'login_code_lookup', 'login_code_lookup_key_id'] as $column) {
                        if ($this->hasColumn($table, $column)) {
                            $values[$column] = null;
                        }
                    }
                }

                $count = $query->update($values);
                if ($count > 0) {
                    $affected[$table] = $count;
                }
            }

            $company->delete();
        });

        return $affected;
    }

    /**
     * @param array<int, string> $platformAdminIds
     * @return array<string, int>
     */
    private function revokeAccess(string $companyId, array $platformAdminIds, $now): array
    {
        $revoked = ['status' => 'revoked', 'revoked_at' => $now, 'updated_at' => $now];
        $reason = ['revocation_reason' => 'company_deleted'];
        $revocations = [
            'licenses' => [$revoked, fn ($query) => $query->where('status', '!=', 'revoked')],
            'auth_sessions' => [$revoked + $reason, fn ($query) => $query->whereNull('revoked_at')
                ->whereNotIn('pos_user_id', $platformAdminIds)],
            'user_access_assignments' => [$revoked + $reason, fn ($query) => $query->whereNull('revoked_at')],
            'sync_nodes' => [$revoked, fn ($query) => $query->whereNull('revoked_at')],
            'sync_terminal_activations' => [$revoked, fn ($query) => $query->whereNull('revoked_at')],
        ];

        $affected = [];
        foreach ($revocations as $table => [$values, $pending]) {
            if (! Schema::hasTable($table) || ! $this->hasColumn($table, 'company_id')) {
                continue;
            }

            // Write only the columns this installation has.
            $values = array_filter(
                $values,
                fn (string $column): bool => $this->hasColumn($table, $column),
                ARRAY_FILTER_USE_KEY
            );
            $marker = array_key_exists('revoked_at', $values) ? 'revoked_at' : 'status';
            if (! array_key_exists($marker, $values)) {
                continue;
            }

            $query = DB::table($table)->where('company_id', $companyId);
            $marker === 'revoked_at'
                ? $pending($query)
                : $query->where('status', '!=', 'revoked');

            $count = $query->update($values);
            if ($count > 0) {
                $affected[$table] = $count;
            }
        }

        return $affected;
    }

    /** @return array<int, string> */
    private function softDeletableCompanyTables(): array
    {
        // Only this connection's database: on a shared MySQL server
        // getTables() would otherwise list every database's tables.
        return collect(Schema::getTables(Schema::getCurrentSchemaName()))
            ->pluck('name')
            ->reject(fn (string $table): bool => $table === 'companies')
            ->filter(fn (string $table): bool => $this->hasColumn($table, 'company_id')
                && $this->hasColumn($table, 'deleted_at'))
            ->values()
            ->all();
    }

    private function hasColumn(string $table, string $column): bool
    {
        $this->columns[$table] ??= Schema::getColumnListing($table);

        return in_array($column, $this->columns[$table], true);
    }
}
