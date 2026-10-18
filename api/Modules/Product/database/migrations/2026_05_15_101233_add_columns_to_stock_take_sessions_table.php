<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stock_take_sessions')) {
            Schema::table('stock_take_sessions', function (Blueprint $table) {
                if (!Schema::hasColumn('stock_take_sessions', 'mode')) {
                    $table->string('mode', 30)->default('normal')->index()->after('status');
                }

                if (!Schema::hasColumn('stock_take_sessions', 'zeroed_at')) {
                    $table->timestamp('zeroed_at')->nullable()->after('uploaded_at');
                }

                if (!Schema::hasColumn('stock_take_sessions', 'trading_started_at')) {
                    $table->timestamp('trading_started_at')->nullable()->after('zeroed_at');
                }

                if (!Schema::hasColumn('stock_take_sessions', 'trading_completed_at')) {
                    $table->timestamp('trading_completed_at')->nullable()->after('trading_started_at');
                }

                if (!Schema::hasColumn('stock_take_sessions', 'snapshot_uploaded_at')) {
                    $table->timestamp('snapshot_uploaded_at')->nullable()->after('trading_completed_at');
                }

                if (!Schema::hasColumn('stock_take_sessions', 'count_uploaded_at')) {
                    $table->timestamp('count_uploaded_at')->nullable()->after('snapshot_uploaded_at');
                }
            });
        }

        if (Schema::hasTable('stock_take_lines')) {
            Schema::table('stock_take_lines', function (Blueprint $table) {
                if (!Schema::hasColumn('stock_take_lines', 'snapshot_quantity')) {
                    $table->decimal('snapshot_quantity', 18, 4)->default(0)->after('uom_name');
                }

                if (!Schema::hasColumn('stock_take_lines', 'trading_quantity')) {
                    $table->decimal('trading_quantity', 18, 4)->default(0)->after('variance_quantity');
                }

                if (!Schema::hasColumn('stock_take_lines', 'trading_sales_quantity')) {
                    $table->decimal('trading_sales_quantity', 18, 4)->default(0)->after('trading_quantity');
                }

                if (!Schema::hasColumn('stock_take_lines', 'trading_returns_quantity')) {
                    $table->decimal('trading_returns_quantity', 18, 4)->default(0)->after('trading_sales_quantity');
                }

                if (!Schema::hasColumn('stock_take_lines', 'final_quantity')) {
                    $table->decimal('final_quantity', 18, 4)->default(0)->after('trading_returns_quantity');
                }

                if (!Schema::hasColumn('stock_take_lines', 'posted_quantity_adjustment')) {
                    $table->decimal('posted_quantity_adjustment', 18, 4)->default(0)->after('final_quantity');
                }

                if (!Schema::hasColumn('stock_take_lines', 'original_allow_negative_stock')) {
                    $table->tinyInteger('original_allow_negative_stock')->nullable()->after('posted_quantity_adjustment');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('stock_take_lines')) {
            Schema::table('stock_take_lines', function (Blueprint $table) {
                foreach ([
                             'snapshot_quantity',
                             'trading_quantity',
                             'trading_sales_quantity',
                             'trading_returns_quantity',
                             'final_quantity',
                             'posted_quantity_adjustment',
                             'original_allow_negative_stock',
                         ] as $column) {
                    if (Schema::hasColumn('stock_take_lines', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('stock_take_sessions')) {
            Schema::table('stock_take_sessions', function (Blueprint $table) {
                foreach ([
                             'mode',
                             'zeroed_at',
                             'trading_started_at',
                             'trading_completed_at',
                             'snapshot_uploaded_at',
                             'count_uploaded_at',
                         ] as $column) {
                    if (Schema::hasColumn('stock_take_sessions', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
