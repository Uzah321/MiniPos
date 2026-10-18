<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | production_order_inputs
        |--------------------------------------------------------------------------
        */

        Schema::table('production_order_inputs', function (Blueprint $table) {
            if (!Schema::hasColumn('production_order_inputs', 'reserved_quantity')) {
                $table->decimal('reserved_quantity', 18, 4)->default(0)->after('planned_quantity');
            }

            if (!Schema::hasColumn('production_order_inputs', 'issued_quantity')) {
                $table->decimal('issued_quantity', 18, 4)->default(0)->after('reserved_quantity');
            }

            if (!Schema::hasColumn('production_order_inputs', 'returned_quantity')) {
                $table->decimal('returned_quantity', 18, 4)->default(0)->after('issued_quantity');
            }

            if (!Schema::hasColumn('production_order_inputs', 'wasted_quantity')) {
                $table->decimal('wasted_quantity', 18, 4)->default(0)->after('returned_quantity');
            }

            if (!Schema::hasColumn('production_order_inputs', 'unit_cost')) {
                $table->decimal('unit_cost', 18, 6)->default(0)->after('wasted_quantity');
            }

            if (!Schema::hasColumn('production_order_inputs', 'total_cost')) {
                $table->decimal('total_cost', 18, 6)->default(0)->after('unit_cost');
            }

            if (!Schema::hasColumn('production_order_inputs', 'currency')) {
                $table->string('currency', 3)->default('USD')->after('total_cost');
            }

            if (!Schema::hasColumn('production_order_inputs', 'metadata')) {
                $table->json('metadata')->nullable()->after('currency');
            }
        });

        /*
        |--------------------------------------------------------------------------
        | production_order_outputs
        |--------------------------------------------------------------------------
        */

        Schema::table('production_order_outputs', function (Blueprint $table) {
            if (!Schema::hasColumn('production_order_outputs', 'quantity_produced')) {
                $table->decimal('quantity_produced', 18, 4)->default(0)->after('uom_id');
            }

            if (!Schema::hasColumn('production_order_outputs', 'quantity_rejected')) {
                $table->decimal('quantity_rejected', 18, 4)->default(0)->after('quantity_produced');
            }

            if (!Schema::hasColumn('production_order_outputs', 'quantity_wasted')) {
                $table->decimal('quantity_wasted', 18, 4)->default(0)->after('quantity_rejected');
            }

            if (!Schema::hasColumn('production_order_outputs', 'unit_cost')) {
                $table->decimal('unit_cost', 18, 6)->default(0)->after('quantity_wasted');
            }

            if (!Schema::hasColumn('production_order_outputs', 'total_cost')) {
                $table->decimal('total_cost', 18, 6)->default(0)->after('unit_cost');
            }

            if (!Schema::hasColumn('production_order_outputs', 'currency')) {
                $table->string('currency', 3)->default('USD')->after('total_cost');
            }

            if (!Schema::hasColumn('production_order_outputs', 'metadata')) {
                $table->json('metadata')->nullable()->after('currency');
            }
        });

        /*
        |--------------------------------------------------------------------------
        | production_orders
        |--------------------------------------------------------------------------
        */

        Schema::table('production_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('production_orders', 'planned_raw_material_cost')) {
                $table->decimal('planned_raw_material_cost', 18, 6)->default(0)->after('planned_output_quantity');
            }

            if (!Schema::hasColumn('production_orders', 'planned_labour_cost')) {
                $table->decimal('planned_labour_cost', 18, 6)->default(0)->after('planned_raw_material_cost');
            }

            if (!Schema::hasColumn('production_orders', 'planned_overhead_cost')) {
                $table->decimal('planned_overhead_cost', 18, 6)->default(0)->after('planned_labour_cost');
            }

            if (!Schema::hasColumn('production_orders', 'planned_total_cost')) {
                $table->decimal('planned_total_cost', 18, 6)->default(0)->after('planned_overhead_cost');
            }

            if (!Schema::hasColumn('production_orders', 'planned_unit_cost')) {
                $table->decimal('planned_unit_cost', 18, 6)->default(0)->after('planned_total_cost');
            }

            if (!Schema::hasColumn('production_orders', 'actual_raw_material_cost')) {
                $table->decimal('actual_raw_material_cost', 18, 6)->default(0)->after('planned_unit_cost');
            }

            if (!Schema::hasColumn('production_orders', 'actual_labour_cost')) {
                $table->decimal('actual_labour_cost', 18, 6)->default(0)->after('actual_raw_material_cost');
            }

            if (!Schema::hasColumn('production_orders', 'actual_overhead_cost')) {
                $table->decimal('actual_overhead_cost', 18, 6)->default(0)->after('actual_labour_cost');
            }

            if (!Schema::hasColumn('production_orders', 'actual_total_cost')) {
                $table->decimal('actual_total_cost', 18, 6)->default(0)->after('actual_overhead_cost');
            }

            if (!Schema::hasColumn('production_orders', 'actual_unit_cost')) {
                $table->decimal('actual_unit_cost', 18, 6)->default(0)->after('actual_total_cost');
            }

            if (!Schema::hasColumn('production_orders', 'actual_good_quantity')) {
                $table->decimal('actual_good_quantity', 18, 4)->default(0)->after('actual_unit_cost');
            }

            if (!Schema::hasColumn('production_orders', 'actual_reject_quantity')) {
                $table->decimal('actual_reject_quantity', 18, 4)->default(0)->after('actual_good_quantity');
            }

            if (!Schema::hasColumn('production_orders', 'actual_waste_quantity')) {
                $table->decimal('actual_waste_quantity', 18, 4)->default(0)->after('actual_reject_quantity');
            }

            if (!Schema::hasColumn('production_orders', 'yield_variance_quantity')) {
                $table->decimal('yield_variance_quantity', 18, 4)->default(0)->after('actual_waste_quantity');
            }

            if (!Schema::hasColumn('production_orders', 'cost_variance_amount')) {
                $table->decimal('cost_variance_amount', 18, 6)->default(0)->after('yield_variance_quantity');
            }

            if (!Schema::hasColumn('production_orders', 'started_at')) {
                $table->timestamp('started_at')->nullable()->after('cost_variance_amount');
            }

            if (!Schema::hasColumn('production_orders', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('started_at');
            }

            if (!Schema::hasColumn('production_orders', 'approved_by')) {
                $table->uuid('approved_by')->nullable()->after('completed_at');
            }
        });

        /*
        |--------------------------------------------------------------------------
        | production_order_overheads
        |--------------------------------------------------------------------------
        */

        Schema::table('production_order_overheads', function (Blueprint $table) {
            if (!Schema::hasColumn('production_order_overheads', 'cost_class')) {
                $table->string('cost_class')->default('factory_overhead')->after('cost_type');
            }

            if (!Schema::hasColumn('production_order_overheads', 'quantity')) {
                $table->decimal('quantity', 18, 4)->default(1)->after('description');
            }

            if (!Schema::hasColumn('production_order_overheads', 'rate')) {
                $table->decimal('rate', 18, 6)->default(0)->after('quantity');
            }

            if (!Schema::hasColumn('production_order_overheads', 'staff_name')) {
                $table->string('staff_name')->nullable()->after('rate');
            }

            if (!Schema::hasColumn('production_order_overheads', 'staff_id')) {
                $table->uuid('staff_id')->nullable()->after('staff_name');
            }

            if (!Schema::hasColumn('production_order_overheads', 'expense_id')) {
                $table->uuid('expense_id')->nullable()->after('currency');
            }

            if (!Schema::hasColumn('production_order_overheads', 'payroll_id')) {
                $table->uuid('payroll_id')->nullable()->after('expense_id');
            }
        });

        /*
        |--------------------------------------------------------------------------
        | stock_movements movement_type enum
        |--------------------------------------------------------------------------
        | Your service uses production_issue, production_return,
        | production_wastage and production_output. The existing enum did not
        | include these, so MySQL truncated the value.
        */

        DB::statement("
            ALTER TABLE stock_movements
            MODIFY movement_type ENUM(
                'stock_take',
                'sale_cancel',
                'opening',
                'sale',
                'purchase',
                'adjustment',
                'transfer_in',
                'transfer_out',
                'break_parent',
                'create_child',
                'return',
                'purchase_return',
                'production_order',
                'production_material_reserve',
                'production_issue',
                'production_return',
                'production_wastage',
                'production_output',
                'production_adjustment',
                'production_cancel'
            ) NOT NULL
        ");
    }

    public function down(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Do not drop columns automatically.
        |--------------------------------------------------------------------------
        | This migration is designed as a safe production patch. Rolling back by
        | dropping columns could destroy production costing history.
        */
    }
};
