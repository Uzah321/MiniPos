<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SuperAdminPermissionRepairTest extends TestCase
{
    private bool $schemaPrepared = false;

    protected function tearDown(): void
    {
        if ($this->schemaPrepared) {
            Schema::dropIfExists('role_permissions');
            Schema::dropIfExists('permissions');
            Schema::dropIfExists('roles');
        }

        parent::tearDown();
    }

    public function test_it_assigns_every_permission_only_to_super_admin_roles_and_is_idempotent(): void
    {
        $driver = config('database.default');
        $pdoDriver = config("database.connections.{$driver}.driver");
        if (! in_array($pdoDriver, \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped("PDO driver {$pdoDriver} is not installed.");
        }

        Schema::create('roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->nullable();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->timestamps();
        });
        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->nullable();
            $table->uuid('role_id');
            $table->uuid('permission_id');
            $table->timestamps();
            $table->unique(['role_id', 'permission_id']);
        });
        $this->schemaPrepared = true;

        $now = now();
        DB::table('roles')->insert([
            ['id' => '10000000-0000-7000-8000-000000000001', 'company_id' => '20000000-0000-7000-8000-000000000001', 'name' => 'Super Admin', 'created_at' => $now, 'updated_at' => $now],
            ['id' => '10000000-0000-7000-8000-000000000002', 'company_id' => '20000000-0000-7000-8000-000000000001', 'name' => 'Manager', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('permissions')->insert([
            ['id' => '30000000-0000-7000-8000-000000000001', 'name' => 'create_sale', 'created_at' => $now, 'updated_at' => $now],
            ['id' => '30000000-0000-7000-8000-000000000002', 'name' => 'view_quotations', 'created_at' => $now, 'updated_at' => $now],
            ['id' => '30000000-0000-7000-8000-000000000003', 'name' => 'general_procedure', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('role_permissions')->insert([
            'id' => '40000000-0000-7000-8000-000000000001',
            'company_id' => null,
            'role_id' => '10000000-0000-7000-8000-000000000001',
            'permission_id' => '30000000-0000-7000-8000-000000000001',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $path = base_path('Modules/RolesAndPermission/database/migrations/2026_10_08_113000_repair_super_admin_permission_assignments.php');
        (require $path)->up();
        (require $path)->up();

        $this->assertSame(3, DB::table('role_permissions')->where('role_id', '10000000-0000-7000-8000-000000000001')->count());
        $this->assertSame(0, DB::table('role_permissions')->where('role_id', '10000000-0000-7000-8000-000000000002')->count());
        $this->assertSame(
            ['20000000-0000-7000-8000-000000000001'],
            DB::table('role_permissions')
                ->where('role_id', '10000000-0000-7000-8000-000000000001')
                ->distinct()
                ->pluck('company_id')
                ->all()
        );
    }
}
