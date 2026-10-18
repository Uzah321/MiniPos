<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('credit_customers') || !Schema::hasTable('credit_customer_ledger')) {
            return;
        }

        $customers = DB::table('credit_customers')->select('id', 'credit_limit')->get();

        foreach ($customers as $customer) {
            $balance = 0.0;

            $ledgerRows = DB::table('credit_customer_ledger')
                ->where('credit_customer_id', $customer->id)
                ->orderBy('document_date')
                ->orderBy('created_at')
                ->orderBy('id')
                ->select('id', 'debit_amount', 'credit_amount')
                ->get();

            foreach ($ledgerRows as $row) {
                $balance = round(
                    $balance + (float) $row->debit_amount - (float) $row->credit_amount,
                    4
                );

                DB::table('credit_customer_ledger')
                    ->where('id', $row->id)
                    ->update(['balance_after' => $balance]);
            }

            $creditLimit = round((float) ($customer->credit_limit ?? 0), 4);

            DB::table('credit_customers')
                ->where('id', $customer->id)
                ->update([
                    // Positive means customer owes. Negative means customer has prepaid credit.
                    'current_balance' => $balance,
                    // Available is NOT opening balance. Available = limit - current balance.
                    // If balance is -19 and limit is 80, available is 99.
                    'available_credit' => round($creditLimit - $balance, 4),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // This is a data correction migration; do not reverse corrected balances.
    }
};
