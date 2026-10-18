<?php

namespace Tests\Feature;

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\RequirePlatformAdministrator;
use App\Services\Inventory\InventoryWarehouseProvisioner;
use App\Services\PublicAssetStorage;
use App\Services\Sync\SyncConfigurationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use Modules\Company\Http\Controllers\CompanyController;
use Modules\Company\Models\Company;
use Modules\Company\Models\CompanyBranch;
use PDO;
use Tests\TestCase;
use Throwable;

class EnterpriseResourceManagementTest extends TestCase
{
    private const COUNTRY = '10000000-0000-0000-0000-000000000001';

    private const COMPANY_A = '10000000-0000-0000-0000-000000000002';

    private const COMPANY_B = '10000000-0000-0000-0000-000000000003';

    private const BRAND_A = '10000000-0000-0000-0000-000000000004';

    private const BRAND_A2 = '10000000-0000-0000-0000-000000000005';

    private const BRAND_B = '10000000-0000-0000-0000-000000000006';

    private const COMPLEX_A = '10000000-0000-0000-0000-000000000007';

    private string $assetRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useAvailableDatabaseDriver();

        $this->withoutMiddleware([Authenticate::class, RequirePlatformAdministrator::class]);
        $this->assetRoot = storage_path('framework/testing/enterprise-resource-assets');
        $this->app->instance(PublicAssetStorage::class, new PublicAssetStorage($this->assetRoot));
        $this->mock(SyncConfigurationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('publishCompanySnapshots')->zeroOrMoreTimes();
            $mock->shouldReceive('markBranchDirty')->zeroOrMoreTimes();
        });
        $this->mock(InventoryWarehouseProvisioner::class, function (MockInterface $mock): void {
            $mock->shouldReceive('ensureBranchDefaults')->zeroOrMoreTimes();
        });

        $this->createTables();
        $this->seedParents();
    }

    protected function tearDown(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (['licenses', 'pos_tills', 'shops', 'company_branches', 'complexes', 'brands', 'companies', 'countries'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();
        File::deleteDirectory($this->assetRoot);

        parent::tearDown();
    }

    public function test_country_company_and_brand_images_are_real_multipart_files_not_text_paths(): void
    {
        $this->postJson('/api/enterprise/countries', [
            'code' => 'ZA',
            'name' => 'South Africa',
            'image_path' => 'C:\\fake\\flag.png',
        ])->assertUnprocessable()->assertJsonValidationErrors('image_path');

        $response = $this->post('/api/enterprise/countries', [
            'code' => 'ZA',
            'name' => 'South Africa',
            'image' => UploadedFile::fake()->image('flag.png', 10, 10),
        ]);
        $response->assertCreated();
        $countryId = (string) $response->json('data.id');
        $firstCountryImage = (string) $response->json('data.image_path');
        $this->assertStringStartsWith(
            'assets/hierarchy/countries/',
            $firstCountryImage
        );
        $this->assertNotNull(app(PublicAssetStorage::class)->existingFile($firstCountryImage));

        $this->post('/api/enterprise/countries/'.$countryId, [
            '_method' => 'PATCH',
            'image' => UploadedFile::fake()->image('replacement.webp', 20, 20),
        ])->assertOk();
        $replacementCountryImage = (string) DB::table('countries')->where('id', $countryId)->value('image_path');
        $this->assertNotSame($firstCountryImage, $replacementCountryImage);
        $this->assertNull(app(PublicAssetStorage::class)->existingFile($firstCountryImage));
        $this->assertNotNull(app(PublicAssetStorage::class)->existingFile($replacementCountryImage));

        $this->withHeader('Accept', 'application/json')->post('/api/enterprise/countries', [
            'code' => 'BW',
            'name' => 'Botswana',
            'image' => UploadedFile::fake()->createWithContent('not-an-image.png', 'not an image'),
        ])->assertUnprocessable()->assertJsonValidationErrors('image');

        $company = $this->post('/api/enterprise/companies', [
            'country_id' => $countryId,
            'company_name' => 'Image Company',
            'location_address' => '2 Test Road',
            'city' => 'Johannesburg',
            'logo' => UploadedFile::fake()->image('company-logo.jpg', 30, 30),
        ])->assertCreated()->json('data');
        $this->assertNotNull(app(PublicAssetStorage::class)->existingFile((string) $company['logo']));

        $brand = $this->post('/api/enterprise/brands', [
            'company_id' => $company['id'],
            'code' => 'IMAGE-BRAND',
            'name' => 'Image Brand',
            'logo' => UploadedFile::fake()->image('brand-logo.png', 40, 40),
        ])->assertCreated()->json('data');
        $brandLogo = (string) $brand['logo_path'];
        $this->assertNotNull(app(PublicAssetStorage::class)->existingFile($brandLogo));

        $this->deleteJson('/api/enterprise/brands/'.$brand['id'])->assertOk();
        $this->assertNull(app(PublicAssetStorage::class)->existingFile($brandLogo));

        $companyLogo = (string) $company['logo'];
        $this->deleteJson('/api/enterprise/companies/'.$company['id'])->assertOk();
        $this->assertNull(app(PublicAssetStorage::class)->existingFile($companyLogo));

        $this->deleteJson('/api/enterprise/countries/'.$countryId)->assertOk();
        $this->assertNull(app(PublicAssetStorage::class)->existingFile($replacementCountryImage));

        $this->postJson('/api/enterprise/brands', [
            'company_id' => self::COMPANY_A,
            'code' => 'TEXT-LOGO',
            'name' => 'Text Logo',
            'logo_path' => 'assets/not-an-upload.png',
        ])->assertUnprocessable()->assertJsonValidationErrors('logo_path');

        $this->postJson('/api/enterprise/companies', [
            'country_id' => self::COUNTRY,
            'company_name' => 'Bad Logo Company',
            'location_address' => '1 Test Road',
            'city' => 'Harare',
            'logo' => 'C:\\fake\\logo.png',
        ])->assertUnprocessable()->assertJsonValidationErrors('logo');
    }

    public function test_a_complex_is_company_owned_and_accepts_shops_from_multiple_brands(): void
    {
        $this->postJson('/api/enterprise/shops', [
            'brand_id' => self::BRAND_B,
            'complex_id' => self::COMPLEX_A,
            'code' => 'CROSS-TENANT',
            'name' => 'Wrong Shop',
        ])->assertUnprocessable()->assertJsonPath('code', 'ENTERPRISE_HIERARCHY_INVALID');

        $first = $this->postJson('/api/enterprise/shops', [
            'brand_id' => self::BRAND_A,
            'complex_id' => self::COMPLEX_A,
            'code' => 'SHOP-A',
            'name' => 'Chicken Inn Sam Levy',
        ])->assertCreated()->json('data');

        $second = $this->postJson('/api/enterprise/shops', [
            'brand_id' => self::BRAND_A2,
            'complex_id' => self::COMPLEX_A,
            'code' => 'SHOP-B',
            'name' => 'Pizza Inn Sam Levy',
        ])->assertCreated()->json('data');

        $this->assertSame(self::COMPLEX_A, $first['complex_id']);
        $this->assertSame(self::COMPLEX_A, $second['complex_id']);
        $this->assertNotSame($first['brand_id'], $second['brand_id']);
        $this->assertDatabaseHas('complexes', [
            'id' => self::COMPLEX_A,
            'company_id' => self::COMPANY_A,
            'brand_id' => null,
        ]);
    }

    public function test_deleting_a_branch_retires_its_shop_and_complex_so_the_code_can_be_reused(): void
    {
        $branch = CompanyBranch::create([
            'company_id' => self::COMPANY_A,
            'branch_name' => 'Avondale',
            'branch_code' => 'AVD',
            'status' => 'active',
        ]);
        $shop = DB::table('shops')->where('branch_id', $branch->id)->first();
        $this->assertNotNull($shop);
        $complexId = $shop->complex_id;

        $branch->delete();

        $this->assertNotNull(DB::table('shops')->where('id', $shop->id)->value('deleted_at'));
        $this->assertNotNull(DB::table('complexes')->where('id', $complexId)->value('deleted_at'));
        $this->assertNull(DB::table('complexes')->where('id', self::COMPLEX_A)->value('deleted_at'));

        $readded = CompanyBranch::create([
            'company_id' => self::COMPANY_A,
            'branch_name' => 'Avondale',
            'branch_code' => 'AVD',
            'status' => 'active',
        ]);
        $this->assertSame(1, DB::table('shops')->where('code', 'AVD')->whereNull('deleted_at')->count());
        $this->assertSame(1, DB::table('complexes')->where('code', 'AVD')->whereNull('deleted_at')->count());
        $this->assertSame($readded->id, DB::table('shops')->where('code', 'AVD')->whereNull('deleted_at')->value('branch_id'));
    }

    public function test_till_crud_persists_and_enforces_the_exact_shop_brand_and_complex(): void
    {
        $shop = $this->postJson('/api/enterprise/shops', [
            'brand_id' => self::BRAND_A,
            'complex_id' => self::COMPLEX_A,
            'code' => 'SHOP-TILL',
            'name' => 'Till Test Shop',
        ])->assertCreated()->json('data');

        $this->postJson('/api/enterprise/tills', [
            'shop_id' => $shop['id'],
            'brand_id' => self::BRAND_A2,
            'complex_id' => self::COMPLEX_A,
            'till_code' => 'T-1',
            'till_name' => 'Wrong Brand Till',
        ])->assertUnprocessable()->assertJsonPath('code', 'ENTERPRISE_HIERARCHY_INVALID');

        $created = $this->postJson('/api/enterprise/tills', [
            'shop_id' => $shop['id'],
            'brand_id' => self::BRAND_A,
            'complex_id' => self::COMPLEX_A,
            'till_code' => 'T-1',
            'till_name' => 'Front Counter',
        ])->assertCreated()->json('data');

        $this->assertDatabaseHas('pos_tills', [
            'id' => $created['id'],
            'company_id' => self::COMPANY_A,
            'country_id' => self::COUNTRY,
            'brand_id' => self::BRAND_A,
            'complex_id' => self::COMPLEX_A,
            'shop_id' => $shop['id'],
        ]);

        $this->patchJson('/api/enterprise/tills/'.$created['id'], [
            'till_name' => 'Counter One',
            'device_label' => 'POS-01',
        ])->assertOk()->assertJsonPath('data.till_name', 'Counter One');

        $this->deleteJson('/api/enterprise/tills/'.$created['id'])->assertOk();
        $this->assertSoftDeleted('pos_tills', ['id' => $created['id']]);
    }

    public function test_existing_company_management_requires_country_and_blocks_moves_with_descendants(): void
    {
        $controller = app(CompanyController::class);
        $missingCountry = $controller->store(Request::create('/api/companies', 'POST'));
        $this->assertSame(422, $missingCountry->getStatusCode());
        $this->assertArrayHasKey('country_id', $missingCountry->getData(true)['data']);

        $textLogo = $controller->store(Request::create('/api/companies', 'POST', [
            'country_id' => self::COUNTRY,
            'company_name' => 'Text Logo Company',
            'location_address' => '1 Test Road',
            'city' => 'Harare',
            'logo' => 'C:\\fake\\logo.png',
            'license' => [
                'license_type' => 'standard',
                'max_branches' => 1,
                'max_users' => 5,
                'max_devices' => 2,
            ],
            'branches' => [[
                'branch_name' => 'Head Office',
            ]],
        ]));
        $this->assertSame(422, $textLogo->getStatusCode());
        $this->assertArrayHasKey('logo', $textLogo->getData(true)['data']);

        $otherCountry = $this->postJson('/api/enterprise/countries', [
            'code' => 'ZA',
            'name' => 'South Africa',
        ])->assertCreated()->json('data.id');
        $company = Company::query()->findOrFail(self::COMPANY_A);
        $move = $controller->update(Request::create('/api/companies/'.$company->id, 'PATCH', [
            'country_id' => $otherCountry,
        ]), $company);

        $this->assertSame(409, $move->getStatusCode());
        $this->assertSame('ENTERPRISE_HIERARCHY_CONFLICT', $move->getData(true)['code']);
        $this->assertSame(self::COUNTRY, $company->fresh()->country_id);
    }

    private function createTables(): void
    {
        Schema::create('countries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('iso_alpha_2')->nullable()->unique();
            $table->string('iso_alpha_3')->nullable()->unique();
            $table->string('name');
            $table->string('currency_code')->nullable();
            $table->string('timezone')->nullable();
            $table->string('locale')->nullable();
            $table->string('image_path')->nullable();
            $table->string('image_url')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('country_id')->nullable();
            $table->string('company_code')->unique();
            $table->string('company_name');
            $table->string('location_address');
            $table->string('city');
            $table->string('country');
            $table->string('domain')->nullable()->unique();
            $table->string('host_ip')->nullable();
            $table->string('license_support_phone')->nullable();
            $table->string('logo')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('brands', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('country_id');
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('status')->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('complexes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('country_id');
            $table->uuid('brand_id')->nullable();
            $table->string('code');
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('timezone')->nullable();
            $table->decimal('latitude')->nullable();
            $table->decimal('longitude')->nullable();
            $table->string('status')->default('active');
            $table->json('metadata')->nullable();
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
        Schema::create('shops', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('country_id');
            $table->uuid('brand_id');
            $table->uuid('complex_id');
            $table->uuid('branch_id');
            $table->string('code');
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('timezone')->nullable();
            $table->string('pos_login_mode')->default('code');
            $table->unsignedBigInteger('configuration_version')->default(1);
            $table->string('status')->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('pos_tills', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('country_id')->nullable();
            $table->uuid('brand_id')->nullable();
            $table->uuid('complex_id')->nullable();
            $table->uuid('branch_id');
            $table->uuid('shop_id')->nullable();
            $table->string('till_code');
            $table->string('till_name');
            $table->string('device_label')->nullable();
            $table->string('registration_token')->nullable();
            $table->string('activation_code')->nullable();
            $table->timestamp('activation_code_expires_at')->nullable();
            $table->boolean('is_registered')->default(false);
            $table->boolean('is_active')->default(true);
            $table->uuid('registered_by')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip_address')->nullable();
            $table->text('last_user_agent')->nullable();
            $table->unsignedBigInteger('configuration_version')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('licenses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->integer('max_devices')->default(100);
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    private function seedParents(): void
    {
        $now = now();

        DB::table('countries')->insert([
            'id' => self::COUNTRY,
            'code' => 'ZW',
            'name' => 'Zimbabwe',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ([self::COMPANY_A => 'Company A', self::COMPANY_B => 'Company B'] as $id => $name) {
            DB::table('companies')->insert([
                'id' => $id,
                'country_id' => self::COUNTRY,
                'company_code' => str_replace(' ', '-', strtoupper($name)),
                'company_name' => $name,
                'location_address' => 'Test Road',
                'city' => 'Harare',
                'country' => 'Zimbabwe',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        foreach ([
            [self::BRAND_A, self::COMPANY_A, 'BRAND-A', 'Chicken Inn'],
            [self::BRAND_A2, self::COMPANY_A, 'BRAND-A2', 'Pizza Inn'],
            [self::BRAND_B, self::COMPANY_B, 'BRAND-B', 'Other Brand'],
        ] as [$id, $companyId, $code, $name]) {
            DB::table('brands')->insert([
                'id' => $id,
                'company_id' => $companyId,
                'country_id' => self::COUNTRY,
                'code' => $code,
                'name' => $name,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        DB::table('complexes')->insert([
            'id' => self::COMPLEX_A,
            'company_id' => self::COMPANY_A,
            'country_id' => self::COUNTRY,
            'brand_id' => null,
            'code' => 'SAM-LEVY',
            'name' => 'Sam Levy Village',
            'city' => 'Harare',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function useAvailableDatabaseDriver(): void
    {
        if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            return;
        }

        $mysql = config('database.connections.mysql');
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $mysql['host'], $mysql['port']),
                $mysql['username'],
                $mysql['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `servenest_hierarchy_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (Throwable $exception) {
            $this->markTestSkipped('SQLite is unavailable and an isolated MySQL test database could not be created: '.$exception->getMessage());
        }

        config()->set('database.default', 'mysql');
        config()->set('database.connections.mysql.database', 'servenest_hierarchy_test');
        DB::purge('mysql');
        DB::reconnect('mysql');
    }
}
