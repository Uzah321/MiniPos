<?php

namespace Tests\Feature;

use App\Services\Auth\PosLoginRateLimiter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Modules\Company\Models\Company;
use PDO;
use Tests\TestCase;

class PosLoginRateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::post('/_test/pos-login/failure', fn () => response()->json([
            'message' => 'Invalid login credentials',
        ], 401))->middleware('throttle:'.PosLoginRateLimiter::LOGIN);

        Route::post('/_test/pos-login/success', fn () => response()->json([
            'message' => 'Login successful',
        ]))->middleware('throttle:'.PosLoginRateLimiter::LOGIN);

        Route::get('/_test/pos-login/mode', fn () => response()->json([
            'login_mode' => 'password',
        ]))->middleware('throttle:'.PosLoginRateLimiter::LOGIN_MODE);
    }

    public function test_failed_logins_are_isolated_per_company_and_terminal(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/_test/pos-login/failure', [
                'username' => 'rotating-user-'.$attempt,
                'workspace' => 'front-office',
            ], [
                'X-Company-Code' => 'TENANT-A',
                'X-Till-Token' => 'terminal-a-secret',
            ])->assertUnauthorized();
        }

        $limited = $this->postJson('/_test/pos-login/failure', [
            'username' => 'next-user',
            'workspace' => 'front-office',
        ], [
            'X-Company-Code' => 'TENANT-A',
            'X-Till-Token' => 'terminal-a-secret',
        ]);

        $limited->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJson([
                'code' => 'LOGIN_RATE_LIMITED',
                'limit_scope' => 'terminal',
            ]);
        $this->assertGreaterThan(0, $limited->json('retry_after'));
        $this->assertStringContainsString(
            (string) $limited->json('retry_after'),
            (string) $limited->json('message')
        );

        $this->postJson('/_test/pos-login/failure', [
            'username' => 'next-user',
            'workspace' => 'front-office',
        ], [
            'X-Company-Code' => 'TENANT-A',
            'X-Till-Token' => 'terminal-b-secret',
        ])->assertUnauthorized();

        $this->postJson('/_test/pos-login/failure', [
            'username' => 'next-user',
            'workspace' => 'front-office',
        ], [
            'X-Company-Code' => 'TENANT-B',
            'X-Till-Token' => 'terminal-a-secret',
        ])->assertUnauthorized();
    }

    public function test_successes_and_login_mode_lookups_do_not_consume_the_failed_login_budget(): void
    {
        $headers = [
            'X-Company-Code' => 'TENANT-SUCCESS',
            'X-Till-Token' => 'terminal-success-secret',
        ];
        $credentials = [
            'username' => 'cashier',
            'workspace' => 'front-office',
        ];

        for ($attempt = 0; $attempt < 15; $attempt++) {
            $this->postJson('/_test/pos-login/success', $credentials, $headers)->assertOk();
            $this->getJson('/_test/pos-login/mode', $headers)->assertOk();
        }

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/_test/pos-login/failure', $credentials, $headers)->assertUnauthorized();
        }

        $this->postJson('/_test/pos-login/failure', $credentials, $headers)
            ->assertStatus(429)
            ->assertJson([
                'code' => 'LOGIN_RATE_LIMITED',
                'limit_scope' => 'credential',
            ]);
    }

    public function test_alphanumeric_login_codes_keep_distinct_failure_buckets(): void
    {
        // This assertion isolates the per-credential bucket. The broader
        // source/network bucket is intentionally tested separately above.
        config()->set('pos_security.login_rate_limit.terminal_failures_per_minute', 100);
        $headers = ['X-Company-Code' => 'TENANT-ALPHANUMERIC'];

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/_test/pos-login/failure', [
                'login_code' => 'AB12',
                'workspace' => 'front-office',
            ], $headers)->assertUnauthorized();
        }

        $this->postJson('/_test/pos-login/failure', [
            'login_code' => 'AB12',
            'workspace' => 'front-office',
        ], $headers)->assertStatus(429)->assertJson([
            'limit_scope' => 'credential',
        ]);

        $this->postJson('/_test/pos-login/failure', [
            'login_code' => 'CD12',
            'workspace' => 'front-office',
        ], $headers)->assertUnauthorized();
    }

    public function test_explicit_enterprise_lockout_policy_controls_only_the_credential_bucket(): void
    {
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The PDO SQLite driver is not enabled.');
        }

        Schema::create('auth_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('policy_key');
            $table->string('scope_type');
            $table->string('scope_key');
            $table->uuid('country_id')->nullable();
            $table->uuid('company_id')->nullable();
            $table->uuid('brand_id')->nullable();
            $table->uuid('complex_id')->nullable();
            $table->uuid('shop_id')->nullable();
            $table->uuid('till_id')->nullable();
            $table->unsignedInteger('priority')->default(0);
            $table->unsignedBigInteger('version')->default(1);
            $table->json('settings');
            $table->boolean('is_active')->default(true);
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_until')->nullable();
            $table->timestamps();
        });
        DB::table('auth_policies')->insert([
            'id' => 'policy-lockout-1',
            'policy_key' => 'authentication',
            'scope_type' => 'global',
            'scope_key' => 'global',
            'priority' => 0,
            'version' => 1,
            'settings' => json_encode([
                'lockout' => ['maximum_attempts' => 3, 'decay_seconds' => 120],
            ], JSON_THROW_ON_ERROR),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $company = new Company;
        $company->setRawAttributes(['id' => 'company-rate-limit', 'country_id' => null], true);
        app()->instance('company', $company);
        app()->forgetInstance('till');

        try {
            $request = Request::create('/api/pos-auth/back-office/login', 'POST', [
                'username' => 'cashier',
            ]);
            $request->headers->set('X-Company-Code', 'TENANT-POLICY');
            $limits = app(PosLoginRateLimiter::class)->login($request);

            $this->assertSame(3, $limits[0]->maxAttempts);
            $this->assertSame(120, $limits[0]->decaySeconds);
            $this->assertSame(30, $limits[1]->maxAttempts);
            $this->assertSame(60, $limits[1]->decaySeconds);
        } finally {
            app()->forgetInstance('company');
            Schema::dropIfExists('auth_policies');
        }
    }

    public function test_terminal_activation_error_does_not_count_as_a_bad_credential(): void
    {
        Route::post('/_test/pos-login/activation-required', fn () => response()->json([
            'message' => 'Pair and register the till first.',
            'code' => 'TILL_TOKEN_REQUIRED',
        ], 401))->middleware('throttle:'.PosLoginRateLimiter::LOGIN);

        $headers = ['X-Company-Code' => 'TENANT-ACTIVATION'];
        $credentials = [
            'username' => 'cashier',
            'workspace' => 'front-office',
        ];

        for ($attempt = 0; $attempt < 15; $attempt++) {
            $this->postJson('/_test/pos-login/activation-required', $credentials, $headers)
                ->assertUnauthorized()
                ->assertJson(['code' => 'TILL_TOKEN_REQUIRED']);
        }
    }

    public function test_every_public_auth_and_sync_throttle_has_its_own_namespace(): void
    {
        $expectedMiddleware = [
            'api/pos-auth/login' => 'Illuminate\\Routing\\Middleware\\ThrottleRequests:'.PosLoginRateLimiter::LOGIN,
            'api/pos-auth/login-mode' => 'Illuminate\\Routing\\Middleware\\ThrottleRequests:'.PosLoginRateLimiter::LOGIN_MODE,
            'api/pos/sync/pair' => 'throttle:10,1,sync-pair:',
            'api/pos/sync/terminal-activations/redeem' => 'throttle:10,1,sync-terminal-redeem:',
            'api/pos/sync/status' => 'throttle:60,1,sync-status:',
            'api/pos/sync/run' => 'throttle:120,1,sync-run:',
        ];

        foreach ($expectedMiddleware as $uri => $middleware) {
            $route = collect(Route::getRoutes()->getRoutes())
                ->first(fn ($route) => $route->uri() === $uri);

            $this->assertNotNull($route, "Route [{$uri}] was not registered.");
            $this->assertContains($middleware, $route->gatherMiddleware());
        }

        $this->assertSame(
            count($expectedMiddleware),
            count(array_unique(array_values($expectedMiddleware))),
            'Public auth/sync throttles must never share a middleware namespace.'
        );
    }
}
