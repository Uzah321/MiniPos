<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('credit_customer_payment_allocations')) {
            $columns = Schema::getColumnListing('credit_customer_payment_allocations');

            if (in_array('allocated_amount', $columns, true) && in_array('amount', $columns, true)) {
                DB::table('credit_customer_payment_allocations')
                    ->where(function ($query) {
                        $query->whereNull('allocated_amount')
                            ->orWhere('allocated_amount', 0);
                    })
                    ->update(['allocated_amount' => DB::raw('amount')]);
            }

            if (in_array('base_allocated_amount', $columns, true)) {
                $source = in_array('allocated_amount', $columns, true)
                    ? 'allocated_amount'
                    : (in_array('amount', $columns, true) ? 'amount' : null);

                if ($source) {
                    DB::table('credit_customer_payment_allocations')
                        ->where(function ($query) {
                            $query->whereNull('base_allocated_amount')
                                ->orWhere('base_allocated_amount', 0);
                        })
                        ->update(['base_allocated_amount' => DB::raw($source)]);
                }
            }

            if (in_array('payment_date', $columns, true) && in_array('created_at', $columns, true)) {
                DB::table('credit_customer_payment_allocations')
                    ->whereNull('payment_date')
                    ->update(['payment_date' => DB::raw('DATE(created_at)')]);
            }

            if (in_array('invoice_number', $columns, true) && in_array('document_number', $columns, true)) {
                DB::table('credit_customer_payment_allocations')
                    ->whereNull('invoice_number')
                    ->update(['invoice_number' => DB::raw('document_number')]);
            }
        }

        if (Schema::hasTable('sales_transactions')) {
            $saleColumns = Schema::getColumnListing('sales_transactions');

            if (in_array('is_credit_sale', $saleColumns, true)) {
                $creditSaleIds = DB::table('sales_transactions')
                    ->where('is_credit_sale', 1)
                    ->pluck('id');

                if ($creditSaleIds->isNotEmpty()) {
                    foreach ($creditSaleIds->chunk(500) as $chunk) {
                        $saleUpdates = [];
                        foreach (['amount_tendered', 'change_amount', 'change'] as $column) {
                            if (in_array($column, $saleColumns, true)) {
                                $saleUpdates[$column] = 0;
                            }
                        }

                        if ($saleUpdates) {
                            DB::table('sales_transactions')
                                ->whereIn('id', $chunk->values()->all())
                                ->update($saleUpdates);
                        }

                        if (Schema::hasTable('sales_transaction_payments')) {
                            $paymentColumns = Schema::getColumnListing('sales_transaction_payments');
                            $paymentUpdates = [];
                            foreach (['amount', 'base_amount', 'amount_tendered', 'change_amount'] as $column) {
                                if (in_array($column, $paymentColumns, true)) {
                                    $paymentUpdates[$column] = 0;
                                }
                            }

                            if ($paymentUpdates && in_array('sales_transaction_id', $paymentColumns, true)) {
                                DB::table('sales_transaction_payments')
                                    ->whereIn('sales_transaction_id', $chunk->values()->all())
                                    ->update($paymentUpdates);
                            }
                        }
                    }
                }
            }
        }

        if (Schema::hasTable('credit_customers') && Schema::hasTable('credit_customer_ledgers')) {
            $customerColumns = Schema::getColumnListing('credit_customers');

            DB::table('credit_customers')
                ->select('id', 'credit_limit')
                ->orderBy('id')
                ->chunk(200, function ($customers) use ($customerColumns) {
                    foreach ($customers as $customer) {
                        $balance = (float) DB::table('credit_customer_ledgers')
                            ->where('credit_customer_id', $customer->id)
                            ->selectRaw('COALESCE(SUM(debit_amount), 0) - COALESCE(SUM(credit_amount), 0) as balance')
                            ->value('balance');

                        $updates = [];
                        if (in_array('current_balance', $customerColumns, true)) {
                            $updates['current_balance'] = round($balance, 4);
                        }
                        if (in_array('available_credit', $customerColumns, true)) {
                            $updates['available_credit'] = round((float) ($customer->credit_limit ?? 0) - $balance, 4);
                        }

                        if ($updates) {
                            DB::table('credit_customers')
                                ->where('id', $customer->id)
                                ->update($updates);
                        }
                    }
                });
        }
    }

    public function down(): void
    {
        // Data repair migration. No destructive rollback.
    }
};
