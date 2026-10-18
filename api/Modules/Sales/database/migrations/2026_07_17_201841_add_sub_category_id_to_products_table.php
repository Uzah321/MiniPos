<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('credit_customer_payment_allocations')) {
            return;
        }

        Schema::table('credit_customer_payment_allocations', function (Blueprint $table) {
            if (!Schema::hasColumn('credit_customer_payment_allocations', 'receipt_line_id')) {
                $table->uuid('receipt_line_id')->nullable()->index()->after('sales_transaction_id');
            }

            if (!Schema::hasColumn('credit_customer_payment_allocations', 'product_id')) {
                $table->uuid('product_id')->nullable()->index()->after('receipt_line_id');
            }

            if (!Schema::hasColumn('credit_customer_payment_allocations', 'invoice_number')) {
                $table->string('invoice_number')->nullable()->index()->after('document_number');
            }

            if (!Schema::hasColumn('credit_customer_payment_allocations', 'payment_number')) {
                $table->string('payment_number')->nullable()->index()->after('invoice_number');
            }

            if (!Schema::hasColumn('credit_customer_payment_allocations', 'payment_date')) {
                $table->date('payment_date')->nullable()->index()->after('payment_number');
            }

            if (!Schema::hasColumn('credit_customer_payment_allocations', 'base_currency_code')) {
                $table->string('base_currency_code', 10)->nullable()->after('currency_code');
            }

            if (!Schema::hasColumn('credit_customer_payment_allocations', 'allocated_amount')) {
                $table->decimal('allocated_amount', 18, 4)->default(0)->after('base_currency_code');
            }

            if (!Schema::hasColumn('credit_customer_payment_allocations', 'base_allocated_amount')) {
                $table->decimal('base_allocated_amount', 18, 4)->default(0)->after('allocated_amount');
            }

            if (!Schema::hasColumn('credit_customer_payment_allocations', 'allocation_status')) {
                $table->string('allocation_status', 30)->default('posted')->index()->after('base_allocated_amount');
            }

            if (!Schema::hasColumn('credit_customer_payment_allocations', 'reversed_at')) {
                $table->timestamp('reversed_at')->nullable()->after('allocation_status');
            }

            if (!Schema::hasColumn('credit_customer_payment_allocations', 'reversed_by')) {
                $table->uuid('reversed_by')->nullable()->after('reversed_at');
            }

            if (!Schema::hasColumn('credit_customer_payment_allocations', 'reversal_reason')) {
                $table->string('reversal_reason', 500)->nullable()->after('reversed_by');
            }

            if (!Schema::hasColumn('credit_customer_payment_allocations', 'metadata')) {
                $table->json('metadata')->nullable()->after('reversal_reason');
            }
        });

        $columns = Schema::getColumnListing('credit_customer_payment_allocations');

        if (in_array('allocated_amount', $columns, true) && in_array('amount', $columns, true)) {
            DB::table('credit_customer_payment_allocations')
                ->where(function ($query) {
                    $query->whereNull('allocated_amount')->orWhere('allocated_amount', 0);
                })
                ->update(['allocated_amount' => DB::raw('amount')]);
        }

        if (in_array('base_allocated_amount', $columns, true)) {
            DB::table('credit_customer_payment_allocations')
                ->where(function ($query) {
                    $query->whereNull('base_allocated_amount')->orWhere('base_allocated_amount', 0);
                })
                ->update(['base_allocated_amount' => DB::raw(
                    in_array('amount', $columns, true) ? 'amount' : 'allocated_amount'
                )]);
        }

        if (in_array('base_currency_code', $columns, true) && in_array('currency_code', $columns, true)) {
            DB::table('credit_customer_payment_allocations')
                ->whereNull('base_currency_code')
                ->update(['base_currency_code' => DB::raw('currency_code')]);
        }

        if (in_array('invoice_number', $columns, true) && in_array('document_number', $columns, true)) {
            DB::table('credit_customer_payment_allocations')
                ->whereNull('invoice_number')
                ->update(['invoice_number' => DB::raw('document_number')]);
        }

        if (in_array('payment_date', $columns, true) && in_array('created_at', $columns, true)) {
            DB::table('credit_customer_payment_allocations')
                ->whereNull('payment_date')
                ->update(['payment_date' => DB::raw('DATE(created_at)')]);
        }

        if (in_array('allocation_status', $columns, true)) {
            DB::table('credit_customer_payment_allocations')
                ->whereNull('allocation_status')
                ->update(['allocation_status' => 'posted']);
        }

        if (Schema::hasTable('credit_customer_payments') && in_array('payment_number', $columns, true)) {
            DB::statement("\n                UPDATE credit_customer_payment_allocations a\n                INNER JOIN credit_customer_payments p ON p.id = a.credit_customer_payment_id\n                SET a.payment_number = p.payment_number\n                WHERE a.payment_number IS NULL\n            ");
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('credit_customer_payment_allocations')) {
            return;
        }

        Schema::table('credit_customer_payment_allocations', function (Blueprint $table) {
            foreach ([
                         'metadata',
                         'reversal_reason',
                         'reversed_by',
                         'reversed_at',
                         'allocation_status',
                         'base_allocated_amount',
                         'allocated_amount',
                         'base_currency_code',
                         'payment_date',
                         'payment_number',
                         'invoice_number',
                         'product_id',
                         'receipt_line_id',
                     ] as $column) {
                if (Schema::hasColumn('credit_customer_payment_allocations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
