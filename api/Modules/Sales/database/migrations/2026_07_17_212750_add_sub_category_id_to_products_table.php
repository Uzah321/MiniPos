<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('sales_transactions')) {
            return;
        }

        Schema::table('sales_transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('sales_transactions', 'credit_customer_id')) {
                $table->uuid('credit_customer_id')->nullable()->index()->after('customer_tax_number');
            }
            if (!Schema::hasColumn('sales_transactions', 'credit_account_number')) {
                $table->string('credit_account_number')->nullable()->index()->after('credit_customer_id');
            }
            if (!Schema::hasColumn('sales_transactions', 'is_credit_sale')) {
                $table->boolean('is_credit_sale')->default(false)->index()->after('credit_account_number');
            }
            if (!Schema::hasColumn('sales_transactions', 'credit_status')) {
                $table->string('credit_status', 30)->nullable()->index()->after('is_credit_sale');
            }
            if (!Schema::hasColumn('sales_transactions', 'credit_amount')) {
                $table->decimal('credit_amount', 18, 4)->default(0)->after('credit_status');
            }
            if (!Schema::hasColumn('sales_transactions', 'credit_paid_amount')) {
                $table->decimal('credit_paid_amount', 18, 4)->default(0)->after('credit_amount');
            }
            if (!Schema::hasColumn('sales_transactions', 'credit_balance_amount')) {
                $table->decimal('credit_balance_amount', 18, 4)->default(0)->after('credit_paid_amount');
            }
            if (!Schema::hasColumn('sales_transactions', 'credit_due_date')) {
                $table->date('credit_due_date')->nullable()->index()->after('credit_balance_amount');
            }
        });

        if (Schema::hasTable('credit_customer_ledgers')) {
            DB::statement("\n                UPDATE sales_transactions st\n                JOIN credit_customer_ledgers l\n                    ON l.sales_transaction_id = st.id\n                   AND l.entry_type = 'sale'\n                   AND COALESCE(l.debit_amount, 0) > 0\n                SET st.is_credit_sale = 1,\n                    st.credit_customer_id = COALESCE(st.credit_customer_id, l.credit_customer_id),\n                    st.credit_status = COALESCE(st.credit_status, 'posted'),\n                    st.credit_amount = CASE WHEN COALESCE(st.credit_amount, 0) = 0 THEN l.debit_amount ELSE st.credit_amount END,\n                    st.credit_balance_amount = CASE WHEN COALESCE(st.credit_balance_amount, 0) = 0 THEN l.debit_amount ELSE st.credit_balance_amount END,\n                    st.credit_due_date = COALESCE(st.credit_due_date, l.due_date)\n            ");
        }

        if (Schema::hasTable('credit_customer_payment_allocations')) {
            $columns = Schema::getColumnListing('credit_customer_payment_allocations');
            $amountExpr = in_array('allocated_amount', $columns, true) && in_array('amount', $columns, true)
                ? 'COALESCE(NULLIF(allocated_amount, 0), amount, 0)'
                : (in_array('allocated_amount', $columns, true) ? 'COALESCE(allocated_amount, 0)' : 'COALESCE(amount, 0)');

            DB::statement("\n                UPDATE sales_transactions st\n                JOIN (\n                    SELECT sales_transaction_id, SUM({$amountExpr}) AS paid\n                    FROM credit_customer_payment_allocations\n                    WHERE sales_transaction_id IS NOT NULL\n                    GROUP BY sales_transaction_id\n                ) a ON a.sales_transaction_id = st.id\n                SET st.credit_paid_amount = LEAST(COALESCE(st.credit_amount, st.total_amount, 0), COALESCE(a.paid, 0)),\n                    st.credit_balance_amount = GREATEST(COALESCE(st.credit_amount, st.total_amount, 0) - COALESCE(a.paid, 0), 0),\n                    st.credit_status = CASE\n                        WHEN GREATEST(COALESCE(st.credit_amount, st.total_amount, 0) - COALESCE(a.paid, 0), 0) <= 0 THEN 'paid'\n                        WHEN COALESCE(a.paid, 0) > 0 THEN 'partially_paid'\n                        ELSE 'posted'\n                    END\n                WHERE COALESCE(st.is_credit_sale, 0) = 1\n            ");
        }

        if (Schema::hasTable('sales_transaction_payments')) {
            DB::statement("\n                UPDATE sales_transaction_payments p\n                JOIN sales_transactions st ON st.id = p.sales_transaction_id\n                SET p.amount = 0,\n                    p.base_amount = 0,\n                    p.amount_tendered = 0,\n                    p.change_amount = 0\n                WHERE COALESCE(st.is_credit_sale, 0) = 1\n                  AND (\n                        LOWER(COALESCE(p.money_code, '')) IN ('customer_credit', 'credit_customer', 'credit_account', 'account')\n                        OR LOWER(COALESCE(p.payment_name, '')) LIKE '%credit%'\n                      )\n            ");
        }
    }

    public function down(): void
    {
        // Data repair migration: do not remove columns or restore fake payment amounts.
    }
};
