<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pos_cash_drawer_movements')) {
            Schema::create('pos_cash_drawer_movements', function (Blueprint $table) {
                $table->char('id', 36)->primary();
                $table->char('company_id', 36)->index();
                $table->char('branch_id', 36)->index();
                $table->char('till_id', 36)->nullable()->index();
                $table->char('shift_session_id', 36)->nullable()->index();
                $table->char('cashier_id', 36)->nullable()->index();

                $table->enum('movement_type', [
                    'cash_in',
                    'cash_out',
                    'float_in',
                    'float_out',
                    'bank_drop',
                    'paid_out',
                    'petty_cash',
                ])->index();

                $table->decimal('amount', 18, 4)->default(0);
                $table->string('currency_code', 10)->default('USD')->index();
                $table->string('reason')->nullable();
                $table->text('notes')->nullable();
                $table->string('reference_no')->nullable();
                $table->char('approved_by', 36)->nullable()->index();
                $table->char('created_by', 36)->nullable()->index();
                $table->timestamp('occurred_at')->nullable()->index();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (Schema::hasTable('pos_cashups')) {
            Schema::table('pos_cashups', function (Blueprint $table) {
                if (!Schema::hasColumn('pos_cashups', 'cashout_total')) {
                    $table->decimal('cashout_total', 18, 4)->default(0)->after('cash_variance');
                }
                if (!Schema::hasColumn('pos_cashups', 'cashout_count')) {
                    $table->integer('cashout_count')->default(0)->after('cashout_total');
                }
                if (!Schema::hasColumn('pos_cashups', 'expected_cash_after_cashouts')) {
                    $table->decimal('expected_cash_after_cashouts', 18, 4)->default(0)->after('cashout_count');
                }
                if (!Schema::hasColumn('pos_cashups', 'cashout_summary')) {
                    $table->json('cashout_summary')->nullable()->after('sales_summary');
                }
                if (!Schema::hasColumn('pos_cashups', 'shift_policy_snapshot')) {
                    $table->json('shift_policy_snapshot')->nullable()->after('cashout_summary');
                }
                if (!Schema::hasColumn('pos_cashups', 'force_close_reason')) {
                    $table->text('force_close_reason')->nullable()->after('shift_policy_snapshot');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pos_cashups')) {
            Schema::table('pos_cashups', function (Blueprint $table) {
                foreach ([
                             'cashout_total',
                             'cashout_count',
                             'expected_cash_after_cashouts',
                             'cashout_summary',
                             'shift_policy_snapshot',
                             'force_close_reason',
                         ] as $column) {
                    if (Schema::hasColumn('pos_cashups', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('pos_cash_drawer_movements');
    }
};
