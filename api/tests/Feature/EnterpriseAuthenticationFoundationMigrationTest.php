<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Authentication\Models\AuthPolicy;
use Modules\Authentication\Models\UserAccessAssignment;
use PDO;
use Tests\TestCase;

class EnterpriseAuthenticationFoundationMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The PDO SQLite driver is not enabled.');
        }

        $this->dropFoundationTables();
        Schema::dropIfExists('roles');
        Schema::dropIfExists('pos_users');

        Schema::create('roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->nullable();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('pos_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('username');
            $table->string('password');
            $table->string('login_code')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->dropFoundationTables();
            Schema::dropIfExists('roles');
            Schema::dropIfExists('pos_users');
        }

        parent::tearDown();
    }

    public function test_migration_and_scoped_models_work_on_a_clean_prerequisite_schema(): void
    {
        $migration = require base_path(
            'Modules/Authentication/database/migrations/2026_10_13_190000_create_enterprise_authentication_foundation.php'
        );
        $migration->up();

        foreach ([
            'auth_policies',
            'user_access_assignments',
            'auth_credential_histories',
            'auth_sessions',
            'authentication_events',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} was not created.");
        }
        foreach ([
            'login_code_lookup',
            'login_code_lookup_key_id',
            'credential_version',
            'credentials_changed_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('pos_users', $column));
        }
        $this->assertTrue(Schema::hasIndex(
            'pos_users',
            'pos_users_company_code_lookup_uq',
            'unique'
        ));

        DB::table('pos_users')->insert([
            'id' => 'user-1',
            'company_id' => 'company-1',
            'username' => 'cashier',
            'password' => 'password-hash',
            'credential_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('roles')->insert([
            'id' => 'role-1',
            'company_id' => 'company-1',
            'name' => 'Cashier',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $policy = AuthPolicy::query()->create([
            'policy_key' => 'authentication',
            'scope_type' => 'company',
            'company_id' => 'company-1',
            // Must be discarded because brand is below company scope.
            'brand_id' => 'stale-brand',
            'version' => 1,
            'settings' => ['pos' => ['login_mode' => 'code']],
        ]);
        $assignment = UserAccessAssignment::query()->create([
            'pos_user_id' => 'user-1',
            'role_id' => 'role-1',
            'scope_type' => 'company',
            'company_id' => 'company-1',
        ]);

        $this->assertSame('company:company-1', $policy->scope_key);
        $this->assertNull($policy->brand_id);
        $this->assertSame('company:company-1', $assignment->scope_key);

        $migration->down();

        $this->assertFalse(Schema::hasTable('auth_policies'));
        $this->assertFalse(Schema::hasColumn('pos_users', 'login_code_lookup'));
    }

    private function dropFoundationTables(): void
    {
        Schema::dropIfExists('authentication_events');
        Schema::dropIfExists('auth_sessions');
        Schema::dropIfExists('auth_credential_histories');
        Schema::dropIfExists('user_access_assignments');
        Schema::dropIfExists('auth_policies');
    }
}
