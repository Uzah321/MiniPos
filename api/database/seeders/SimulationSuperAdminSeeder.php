<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Creates the deliberately insecure local/simulation administrator fixture.
 *
 * Production installations must provision their first administrator through
 * the normal signed bootstrap flow. This fixture is enabled automatically only
 * for local/testing environments, or explicitly with
 * SERVENEST_SEED_SIMULATION_DATA=true.
 */
class SimulationSuperAdminSeeder extends Seeder
{
    public const USER_ID = '0199c7ee-0000-7000-8000-000000000005';

    public const USERNAME = 'Cashier1';

    private const PASSWORD = 'password123';

    private const COUNTRY_ID = '0199c7ee-0000-7000-8000-000000000001';

    private const COMPANY_ID = '0199c7ee-0000-7000-8000-000000000002';

    private const BRANCH_ID = '0199c7ee-0000-7000-8000-000000000003';

    private const ROLE_ID = '0199c7ee-0000-7000-8000-000000000004';

    private const USER_BRANCH_ID = '0199c7ee-0000-7000-8000-000000000006';

    private const ACCESS_ASSIGNMENT_ID = '0199c7ee-0000-7000-8000-000000000007';

    private const PIZZA_INN_BRAND_ID = '0199c7ee-0000-7000-8000-000000000008';

    private const CHICKEN_INN_BRAND_ID = '0199c7ee-0000-7000-8000-000000000009';

    public function run(): void
    {
        $this->assertSimulationSeedingIsAllowed();
        $this->assertSchemaIsReady();

        // Keep direct --class execution deterministic as well as DatabaseSeeder.
        app(PermissionSeeder::class)->run();

        DB::transaction(function (): void {
            $countryId = $this->resolveCountry();
            $companyId = $this->resolveCompany($countryId);
            $this->upsertSimulationBrands($countryId, $companyId);
            $branchId = $this->resolveBranch($companyId);
            $roleId = $this->resolveSuperAdminRole($companyId);

            $this->assignEveryPermission($companyId, $roleId);
            $this->upsertSimulationUser($companyId, $branchId, $roleId);
            $this->assignBranch($companyId, $branchId);
            $this->assignCompanyScope($countryId, $companyId, $roleId);
        }, 3);
    }

    private function assertSimulationSeedingIsAllowed(): void
    {
        if (app()->environment(['local', 'testing'])
            || (bool) config('servnest.simulation.seed_data', false)) {
            return;
        }

        throw new RuntimeException(
            'Simulation data seeding is disabled. Set SERVENEST_SEED_SIMULATION_DATA=true only for an intentional test installation.'
        );
    }

    private function assertSchemaIsReady(): void
    {
        foreach ([
            'countries',
            'companies',
            'brands',
            'company_branches',
            'roles',
            'permissions',
            'role_permissions',
            'pos_users',
            'user_branches',
            'user_access_assignments',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Simulation administrator seeding requires the {$table} migration.");
            }
        }
    }

