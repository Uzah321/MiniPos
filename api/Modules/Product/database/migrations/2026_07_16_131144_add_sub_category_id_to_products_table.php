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
         * Keep every movement_type already existing in your database.
         * Only add the new enterprise issue movement types.
         */
        DB::statement("
            ALTER TABLE stock_movements
            MODIFY movement_type ENUM(
                'manufacturing_in',
                'manufacturing_out',

                'boh_purchase_receive',
                'boh_issue_to_front',
                'boh_issue_to_expense',
                'boh_issue_to_department',
                'boh_issue_to_production',

                'stock_take',
                'sale_cancel',
                'opening',
                'sale',
                'purchase',
                'adjustment',
                'return',
                'purchase_return',

                'transfer',
                'transfer_in',
                'transfer_out',

                'break_parent',
                'create_child',

                'recipe_sale_consumption',
                'modifier_recipe_consumption',

                'production_order',
                'production_material_reserve',
                'production_issue',
                'production_return',
                'production_wastage',
                'production_output',
                'production_adjustment',
                'production_cancel',

                'back',
                'front'
            ) NOT NULL
        ");

        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                if (!Schema::hasColumn('products', 'stock_usage_type')) {
                    $table->string('stock_usage_type', 30)
                        ->default('turnover')
                        ->after('product_type');
                }

                if (!Schema::hasColumn('products', 'default_issue_destination')) {
                    $table->string('default_issue_destination', 40)
                        ->nullable()
                        ->after('stock_usage_type');
                }

                if (!Schema::hasColumn('products', 'expense_account_code')) {
                    $table->string('expense_account_code', 80)
                        ->nullable()
                        ->after('default_issue_destination');
                }
            });
        }

        if (Schema::hasTable('stock_movements')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                if (!Schema::hasColumn('stock_movements', 'issue_destination')) {
                    $table->string('issue_destination', 40)
                        ->nullable()
                        ->after('to_stock_area');
                }

                if (!Schema::hasColumn('stock_movements', 'stock_usage_type')) {
                    $table->string('stock_usage_type', 30)
                        ->nullable()
                        ->after('issue_destination');
                }

                if (!Schema::hasColumn('stock_movements', 'expense_account_code')) {
                    $table->string('expense_account_code', 80)
                        ->nullable()
                        ->after('stock_usage_type');
                }
            });
        }

        /*
         * Existing consumables should default to non-turnover.
         * These are received into BOH/back stock, then issued directly to expense/department use.
         */
        DB::table('products')
            ->whereIn('product_type', ['consumable', 'consumables'])
            ->update([
                'stock_usage_type' => 'non_turnover',
                'default_issue_destination' => 'operating_expense',
            ]);

        /*
         * Turnover stock remains stock used for production/sale/front movement.
         */
        DB::table('products')
            ->whereIn('product_type', [
                'raw_material',
                'semi_finished',
                'semi_finished_good',
                'packaging',
                'finished_good',
                'simple',
                'child',
                'parent',
            ])
            ->where(function ($query) {
                $query->whereNull('stock_usage_type')
                    ->orWhere('stock_usage_type', '');
            })
            ->update([
                'stock_usage_type' => 'turnover',
            ]);
    }

    public function down(): void
    {
        /*
         * Do not remove enum values in down().
         * Your database already has these movement types in historical rows.
         */
        DB::statement("
            ALTER TABLE stock_movements
            MODIFY movement_type ENUM(
                'manufacturing_in',
                'manufacturing_out',

                'boh_purchase_receive',
                'boh_issue_to_front',
                'boh_issue_to_expense',
                'boh_issue_to_department',
                'boh_issue_to_production',

                'stock_take',
                'sale_cancel',
                'opening',
                'sale',
                'purchase',
                'adjustment',
                'return',
                'purchase_return',

                'transfer',
                'transfer_in',
                'transfer_out',

                'break_parent',
                'create_child',

                'recipe_sale_consumption',
                'modifier_recipe_consumption',

                'production_order',
                'production_material_reserve',
                'production_issue',
                'production_return',
                'production_wastage',
                'production_output',
                'production_adjustment',
                'production_cancel',

                'back',
                'front'
            ) NOT NULL
        ");

        if (Schema::hasTable('stock_movements')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                if (Schema::hasColumn('stock_movements', 'expense_account_code')) {
                    $table->dropColumn('expense_account_code');
                }

                if (Schema::hasColumn('stock_movements', 'stock_usage_type')) {
                    $table->dropColumn('stock_usage_type');
                }

                if (Schema::hasColumn('stock_movements', 'issue_destination')) {
                    $table->dropColumn('issue_destination');
                }
            });
        }

        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                if (Schema::hasColumn('products', 'expense_account_code')) {
                    $table->dropColumn('expense_account_code');
                }

                if (Schema::hasColumn('products', 'default_issue_destination')) {
                    $table->dropColumn('default_issue_destination');
                }

                if (Schema::hasColumn('products', 'stock_usage_type')) {
                    $table->dropColumn('stock_usage_type');
                }
            });
        }
    }
};
