<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const PERMISSIONS = [
        ['view_inventory_variance_agent', 'inventory', 'View Inventory Variance Agent'],
        ['manage_inventory_variance_agent', 'inventory', 'Configure and run Inventory Variance Agent'],
    ];

    private const SETTINGS = [
        ['inventory_variance_agent_enabled', 'false', 'boolean', 'Enable the Inventory Variance Agent for this company'],
        ['inventory_variance_quantity_threshold', '0.0001', 'decimal', 'Minimum unexplained quantity difference that opens a variance'],
        ['inventory_variance_value_threshold', '0', 'decimal', 'Optional cost exposure threshold used to prioritize severity'],
        ['inventory_variance_scan_interval_minutes', '5', 'integer', 'Minimum interval between automatic overview scans in minutes'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('inventory_variance_cycles')) {
            Schema::create('inventory_variance_cycles', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('company_id')->index();
                $table->uuid('branch_id')->index();
                $table->uuid('product_id')->index();
                $table->string('stock_area', 20)->default('front');
                $table->string('status', 20)->default('active')->index();
                // NULL is used for closed cycles so the unique key permits unlimited
                // history while still enforcing one live baseline per stock balance.
                $table->boolean('active_key')->nullable()->default(true);
                $table->decimal('baseline_quantity', 20, 6)->default(0);
                $table->decimal('baseline_ledger_quantity', 20, 6)->default(0);
                $table->unsignedBigInteger('baseline_movement_count')->default(0);
                $table->timestamp('baseline_at')->index();
                $table->timestamp('last_consistent_at')->nullable();
                $table->string('baseline_reason', 80);
                $table->string('source_type', 120)->nullable();
                $table->string('source_id', 120)->nullable();
                $table->uuid('opened_by')->nullable();
                $table->timestamp('last_scanned_at')->nullable()->index();
                $table->timestamp('closed_at')->nullable();
                $table->string('close_reason', 80)->nullable();
                $table->string('closed_source_type', 120)->nullable();
                $table->string('closed_source_id', 120)->nullable();
                $table->uuid('closed_by')->nullable();
                $table->timestamps();

                $table->index(
                    ['company_id', 'branch_id', 'product_id', 'stock_area', 'status'],
                    'variance_cycles_scope_status_index'
                );
                $table->unique(
                    ['company_id', 'branch_id', 'product_id', 'stock_area', 'active_key'],
                    'variance_cycles_one_active_unique'
                );
            });
        }

        if (! Schema::hasTable('inventory_variances')) {
            Schema::create('inventory_variances', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('cycle_id')->index();
                $table->uuid('company_id')->index();
                $table->uuid('branch_id')->index();
                $table->uuid('product_id')->index();
                $table->string('stock_area', 20)->default('front');
                $table->string('status', 20)->default('open')->index();
                $table->string('direction', 20)->index();
                $table->string('severity', 20)->default('low')->index();
                $table->decimal('expected_quantity', 20, 6)->default(0);
                $table->decimal('actual_quantity', 20, 6)->default(0);
                $table->decimal('variance_quantity', 20, 6)->default(0);
                $table->decimal('absolute_variance_quantity', 20, 6)->default(0);
                $table->decimal('unit_cost', 20, 6)->default(0);
                $table->decimal('exposure_value', 20, 6)->default(0);
                $table->string('currency_code', 8)->nullable();
                $table->timestamp('started_at')->index();
                // MariaDB 10.4 with strict NO_ZERO_DATE rejects a second required
                // TIMESTAMP without an explicit default. DATETIME keeps the
                // application-supplied instant without implicit server defaults.
                $table->dateTime('first_detected_at');
                $table->dateTime('last_detected_at')->index();
                $table->unsignedInteger('detection_count')->default(1);
                $table->json('evidence')->nullable();
                $table->timestamp('resolved_at')->nullable()->index();
                $table->string('resolution_reason', 80)->nullable();
                $table->string('resolution_source_type', 120)->nullable();
                $table->string('resolution_source_id', 120)->nullable();
                $table->uuid('resolved_by')->nullable();
                $table->timestamps();

                $table->unique(['cycle_id', 'status'], 'variance_cycle_status_unique');
                $table->index(
                    ['company_id', 'branch_id', 'status', 'severity'],
                    'variances_scope_status_severity_index'
                );
            });
        }

        if (! Schema::hasTable('inventory_variance_events')) {
            Schema::create('inventory_variance_events', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('company_id')->index();
                $table->uuid('branch_id')->nullable()->index();
                $table->uuid('product_id')->nullable()->index();
                $table->string('stock_area', 20)->nullable();
                $table->uuid('cycle_id')->nullable()->index();
                $table->uuid('variance_id')->nullable()->index();
                $table->string('event_type', 80)->index();
                $table->string('source_type', 120)->nullable();
                $table->string('source_id', 120)->nullable();
                $table->uuid('actor_id')->nullable();
                $table->json('payload')->nullable();
                $table->timestamp('occurred_at')->index();
                $table->timestamps();

                $table->index(
                    ['company_id', 'branch_id', 'product_id', 'stock_area', 'occurred_at'],
                    'variance_events_scope_occurred_index'
                );
            });
        }

        if (! Schema::hasTable('inventory_variance_scan_runs')) {
            Schema::create('inventory_variance_scan_runs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('company_id')->index();
                $table->string('status', 20)->default('running')->index();
                $table->string('trigger', 40)->default('scheduled');
                $table->json('branch_ids')->nullable();
                $table->char('branch_scope_key', 64)->index();
                $table->uuid('last_stock_id')->nullable();
                $table->uuid('triggered_by')->nullable();
                $table->unsignedInteger('products_scanned')->default(0);
                $table->unsignedInteger('baselines_created')->default(0);
                $table->unsignedInteger('variances_opened')->default(0);
                $table->unsignedInteger('variances_updated')->default(0);
                $table->text('error_message')->nullable();
                $table->timestamp('started_at')->index();
                $table->timestamp('completed_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasIndex('stock_movements', 'variance_movements_scope_area_index')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->index(
                    ['company_id', 'branch_id', 'product_id', 'stock_area'],
                    'variance_movements_scope_area_index'
                );
            });
        }

        $this->seedPermissionsAndSettings();
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex('variance_movements_scope_area_index');
        });
        if (Schema::hasTable('general_settings')) {
            DB::table('general_settings')
                ->whereIn('setting_key', array_column(self::SETTINGS, 0))
                ->delete();
        }

        if (Schema::hasTable('permissions')) {
            $permissionIds = DB::table('permissions')
                ->whereIn('name', array_column(self::PERMISSIONS, 0))
                ->pluck('id');

            if (Schema::hasTable('role_permissions')) {
                DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
            }

            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }

        Schema::dropIfExists('inventory_variance_scan_runs');
        Schema::dropIfExists('inventory_variance_events');
        Schema::dropIfExists('inventory_variances');
        Schema::dropIfExists('inventory_variance_cycles');
    }

    private function seedPermissionsAndSettings(): void
    {
        $now = now();

        if (Schema::hasTable('permissions')) {
            foreach (self::PERMISSIONS as [$name, $group, $description]) {
                $existing = DB::table('permissions')->where('name', $name)->first();
                if ($existing) {
                    DB::table('permissions')->where('id', $existing->id)->update([
                        'group' => $group,
                        'description' => $description,
                        'updated_at' => $now,
                    ]);
                } else {
                    DB::table('permissions')->insert([
                        'id' => (string) Str::uuid(),
                        'name' => $name,
                        'group' => $group,
                        'description' => $description,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            if (Schema::hasTable('roles') && Schema::hasTable('role_permissions')) {
                $permissionIds = DB::table('permissions')
                    ->whereIn('name', array_column(self::PERMISSIONS, 0))
                    ->pluck('id');
                $superRoles = DB::table('roles')
                    ->whereRaw("LOWER(REPLACE(REPLACE(TRIM(name), ' ', '_'), '-', '_')) IN ('super_admin', 'super_administrator')")
                    ->get();

                foreach ($superRoles as $role) {
                    foreach ($permissionIds as $permissionId) {
                        if (DB::table('role_permissions')
                            ->where('role_id', $role->id)
                            ->where('permission_id', $permissionId)
                            ->exists()) {
                            continue;
                        }

                        DB::table('role_permissions')->insert([
                            'id' => (string) Str::uuid(),
                            'role_id' => $role->id,
                            'permission_id' => $permissionId,
                            'company_id' => $role->company_id,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            }
        }

        if (! Schema::hasTable('companies') || ! Schema::hasTable('general_settings')) {
            return;
        }

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            foreach (self::SETTINGS as [$key, $value, $type, $description]) {
                if (DB::table('general_settings')
                    ->where('company_id', $companyId)
                    ->whereNull('branch_id')
                    ->where('setting_key', $key)
                    ->exists()) {
                    continue;
                }

                DB::table('general_settings')->insert([
                    'id' => (string) Str::uuid(),
                    'company_id' => $companyId,
                    'branch_id' => null,
                    'setting_key' => $key,
                    'setting_value' => $value,
                    'setting_type' => $type,
                    'module' => 'inventory',
                    'description' => $description,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
};
