<?php

namespace App\Services\Provisioning;

use App\Exceptions\StandaloneAdminBootstrapException;
use App\Services\Sync\LocalHubCompanyScopeService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class StandaloneInitialAdminBootstrapService
{
    public const EMPLOYEE_CODE = 'STANDALONE-INITIAL-ADMIN';

    /**
     * @param array{
     *     schema_version:mixed,
     *     company_id:mixed,
     *     user:mixed
     * } $credentials
     * @return array{company_id:string,branch_id:string,user_id:string,created:bool}
     */
    public function bootstrap(array $credentials, bool $rotateExisting = false): array
    {
        $this->assertStandaloneDeployment();
        $this->assertSchemaReady();
        $data = $this->validateCredentials($credentials);
        $companyId = $this->resolveOnlyProvisionedCompany($data['company_id']);
        $branch = $this->resolveDefaultBranch($companyId, $data['user']['branch_id']);

        return DB::transaction(function () use ($companyId, $branch, $data, $rotateExisting): array {
            DB::table('companies')->where('id', $companyId)->lockForUpdate()->first();

            $marker = DB::table('standalone_initial_admins')
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->first();
            $users = DB::table('pos_users')
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->get(['id', 'deleted_at']);
            $userId = $data['user']['id'];
            $created = $marker === null;

            if ($created) {
                if ($users->isNotEmpty()) {
                    throw new StandaloneAdminBootstrapException(
                        'Bootstrap refused because company users already exist. Create administrators through the normal Back Office workflow.'
                    );
                }
            } else {
                if (! $rotateExisting) {
                    throw new StandaloneAdminBootstrapException(
                        'The initial administrator is already bootstrapped. Use --rotate-existing only for an intentional credential rotation.'
                    );
                }

                if ((string) $marker->pos_user_id !== $userId
                    || $users->count() !== 1
                    || (string) $users->first()?->id !== $userId
                    || $users->first()?->deleted_at !== null) {
                    throw new StandaloneAdminBootstrapException(
                        'Credential rotation was refused because the designated initial administrator is missing or company user data is no longer pristine.'
                    );
                }
            }

            $usernameTaken = DB::table('pos_users')
                ->where('username', $data['user']['username'])
                ->where('id', '!=', $userId)
                ->whereNull('deleted_at')
                ->exists();
            if ($usernameTaken) {
                throw new StandaloneAdminBootstrapException(
                    'Bootstrap refused because the requested username is already assigned.'
                );
            }

            // This is the canonical application permission catalogue. Running
            // it here makes a fresh installer deterministic without invoking
            // DatabaseSeeder, which contains unrelated operational fixtures.
            app(PermissionSeeder::class)->run();
            $permissionIds = DB::table('permissions')->pluck('id')->map(fn ($id) => (string) $id)->all();
            if ($permissionIds === []) {
                throw new StandaloneAdminBootstrapException('Bootstrap refused because the permission catalogue is empty.');
            }

            $roleId = $this->resolveCompanySuperAdminRole($companyId);
            $this->assignEveryPermission($companyId, $roleId, $permissionIds);

            $now = now();
            $userValues = [
                'company_id' => $companyId,
                'branch_id' => (string) $branch->id,
                'role_id' => $roleId,
                'name' => $data['user']['name'],
                'username' => $data['user']['username'],
                'phone' => null,
                'password' => Hash::make($data['user']['password']),
                'employee_code' => self::EMPLOYEE_CODE,
                'user_type' => 'super_admin',
                'can_login_pos' => true,
                'can_access_front_office' => true,
                'can_access_back_office' => true,
                'is_active' => true,
                'login_code' => $data['user']['login_code'] === null
                    ? null
                    : Hash::make($data['user']['login_code']),
                'updated_at' => $now,
            ];

            if ($created) {
                DB::table('pos_users')->insert($userValues + [
                    'id' => $userId,
                    'last_login_at' => null,
                    'remember_token' => null,
                    'created_at' => $now,
                    'deleted_at' => null,
                ]);
            } else {
                DB::table('pos_users')->where('id', $userId)->update($userValues);

                if (Schema::hasTable('personal_access_tokens')) {
                    DB::table('personal_access_tokens')
                        ->where('tokenable_id', $userId)
                        ->where('tokenable_type', 'Modules\\Authentication\\Models\\PosUser')
                        ->delete();
                }
            }

            $this->assignCompanyBranches($companyId, $userId, (string) $branch->id);

            if ($created) {
                DB::table('standalone_initial_admins')->insert([
                    'id' => (string) Str::uuid(),
                    'company_id' => $companyId,
                    'pos_user_id' => $userId,
                    'bootstrapped_at' => $now,
                    'last_rotated_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('standalone_initial_admins')
                    ->where('company_id', $companyId)
                    ->update([
                        'last_rotated_at' => $now,
                        'updated_at' => $now,
                    ]);
            }

            return [
                'company_id' => $companyId,
                'branch_id' => (string) $branch->id,
                'user_id' => $userId,
                'created' => $created,
            ];
        }, 3);
    }

    private function assertStandaloneDeployment(): void
    {
        $deploymentMode = strtolower(trim((string) config('salexa_sync.deployment_mode')));
        $localHub = $deploymentMode === 'cloud' && (bool) config('salexa_sync.local_hub', false);
        if ($deploymentMode !== 'standalone' && ! $localHub) {
            throw new StandaloneAdminBootstrapException(
                'Initial administrator bootstrap is available only on a standalone or local Back Office installation.'
            );
        }
        if ($localHub && ((string) config('license.signing.mode') !== 'verify_only'
            || trim((string) config('license.signing.private_key')) !== '')) {
            throw new StandaloneAdminBootstrapException(
                'A local Back Office must use verify-only licensing and must not contain an issuer private key.'
            );
        }
    }

    private function assertSchemaReady(): void
    {
        $requiredTables = [
            'companies',
            'company_branches',
            'standalone_provisioning_imports',
            'standalone_initial_admins',
            'pos_users',
            'user_branches',
            'roles',
            'permissions',
            'role_permissions',
        ];

        foreach ($requiredTables as $table) {
            if (! Schema::hasTable($table)) {
                throw new StandaloneAdminBootstrapException(
                    'The database schema is incomplete. Run all migrations before bootstrapping the administrator.'
                );
            }
        }
    }

    /**
     * @return array{
     *     company_id:string,
     *     user:array{id:string,name:string,username:string,password:string,login_code:?string,branch_id:?string}
     * }
     */
    private function validateCredentials(array $credentials): array
    {
        $this->assertOnlyKeys($credentials, ['schema_version', 'company_id', 'user']);
        if (($credentials['schema_version'] ?? null) !== 1
            || ! is_string($credentials['company_id'] ?? null)
            || ! Str::isUuid($credentials['company_id'])) {
            throw new StandaloneAdminBootstrapException('The credentials file schema or company identity is invalid.');
        }

        $user = $credentials['user'] ?? null;
        if (! is_array($user)) {
            throw new StandaloneAdminBootstrapException('The credentials file user section is invalid.');
        }
        $this->assertOnlyKeys($user, ['id', 'name', 'username', 'password', 'login_code', 'branch_id']);

        $id = $user['id'] ?? null;
        $name = is_string($user['name'] ?? null) ? trim($user['name']) : '';
        $username = is_string($user['username'] ?? null) ? trim($user['username']) : '';
        $password = is_string($user['password'] ?? null) ? $user['password'] : '';
        $loginCode = $user['login_code'] ?? null;
        $branchId = $user['branch_id'] ?? null;

        if (! is_string($id) || ! Str::isUuid($id)
            || $name === '' || mb_strlen($name) > 255
            || preg_match('/^[A-Za-z0-9._@-]{3,255}$/', $username) !== 1
            || strlen($password) < 12 || strlen($password) > 1024
            || hash_equals(strtolower($username), strtolower($password))) {
            throw new StandaloneAdminBootstrapException('The initial administrator credentials do not meet the required format.');
        }

        if ($loginCode !== null && (! is_string($loginCode) || preg_match('/^\d{4}$/D', $loginCode) !== 1)) {
            throw new StandaloneAdminBootstrapException('The optional login code must contain exactly four digits.');
        }
        if ($branchId !== null && (! is_string($branchId) || ! Str::isUuid($branchId))) {
            throw new StandaloneAdminBootstrapException('The default branch identity is invalid.');
        }

        return [
            'company_id' => strtolower($credentials['company_id']),
            'user' => [
                'id' => strtolower($id),
                'name' => $name,
                'username' => $username,
                'password' => $password,
                'login_code' => $loginCode,
                'branch_id' => $branchId === null ? null : strtolower($branchId),
            ],
        ];
    }

    /** @param list<string> $allowed */
    private function assertOnlyKeys(array $data, array $allowed): void
    {
        if (array_diff(array_keys($data), $allowed) !== []) {
            throw new StandaloneAdminBootstrapException('The credentials file contains unsupported fields.');
        }
    }

    private function resolveOnlyProvisionedCompany(string $expectedCompanyId): string
    {
        $localHubScope = app(LocalHubCompanyScopeService::class);
        if ($localHubScope->isLocalHub()) {
            $provisionedCompanyId = $localHubScope->provisionedCompanyId();
            if ($provisionedCompanyId === null
                || ! hash_equals(strtolower($provisionedCompanyId), strtolower($expectedCompanyId))) {
                throw new StandaloneAdminBootstrapException(
                    'Bootstrap requires exactly one company matching the Central-signed provisioning import.'
                );
            }

            return $provisionedCompanyId;
        }

        $companies = DB::table('companies')
            ->when(Schema::hasColumn('companies', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
            ->pluck('id')
            ->map(fn ($id) => strtolower((string) $id));

        if ($companies->count() !== 1 || $companies->first() !== strtolower($expectedCompanyId)) {
            throw new StandaloneAdminBootstrapException(
                'Bootstrap requires exactly one local company matching the signed provisioning package.'
            );
        }

        $importCompanies = DB::table('standalone_provisioning_imports')
            ->distinct()
            ->pluck('company_id')
            ->map(fn ($id) => strtolower((string) $id));
        if ($importCompanies->count() !== 1 || $importCompanies->first() !== $companies->first()) {
            throw new StandaloneAdminBootstrapException(
                'Bootstrap requires a successfully imported Central-issued standalone provisioning package.'
            );
        }

        return (string) $companies->first();
    }

    private function resolveDefaultBranch(string $companyId, ?string $requestedBranchId): object
    {
        $query = DB::table('company_branches')
            ->where('company_id', $companyId)
            ->when(
                Schema::hasColumn('company_branches', 'deleted_at'),
                fn ($builder) => $builder->whereNull('deleted_at')
            );

        if ($requestedBranchId !== null) {
            $branches = $query->where('id', $requestedBranchId)->get(['id']);
        } else {
            $branches = $query->where('is_main_branch', true)->get(['id']);
        }

        if ($branches->count() !== 1) {
            throw new StandaloneAdminBootstrapException(
                $requestedBranchId === null
                    ? 'Bootstrap requires exactly one company main branch when branch_id is omitted.'
                    : 'The requested default branch does not belong to the provisioned company.'
            );
        }

        return $branches->first();
    }

    private function resolveCompanySuperAdminRole(string $companyId): string
    {
        $roles = DB::table('roles')
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->get(['id', 'name'])
            ->filter(fn ($role) => in_array($this->normaliseRole($role->name), ['super_admin', 'super_administrator'], true))
            ->values();

        if ($roles->count() > 1) {
            throw new StandaloneAdminBootstrapException(
                'Bootstrap refused because the company Super Admin role is ambiguous.'
            );
        }

        if ($roles->count() === 1) {
            return (string) $roles->first()->id;
        }

        $roleId = (string) Str::uuid();
        DB::table('roles')->insert([
            'id' => $roleId,
            'company_id' => $companyId,
            'name' => 'Super Admin',
            'group' => 'Administration',
            'description' => 'Company-scoped Super Administrator',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $roleId;
    }

    /** @param list<string> $permissionIds */
    private function assignEveryPermission(string $companyId, string $roleId, array $permissionIds): void
    {
        $now = now();
        $rows = array_map(static fn (string $permissionId): array => [
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'role_id' => $roleId,
            'permission_id' => $permissionId,
            'created_at' => $now,
            'updated_at' => $now,
        ], $permissionIds);

        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table('role_permissions')->upsert(
                $chunk,
                ['role_id', 'permission_id'],
                ['company_id', 'updated_at']
            );
        }
    }

    private function assignCompanyBranches(string $companyId, string $userId, string $defaultBranchId): void
    {
        $branchIds = DB::table('company_branches')
            ->where('company_id', $companyId)
            ->when(
                Schema::hasColumn('company_branches', 'deleted_at'),
                fn ($query) => $query->whereNull('deleted_at')
            )
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
        if ($branchIds === [] || ! in_array($defaultBranchId, $branchIds, true)) {
            throw new StandaloneAdminBootstrapException('The company branch assignment is invalid.');
        }

        DB::table('user_branches')->where('pos_user_id', $userId)->delete();
        $now = now();
        DB::table('user_branches')->insert(array_map(
            static fn (string $branchId): array => [
                'id' => (string) Str::uuid(),
                'company_id' => $companyId,
                'pos_user_id' => $userId,
                'branch_id' => $branchId,
                'is_default' => $branchId === $defaultBranchId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $branchIds
        ));
    }

    private function normaliseRole(mixed $value): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim((string) $value)));
    }
}
