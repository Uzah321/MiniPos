<?php

namespace App\Services\Provisioning;

use App\Exceptions\PlatformAdminBootstrapException;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Creates the first platform administrator of a freshly installed Central
 * Back Office: its home country, operator company, head-office branch and a
 * company Super Admin whose UUID the installer lists in
 * PLATFORM_ADMIN_POS_USER_IDS. Further companies, users and licences are then
 * managed through the normal Back Office workflow.
 */
class PlatformAdministratorBootstrapService
{
    public const EMPLOYEE_CODE = 'SERVENEST-PLATFORM-ADMIN';

    /**
     * @param array{schema_version:mixed,country:mixed,company:mixed,user:mixed} $credentials
     * @return array{country_id:string,company_id:string,branch_id:string,user_id:string,created:bool}
     */
    /**
     * $ensure makes a retried installation idempotent: the designated
     * administrator is created when missing, otherwise its credentials are
     * set again (the UUID must match).
     */
    public function bootstrap(array $credentials, bool $rotateExisting = false, bool $ensure = false): array
    {
        $this->assertCentralDeployment();
        $this->assertSchemaReady();
        $data = $this->validateCredentials($credentials);

        return DB::transaction(function () use ($data, $rotateExisting, $ensure): array {
            $existing = DB::table('pos_users')
                ->where('employee_code', self::EMPLOYEE_CODE)
                ->lockForUpdate()
                ->get(['id', 'company_id', 'branch_id', 'deleted_at']);

            if ($existing->isNotEmpty()) {
                if (! $rotateExisting && ! $ensure) {
                    throw new PlatformAdminBootstrapException(
                        'The platform administrator is already bootstrapped. Use --rotate-existing only for an intentional credential rotation.'
                    );
                }

                return $this->rotate($existing, $data);
            }

            if ($rotateExisting && ! $ensure) {
                throw new PlatformAdminBootstrapException(
                    'There is no bootstrapped platform administrator to rotate.'
                );
            }

            return $this->create($data);
        }, 3);
    }

