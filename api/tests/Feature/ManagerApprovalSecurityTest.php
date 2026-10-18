<?php

namespace Tests\Feature;

use App\Services\Auth\ManagerApprovalRateLimiter;
use App\Services\Sales\DiscountEngineService;
use Exception;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Modules\Authentication\Http\Controllers\ManagerApprovalController;
use Modules\Authentication\Models\PosUser;
use ReflectionClass;
use Tests\TestCase;

class ManagerApprovalSecurityTest extends TestCase
{
    private bool $databaseAvailable = false;

    private const COMPANY_ID = '10000000-0000-7000-8000-000000000001';

    private const OTHER_COMPANY_ID = '20000000-0000-7000-8000-000000000001';

    private const BRANCH_ID = '10000000-0000-7000-8000-000000000002';

    private const MANAGER_ID = '10000000-0000-7000-8000-000000000003';

    private const MANAGER_ROLE_ID = '10000000-0000-7000-8000-000000000004';

    private const CASHIER_ID = '10000000-0000-7000-8000-000000000005';

    private const CASHIER_ROLE_ID = '10000000-0000-7000-8000-000000000006';

    private const GENERAL_PERMISSION_ID = '10000000-0000-7000-8000-000000000007';

    private const DISCOUNT_PERMISSION_ID = '10000000-0000-7000-8000-000000000008';

    private const DISCOUNT_TYPE_ID = '10000000-0000-7000-8000-000000000009';

    private const DISCOUNT_ID = '10000000-0000-7000-8000-000000000010';

    private const APPROVAL_ID = '10000000-0000-7000-8000-000000000011';

    protected function setUp(): void
    {
        parent::setUp();

        app()->instance('company', (object) ['id' => self::COMPANY_ID]);

        $actor = new PosUser;
        $actor->forceFill([
            'id' => self::CASHIER_ID,
            'company_id' => self::COMPANY_ID,
            'branch_id' => self::BRANCH_ID,
            'role_id' => self::CASHIER_ROLE_ID,
            'name' => 'Requesting Cashier',
            'username' => 'cashier',
            'user_type' => 'cashier',
        ]);
        $actor->exists = true;
        Auth::setUser($actor);
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        app()->forgetInstance('company');

        if ($this->databaseAvailable) {
            foreach ([
                'general_settings',
                'discount_role_permissions',
                'discount_categories',
                'discount_products',
                'discounts',
                'discount_types',
                'audit_trails',
                'pos_users',
                'role_permissions',
                'permissions',
                'roles',
            ] as $table) {
                Schema::dropIfExists($table);
            }
        }

        parent::tearDown();
    }

    public function test_action_permissions_are_an_explicit_allowlist_with_all_existing_aliases(): void
    {
        $map = (new ReflectionClass(ManagerApprovalController::class))
            ->getConstant('ACTION_PERMISSION_MAP');

        $this->assertSame([
            'cashout' => 'approve_cash_drawer_movement',
            'cash_out' => 'approve_cash_drawer_movement',
            'bank_drop' => 'approve_cash_drawer_movement',
            'paid_out' => 'approve_cash_drawer_movement',
            'petty_cash' => 'approve_cash_drawer_movement',
            'float_out' => 'approve_cash_drawer_movement',
            'cash_drawer_movement' => 'approve_cash_drawer_movement',
            'cashup' => 'approve_cashup_end_shift',
            'end_shift' => 'approve_cashup_end_shift',
            'price_override' => 'approve_price_override',
            'price_level_override' => 'approve_price_override',
            'price_change' => 'approve_price_override',
            'manual_price' => 'approve_price_override',
            'manual_price_change' => 'approve_price_override',
            'sale_price_override' => 'approve_price_override',
            'product_price_override' => 'approve_price_override',
            'discount_override' => 'approve_discount_override',
            'manual_discount' => 'approve_discount_override',
            'apply_discount' => 'approve_discount_override',
            'cart_discount' => 'approve_discount_override',
            'line_discount' => 'approve_discount_override',
            'void_sale' => 'approve_void_sale',
            'void_receipt' => 'approve_void_sale',
            'cancel_receipt' => 'approve_void_sale',
            'clear_open_table' => 'clear_open_tables',
            'clear_table' => 'clear_open_tables',
            'clear_hanging_table' => 'clear_open_tables',
        ], $map);
        $this->assertArrayNotHasKey('unmapped_action', $map);
    }

