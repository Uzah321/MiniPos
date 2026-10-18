<?php

namespace App\Services\Provisioning;

use App\Services\Inventory\InventoryWarehouseProvisioner;
use App\Services\License\LicenseService;
use App\Services\License\LicenseSignatureService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use Modules\Company\Models\Company;
use Modules\Company\Models\CompanyBranch;
use Modules\Company\Models\License;
use RuntimeException;

class StandaloneProvisioningPackageService
{
    public const FORMAT = 'salexa-standalone-provisioning';

    public const VERSION = 1;

    public function __construct(
        private readonly LicenseSignatureService $signatures,
        private readonly LicenseService $licenses,
        private readonly InventoryWarehouseProvisioner $warehouses,
    ) {}

    /**
     * Build a portable, signed company bootstrap package on the Central issuer.
     *
     * @return array<string, mixed>
     */
    public function generate(Company $company): array
    {
        if ((string) config('salexa_sync.deployment_mode') !== 'cloud'
            || (bool) config('salexa_sync.local_hub', false)) {
            throw new RuntimeException('Standalone provisioning packages can only be issued by Central.');
        }
        if (! $this->signatures->canSign()) {
            throw new RuntimeException('Central is not configured with an active licence signing key.');
        }
        if (strtolower(trim((string) $company->status)) !== 'active') {
            throw new InvalidArgumentException('Only an active company can be provisioned.');
        }

        $companyCode = strtoupper(trim((string) $company->company_code));
        if ($companyCode === '') {
            throw new InvalidArgumentException('The company must have a company code before it can be provisioned.');
        }

        /** @var License|null $license */
        $license = $company->licenses()
            ->where('status', 'active')
            ->latest('created_at')
            ->first();
        if (! $license || $this->licenses->validationCode($license) !== 'LICENSE_VALID') {
            throw new InvalidArgumentException('The company must have a valid active licence before it can be provisioned.');
        }

        $branches = $company->branches()
            ->orderBy('id')
            ->get();
        if ($branches->isEmpty()) {
            throw new InvalidArgumentException('The company must have at least one branch before it can be provisioned.');
        }

        $issuedAt = CarbonImmutable::now('UTC')->startOfSecond();
        $expiresAt = $issuedAt->addHours(max(
            1,
            (int) config('salexa_provisioning.standalone_package_ttl_hours', 168)
        ));
        $licenseToken = $this->licenses->generateOfflineLicenseToken($company, [], $license);

        $payload = [
            'branches' => $branches->map(fn (CompanyBranch $branch): array => [
                'branch_address' => $branch->branch_address,
                'branch_city' => $branch->branch_city,
                'branch_code' => $branch->branch_code,
                'branch_country' => $branch->branch_country,
                'branch_name' => (string) $branch->branch_name,
                'company_id' => (string) $company->id,
                'id' => (string) $branch->id,
                'is_main_branch' => (bool) $branch->is_main_branch,
                'level' => $branch->level,
                'status' => (string) ($branch->status ?: 'active'),
            ])->values()->all(),
            'company' => [
                'city' => (string) $company->city,
                'company_code' => $companyCode,
                'company_name' => (string) $company->company_name,
                'country' => (string) $company->country,
                'country_id' => $company->country_id ? (string) $company->country_id : null,
                'id' => (string) $company->id,
                'license_support_phone' => $company->license_support_phone,
                'location_address' => (string) $company->location_address,
                'status' => 'active',
            ],
            'enterprise_hierarchy' => $this->enterpriseHierarchyPayload($company),
            'expires_at' => $expiresAt->toIso8601String(),
            'issued_at' => $issuedAt->toIso8601String(),
            'license_token' => $licenseToken,
            'package_id' => (string) Str::uuid(),
            'purpose' => self::FORMAT,
            'version' => self::VERSION,
        ];
        $encodedPayload = base64_encode($this->canonicalJson($payload));
        $signed = $this->signatures->sign($encodedPayload);

        return [
            'algorithm' => $signed['algorithm'],
            'format' => self::FORMAT,
            'key_id' => $signed['key_id'],
            'payload' => $encodedPayload,
            'signature' => $signed['signature'],
            'version' => self::VERSION,
        ];
    }

    /** @param array<string, mixed> $package */
    public function serialize(array $package): string
    {
        return json_encode(
            $this->canonicalize($package),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ).PHP_EOL;
    }

