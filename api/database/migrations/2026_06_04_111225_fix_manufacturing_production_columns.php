<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Production Order Inputs
        |--------------------------------------------------------------------------
        | Needed by ProductionOrderService:
        | - reserveMaterials()
        | - issueMaterials()
        | - returnMaterial()
        | - recordWastage()
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
                $table->decimal('total_cost', 18, 4)->default(0)->after('unit_cost');
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
        | Production Order Outputs
        |--------------------------------------------------------------------------
        | Needed by ProductionOrderService@complete()
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
                $table->decimal('total_cost', 18, 4)->default(0)->after('unit_cost');
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
        | Production Orders
        |--------------------------------------------------------------------------
        | Ensure actual completion/costing fields exist.
        */

        Schema::table('production_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('production_orders', 'actual_good_quantity')) {
                $table->decimal('actual_good_quantity', 18, 4)->default(0)->after('planned_output_quantity');
            }

            if (!Schema::hasColumn('production_orders', 'actual_waste_quantity')) {
                $table->decimal('actual_waste_quantity', 18, 4)->default(0)->after('actual_good_quantity');
            }

            if (!Schema::hasColumn('production_orders', 'actual_reject_quantity')) {
                $table->decimal('actual_reject_quantity', 18, 4)->default(0)->after('actual_waste_quantity');
            }

            if (!Schema::hasColumn('production_orders', 'actual_raw_material_cost')) {
                $table->decimal('actual_raw_material_cost', 18, 4)->default(0)->after('planned_unit_cost');
            }

            if (!Schema::hasColumn('production_orders', 'actual_labour_cost')) {
                $table->decimal('actual_labour_cost', 18, 4)->default(0)->after('actual_raw_material_cost');
            }

            if (!Schema::hasColumn('production_orders', 'actual_overhead_cost')) {
                $table->decimal('actual_overhead_cost', 18, 4)->default(0)->after('actual_labour_cost');
            }

            if (!Schema::hasColumn('production_orders', 'actual_total_cost')) {
                $table->decimal('actual_total_cost', 18, 4)->default(0)->after('actual_overhead_cost');
            }

            if (!Schema::hasColumn('production_orders', 'actual_unit_cost')) {
                $table->decimal('actual_unit_cost', 18, 6)->default(0)->after('actual_total_cost');
            }

            if (!Schema::hasColumn('production_orders', 'yield_variance_quantity')) {
                $table->decimal('yield_variance_quantity', 18, 4)->default(0)->after('actual_unit_cost');
            }

            if (!Schema::hasColumn('production_orders', 'cost_variance_amount')) {
                $table->decimal('cost_variance_amount', 18, 4)->default(0)->after('yield_variance_quantity');
            }

            if (!Schema::hasColumn('production_orders', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('started_at');
            }
        });

        /*
        |--------------------------------------------------------------------------
        | Stock Movements Enum Fix
        |--------------------------------------------------------------------------
        | Your service uses:
        | - production_issue
        | - production_return
        | - production_wastage
        | - production_output
        |
        | Your DB enum currently only allows production_order.
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
                'production_issue',
                'production_return',
                'production_wastage',
                'production_output'
            ) NOT NULL
        ");
    }

    public function down(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Keep down() safe.
        | Do not drop production data columns automatically because they may contain
        | real stock/costing history after production starts running.
        |--------------------------------------------------------------------------
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
                'production_order'
            ) NOT NULL
        ");
    }
};
