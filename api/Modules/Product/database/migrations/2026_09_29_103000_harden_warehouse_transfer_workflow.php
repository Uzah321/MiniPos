<?php

use App\Services\Inventory\InventoryBatchSynchronizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private array $permissions = [
        ['view_warehouse_stock', 'Inventory', 'View warehouse products, quantities and valuation'],
        ['request_warehouse_transfer', 'Inventory', 'Request stock from one warehouse to another'],
        ['approve_warehouse_transfer', 'Inventory', 'Approve or reject warehouse transfer requests'],
        ['dispatch_warehouse_transfer', 'Inventory', 'Dispatch approved warehouse transfers'],
        ['receive_warehouse_transfer', 'Inventory', 'Receive dispatched warehouse transfers'],
    ];

    public function up(): void
    {
        Schema::table('stock_transfer_headers', function (Blueprint $table) {
            if (!Schema::hasColumn('stock_transfer_headers', 'from_warehouse_id')) {
                $table->uuid('from_warehouse_id')->nullable()->index()->after('from_branch_id');
            }
            if (!Schema::hasColumn('stock_transfer_headers', 'to_warehouse_id')) {
                $table->uuid('to_warehouse_id')->nullable()->index()->after('to_branch_id');
            }
        });

        $now = now();
        foreach ($this->permissions as [$name, $group, $displayName]) {
            $permission = DB::table('permissions')->where('name', $name)->first();
            if ($permission) {
                DB::table('permissions')->where('id', $permission->id)->update([
                    'group' => $group, 'description' => $displayName, 'updated_at' => $now,
                ]);
            } else {
                DB::table('permissions')->insert([
                    'id' => (string) Str::uuid(), 'name' => $name, 'group' => $group,
                    'description' => $displayName, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        $permissionIds = DB::table('permissions')->whereIn('name', array_column($this->permissions, 0))->pluck('id');
        $superRoles = DB::table('roles')->whereRaw("LOWER(REPLACE(REPLACE(TRIM(name), ' ', '_'), '-', '_')) IN ('super_admin', 'super_administrator')")->get();
        foreach ($superRoles as $role) {
            foreach ($permissionIds as $permissionId) {
                if (!DB::table('role_permissions')->where('role_id', $role->id)->where('permission_id', $permissionId)->exists()) {
                    DB::table('role_permissions')->insert([
                        'id' => (string) Str::uuid(), 'role_id' => $role->id, 'permission_id' => $permissionId,
                        'company_id' => $role->company_id, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        }

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            foreach ([
                ['stock_transfer_requires_approval', 'true', 'boolean', 'Require an authorised approver before warehouse dispatch'],
                ['stock_transfer_allow_requester_approval', 'false', 'boolean', 'Allow the requester to approve their own warehouse transfer'],
            ] as [$key, $value, $type, $description]) {
                if (!DB::table('general_settings')->where('company_id', $companyId)->whereNull('branch_id')->where('setting_key', $key)->exists()) {
                    DB::table('general_settings')->insert([
                        'id' => (string) Str::uuid(), 'company_id' => $companyId, 'branch_id' => null,
                        'setting_key' => $key, 'setting_value' => $value, 'setting_type' => $type,
                        'module' => 'inventory', 'description' => $description, 'is_active' => true,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
            app(InventoryBatchSynchronizer::class)->syncCompany($companyId);
        }
    }

    public function down(): void
    {
        DB::table('general_settings')->whereIn('setting_key', [
            'stock_transfer_requires_approval', 'stock_transfer_allow_requester_approval',
        ])->delete();
        $ids = DB::table('permissions')->whereIn('name', array_column($this->permissions, 0))->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        Schema::table('stock_transfer_headers', function (Blueprint $table) {
            $columns = array_values(array_filter(['from_warehouse_id', 'to_warehouse_id'], fn ($column) => Schema::hasColumn('stock_transfer_headers', $column)));
            if ($columns) $table->dropColumn($columns);
        });
    }
};
