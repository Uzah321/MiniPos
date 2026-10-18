<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Modules\Authentication\Data\AuthorizationScope;
use Modules\Authentication\Models\AuthSession;
use Modules\Authentication\Models\PosUser;
use Modules\Authentication\Services\AuthSessionService;
use PDO;
use Tests\TestCase;

class DynamicAuthSessionPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The PDO SQLite driver is not enabled.');
        }
        $this->dropTables();
        $this->createTables();
        Carbon::setTestNow('2026-10-13 08:00:00');
        Route::get('/_test/auth-session', fn () => response()->json(['ok' => true]))
            ->middleware('auth:sanctum');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->dropTables();
        }
        parent::tearDown();
    }

    public function test_issued_session_uses_dynamic_lifetime_and_enforces_idle_timeout(): void
    {
        $this->insertSessionPolicy(10, 2);
        $user = $this->createUser();
        $issuedToken = $user->createToken('counter-1', ['workspace:front-office']);
        $request = Request::create('/api/pos-auth/front-office/login', 'POST', [
            'device_name' => 'counter-1',
        ]);
        $request->server->set('REMOTE_ADDR', '10.0.0.5');

        $session = app(AuthSessionService::class)->issue(
            $user,
            $issuedToken,
            'front-office',
            'login_code',
            AuthorizationScope::global(),
            $request,
        );

        $this->assertNotNull($session);
        $this->assertSame('pos', $session->channel);
        $this->assertSame(10.0, $session->issued_at->diffInMinutes($session->expires_at));
        $this->assertSame(2, $session->policy_snapshot['idle_timeout_minutes']);

        $authenticated = Request::create('/api/pos/me', 'GET');
        $user->withAccessToken($issuedToken->accessToken);
        $authenticated->setUserResolver(fn () => $user);

        Carbon::setTestNow('2026-10-13 08:00:30');
        $this->assertNull(app(AuthSessionService::class)->violation($authenticated));
        $this->assertSame('2026-10-13 08:00:00', $session->fresh()->last_activity_at->format('Y-m-d H:i:s'));

        Carbon::setTestNow('2026-10-13 08:01:01');
        $this->assertNull(app(AuthSessionService::class)->violation($authenticated));
        $this->assertSame('2026-10-13 08:01:01', $session->fresh()->last_activity_at->format('Y-m-d H:i:s'));

        Carbon::setTestNow('2026-10-13 08:03:02');
        $this->assertSame([
            'message' => 'Your session timed out because it was inactive. Sign in again.',
            'code' => 'SESSION_IDLE_TIMEOUT',
        ], app(AuthSessionService::class)->violation($authenticated));
        $this->assertNotNull($session->fresh()->revoked_at);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $issuedToken->accessToken->getKey(),
        ]);
    }

    public function test_credential_version_change_revokes_session_and_bearer_token(): void
    {
        $user = $this->createUser();
        $issuedToken = $user->createToken('back-office', ['workspace:back-office']);
        $request = Request::create('/api/pos-auth/back-office/login', 'POST');
        $session = app(AuthSessionService::class)->issue(
            $user,
            $issuedToken,
            'back-office',
            'username_password',
            AuthorizationScope::global(),
            $request,
        );

        $user->forceFill(['credential_version' => 2])->save();
        $user->withAccessToken($issuedToken->accessToken);
        $authenticated = Request::create('/api/pos/me', 'GET');
        $authenticated->setUserResolver(fn () => $user);

        $this->assertSame([
            'message' => 'Your credentials changed. Sign in again.',
            'code' => 'SESSION_CREDENTIAL_CHANGED',
        ], app(AuthSessionService::class)->violation($authenticated));
        $this->assertSame('credential_version_changed', $session->fresh()->revocation_reason);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $issuedToken->accessToken->getKey(),
        ]);
    }

    public function test_revoke_all_marks_sessions_and_removes_tokens(): void
    {
        $user = $this->createUser();
        $issuedToken = $user->createToken('back-office', ['workspace:back-office']);
        app(AuthSessionService::class)->issue(
            $user,
            $issuedToken,
            'back-office',
            'username_password',
            AuthorizationScope::global(),
            Request::create('/api/pos-auth/back-office/login', 'POST'),
        );

        app(AuthSessionService::class)->revokeAllForUser($user, 'access_assignment_changed');

        $this->assertSame(
            'access_assignment_changed',
            AuthSession::query()->where('pos_user_id', $user->id)->value('revocation_reason')
        );
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_unmanaged_token_is_rejected_once_session_schema_is_available(): void
    {
        $user = $this->createUser();
        $issuedToken = $user->createToken('unmanaged', ['workspace:back-office']);
        $user->withAccessToken($issuedToken->accessToken);
        $authenticated = Request::create('/api/pos/me', 'GET');
        $authenticated->setUserResolver(fn () => $user);

        $this->assertSame([
            'message' => 'This session is not registered. Sign in again.',
            'code' => 'SESSION_NOT_REGISTERED',
        ], app(AuthSessionService::class)->violation($authenticated));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_auth_middleware_enforces_registered_session_expiry(): void
    {
        $user = $this->createUser();
        $issuedToken = $user->createToken('managed', ['workspace:back-office']);
        $session = app(AuthSessionService::class)->issue(
            $user,
            $issuedToken,
            'back-office',
            'username_password',
            AuthorizationScope::global(),
            Request::create('/api/pos-auth/back-office/login', 'POST'),
        );
        $headers = ['Authorization' => 'Bearer '.$issuedToken->plainTextToken];

        $this->getJson('/_test/auth-session', $headers)
            ->assertOk()
            ->assertJson(['ok' => true]);

        $session->forceFill(['expires_at' => now()->subSecond()])->saveQuietly();
        $this->getJson('/_test/auth-session', $headers)
            ->assertUnauthorized()
            ->assertJson(['code' => 'SESSION_EXPIRED']);
    }

    private function createUser(): PosUser
    {
        DB::table('pos_users')->insert([
            'id' => 'user-1',
            'username' => 'cashier',
            'password' => 'hash',
            'credential_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return PosUser::query()->findOrFail('user-1');
    }

    private function insertSessionPolicy(int $lifetimeMinutes, int $idleMinutes): void
    {
        DB::table('auth_policies')->insert([
            'id' => 'session-policy-1',
            'policy_key' => 'session',
            'scope_type' => 'global',
            'scope_key' => 'global',
            'priority' => 0,
            'version' => 1,
            'settings' => json_encode([
                'pos' => [
                    'lifetime_minutes' => $lifetimeMinutes,
                    'idle_timeout_minutes' => $idleMinutes,
                ],
            ], JSON_THROW_ON_ERROR),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTables(): void
    {
        Schema::create('pos_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('username');
            $table->string('password');
            $table->unsignedBigInteger('credential_version')->default(1);
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('tokenable_type');
            $table->uuid('tokenable_id');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['tokenable_type', 'tokenable_id']);
        });
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
        Schema::create('auth_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('pos_user_id');
            $table->unsignedBigInteger('personal_access_token_id')->nullable();
            $table->string('channel');
            $table->string('workspace')->nullable();
            $table->string('authentication_method');
            $table->uuid('country_id')->nullable();
            $table->uuid('company_id')->nullable();
            $table->uuid('brand_id')->nullable();
            $table->uuid('complex_id')->nullable();
            $table->uuid('shop_id')->nullable();
            $table->uuid('till_id')->nullable();
            $table->uuid('branch_id')->nullable();
            $table->unsignedBigInteger('credential_version')->default(1);
            $table->char('policy_fingerprint', 64)->nullable();
            $table->json('policy_snapshot')->nullable();
            $table->char('device_identifier_hash', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('issued_at');
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason', 120)->nullable();
            $table->timestamps();
        });
    }

    private function dropTables(): void
    {
        Schema::dropIfExists('auth_sessions');
        Schema::dropIfExists('auth_policies');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('pos_users');
    }
}