    private function resolveCountry(): string
    {
        $country = DB::table('countries')->where('code', 'ZW')->first(['id']);
        if ($country) {
            return (string) $country->id;
        }

        $this->assertFixtureIdIsAvailable('countries', self::COUNTRY_ID);
        $now = now();
        DB::table('countries')->insert([
            'id' => self::COUNTRY_ID,
            'code' => 'ZW',
            'iso_alpha_2' => 'ZW',
            'iso_alpha_3' => 'ZWE',
            'name' => 'Zimbabwe',
            'currency_code' => 'USD',
            'timezone' => 'Africa/Harare',
            'locale' => 'en_ZW',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return self::COUNTRY_ID;
    }

    private function resolveCompany(string $countryId): string
    {
        $company = DB::table('companies')
            ->where('company_code', 'SERVENEST-SIM')
            ->first(['id']);
        if ($company) {
            return (string) $company->id;
        }

        $this->assertFixtureIdIsAvailable('companies', self::COMPANY_ID);
        $now = now();
        DB::table('companies')->insert([
            'id' => self::COMPANY_ID,
            'country_id' => $countryId,
            'company_code' => 'SERVENEST-SIM',
            'company_name' => 'ServeNest Simulation',
            'location_address' => 'Local simulation environment',
            'city' => 'Harare',
            'country' => 'Zimbabwe',
            'domain' => 'servenest-simulation.local',
            'host_ip' => '127.0.0.1',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return self::COMPANY_ID;
    }

    private function resolveBranch(string $companyId): string
    {
        $branch = DB::table('company_branches')
            ->where('company_id', $companyId)
            ->where('branch_code', 'SIM-HQ')
            ->first(['id']);
        if ($branch) {
            return (string) $branch->id;
        }

        $this->assertFixtureIdIsAvailable('company_branches', self::BRANCH_ID);
        $now = now();
        DB::table('company_branches')->insert([
            'id' => self::BRANCH_ID,
            'company_id' => $companyId,
            'branch_name' => 'ServeNest Simulation HQ',
            'branch_code' => 'SIM-HQ',
            'branch_address' => 'Local simulation environment',
            'branch_city' => 'Harare',
            'branch_country' => 'Zimbabwe',
            'level' => 'head_office',
            'is_main_branch' => true,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return self::BRANCH_ID;
    }

    private function upsertSimulationBrands(string $countryId, string $companyId): void
    {
        foreach ([
            [
                'id' => self::PIZZA_INN_BRAND_ID,
                'code' => 'PZ',
                'name' => 'Pizza Inn',
                'workbook_format' => 'pizza_inn_gaap',
            ],
            [
                'id' => self::CHICKEN_INN_BRAND_ID,
                'code' => 'CH',
                'name' => 'Chicken Inn',
                'workbook_format' => 'chicken_inn_gaap',
            ],
        ] as $fixture) {
            $existing = DB::table('brands')
                ->where('company_id', $companyId)
                ->where('country_id', $countryId)
                ->where('code', $fixture['code'])
                ->first(['id']);
            $values = [
                'company_id' => $companyId,
                'country_id' => $countryId,
                'code' => $fixture['code'],
                'name' => $fixture['name'],
                'status' => 'active',
                'metadata' => json_encode([
                    'simulation_fixture' => true,
                    'catalogue_format' => $fixture['workbook_format'],
                ], JSON_THROW_ON_ERROR),
                'deleted_at' => null,
                'updated_at' => now(),
            ];

            if ($existing) {
                DB::table('brands')->where('id', $existing->id)->update($values);
                continue;
            }

            $this->assertFixtureIdIsAvailable('brands', $fixture['id']);
            DB::table('brands')->insert($values + [
                'id' => $fixture['id'],
                'description' => null,
                'logo_path' => null,
                'logo_url' => null,
                'created_at' => now(),
            ]);
        }
    }

    private function resolveSuperAdminRole(string $companyId): string
    {
        $roles = DB::table('roles')
            ->where('company_id', $companyId)
            ->whereRaw("LOWER(REPLACE(REPLACE(TRIM(name), ' ', '_'), '-', '_')) IN ('super_admin', 'super_administrator')")
            ->get(['id']);

        if ($roles->count() > 1) {
            throw new RuntimeException('Simulation administrator seeding found ambiguous company Super Admin roles.');
        }
        if ($roles->count() === 1) {
            return (string) $roles->first()->id;
        }

        $this->assertFixtureIdIsAvailable('roles', self::ROLE_ID);
        $now = now();
        DB::table('roles')->insert([
            'id' => self::ROLE_ID,
            'company_id' => $companyId,
            'name' => 'Super Admin',
            'group' => 'Administration',
            'description' => 'Company-scoped Super Administrator',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return self::ROLE_ID;
    }

    private function assignEveryPermission(string $companyId, string $roleId): void
    {
        $now = now();
        $rows = DB::table('permissions')
            ->pluck('id')
            ->map(fn ($permissionId): array => [
                'id' => (string) Str::uuid(),
                'company_id' => $companyId,
                'role_id' => $roleId,
                'permission_id' => (string) $permissionId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        if ($rows->isEmpty()) {
            throw new RuntimeException('Simulation administrator seeding requires a non-empty permission catalogue.');
        }

        $rows->chunk(250)->each(function ($chunk): void {
            DB::table('role_permissions')->upsert(
                $chunk->all(),
                ['role_id', 'permission_id'],
                ['company_id', 'updated_at']
            );
        });
    }

    private function upsertSimulationUser(string $companyId, string $branchId, string $roleId): void
    {
        $existing = DB::table('pos_users')->where('id', self::USER_ID)->lockForUpdate()->first();
        if ($existing && (string) $existing->company_id !== $companyId) {
            throw new RuntimeException('The reserved simulation administrator UUID is already owned by another company.');
        }

        $conflictingUsername = DB::table('pos_users')
            ->where('company_id', $companyId)
            ->where('username', self::USERNAME)
            ->where('id', '!=', self::USER_ID)
            ->exists();
        if ($conflictingUsername) {
            throw new RuntimeException(
                'Cashier1 already belongs to another user in the simulation company; no existing user was changed.'
            );
        }

        $now = now();
        $credentialChanged = ! $existing
            || ! Hash::check(self::PASSWORD, (string) $existing->password)
            || Hash::needsRehash((string) $existing->password);
        $password = $credentialChanged
            ? Hash::make(self::PASSWORD)
            : (string) $existing->password;
        $credentialVersion = max(1, (int) ($existing->credential_version ?? 1));
        if ($existing && $credentialChanged) {
            $credentialVersion++;
        }

        $values = [
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'role_id' => $roleId,
            'name' => 'ServeNest Simulation Super Admin',
            'username' => self::USERNAME,
            'phone' => null,
            'password' => $password,
            'employee_code' => 'SERVENEST-SIM-SUPER-ADMIN',
            'user_type' => 'super_admin',
            'can_login_pos' => true,
            'can_access_front_office' => true,
            'can_access_back_office' => true,
            'is_active' => true,
            'credential_version' => $credentialVersion,
            'credentials_changed_at' => $credentialChanged
                ? $now
                : $existing->credentials_changed_at,
            'deleted_at' => null,
            'updated_at' => $now,
        ];

        if ($existing) {
            DB::table('pos_users')->where('id', self::USER_ID)->update($values);
        } else {
            DB::table('pos_users')->insert($values + [
                'id' => self::USER_ID,
                'login_code' => null,
                'login_code_lookup' => null,
                'login_code_lookup_key_id' => null,
                'last_login_at' => null,
                'remember_token' => null,
                'created_at' => $now,
            ]);
        }

        if ($credentialChanged && Schema::hasTable('personal_access_tokens')) {
            DB::table('personal_access_tokens')
                ->where('tokenable_id', self::USER_ID)
                ->where('tokenable_type', 'Modules\\Authentication\\Models\\PosUser')
                ->delete();
        }
    }

    private function assignBranch(string $companyId, string $branchId): void
    {
        DB::table('user_branches')
            ->where('pos_user_id', self::USER_ID)
            ->update(['is_default' => false, 'updated_at' => now()]);

        $existing = DB::table('user_branches')
            ->where('pos_user_id', self::USER_ID)
            ->where('branch_id', $branchId)
            ->first(['id']);
        $values = [
            'company_id' => $companyId,
            'is_default' => true,
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('user_branches')->where('id', $existing->id)->update($values);

            return;
        }

        $this->assertFixtureIdIsAvailable('user_branches', self::USER_BRANCH_ID);
        DB::table('user_branches')->insert($values + [
            'id' => self::USER_BRANCH_ID,
            'pos_user_id' => self::USER_ID,
            'branch_id' => $branchId,
            'created_at' => now(),
        ]);
    }

    private function assignCompanyScope(string $countryId, string $companyId, string $roleId): void
    {
        $scopeKey = 'company:'.$companyId;
        $existing = DB::table('user_access_assignments')
            ->where('pos_user_id', self::USER_ID)
            ->where('role_id', $roleId)
            ->where('scope_key', $scopeKey)
            ->first(['id']);
        $values = [
            'scope_type' => 'company',
            'scope_key' => $scopeKey,
            'country_id' => $countryId,
            'company_id' => $companyId,
            'brand_id' => null,
            'complex_id' => null,
            'shop_id' => null,
            'till_id' => null,
            'status' => 'active',
            'valid_from' => null,
            'valid_until' => null,
            'granted_by' => null,
            'grant_reason' => 'ServeNest local simulation bootstrap',
            'revoked_by' => null,
            'revoked_at' => null,
            'revocation_reason' => null,
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('user_access_assignments')->where('id', $existing->id)->update($values);

            return;
        }

        $this->assertFixtureIdIsAvailable('user_access_assignments', self::ACCESS_ASSIGNMENT_ID);
        DB::table('user_access_assignments')->insert($values + [
            'id' => self::ACCESS_ASSIGNMENT_ID,
            'pos_user_id' => self::USER_ID,
            'role_id' => $roleId,
            'created_at' => now(),
        ]);
    }

    private function assertFixtureIdIsAvailable(string $table, string $id): void
    {
        if (DB::table($table)->where('id', $id)->exists()) {
            throw new RuntimeException("The reserved simulation fixture UUID already exists in {$table}.");
        }
    }
}