    /** @param array{country:array<string,string>,company:array<string,string>,user:array<string,string>} $data */
    private function create(array $data): array
    {
        // Creating needs the home country and operator company; a password
        // rotation needs only the user section.
        if ($data['country'] === [] || $data['company'] === []) {
            throw new PlatformAdminBootstrapException('The credentials file country or company section is invalid.');
        }
        $user = $data['user'];
        if (DB::table('pos_users')->where('id', $user['id'])->exists()) {
            throw new PlatformAdminBootstrapException('Bootstrap refused because the requested administrator ID already exists.');
        }
        if (DB::table('pos_users')->where('username', $user['username'])->whereNull('deleted_at')->exists()) {
            throw new PlatformAdminBootstrapException('Bootstrap refused because the requested username is already assigned.');
        }
        if (DB::table('companies')->where('company_code', $data['company']['code'])->whereNull('deleted_at')->exists()) {
            throw new PlatformAdminBootstrapException('Bootstrap refused because the company code is already in use.');
        }

        $countryId = $this->resolveCountry($data['country']);
        $companyId = (string) Str::uuid();
        $branchId = (string) Str::uuid();
        $now = now();

        DB::table('companies')->insert([
            'id' => $companyId,
            'country_id' => $countryId,
            'company_code' => $data['company']['code'],
            'company_name' => $data['company']['name'],
            'location_address' => 'Head office',
            'city' => $data['company']['city'],
            'country' => $data['country']['name'],
            'domain' => null,
            'host_ip' => null,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('company_branches')->insert([
            'id' => $branchId,
            'company_id' => $companyId,
            'branch_name' => $data['company']['name'].' Head Office',
            'branch_code' => 'HQ',
            'branch_address' => 'Head office',
            'branch_city' => $data['company']['city'],
            'branch_country' => $data['country']['name'],
            'level' => 'head_office',
            'is_main_branch' => true,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // The canonical permission catalogue; DatabaseSeeder is run separately
        // by the installer for the remaining reference data.
        app(PermissionSeeder::class)->run();
        $permissionIds = DB::table('permissions')->pluck('id')->map(fn ($id) => (string) $id)->all();
        if ($permissionIds === []) {
            throw new PlatformAdminBootstrapException('Bootstrap refused because the permission catalogue is empty.');
        }

        $roleId = (string) Str::uuid();
        DB::table('roles')->insert([
            'id' => $roleId,
            'company_id' => $companyId,
            'name' => 'Super Admin',
            'group' => 'Administration',
            'description' => 'Company-scoped Super Administrator',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->assignEveryPermission($companyId, $roleId, $permissionIds);

        DB::table('pos_users')->insert($this->userValues($user, $companyId, $branchId, $roleId) + [
            'id' => $user['id'],
            'phone' => null,
            'login_code' => null,
            'last_login_at' => null,
            'remember_token' => null,
            'created_at' => $now,
            'deleted_at' => null,
        ]);
        DB::table('user_branches')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'pos_user_id' => $user['id'],
            'branch_id' => $branchId,
            'is_default' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->assignCompanyScope($user['id'], $roleId, $countryId, $companyId);

        return [
            'country_id' => $countryId,
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'user_id' => $user['id'],
            'created' => true,
        ];
    }

    private function rotate($existing, array $data): array
    {
        $user = $data['user'];
        $current = $existing->first();
        if ($existing->count() !== 1
            || (string) $current->id !== $user['id']
            || $current->deleted_at !== null) {
            throw new PlatformAdminBootstrapException(
                'Credential rotation was refused because the designated platform administrator is missing or does not match.'
            );
        }
        if (DB::table('pos_users')
            ->where('username', $user['username'])
            ->where('id', '!=', $user['id'])
            ->whereNull('deleted_at')
            ->exists()) {
            throw new PlatformAdminBootstrapException('Rotation refused because the requested username is already assigned.');
        }

        $row = DB::table('pos_users')->where('id', $user['id'])->first();
        $values = [
            'name' => $user['name'],
            'username' => $user['username'],
            'password' => Hash::make($user['password']),
            'is_active' => true,
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('pos_users', 'credential_version')) {
            $values['credential_version'] = max(1, (int) ($row->credential_version ?? 1)) + 1;
        }
        if (Schema::hasColumn('pos_users', 'credentials_changed_at')) {
            $values['credentials_changed_at'] = now();
        }
        DB::table('pos_users')->where('id', $user['id'])->update($values);

        if (Schema::hasTable('personal_access_tokens')) {
            DB::table('personal_access_tokens')
                ->where('tokenable_id', $user['id'])
                ->where('tokenable_type', 'Modules\\Authentication\\Models\\PosUser')
                ->delete();
        }

        return [
            'country_id' => (string) DB::table('companies')->where('id', $current->company_id)->value('country_id'),
            'company_id' => (string) $current->company_id,
            'branch_id' => (string) $current->branch_id,
            'user_id' => $user['id'],
            'created' => false,
        ];
    }

    /** @param array<string,string> $user */
    private function userValues(array $user, string $companyId, string $branchId, string $roleId): array
    {
        $values = [
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'role_id' => $roleId,
            'name' => $user['name'],
            'username' => $user['username'],
            'password' => Hash::make($user['password']),
            'employee_code' => self::EMPLOYEE_CODE,
            'user_type' => 'super_admin',
            // Back Office sign-in requires can_login_pos as well as Back
            // Office access. The platform owner has no till workspace.
            'can_login_pos' => true,
            'can_access_front_office' => false,
            'can_access_back_office' => true,
            'is_active' => true,
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('pos_users', 'credential_version')) {
            $values['credential_version'] = 1;
        }
        if (Schema::hasColumn('pos_users', 'credentials_changed_at')) {
            $values['credentials_changed_at'] = now();
        }

        return $values;
    }

    /** @param array<string,string> $country */
    private function resolveCountry(array $country): string
    {
        $existing = DB::table('countries')
            ->where(fn ($query) => $query
                ->where('code', $country['code'])
                ->orWhere('iso_alpha_2', $country['code']))
            ->orderByRaw('CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END')
            ->first(['id', 'deleted_at']);
        if ($existing) {
            if ($existing->deleted_at !== null) {
                throw new PlatformAdminBootstrapException('Bootstrap refused because the home country exists but is deleted.');
            }

            return (string) $existing->id;
        }

        $id = (string) Str::uuid();
        DB::table('countries')->insert([
            'id' => $id,
            'code' => $country['code'],
            'iso_alpha_2' => $country['code'],
            'iso_alpha_3' => null,
            'name' => $country['name'],
            'currency_code' => $country['currency_code'],
            'timezone' => $country['timezone'],
            'locale' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
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

    private function assignCompanyScope(string $userId, string $roleId, string $countryId, string $companyId): void
    {
        if (! Schema::hasTable('user_access_assignments')) {
            return;
        }

        DB::table('user_access_assignments')->insert([
            'id' => (string) Str::uuid(),
            'pos_user_id' => $userId,
            'role_id' => $roleId,
            'scope_type' => 'company',
            'scope_key' => 'company:'.$companyId,
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
            'grant_reason' => 'ServeNest Central Back Office installation',
            'revoked_by' => null,
            'revoked_at' => null,
            'revocation_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertCentralDeployment(): void
    {
        if (strtolower(trim((string) config('salexa_sync.deployment_mode'))) !== 'cloud'
            || (bool) config('salexa_sync.local_hub', false)) {
            throw new PlatformAdminBootstrapException(
                'Platform administrator bootstrap is available only on a Central (cloud) Back Office installation.'
            );
        }
    }

    private function assertSchemaReady(): void
    {
        foreach ([
            'countries',
            'companies',
            'company_branches',
            'roles',
            'permissions',
            'role_permissions',
            'pos_users',
            'user_branches',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new PlatformAdminBootstrapException(
                    'The database schema is incomplete. Run all migrations before bootstrapping the platform administrator.'
                );
            }
        }
    }

    /**
     * @return array{country:array<string,string>,company:array<string,string>,user:array<string,string>}
     */
    private function validateCredentials(array $credentials): array
    {
        // Organisation sections are optional here (a rotation omits them);
        // when present they must be valid, and create() requires them.
        $requireOrganisation = array_key_exists('country', $credentials) || array_key_exists('company', $credentials);
        $this->assertOnlyKeys($credentials, ['schema_version', 'country', 'company', 'user']);
        if (($credentials['schema_version'] ?? null) !== 1) {
            throw new PlatformAdminBootstrapException('The credentials file schema is invalid.');
        }

        $user = $credentials['user'] ?? null;
        if (! is_array($user)) {
            throw new PlatformAdminBootstrapException('The credentials file user section is invalid.');
        }
        $this->assertOnlyKeys($user, ['id', 'name', 'username', 'password']);

        $countryData = [];
        $companyData = [];
        if ($requireOrganisation) {
            $country = $credentials['country'] ?? null;
            $company = $credentials['company'] ?? null;
            if (! is_array($country) || ! is_array($company)) {
                throw new PlatformAdminBootstrapException('The credentials file country or company section is invalid.');
            }
            $this->assertOnlyKeys($country, ['code', 'name', 'currency_code', 'timezone']);
            $this->assertOnlyKeys($company, ['code', 'name', 'city']);

            $countryData = [
                'code' => strtoupper($this->text($country, 'code')),
                'name' => $this->text($country, 'name'),
                'currency_code' => strtoupper($this->text($country, 'currency_code')),
                'timezone' => $this->text($country, 'timezone'),
            ];
            if (preg_match('/^[A-Z]{2}$/', $countryData['code']) !== 1
                || $countryData['name'] === '' || mb_strlen($countryData['name']) > 255
                || preg_match('/^[A-Z]{3}$/', $countryData['currency_code']) !== 1
                || ! in_array($countryData['timezone'], timezone_identifiers_list(), true)) {
                throw new PlatformAdminBootstrapException('The home country details are invalid.');
            }

            $companyData = [
                'code' => strtoupper($this->text($company, 'code')),
                'name' => $this->text($company, 'name'),
                'city' => $this->text($company, 'city'),
            ];
            if (preg_match('/^[A-Z0-9][A-Z0-9_-]{1,39}$/', $companyData['code']) !== 1
                || $companyData['name'] === '' || mb_strlen($companyData['name']) > 255
                || $companyData['city'] === '' || mb_strlen($companyData['city']) > 255) {
                throw new PlatformAdminBootstrapException('The operator company details are invalid.');
            }
        }

        $id = $user['id'] ?? null;
        $name = $this->text($user, 'name');
        $username = $this->text($user, 'username');
        $password = is_string($user['password'] ?? null) ? $user['password'] : '';
        if (! is_string($id) || ! Str::isUuid($id)
            || $name === '' || mb_strlen($name) > 255
            || preg_match('/^[A-Za-z0-9._@-]{3,255}$/', $username) !== 1) {
            throw new PlatformAdminBootstrapException('The platform administrator identity does not meet the required format.');
        }
        // Mirrors the default Back Office credential policy so the first
        // administrator is not created with a password the policy rejects.
        if (strlen($password) < 12 || strlen($password) > 128
            || preg_match('/[A-Z]/', $password) !== 1
            || preg_match('/[a-z]/', $password) !== 1
            || preg_match('/[0-9]/', $password) !== 1
            || preg_match('/[^A-Za-z0-9]/', $password) !== 1
            || hash_equals(strtolower($username), strtolower($password))) {
            throw new PlatformAdminBootstrapException(
                'The platform administrator password must be 12-128 characters with upper-case, lower-case, number and symbol characters.'
            );
        }

        return [
            'country' => $countryData,
            'company' => $companyData,
            'user' => [
                'id' => strtolower($id),
                'name' => $name,
                'username' => $username,
                'password' => $password,
            ],
        ];
    }

    private function text(array $data, string $key): string
    {
        return is_string($data[$key] ?? null) ? trim($data[$key]) : '';
    }

    /** @param list<string> $allowed */
    private function assertOnlyKeys(array $data, array $allowed): void
    {
        if (array_diff(array_keys($data), $allowed) !== []) {
            throw new PlatformAdminBootstrapException('The credentials file contains unsupported fields.');
        }
    }
}
