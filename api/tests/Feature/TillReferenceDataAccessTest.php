<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Authentication\Models\PosUser;
use Modules\Company\Models\Company;
use Modules\Currency\Http\Controllers\CurrencyController;
use Modules\Currency\Http\Controllers\PaymentTypeController;
use Tests\TestCase;

/**
 * A cashier who can sell needs the shop's currencies and payment types to
 * price and settle a sale, even when their role cannot manage either list.
 */
class TillReferenceDataAccessTest extends TestCase
{
    private const COMPANY = '0199c7ee-0000-7000-8000-00000000c0a1';
    private const BRANCH = '0199c7ee-0000-7000-8000-00000000b0a1';

    /** @var list<string> */
    private array $tables = [
        'payment_types', 'currency_rates', 'currencies', 'role_permissions',
        'permissions', 'roles', 'pos_users', 'company_branches', 'companies',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite is required.');
        }

        foreach ($this->tables as $table) {
            Schema::dropIfExists($table);
        }
        $this->createSchema();

        DB::table('companies')->insert(['id' => self::COMPANY, 'company_name' => 'Chicken Inn']);
        DB::table('company_branches')->insert([
            'id' => self::BRANCH, 'company_id' => self::COMPANY,
            'branch_name' => 'Chicken Inn CBD', 'branch_code' => 'CICBD',
        ]);
        foreach (['create_sale', 'view_currency', 'view_payment_type', 'view_sale'] as $permission) {
            DB::table('permissions')->insert(['id' => (string) Str::uuid(), 'name' => $permission]);
        }
        DB::table('currencies')->insert([
            'id' => (string) Str::uuid(), 'company_id' => self::COMPANY, 'branch_id' => self::BRANCH,
            'code' => 'USD', 'name' => 'US Dollar', 'is_base_currency' => true, 'is_active' => true,
        ]);
        DB::table('payment_types')->insert([
            'id' => (string) Str::uuid(), 'company_id' => self::COMPANY, 'branch_id' => self::BRANCH,
            'name' => 'Cash', 'type' => 'cash', 'is_active' => true,
        ]);

        app()->instance('company', Company::query()->findOrFail(self::COMPANY));
    }

    protected function tearDown(): void
    {
        foreach ($this->tables as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_a_cashier_who_can_sell_gets_currencies_and_payment_types(): void
    {
        $cashier = $this->userWith(['create_sale', 'view_sale']);

        $currencies = $this->list(CurrencyController::class, $cashier, '/api/pos/currencies');
        $this->assertSame(200, $currencies->getStatusCode());
        $this->assertSame(['USD'], collect($currencies->getData(true)['data']['data'])->pluck('code')->all());

        $paymentTypes = $this->list(PaymentTypeController::class, $cashier, '/api/pos/payment-types');
        $this->assertSame(200, $paymentTypes->getStatusCode());
        $this->assertSame(['Cash'], collect($paymentTypes->getData(true)['data']['data'])->pluck('name')->all());
    }

    public function test_the_management_permissions_still_grant_access(): void
    {
        $manager = $this->userWith(['view_currency', 'view_payment_type']);

        $this->assertSame(200, $this->list(CurrencyController::class, $manager, '/api/pos/currencies')->getStatusCode());
        $this->assertSame(200, $this->list(PaymentTypeController::class, $manager, '/api/pos/payment-types')->getStatusCode());
    }

    public function test_a_user_who_can_neither_sell_nor_view_is_refused(): void
    {
        $viewer = $this->userWith(['view_sale']);

        $this->assertSame(403, $this->list(CurrencyController::class, $viewer, '/api/pos/currencies')->getStatusCode());
        $this->assertSame(403, $this->list(PaymentTypeController::class, $viewer, '/api/pos/payment-types')->getStatusCode());
    }

    private function list(string $controller, PosUser $user, string $url): JsonResponse
    {
        auth()->setUser($user);
        $request = Request::create($url, 'GET', ['per_page' => 100, 'is_active' => 'true']);
        $request->attributes->set('company_branch_id', self::BRANCH);
        $request->setUserResolver(fn () => $user);
        app()->instance('request', $request);

        return app()->call([app($controller), 'index'], ['request' => $request]);
    }

    /** @param list<string> $permissions */
    private function userWith(array $permissions): PosUser
    {
        $roleId = (string) Str::uuid();
        DB::table('roles')->insert(['id' => $roleId, 'company_id' => self::COMPANY, 'name' => 'Role '.Str::random(4)]);
        foreach ($permissions as $permission) {
            DB::table('role_permissions')->insert([
                'id' => (string) Str::uuid(), 'company_id' => self::COMPANY, 'role_id' => $roleId,
                'permission_id' => DB::table('permissions')->where('name', $permission)->value('id'),
            ]);
        }
        $userId = (string) Str::uuid();
        DB::table('pos_users')->insert([
            'id' => $userId, 'company_id' => self::COMPANY, 'branch_id' => self::BRANCH,
            'role_id' => $roleId, 'name' => 'Till user', 'username' => 'till.'.Str::random(6),
        ]);

        return PosUser::query()->findOrFail($userId);
    }

    private function createSchema(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('company_name');
            $table->softDeletes();
        });
        Schema::create('company_branches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('branch_name');
            $table->string('branch_code');
            $table->softDeletes();
        });
        Schema::create('pos_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('role_id');
            $table->string('name');
            $table->string('username')->nullable();
            $table->string('user_type')->default('staff');
            $table->softDeletes();
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('name');
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->string('group')->nullable();
            $table->timestamps();
        });
        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('role_id');
            $table->uuid('permission_id');
            $table->timestamps();
        });
        Schema::create('currencies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->string('code');
            $table->string('name');
            $table->string('country')->nullable();
            $table->boolean('is_base_currency')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('currency_rates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('currency_id');
            $table->uuid('branch_id')->nullable();
            $table->decimal('rate', 18, 6);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('payment_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->string('name');
            $table->string('type')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }
}