    /** @return array<string, mixed> */
    public function deserialize(string $contents): array
    {
        if (strlen($contents) > 5 * 1024 * 1024) {
            throw new InvalidArgumentException('The standalone provisioning package is too large.');
        }

        try {
            $package = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The standalone provisioning package is not valid JSON.', 0, $exception);
        }
        if (! is_array($package)) {
            throw new InvalidArgumentException('The standalone provisioning package is invalid.');
        }

        return $package;
    }

    /**
     * Verify and import a package into a local-only installation.
     *
     * @param  array<string, mixed>  $package
     * @return array{company_id: string, company_code: string, branch_count: int, license_id: string, already_imported: bool}
     */
    public function import(array $package): array
    {
        $deploymentMode = (string) config('salexa_sync.deployment_mode');
        $localHub = $deploymentMode === 'cloud' && (bool) config('salexa_sync.local_hub', false);
        if ($deploymentMode !== 'standalone' && ! $localHub) {
            throw new RuntimeException('Provisioning packages can only be imported into standalone or local Back Office installations.');
        }
        if ($localHub && ((string) config('license.signing.mode') !== 'verify_only'
            || trim((string) config('license.signing.private_key')) !== '')) {
            throw new RuntimeException('A local Back Office must use verify-only licensing and must not contain an issuer private key.');
        }

        $verified = $this->verify($package);
        $payload = $verified['payload'];
        $licensePayload = $this->licenses->validateOfflineLicenseToken((string) $payload['license_token']);
        $companyId = (string) $payload['company']['id'];
        if (! hash_equals($companyId, (string) ($licensePayload['company_id'] ?? ''))) {
            throw new InvalidArgumentException('The licence entitlement belongs to a different company.');
        }

        $this->assertImportSchemaReady($payload);

        return DB::transaction(function () use ($package, $verified, $payload, $licensePayload, $companyId): array {
            $packageId = (string) $payload['package_id'];
            // Even an idempotent retry must not report a healthy provisioned
            // installation after another company has appeared in this DB.
            if (Company::withTrashed()->whereKeyNot($companyId)->exists()) {
                throw new InvalidArgumentException('This standalone database is already assigned to another company.');
            }
            $existingImport = DB::table('standalone_provisioning_imports')
                ->where('package_id', $packageId)
                ->lockForUpdate()
                ->first();
            if ($existingImport) {
                if (! hash_equals((string) $existingImport->payload_hash, $verified['payload_hash'])) {
                    throw new InvalidArgumentException('The provisioning package identifier conflicts with an earlier import.');
                }

                return [
                    'already_imported' => true,
                    'branch_count' => count($payload['branches']),
                    'company_code' => (string) $payload['company']['company_code'],
                    'company_id' => $companyId,
                    'license_id' => (string) $licensePayload['license_id'],
                ];
            }

            $companyCode = (string) $payload['company']['company_code'];
            if (Company::withTrashed()
                ->where('company_code', $companyCode)
                ->whereKeyNot($companyId)
                ->exists()) {
                throw new InvalidArgumentException('The company code is already assigned to another local company.');
            }

            $hierarchy = is_array($payload['enterprise_hierarchy'] ?? null)
                ? $payload['enterprise_hierarchy']
                : [];
            $this->upsertEnterpriseRows('countries', $hierarchy['countries'] ?? [], [
                'id', 'code', 'iso_alpha_2', 'iso_alpha_3', 'name', 'currency_code',
                'timezone', 'locale', 'image_path', 'image_url', 'metadata', 'is_active',
            ]);

            /** @var Company|null $company */
            $company = Company::withTrashed()->find($companyId);
            if ($company?->trashed()) {
                throw new InvalidArgumentException('The centrally managed company is deleted in this local database.');
            }
            if (! $company) {
                $company = new Company;
                $company->setAttribute($company->getKeyName(), $companyId);
            }
            $companyValues = [
                'city' => $payload['company']['city'],
                'company_code' => $companyCode,
                'company_name' => $payload['company']['company_name'],
                'country' => $payload['company']['country'],
                'domain' => $company->exists ? $company->domain : null,
                'host_ip' => $company->exists ? $company->host_ip : null,
                'license_support_phone' => $payload['company']['license_support_phone'] ?? null,
                'location_address' => $payload['company']['location_address'],
                'status' => 'active',
            ];
            if (Schema::hasColumn('companies', 'country_id')) {
                $companyValues['country_id'] = $payload['company']['country_id'] ?? null;
            }
            $company->forceFill($companyValues)->save();

            $this->upsertEnterpriseRows('brands', $hierarchy['brands'] ?? [], [
                'id', 'company_id', 'country_id', 'code', 'name', 'description',
                'logo_path', 'logo_url', 'status', 'metadata',
            ]);
            $this->upsertEnterpriseRows('complexes', $hierarchy['complexes'] ?? [], [
                'id', 'company_id', 'country_id', 'code', 'name', 'address',
                'city', 'timezone', 'latitude', 'longitude', 'status', 'metadata',
            ]);

            $incomingBranchIds = collect($payload['branches'])
                ->pluck('id')
                ->map(fn (mixed $id): string => (string) $id)
                ->all();
            if (CompanyBranch::withTrashed()
                ->where('company_id', $companyId)
                ->whereNotIn('id', $incomingBranchIds)
                ->exists()) {
                throw new InvalidArgumentException(
                    'This local company has branches that are not present in the Central provisioning package.'
                );
            }

            $importedBranches = [];
            foreach ($payload['branches'] as $branchData) {
                /** @var CompanyBranch|null $branch */
                $branch = CompanyBranch::withTrashed()->find((string) $branchData['id']);
                if ($branch && (string) $branch->company_id !== $companyId) {
                    throw new InvalidArgumentException('A Central branch identifier belongs to another local company.');
                }
                if ($branch?->trashed()) {
                    throw new InvalidArgumentException('A centrally managed branch is deleted in this local database.');
                }
                if (! $branch) {
                    $branch = new CompanyBranch;
                    $branch->setAttribute($branch->getKeyName(), (string) $branchData['id']);
                }
                $branch->forceFill([
                    'branch_address' => $branchData['branch_address'] ?? null,
                    'branch_city' => $branchData['branch_city'] ?? null,
                    'branch_code' => $branchData['branch_code'] ?? null,
                    'branch_country' => $branchData['branch_country'] ?? null,
                    'branch_name' => $branchData['branch_name'],
                    'company_id' => $companyId,
                    'is_main_branch' => $branchData['is_main_branch'],
                    'level' => $branchData['level'] ?? null,
                    'status' => $branchData['status'],
                ]);
                if (($hierarchy['shops'] ?? []) !== []) {
                    // The signed hierarchy rows below are authoritative. Avoid
                    // the legacy branch-created compatibility hook generating
                    // a placeholder brand/complex/shop during this import.
                    $branch->saveQuietly();
                } else {
                    $branch->save();
                }
                $importedBranches[] = $branch;
            }

            $this->upsertEnterpriseRows('shops', $hierarchy['shops'] ?? [], [
                'id', 'company_id', 'country_id', 'brand_id', 'complex_id', 'branch_id',
                'code', 'name', 'address', 'city', 'timezone', 'pos_login_mode',
                'configuration_version', 'status', 'metadata',
            ]);
            $this->upsertEnterpriseRows('pos_tills', $hierarchy['tills'] ?? [], [
                'id', 'company_id', 'country_id', 'brand_id', 'complex_id', 'branch_id', 'shop_id', 'till_code', 'till_name',
                'device_label', 'configuration_version', 'is_active',
            ]);

            $license = $this->licenses->renewUsingOfflineToken(
                (string) $payload['license_token'],
                $company
            );

            foreach ($importedBranches as $branch) {
                $this->warehouses->ensureBranchDefaults($branch);
            }

            $now = now();
            DB::table('standalone_provisioning_imports')->insert([
                'company_id' => $companyId,
                'created_at' => $now,
                'imported_at' => $now,
                'package_expires_at' => CarbonImmutable::parse($payload['expires_at'])->utc(),
                'package_id' => $packageId,
                'package_issued_at' => CarbonImmutable::parse($payload['issued_at'])->utc(),
                'payload_hash' => $verified['payload_hash'],
                'signature_algorithm' => $package['algorithm'],
                'signature_key_id' => $package['key_id'],
                'updated_at' => $now,
            ]);

            return [
                'already_imported' => false,
                'branch_count' => count($payload['branches']),
                'company_code' => $companyCode,
                'company_id' => $companyId,
                'license_id' => (string) $license->id,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $package
     * @return array{payload: array<string, mixed>, payload_hash: string}
     */
    public function verify(array $package): array
    {
        foreach (['algorithm', 'format', 'key_id', 'payload', 'signature'] as $key) {
            if (! isset($package[$key]) || ! is_string($package[$key]) || trim($package[$key]) === '') {
                throw new InvalidArgumentException('The standalone provisioning package envelope is incomplete.');
            }
        }
        if ($package['format'] !== self::FORMAT || (int) ($package['version'] ?? 0) !== self::VERSION) {
            throw new InvalidArgumentException('The standalone provisioning package format is unsupported.');
        }

        $encodedPayload = $package['payload'];
        $payloadJson = base64_decode($encodedPayload, true);
        if ($payloadJson === false || ! hash_equals(base64_encode($payloadJson), $encodedPayload)) {
            throw new InvalidArgumentException('The standalone provisioning package payload encoding is invalid.');
        }
        if (! $this->signatures->verify(
            $encodedPayload,
            $package['signature'],
            $package['algorithm'],
            $package['key_id']
        )) {
            throw new InvalidArgumentException('The standalone provisioning package signature is invalid.');
        }

        try {
            $payload = json_decode($payloadJson, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The standalone provisioning package payload is invalid.', 0, $exception);
        }
        if (! is_array($payload)
            || ! hash_equals($this->canonicalJson($payload), $payloadJson)
            || ($payload['purpose'] ?? null) !== self::FORMAT
            || (int) ($payload['version'] ?? 0) !== self::VERSION) {
            throw new InvalidArgumentException('The standalone provisioning package payload is invalid.');
        }

        $this->validatePayload($payload);

        return [
            'payload' => $payload,
            'payload_hash' => hash('sha256', $encodedPayload),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function validatePayload(array $payload): void
    {
        if (! is_string($payload['package_id'] ?? null) || ! Str::isUuid($payload['package_id'])) {
            throw new InvalidArgumentException('The standalone provisioning package identifier is invalid.');
        }
        foreach (['issued_at', 'expires_at'] as $field) {
            if (! is_string($payload[$field] ?? null) || trim($payload[$field]) === '') {
                throw new InvalidArgumentException('The standalone provisioning package validity window is invalid.');
            }
        }

        try {
            $issuedAt = CarbonImmutable::parse($payload['issued_at'])->utc();
            $expiresAt = CarbonImmutable::parse($payload['expires_at'])->utc();
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('The standalone provisioning package validity window is invalid.', 0, $exception);
        }
        $now = CarbonImmutable::now('UTC');
        if ($expiresAt->lte($issuedAt) || $expiresAt->lte($now)) {
            throw new InvalidArgumentException('The standalone provisioning package has expired.');
        }
        if ($issuedAt->gt($now->addMinutes(5))) {
            throw new InvalidArgumentException('The standalone provisioning package was issued in the future.');
        }

        if (! is_array($payload['company'] ?? null)) {
            throw new InvalidArgumentException('The standalone provisioning package company is invalid.');
        }
        $company = $payload['company'];
        if (! is_string($company['id'] ?? null) || ! Str::isUuid($company['id'])) {
            throw new InvalidArgumentException('The standalone provisioning package company identifier is invalid.');
        }
        foreach ([
            'company_code' => 40,
            'company_name' => 255,
            'location_address' => 65535,
            'city' => 255,
            'country' => 255,
        ] as $field => $maxLength) {
            $this->assertRequiredString($company, $field, $maxLength, 'company');
        }
        if (strtoupper(trim((string) $company['company_code'])) !== $company['company_code']) {
            throw new InvalidArgumentException('The standalone provisioning package company code is invalid.');
        }
        if (! preg_match('/^[A-Z0-9][A-Z0-9_-]*$/', $company['company_code'])) {
            throw new InvalidArgumentException('The standalone provisioning package company code is invalid.');
        }
        if (($company['status'] ?? null) !== 'active') {
            throw new InvalidArgumentException('The standalone provisioning package company is not active.');
        }
        $this->assertNullableString($company, 'license_support_phone', 50, 'company');
        if (($company['country_id'] ?? null) !== null
            && (! is_string($company['country_id']) || ! Str::isUuid($company['country_id']))) {
            throw new InvalidArgumentException('The standalone provisioning package country identifier is invalid.');
        }

        if (! is_array($payload['branches'] ?? null) || $payload['branches'] === []) {
            throw new InvalidArgumentException('The standalone provisioning package must contain at least one branch.');
        }
        $seenBranches = [];
        foreach ($payload['branches'] as $branch) {
            if (! is_array($branch)
                || ! is_string($branch['id'] ?? null)
                || ! Str::isUuid($branch['id'])
                || isset($seenBranches[$branch['id']])) {
                throw new InvalidArgumentException('The standalone provisioning package contains an invalid branch identifier.');
            }
            $seenBranches[$branch['id']] = true;
            if (! is_string($branch['company_id'] ?? null)
                || ! hash_equals((string) $company['id'], $branch['company_id'])) {
                throw new InvalidArgumentException('The standalone provisioning package contains a branch for another company.');
            }
            $this->assertRequiredString($branch, 'branch_name', 255, 'branch');
            foreach (['branch_code', 'branch_address', 'branch_city', 'branch_country', 'level'] as $field) {
                $this->assertNullableString($branch, $field, 255, 'branch');
            }
            if (! is_bool($branch['is_main_branch'] ?? null)) {
                throw new InvalidArgumentException('The standalone provisioning package branch main flag is invalid.');
            }
            $this->assertRequiredString($branch, 'status', 32, 'branch');
        }

        $hierarchy = $payload['enterprise_hierarchy'] ?? [];
        if (! is_array($hierarchy)) {
            throw new InvalidArgumentException('The standalone provisioning package hierarchy is invalid.');
        }
        foreach (['countries', 'brands', 'complexes', 'shops', 'tills'] as $collection) {
            $rows = $hierarchy[$collection] ?? [];
            if (! is_array($rows) || ! array_is_list($rows)) {
                throw new InvalidArgumentException("The standalone provisioning package {$collection} hierarchy is invalid.");
            }
            $seen = [];
            foreach ($rows as $row) {
                if (! is_array($row)
                    || ! is_string($row['id'] ?? null)
                    || ! Str::isUuid($row['id'])
                    || isset($seen[$row['id']])) {
                    throw new InvalidArgumentException("The standalone provisioning package {$collection} hierarchy is invalid.");
                }
                $seen[$row['id']] = true;
                if ($collection !== 'countries'
                    && (! is_string($row['company_id'] ?? null)
                        || ! hash_equals((string) $company['id'], $row['company_id']))) {
                    throw new InvalidArgumentException("The standalone provisioning package {$collection} hierarchy belongs to another company.");
                }
                if (in_array($collection, ['shops', 'tills'], true)
                    && (! is_string($row['branch_id'] ?? null) || ! isset($seenBranches[$row['branch_id']]))) {
                    throw new InvalidArgumentException("The standalone provisioning package {$collection} hierarchy names an unknown branch.");
                }
            }
        }

        if (! is_string($payload['license_token'] ?? null)
            || trim($payload['license_token']) === ''
            || strlen($payload['license_token']) > 1024 * 1024) {
            throw new InvalidArgumentException('The standalone provisioning package licence entitlement is invalid.');
        }
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function enterpriseHierarchyPayload(Company $company): array
    {
        $empty = [
            'countries' => [],
            'brands' => [],
            'complexes' => [],
            'shops' => [],
            'tills' => [],
        ];
        foreach (array_keys($empty) as $table) {
            $physicalTable = $table === 'tills' ? 'pos_tills' : $table;
            if (! Schema::hasTable($physicalTable)) {
                return [];
            }
        }

        $rows = static fn ($query, array $columns): array => $query
            ->orderBy('id')
            ->get($columns)
            ->map(static fn (object $row): array => (array) $row)
            ->values()
            ->all();

        return [
            'countries' => $company->country_id
                ? $rows(DB::table('countries')->where('id', $company->country_id)->whereNull('deleted_at'), [
                    'id', 'code', 'iso_alpha_2', 'iso_alpha_3', 'name', 'currency_code',
                    'timezone', 'locale', 'image_path', 'image_url', 'metadata', 'is_active',
                ])
                : [],
            'brands' => $rows(DB::table('brands')->where('company_id', $company->id)->whereNull('deleted_at'), [
                'id', 'company_id', 'country_id', 'code', 'name', 'description',
                'logo_path', 'logo_url', 'status', 'metadata',
            ]),
            'complexes' => $rows(DB::table('complexes')->where('company_id', $company->id)->whereNull('deleted_at'), [
                'id', 'company_id', 'country_id', 'code', 'name', 'address',
                'city', 'timezone', 'latitude', 'longitude', 'status', 'metadata',
            ]),
            'shops' => $rows(DB::table('shops')->where('company_id', $company->id)->whereNull('deleted_at'), [
                'id', 'company_id', 'country_id', 'brand_id', 'complex_id', 'branch_id',
                'code', 'name', 'address', 'city', 'timezone', 'pos_login_mode',
                'configuration_version', 'status', 'metadata',
            ]),
            'tills' => $rows(DB::table('pos_tills')->where('company_id', $company->id)->whereNull('deleted_at'), [
                'id', 'company_id', 'country_id', 'brand_id', 'complex_id', 'branch_id', 'shop_id', 'till_code', 'till_name',
                'device_label', 'configuration_version', 'is_active',
            ]),
        ];
    }

    /**
     * @param  array<int, mixed>  $rows
     * @param  array<int, string>  $allowed
     */
    private function upsertEnterpriseRows(string $table, array $rows, array $allowed): void
    {
        if ($rows === []) {
            return;
        }

        $now = now();
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['id'] ?? null) || ! Str::isUuid($row['id'])) {
                throw new InvalidArgumentException("The provisioning package contains an invalid {$table} row.");
            }
            $row = array_intersect_key($row, array_flip($allowed));
            foreach ($row as $column => $value) {
                if (is_array($value) || is_object($value)) {
                    $row[$column] = json_encode($value, JSON_THROW_ON_ERROR);
                }
            }
            $row['updated_at'] = $now;
            if (! DB::table($table)->where('id', $row['id'])->exists()) {
                $row['created_at'] = $now;
            }
            DB::table($table)->upsert(
                [$row],
                ['id'],
                array_values(array_diff(array_keys($row), ['id', 'created_at']))
            );
        }
    }

    /** @param array<string, mixed> $payload */
    private function assertImportSchemaReady(array $payload): void
    {
        foreach ([
            'companies',
            'company_branches',
            'licenses',
            'standalone_provisioning_imports',
            'inventory_warehouses',
            'inventory_locations',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Database migration is incomplete: missing {$table}.");
            }
        }
        if (($payload['enterprise_hierarchy'] ?? []) !== []) {
            foreach (['countries', 'brands', 'complexes', 'shops', 'pos_tills'] as $table) {
                if (! Schema::hasTable($table)) {
                    throw new RuntimeException("Database migration is incomplete: missing {$table}.");
                }
            }
        }
        foreach ([
            'entitlement_payload_v2',
            'entitlement_signature_v2',
            'signature_algorithm',
            'signature_key_id',
            'entitlement_version',
            'entitlement_revision',
            'signed_at',
        ] as $column) {
            if (! Schema::hasColumn('licenses', $column)) {
                throw new RuntimeException("Database migration is incomplete: missing licenses.{$column}.");
            }
        }
    }

    /** @param array<string, mixed> $row */
    private function assertRequiredString(
        array $row,
        string $field,
        int $maxLength,
        string $scope
    ): void {
        if (! is_string($row[$field] ?? null)
            || trim($row[$field]) === ''
            || mb_strlen($row[$field]) > $maxLength) {
            throw new InvalidArgumentException(
                "The standalone provisioning package {$scope} {$field} is invalid."
            );
        }
    }

    /** @param array<string, mixed> $row */
    private function assertNullableString(
        array $row,
        string $field,
        int $maxLength,
        string $scope
    ): void {
        if (! array_key_exists($field, $row) || $row[$field] === null) {
            return;
        }
        if (! is_string($row[$field]) || mb_strlen($row[$field]) > $maxLength) {
            throw new InvalidArgumentException(
                "The standalone provisioning package {$scope} {$field} is invalid."
            );
        }
    }

    /** @param array<string, mixed> $payload */
    private function canonicalJson(array $payload): string
    {
        return json_encode(
            $this->canonicalize($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