    public function test_caller_permission_cannot_override_the_action_and_unknown_actions_are_rejected(): void
    {
        $this->prepareDatabase();

        $controller = app(ManagerApprovalController::class);

        $unknown = $controller->verify($this->approvalRequest([
            'action' => 'unmapped action',
            'permission' => 'manager_approval',
        ]));

        $this->assertSame(422, $unknown->getStatusCode());
        $this->assertSame(
            'Unsupported manager approval action.',
            $unknown->getData(true)['errors']['action'][0]
        );
        $this->assertSame(0, DB::table('audit_trails')->count());

        $bypassAttempt = $controller->verify($this->approvalRequest([
            'action' => 'discount_override',
            'permission' => 'manager_approval',
        ]));

        $this->assertSame(403, $bypassAttempt->getStatusCode());
        $this->assertSame(
            'approve_discount_override',
            $bypassAttempt->getData(true)['required_permission']
        );

        $failedAudit = DB::table('audit_trails')->where('status', 'failed')->first();
        $this->assertNotNull($failedAudit);
        $this->assertSame(
            'approve_discount_override',
            json_decode($failedAudit->metadata, true)['required_permission']
        );

        DB::table('role_permissions')->insert([
            'id' => '10000000-0000-7000-8000-000000000012',
            'company_id' => self::COMPANY_ID,
            'role_id' => self::MANAGER_ROLE_ID,
            'permission_id' => self::DISCOUNT_PERMISSION_ID,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $validAlias = $controller->verify($this->approvalRequest([
            'action' => 'Manual Discount',
            'permission' => 'manager_approval',
        ]));

        $this->assertSame(200, $validAlias->getStatusCode());
        $this->assertSame('manual_discount', $validAlias->getData(true)['data']['action']);
        $this->assertSame(
            'approve_discount_override',
            $validAlias->getData(true)['data']['required_permission']
        );
    }

    public function test_discount_approval_requires_permission_and_exact_available_tenant_scope(): void
    {
        $this->prepareDatabase();

        $this->insertDiscountApproval(
            permission: 'manager_approval',
            companyId: self::COMPANY_ID,
            branchId: self::BRANCH_ID
        );

        $service = app(DiscountEngineService::class);
        $line = $this->discountLine();

        $this->assertDiscountApprovalRejected($service, $line);

        DB::table('audit_trails')->where('id', self::APPROVAL_ID)->update([
            'metadata' => json_encode(['required_permission' => 'approve_discount_override']),
            'new_values' => json_encode(['required_permission' => 'approve_discount_override']),
            'branch_id' => null,
        ]);
        $this->assertDiscountApprovalRejected($service, $line);

        DB::table('audit_trails')->where('id', self::APPROVAL_ID)->update([
            'branch_id' => self::BRANCH_ID,
        ]);
        $result = $service->validateDiscountApproval(
            $line,
            self::COMPANY_ID,
            self::BRANCH_ID,
            self::CASHIER_ID
        );

        $this->assertSame(self::APPROVAL_ID, $result['manager_approval_id']);
        $this->assertSame(self::MANAGER_ID, $result['approved_by']);

        DB::table('audit_trails')->where('id', self::APPROVAL_ID)->update([
            'company_id' => self::OTHER_COMPANY_ID,
        ]);
        $this->assertDiscountApprovalRejected($service, $line);
    }

    public function test_manager_approval_limiter_uses_non_secret_identity_fingerprints(): void
    {
        $request = $this->limiterRequest('sensitive-approver', 'correct horse battery staple', '192.0.2.10');
        $limits = app(ManagerApprovalRateLimiter::class)->verify($request);

        $this->assertCount(3, $limits);
        foreach ($limits as $limit) {
            $this->assertStringNotContainsString('correct horse battery staple', $limit->key);
            $this->assertStringNotContainsString('sensitive-approver', $limit->key);
            $this->assertStringNotContainsString('192.0.2.10', $limit->key);
            $this->assertStringNotContainsString(self::COMPANY_ID, $limit->key);
            $this->assertStringNotContainsString(self::CASHIER_ID, $limit->key);
        }

        $changedPassword = app(ManagerApprovalRateLimiter::class)->verify(
            $this->limiterRequest('sensitive-approver', 'a different password', '192.0.2.10')
        );
        $this->assertSame(
            array_column($limits, 'key'),
            array_column($changedPassword, 'key'),
            'Passwords must not influence or appear in rate-limit keys.'
        );

        $changedManager = app(ManagerApprovalRateLimiter::class)->verify(
            $this->limiterRequest('another-approver', 'a different password', '192.0.2.10')
        );
        $this->assertNotSame($limits[0]->key, $changedManager[0]->key);

        $changedNetwork = app(ManagerApprovalRateLimiter::class)->verify(
            $this->limiterRequest('sensitive-approver', 'a different password', '192.0.2.11')
        );
        $this->assertNotSame($limits[2]->key, $changedNetwork[2]->key);
    }

    public function test_manager_approval_route_has_a_dedicated_failed_credential_throttle(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($route) => $route->uri() === 'api/pos/manager-approvals/verify');

        $this->assertNotNull($route);
        $this->assertContains(
            ThrottleRequests::class.':'.ManagerApprovalRateLimiter::VERIFY,
            $route->gatherMiddleware()
        );

        config()->set('pos_security.manager_approval_rate_limit.manager_failures_per_minute', 2);
        config()->set('pos_security.manager_approval_rate_limit.actor_failures_per_minute', 10);
        config()->set('pos_security.manager_approval_rate_limit.network_failures_per_minute', 10);

        Route::post('/_test/manager-approval/failure', fn () => response()->json([
            'message' => 'Invalid manager username or password.',
            'code' => 'MANAGER_APPROVAL_INVALID_CREDENTIALS',
        ], 422))->middleware(ThrottleRequests::using(ManagerApprovalRateLimiter::VERIFY));

        $payload = [
            'username' => 'throttled-manager',
            'password' => 'wrong-password',
            'action' => 'discount_override',
        ];

        $this->postJson('/_test/manager-approval/failure', $payload)->assertStatus(422);
        $this->postJson('/_test/manager-approval/failure', $payload)->assertStatus(422);
        $this->postJson('/_test/manager-approval/failure', $payload)
            ->assertStatus(429)
            ->assertJson([
                'code' => 'MANAGER_APPROVAL_RATE_LIMITED',
                'limit_scope' => 'manager',
            ]);
    }

    private function approvalRequest(array $overrides = []): Request
    {
        $request = Request::create('/api/pos/manager-approvals/verify', 'POST', array_merge([
            'username' => 'manager',
            'password' => 'manager-secret',
            'action' => 'discount_override',
            'branch_id' => self::BRANCH_ID,
        ], $overrides));
        $request->setUserResolver(fn () => Auth::user());

        return $request;
    }

    private function limiterRequest(string $username, string $password, string $ip): Request
    {
        $request = Request::create('/api/pos/manager-approvals/verify', 'POST', [
            'username' => $username,
            'password' => $password,
            'action' => 'discount_override',
        ], [], [], ['REMOTE_ADDR' => $ip]);
        $request->setUserResolver(fn () => Auth::user());

        return $request;
    }

    private function discountLine(): array
    {
        return [
            'quantity' => 1,
            'discount_payload' => [
                'discount_id' => self::DISCOUNT_ID,
                'discount_amount' => 10,
                'gross_amount' => 100,
                'manager_approval_id' => self::APPROVAL_ID,
            ],
        ];
    }

    private function assertDiscountApprovalRejected(DiscountEngineService $service, array $line): void
    {
        try {
            $service->validateDiscountApproval(
                $line,
                self::COMPANY_ID,
                self::BRANCH_ID,
                self::CASHIER_ID
            );
            $this->fail('An incorrectly scoped or under-permissioned approval was accepted.');
        } catch (Exception $exception) {
            $this->assertSame('Invalid manager approval for discount.', $exception->getMessage());
        }
    }

    private function insertDiscountApproval(string $permission, string $companyId, ?string $branchId): void
    {
        DB::table('audit_trails')->insert([
            'id' => self::APPROVAL_ID,
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'user_id' => self::MANAGER_ID,
            'action' => 'manager_approval_discount_override',
            'module' => 'manager-approval',
            'status' => 'success',
            'metadata' => json_encode(['required_permission' => $permission]),
            'new_values' => json_encode(['required_permission' => $permission]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function prepareSchema(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->nullable();
            $table->string('name');
            $table->string('group')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->string('group')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->nullable();
            $table->uuid('role_id');
            $table->uuid('permission_id');
            $table->timestamps();
        });

        Schema::create('pos_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('role_id');
            $table->string('name');
            $table->string('username')->unique();
            $table->string('password');
            $table->string('user_type')->default('cashier');
            $table->boolean('can_login_pos')->default(true);
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('audit_trails', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->nullable();
            $table->uuid('branch_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->string('user_name')->nullable();
            $table->string('user_email')->nullable();
            $table->string('user_role')->nullable();
            $table->string('action');
            $table->string('module')->nullable();
            $table->string('description')->nullable();
            $table->string('auditable_type')->nullable();
            $table->string('auditable_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('changed_fields')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('device_name')->nullable();
            $table->string('terminal_name')->nullable();
            $table->string('session_id')->nullable();
            $table->string('status')->default('success');
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('discount_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('code');
            $table->string('name');
            $table->string('scope')->default('line');
            $table->string('calculation_type')->default('percentage');
            $table->boolean('requires_manager_approval')->default(false);
            $table->boolean('requires_reason')->default(false);
            $table->boolean('allow_stacking')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('discounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('discount_type_id');
            $table->string('code');
            $table->string('name');
            $table->string('discount_basis')->default('percentage');
            $table->decimal('value', 15, 4)->default(0);
            $table->decimal('max_discount_amount', 15, 4)->nullable();
            $table->decimal('min_sale_amount', 15, 4)->nullable();
            $table->decimal('min_quantity', 15, 4)->nullable();
            $table->boolean('applies_to_all_products')->default(true);
            $table->boolean('applies_to_all_categories')->default(true);
            $table->boolean('requires_customer')->default(false);
            $table->boolean('requires_coupon_code')->default(false);
            $table->string('coupon_code')->nullable();
            $table->integer('usage_limit')->nullable();
            $table->integer('usage_count')->default(0);
            $table->integer('per_customer_limit')->nullable();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('requires_manager_approval')->default(false);
            $table->boolean('requires_reason')->default(false);
            $table->boolean('allow_stacking')->default(false);
            $table->integer('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        foreach ([
            'discount_products' => 'product_id',
            'discount_categories' => 'category_id',
        ] as $tableName => $targetColumn) {
            Schema::create($tableName, function (Blueprint $table) use ($targetColumn) {
                $table->uuid('id')->primary();
                $table->uuid('company_id');
                $table->uuid('discount_id');
                $table->uuid($targetColumn);
                $table->timestamps();
            });
        }

        Schema::create('discount_role_permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('discount_id');
            $table->uuid('role_id');
            $table->decimal('max_percentage', 8, 4)->nullable();
            $table->decimal('max_amount', 15, 4)->nullable();
            $table->boolean('can_apply_without_manager')->default(false);
            $table->timestamps();
        });

        Schema::create('general_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->string('setting_key');
            $table->longText('setting_value')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    private function seedSecurityFixtures(): void
    {
        DB::table('roles')->insert([
            [
                'id' => self::MANAGER_ROLE_ID,
                'company_id' => self::COMPANY_ID,
                'name' => 'Manager',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => self::CASHIER_ROLE_ID,
                'company_id' => self::COMPANY_ID,
                'name' => 'Cashier',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('permissions')->insert([
            [
                'id' => self::GENERAL_PERMISSION_ID,
                'name' => 'manager_approval',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => self::DISCOUNT_PERMISSION_ID,
                'name' => 'approve_discount_override',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('role_permissions')->insert([
            'id' => '10000000-0000-7000-8000-000000000013',
            'company_id' => self::COMPANY_ID,
            'role_id' => self::MANAGER_ROLE_ID,
            'permission_id' => self::GENERAL_PERMISSION_ID,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('pos_users')->insert([
            [
                'id' => self::MANAGER_ID,
                'company_id' => self::COMPANY_ID,
                'branch_id' => self::BRANCH_ID,
                'role_id' => self::MANAGER_ROLE_ID,
                'name' => 'Approval Manager',
                'username' => 'manager',
                'password' => Hash::make('manager-secret'),
                'user_type' => 'manager',
                'can_login_pos' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => self::CASHIER_ID,
                'company_id' => self::COMPANY_ID,
                'branch_id' => self::BRANCH_ID,
                'role_id' => self::CASHIER_ROLE_ID,
                'name' => 'Requesting Cashier',
                'username' => 'cashier',
                'password' => Hash::make('cashier-secret'),
                'user_type' => 'cashier',
                'can_login_pos' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('discount_types')->insert([
            'id' => self::DISCOUNT_TYPE_ID,
            'company_id' => self::COMPANY_ID,
            'code' => 'MANUAL_MANAGER',
            'name' => 'Manual manager discount',
            'scope' => 'line',
            'calculation_type' => 'percentage',
            'requires_manager_approval' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('discounts')->insert([
            'id' => self::DISCOUNT_ID,
            'company_id' => self::COMPANY_ID,
            'branch_id' => self::BRANCH_ID,
            'discount_type_id' => self::DISCOUNT_TYPE_ID,
            'code' => 'MANAGER10',
            'name' => 'Manager ten percent',
            'discount_basis' => 'percentage',
            'value' => 10,
            'requires_manager_approval' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function prepareDatabase(): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if (! in_array($driver, \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped("The PDO {$driver} driver is required for database-backed approval assertions.");
        }

        $this->databaseAvailable = true;
        $this->prepareSchema();
        $this->seedSecurityFixtures();
        Auth::setUser(PosUser::query()->findOrFail(self::CASHIER_ID));
    }
}
