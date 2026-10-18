<?php

namespace Tests\Feature;

use App\Services\License\LicenseService;
use App\Services\License\LicenseSignatureService;
use App\Services\Provisioning\StandaloneProvisioningPackageService;
use App\Http\Middleware\ResolveCompanyFromDomain;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Modules\Company\Http\Controllers\CompanyController;
use Modules\Company\Models\Company;
use Modules\Company\Models\CompanyBranch;
use Modules\Company\Models\License;
use PDO;
use Tests\TestCase;

class StandaloneProvisioningPackageTest extends TestCase
{
    private const COMPANY_ID = '019fa100-0000-7000-8000-000000000001';

    private const BRANCH_ID = '019fa100-0000-7000-8000-000000000002';

    private const LICENSE_ID = '019fa100-0000-7000-8000-000000000003';

    private bool $databaseAvailable = false;

    private string $privateKey;

    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The PDO SQLite driver is required for this feature test.');
        }
        $this->databaseAvailable = true;
        Carbon::setTestNow('2026-10-08 10:00:00');

        $this->dropTables();
        $this->createTables();
        [$this->privateKey, $this->publicKey] = $this->generateOpenSslKeyPair();
        $this->configureIssuer();
        $this->seedCentralCompany();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if ($this->databaseAvailable) {
            $this->dropTables();
        }
        parent::tearDown();
    }

    public function test_central_package_bootstraps_exact_company_and_branch_ids_without_exposing_secrets(): void
    {
        $packages = app(StandaloneProvisioningPackageService::class);
        $company = Company::query()->findOrFail(self::COMPANY_ID);
        $package = $packages->generate($company);
        $serialized = $packages->serialize($package);
        $verified = $packages->verify($package);
        $payload = $verified['payload'];
        $licenseToken = (string) $payload['license_token'];

        $this->assertSame(StandaloneProvisioningPackageService::FORMAT, $package['format']);
        $this->assertSame(self::COMPANY_ID, $payload['company']['id']);
        $this->assertSame(self::BRANCH_ID, $payload['branches'][0]['id']);
        $this->assertSame('v2.', substr($licenseToken, 0, 3));
        foreach ([
            'LICENSE_SIGNATURE_PRIVATE_KEY',
            'APP_KEY',
            'password',
            'credential_secret',
            'entitlement_payload_v2',
            'entitlement_signature_v2',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
        $this->assertStringNotContainsString($this->privateKey, $serialized);

        $this->clearCentralRows();
        $this->configureVerifier();

        $path = tempnam(sys_get_temp_dir(), 'salexa-provisioning-');
        $this->assertIsString($path);
        file_put_contents($path, $serialized);
        try {
            $this->assertSame(0, Artisan::call('salexa:standalone-import', ['package' => $path]));
            $output = Artisan::output();
            $this->assertStringNotContainsString($licenseToken, $output);
            $this->assertStringNotContainsString('license_token', $output);
        } finally {
            @unlink($path);
        }

        $this->assertDatabaseHas('companies', [
            'id' => self::COMPANY_ID,
            'company_code' => 'CMP-DEMO',
            'company_name' => 'Demo Company',
        ]);
        $this->assertDatabaseHas('company_branches', [
            'id' => self::BRANCH_ID,
            'company_id' => self::COMPANY_ID,
            'branch_code' => 'MAIN',
        ]);
        $this->assertDatabaseHas('licenses', [
            'id' => self::LICENSE_ID,
            'company_id' => self::COMPANY_ID,
            'status' => 'active',
            'entitlement_version' => 2,
        ]);
        $this->assertDatabaseCount('inventory_warehouses', 1);
        $this->assertDatabaseCount('inventory_locations', 3);
        $this->assertDatabaseHas('standalone_provisioning_imports', [
            'package_id' => $payload['package_id'],
            'company_id' => self::COMPANY_ID,
            'signature_key_id' => 'test-primary',
        ]);

        $license = License::query()->findOrFail(self::LICENSE_ID);
        $this->assertSame('LICENSE_VALID', app(LicenseService::class)->validationCode($license));

        $repeat = $packages->import($package);
        $this->assertTrue($repeat['already_imported']);
        $this->assertDatabaseCount('standalone_provisioning_imports', 1);
    }

    public function test_central_package_bootstraps_a_verify_only_single_company_local_sync_hub(): void
    {
        $packages = app(StandaloneProvisioningPackageService::class);
        $package = $packages->generate(Company::query()->findOrFail(self::COMPANY_ID));
        $this->clearCentralRows();
        $this->configureVerifier();
        config()->set('salexa_sync.deployment_mode', 'cloud');
        config()->set('salexa_sync.local_hub', true);

        $unprovisioned = app(ResolveCompanyFromDomain::class)->handle(
            $this->localHubLoginRequest(),
            fn () => response()->json(['allowed' => true])
        );
        $this->assertSame(409, $unprovisioned->getStatusCode());
        $this->assertSame('LOCAL_HUB_COMPANY_UNAVAILABLE', $unprovisioned->getData(true)['code']);

        $result = $packages->import($package);

        $this->assertSame(self::COMPANY_ID, $result['company_id']);
        $this->assertSame(self::BRANCH_ID, CompanyBranch::query()->sole()->id);
        $this->assertSame('LICENSE_VALID', app(LicenseService::class)->validationCode(
            License::query()->findOrFail(self::LICENSE_ID)
        ));
        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('standalone_provisioning_imports', 1);
        $this->assertFalse(app(LicenseSignatureService::class)->canSign());

        $login = $this->localHubLoginRequest();
        $resolved = app(ResolveCompanyFromDomain::class)->handle(
            $login,
            fn (): \Illuminate\Http\JsonResponse => response()->json([
                'company_id' => (string) app('company')->id,
            ])
        );
        $this->assertSame(200, $resolved->getStatusCode());
        $this->assertSame(self::COMPANY_ID, $resolved->getData(true)['company_id']);

        $frontOffice = app(ResolveCompanyFromDomain::class)->handle(
            $this->localHubLoginRequest('front-office'),
            fn () => response()->json(['allowed' => true])
        );
        $this->assertSame(422, $frontOffice->getStatusCode());
        $this->assertSame('COMPANY_CODE_REQUIRED', $frontOffice->getData(true)['code']);

        $otherCompany = new Company;
        $otherCompany->setAttribute('id', '019fa100-0000-7000-8000-000000000099');
        $otherCompany->forceFill([
            'company_code' => 'OTHER',
            'company_name' => 'Other Company',
            'location_address' => 'Other address',
            'city' => 'Harare',
            'country' => 'Zimbabwe',
            'status' => 'active',
        ])->save();

        $ambiguous = app(ResolveCompanyFromDomain::class)->handle(
            $this->localHubLoginRequest(),
            fn () => response()->json(['allowed' => true])
        );
        $this->assertSame(409, $ambiguous->getStatusCode());
        $this->assertSame('LOCAL_HUB_COMPANY_UNAVAILABLE', $ambiguous->getData(true)['code']);

        $explicitLogin = $this->localHubLoginRequest();
        $explicitLogin->headers->set('X-Company-Code', 'CMP-DEMO');
        $explicitDenied = app(ResolveCompanyFromDomain::class)->handle(
            $explicitLogin,
            fn () => response()->json(['allowed' => true])
        );
        $this->assertSame(409, $explicitDenied->getStatusCode());
        $this->assertSame('LOCAL_HUB_COMPANY_UNAVAILABLE', $explicitDenied->getData(true)['code']);

        $edgePairing = app(\App\Http\Controllers\SyncProtocolController::class)->pair(
            Request::create('/api/pos/sync/pair', 'POST', [
                'company_code' => 'CMP-DEMO',
                'pairing_code' => 'UNCONSUMED.CODE',
                'target_platform' => 'edge',
            ])
        );
        $this->assertSame(409, $edgePairing->getStatusCode());
        $this->assertSame('LOCAL_HUB_COMPANY_UNAVAILABLE', $edgePairing->getData(true)['code']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('another company');
        $packages->import($package);
    }

    public function test_local_sync_hub_rejects_manually_populated_company_without_signed_import(): void
    {
        $this->configureVerifier();
        config()->set('salexa_sync.deployment_mode', 'cloud');
        config()->set('salexa_sync.local_hub', true);

        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('standalone_provisioning_imports', 0);

        $request = $this->localHubLoginRequest();
        $request->headers->set('X-Company-Code', 'CMP-DEMO');
        $response = app(ResolveCompanyFromDomain::class)->handle(
            $request,
            fn () => response()->json(['allowed' => true])
        );

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('LOCAL_HUB_COMPANY_UNAVAILABLE', $response->getData(true)['code']);
    }

    public function test_local_sync_hub_refuses_import_if_issuer_material_is_configured(): void
    {
        $packages = app(StandaloneProvisioningPackageService::class);
        $package = $packages->generate(Company::query()->findOrFail(self::COMPANY_ID));
        $this->clearCentralRows();
        $this->configureVerifier();
        config()->set('salexa_sync.deployment_mode', 'cloud');
        config()->set('salexa_sync.local_hub', true);
        config()->set('license.signing.private_key', $this->privateKey);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must not contain an issuer private key');
        $packages->import($package);
    }

    public function test_local_sync_hub_cannot_create_change_or_delete_centrally_managed_branches(): void
    {
        $packages = app(StandaloneProvisioningPackageService::class);
        $package = $packages->generate(Company::query()->findOrFail(self::COMPANY_ID));
        $this->clearCentralRows();
        $this->configureVerifier();
        config()->set('salexa_sync.deployment_mode', 'cloud');
        config()->set('salexa_sync.local_hub', true);
        $packages->import($package);

        $company = Company::query()->findOrFail(self::COMPANY_ID);
        $branch = CompanyBranch::query()->findOrFail(self::BRANCH_ID);
        app()->instance('company', $company);
        $controller = app(CompanyController::class);

        $responses = [
            $controller->storeBranchForResolvedCompany(Request::create('/api/pos/branches', 'POST', [
                'branch_name' => 'Unapproved Branch',
            ])),
            $controller->storeBranch(Request::create('/api/pos/companies/x/branches', 'POST', [
                'branch_name' => 'Unapproved Branch',
            ]), $company),
            $controller->updateBranch(Request::create('/api/pos/branches/x', 'PUT', [
                'branch_name' => 'Changed Locally',
            ]), $branch),
            $controller->destroyBranch($branch),
        ];

        foreach ($responses as $response) {
            $this->assertSame(409, $response->getStatusCode());
            $this->assertSame('LOCAL_HUB_BRANCH_CENTRAL_MANAGED', $response->getData(true)['code']);
        }
        $this->assertDatabaseCount('company_branches', 1);
        $this->assertSame('Main Branch', $branch->fresh()->branch_name);
    }

    public function test_local_sync_hub_adds_new_central_branch_with_the_original_central_id(): void
    {
        $packages = app(StandaloneProvisioningPackageService::class);
        $company = Company::query()->findOrFail(self::COMPANY_ID);
        $initial = $packages->generate($company);
        $secondId = '019fa100-0000-7000-8000-000000000004';
        $newBranch = new CompanyBranch;
        $newBranch->setAttribute('id', $secondId);
        $newBranch->forceFill([
            'company_id' => self::COMPANY_ID,
            'branch_name' => 'Second Branch',
            'branch_code' => 'SECOND',
            'is_main_branch' => false,
            'status' => 'active',
        ])->save();
        $expanded = $packages->generate($company);

        $this->clearCentralRows();
        $this->configureVerifier();
        config()->set('salexa_sync.deployment_mode', 'cloud');
        config()->set('salexa_sync.local_hub', true);

        $packages->import($initial);
        $result = $packages->import($expanded);

        $this->assertSame(2, $result['branch_count']);
        $this->assertDatabaseHas('company_branches', [
            'id' => $secondId,
            'company_id' => self::COMPANY_ID,
            'branch_code' => 'SECOND',
        ]);
        $this->assertDatabaseCount('company_branches', 2);
    }

    public function test_tampering_expiry_and_conflicting_local_company_fail_closed(): void
    {
        config()->set('salexa_provisioning.standalone_package_ttl_hours', 1);
        $packages = app(StandaloneProvisioningPackageService::class);
        $package = $packages->generate(Company::query()->findOrFail(self::COMPANY_ID));

        $tampered = $package;
        $payload = json_decode(base64_decode($tampered['payload'], true), true, 32, JSON_THROW_ON_ERROR);
        $payload['company']['company_name'] = 'Attacker Company';
        $tampered['payload'] = base64_encode(json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('signature is invalid');
        $packages->verify($tampered);
    }

    public function test_expired_package_is_rejected_before_database_mutation(): void
    {
        config()->set('salexa_provisioning.standalone_package_ttl_hours', 1);
        $packages = app(StandaloneProvisioningPackageService::class);
        $package = $packages->generate(Company::query()->findOrFail(self::COMPANY_ID));
        $this->clearCentralRows();
        $this->configureVerifier();
        Carbon::setTestNow('2026-10-08 12:00:00');

        try {
            $packages->import($package);
            $this->fail('Expired standalone provisioning package was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('expired', $exception->getMessage());
        }

        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('standalone_provisioning_imports', 0);
    }

    public function test_existing_other_company_blocks_import_without_partial_writes(): void
    {
        $packages = app(StandaloneProvisioningPackageService::class);
        $package = $packages->generate(Company::query()->findOrFail(self::COMPANY_ID));
        $this->clearCentralRows();
        $this->configureVerifier();

        DB::table('companies')->insert([
            'id' => '019fa100-0000-7000-8000-000000000099',
            'company_code' => 'OTHER',
            'company_name' => 'Other Company',
            'location_address' => 'Other address',
            'city' => 'Harare',
            'country' => 'Zimbabwe',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $packages->import($package);
            $this->fail('A package was imported into another company database.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('another company', $exception->getMessage());
        }

        $this->assertDatabaseMissing('companies', ['id' => self::COMPANY_ID]);
        $this->assertDatabaseCount('standalone_provisioning_imports', 0);
    }

    public function test_valid_package_with_another_company_entitlement_is_rejected(): void
    {
        $packages = app(StandaloneProvisioningPackageService::class);
        $package = $packages->generate(Company::query()->findOrFail(self::COMPANY_ID));

        $otherCompany = new Company;
        $otherCompany->setAttribute('id', '019fa100-0000-7000-8000-000000000080');
        $otherCompany->forceFill([
            'company_code' => 'OTHER-TOKEN',
            'company_name' => 'Other Token Company',
            'location_address' => '2 Demo Road',
            'city' => 'Harare',
            'country' => 'Zimbabwe',
            'status' => 'active',
        ])->save();
        $otherLicense = new License;
        $otherLicense->setAttribute('id', '019fa100-0000-7000-8000-000000000081');
        $otherLicense->forceFill([
            'company_id' => $otherCompany->id,
            'license_key' => 'WXYZ-2345-6789-ABCD',
            'license_type' => 'enterprise',
            'max_branches' => 1,
            'max_users' => 5,
            'max_devices' => 1,
            'starts_at' => '2026-01-01',
            'expires_at' => '2027-12-31',
            'status' => 'active',
        ])->save();
        $otherLicense = app(LicenseService::class)->signEntitlement($otherLicense);
        $otherToken = app(LicenseService::class)->generateOfflineLicenseToken(
            $otherCompany,
            [],
            $otherLicense
        );

        $payload = json_decode(base64_decode($package['payload'], true), true, 32, JSON_THROW_ON_ERROR);
        $payload['license_token'] = $otherToken;
        $package['payload'] = base64_encode(json_encode(
            $this->canonicalize($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
        $signed = app(LicenseSignatureService::class)->sign($package['payload']);
        $package['algorithm'] = $signed['algorithm'];
        $package['key_id'] = $signed['key_id'];
        $package['signature'] = $signed['signature'];

        $this->clearCentralRows();
        $this->configureVerifier();

        try {
            $packages->import($package);
            $this->fail('A valid entitlement for another company was imported.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('different company', $exception->getMessage());
        }

        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('standalone_provisioning_imports', 0);
    }

    public function test_export_route_is_protected_by_platform_admin_middleware(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($route) => $route->uri() === 'api/companies/{company}/standalone-provisioning-package'
        );

        $this->assertNotNull($route);
        $this->assertContains('auth:sanctum', $route->middleware());
        $this->assertContains('platform.admin', $route->middleware());
        $this->assertSame(['POST'], $route->methods());
    }

    private function localHubLoginRequest(string $workspace = 'back-office'): Request
    {
        $request = Request::create('/api/pos-auth/login', 'POST', [
            'workspace' => $workspace,
        ]);
        $route = collect(Route::getRoutes())->first(fn ($candidate): bool =>
            $candidate->uri() === 'api/pos-auth/login' && in_array('POST', $candidate->methods(), true));
        $this->assertNotNull($route);
        $route = clone $route;
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);

        return $request;
    }

    private function configureIssuer(): void
    {
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('c', 32)));
        config()->set('salexa_sync.deployment_mode', 'cloud');
        config()->set('salexa_provisioning.standalone_package_ttl_hours', 168);
        config()->set('license.signing', [
            'mode' => 'issuer',
            'algorithm' => LicenseSignatureService::ALGORITHM_OPENSSL_SHA256,
            'active_key_id' => 'test-primary',
            'private_key' => $this->privateKey,
            'private_key_passphrase' => null,
            'public_keys' => ['test-primary' => $this->publicKey],
            'legacy_hmac_verify' => false,
            'legacy_hmac_sign' => false,
            'legacy_hmac_key' => null,
            'legacy_app_key_fallback' => false,
            'allow_unsigned_legacy' => false,
            'auto_upgrade_unsigned' => false,
            'legacy_offline_token_verify' => false,
        ]);
    }

    private function configureVerifier(): void
    {
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('s', 32)));
        config()->set('salexa_sync.deployment_mode', 'standalone');
        config()->set('license.signing.mode', 'verify_only');
        config()->set('license.signing.private_key', null);
        config()->set('license.signing.public_keys', ['test-primary' => $this->publicKey]);
    }

    private function seedCentralCompany(): void
    {
        $company = new Company;
        $company->setAttribute('id', self::COMPANY_ID);
        $company->forceFill([
            'company_code' => 'CMP-DEMO',
            'company_name' => 'Demo Company',
            'location_address' => '1 Demo Road',
            'city' => 'Harare',
            'country' => 'Zimbabwe',
            'domain' => null,
            'host_ip' => null,
            'license_support_phone' => '+263770000000',
            'status' => 'active',
        ])->save();

        $branch = new CompanyBranch;
        $branch->setAttribute('id', self::BRANCH_ID);
        $branch->forceFill([
            'company_id' => self::COMPANY_ID,
            'branch_name' => 'Main Branch',
            'branch_code' => 'MAIN',
            'branch_address' => '1 Demo Road',
            'branch_city' => 'Harare',
            'branch_country' => 'Zimbabwe',
            'level' => 'head-office',
            'is_main_branch' => true,
            'status' => 'active',
        ])->save();

        $license = new License;
        $license->setAttribute('id', self::LICENSE_ID);
        $license->forceFill([
            'company_id' => self::COMPANY_ID,
            'license_key' => 'ABCD-EFGH-JKMP-QRST',
            'license_type' => 'enterprise',
            'max_branches' => 2,
            'max_users' => 20,
            'max_devices' => 5,
            'starts_at' => '2026-01-01',
            'expires_at' => '2027-12-31',
            'status' => 'active',
            'renewal_source' => 'central',
            'activated_at' => now(),
        ])->save();
        app(LicenseService::class)->signEntitlement($license);
    }

    private function clearCentralRows(): void
    {
        DB::table('inventory_locations')->delete();
        DB::table('inventory_warehouses')->delete();
        DB::table('standalone_provisioning_imports')->delete();
        DB::table('licenses')->delete();
        DB::table('company_branches')->delete();
        DB::table('companies')->delete();
    }

    /** @return array{0: string, 1: string} */
    private function generateOpenSslKeyPair(): array
    {
        $key = openssl_pkey_new($this->openSslOptions([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]));
        $this->assertNotFalse($key);
        $this->assertTrue(openssl_pkey_export($key, $private, null, $this->openSslOptions()));
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);

        return [$private, $details['key']];
    }

    private function createTables(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('company_code', 40)->unique();
            $table->string('company_name');
            $table->text('location_address');
            $table->string('city');
            $table->string('country');
            $table->string('domain')->nullable()->unique();
            $table->string('host_ip')->nullable();
            $table->string('license_support_phone', 50)->nullable();
            $table->string('logo')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('company_branches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('branch_name');
            $table->string('branch_code')->nullable();
            $table->string('branch_address')->nullable();
            $table->string('branch_city')->nullable();
            $table->string('branch_country')->nullable();
            $table->string('level')->nullable();
            $table->boolean('is_main_branch')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('licenses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('license_key')->unique();
            $table->string('license_type');
            $table->integer('max_branches');
            $table->integer('max_users');
            $table->integer('max_devices');
            $table->date('starts_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('status');
            $table->string('renewal_source')->nullable();
            $table->longText('license_token')->nullable();
            $table->longText('entitlement_payload')->nullable();
            $table->string('entitlement_signature', 64)->nullable();
            $table->longText('entitlement_payload_v2')->nullable();
            $table->text('entitlement_signature_v2')->nullable();
            $table->string('signature_algorithm', 32)->nullable();
            $table->string('signature_key_id', 64)->nullable();
            $table->unsignedSmallInteger('entitlement_version')->default(1);
            $table->unsignedBigInteger('entitlement_revision')->default(1);
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('inventory_warehouses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->string('code');
            $table->string('name');
            $table->string('warehouse_type');
            $table->boolean('is_active');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('inventory_locations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('warehouse_id');
            $table->string('code');
            $table->string('name');
            $table->string('location_type');
            $table->integer('pick_sequence');
            $table->boolean('is_quarantine');
            $table->boolean('is_active');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('standalone_provisioning_imports', function (Blueprint $table): void {
            $table->uuid('package_id')->primary();
            $table->uuid('company_id');
            $table->char('payload_hash', 64);
            $table->string('signature_algorithm', 32);
            $table->string('signature_key_id', 64);
            $table->timestamp('package_issued_at');
            $table->timestamp('package_expires_at');
            $table->timestamp('imported_at');
            $table->timestamps();
        });
    }

    private function dropTables(): void
    {
        foreach ([
            'standalone_provisioning_imports',
            'inventory_locations',
            'inventory_warehouses',
            'licenses',
            'company_branches',
            'companies',
        ] as $table) {
            Schema::dropIfExists($table);
        }
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
