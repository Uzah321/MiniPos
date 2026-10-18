<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sales_transactions')) {
            return;
        }

        $this->zeroOldCreditSalePaymentRows();
        $this->rebuildCreditAllocationsFromLedgers();
        $this->resyncCreditCustomersBalances();
    }

    public function down(): void
    {
        // Data repair migration. No destructive rollback.
    }

    private function zeroOldCreditSalePaymentRows(): void
    {
        if (!Schema::hasTable('sales_transaction_payments')) {
            return;
        }

        $creditSaleIds = DB::table('sales_transactions')
            ->where(function ($query) {
                if (Schema::hasColumn('sales_transactions', 'is_credit_sale')) {
                    $query->orWhere('is_credit_sale', 1);
                }

                if (Schema::hasColumn('sales_transactions', 'credit_customer_id')) {
                    $query->orWhereNotNull('credit_customer_id');
                }

                $query->orWhereRaw("LOWER(COALESCE(payment_method, '')) LIKE '%credit%'");
            })
            ->pluck('id')
            ->filter()
            ->values();

        if ($creditSaleIds->isEmpty()) {
            return;
        }

        $columns = Schema::getColumnListing('sales_transaction_payments');
        $updates = [];

        foreach (['amount', 'base_amount', 'amount_tendered', 'change_amount'] as $column) {
            if (in_array($column, $columns, true)) {
                $updates[$column] = 0;
            }
        }

        if (in_array('payment_name', $columns, true)) {
            $updates['payment_name'] = DB::raw("CONCAT(COALESCE(payment_name, 'Customer Credit'), ' - Invoice Only')");
        }

        if (in_array('updated_at', $columns, true)) {
            $updates['updated_at'] = now();
        }

        if (!$updates) {
            return;
        }

        foreach ($creditSaleIds->chunk(500) as $ids) {
            DB::table('sales_transaction_payments')
                ->whereIn('sales_transaction_id', $ids->all())
                ->where(function ($query) {
                    $query->whereRaw("LOWER(COALESCE(money_code, '')) IN ('customer_credit', 'credit_customer', 'credit_account', 'account')")
                        ->orWhereRaw("LOWER(COALESCE(payment_name, '')) LIKE '%credit%'");
                })
                ->update($updates);
        }
    }

    private function rebuildCreditAllocationsFromLedgers(): void
    {
        if (!Schema::hasTable('credit_customer_ledgers') || !Schema::hasTable('credit_customer_payment_allocations')) {
            return;
        }

        $allocationColumns = Schema::getColumnListing('credit_customer_payment_allocations');
        if (!in_array('sale_ledger_id', $allocationColumns, true)) {
            return;
        }

        DB::table('credit_customer_payment_allocations')->delete();

        $customerIds = DB::table('credit_customer_ledgers')
            ->select('credit_customer_id')
            ->whereNotNull('credit_customer_id')
            ->distinct()
            ->pluck('credit_customer_id');

        foreach ($customerIds as $customerId) {
            $this->rebuildCustomerAllocations((string) $customerId, $allocationColumns);
        }
    }

    private function rebuildCustomerAllocations(string $customerId, array $allocationColumns): void
    {
        $saleLedgers = DB::table('credit_customer_ledgers')
            ->where('credit_customer_id', $customerId)
            ->where('entry_type', 'sale')
            ->where('debit_amount', '>', 0)
            ->orderBy('due_date')
            ->orderBy('document_date')
            ->orderBy('created_at')
            ->get()
            ->filter(fn ($ledger) => !$this->saleLedgerIsCancelled($ledger))
            ->values();

        $payments = DB::table('credit_customer_ledgers')
            ->where('credit_customer_id', $customerId)
            ->where('entry_type', 'payment')
            ->where('credit_amount', '>', 0)
            ->orderBy('document_date')
            ->orderBy('created_at')
            ->get();

        $paidBySaleLedger = [];

        foreach ($saleLedgers as $saleLedger) {
            $paidBySaleLedger[(string) $saleLedger->id] = 0.0;
        }

        foreach ($payments as $paymentLedger) {
            $remaining = round((float) $paymentLedger->credit_amount, 4);

            if ($remaining <= 0) {
                continue;
            }

            foreach ($saleLedgers as $saleLedger) {
                if ($remaining <= 0) {
                    break;
                }

                $saleLedgerId = (string) $saleLedger->id;
                $saleAmount = round((float) $saleLedger->debit_amount, 4);
                $alreadyPaid = round((float) ($paidBySaleLedger[$saleLedgerId] ?? 0), 4);
                $outstanding = round(max(0, $saleAmount - $alreadyPaid), 4);

                if ($outstanding <= 0) {
                    continue;
                }

                $allocated = round(min($remaining, $outstanding), 4);
                $status = round($outstanding - $allocated, 4) <= 0 ? 'paid' : 'partial';

                $this->insertAllocationRow($allocationColumns, $saleLedger, $paymentLedger, $allocated, $status);

                $paidBySaleLedger[$saleLedgerId] = round($alreadyPaid + $allocated, 4);
                $remaining = round($remaining - $allocated, 4);
            }
        }

        foreach ($saleLedgers as $saleLedger) {
            $saleAmount = round((float) $saleLedger->debit_amount, 4);
            $paid = round(min((float) ($paidBySaleLedger[(string) $saleLedger->id] ?? 0), $saleAmount), 4);
            $balance = round(max(0, $saleAmount - $paid), 4);
            $status = $balance <= 0 ? 'paid' : ($paid > 0 ? 'partially_paid' : 'posted');

            $this->syncSaleCreditColumns($saleLedger, $saleAmount, $paid, $balance, $status);
        }
    }

    private function insertAllocationRow(array $columns, object $saleLedger, object $paymentLedger, float $allocated, string $status): void
    {
        $payload = [
            'id' => (string) Str::uuid(),
            'company_id' => $saleLedger->company_id ?? $paymentLedger->company_id ?? null,
            'branch_id' => $saleLedger->branch_id ?? $paymentLedger->branch_id ?? null,
            'credit_customer_id' => $saleLedger->credit_customer_id ?? $paymentLedger->credit_customer_id ?? null,
            'credit_customer_payment_id' => $paymentLedger->payment_id ?? null,
            'payment_ledger_id' => $paymentLedger->id ?? null,
            'sale_ledger_id' => $saleLedger->id ?? null,
            'sales_transaction_id' => $saleLedger->sales_transaction_id ?? null,
            'document_number' => $saleLedger->document_number ?? null,
            'invoice_number' => $saleLedger->document_number ?? null,
            'payment_number' => $paymentLedger->document_number ?? null,
            'payment_date' => $paymentLedger->document_date ?? now()->toDateString(),
            'amount' => $allocated,
            'allocated_amount' => $allocated,
            'base_allocated_amount' => $allocated,
            'currency_code' => $saleLedger->currency_code ?? $paymentLedger->currency_code ?? null,
            'base_currency_code' => $saleLedger->base_currency_code ?? $paymentLedger->base_currency_code ?? null,
            'allocation_status' => $status,
            'metadata' => json_encode([
                'source' => 'migration_rebuild_credit_allocation',
                'sale_ledger_id' => $saleLedger->id ?? null,
                'payment_ledger_id' => $paymentLedger->id ?? null,
                'allocated_amount' => $allocated,
            ]),
            'created_by' => $paymentLedger->created_by ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('credit_customer_payment_allocations')
            ->insert(array_intersect_key($payload, array_flip($columns)));
    }

    private function syncSaleCreditColumns(object $saleLedger, float $saleAmount, float $paid, float $balance, string $status): void
    {
        if (empty($saleLedger->sales_transaction_id) || !Schema::hasTable('sales_transactions')) {
            return;
        }

        $columns = Schema::getColumnListing('sales_transactions');
        $updates = [];

        foreach ([
                     'is_credit_sale' => 1,
                     'credit_customer_id' => $saleLedger->credit_customer_id ?? null,
                     'credit_status' => $status,
                     'credit_amount' => $saleAmount,
                     'credit_paid_amount' => $paid,
                     'credit_balance_amount' => $balance,
                     'credit_due_date' => $saleLedger->due_date ?? null,
                 ] as $column => $value) {
            if (in_array($column, $columns, true)) {
                $updates[$column] = $value;
            }
        }

        if (in_array('updated_at', $columns, true)) {
            $updates['updated_at'] = now();
        }

        if ($updates) {
            DB::table('sales_transactions')
                ->where('id', $saleLedger->sales_transaction_id)
                ->update($updates);
        }
    }

    private function resyncCreditCustomersBalances(): void
    {
        if (!Schema::hasTable('credit_customers') || !Schema::hasTable('credit_customer_ledgers')) {
            return;
        }

        $customerColumns = Schema::getColumnListing('credit_customers');
        $customers = DB::table('credit_customers')->select('id', 'credit_limit')->get();

        foreach ($customers as $customer) {
            $ledgers = DB::table('credit_customer_ledgers')
                ->where('credit_customer_id', $customer->id)
                ->orderBy('document_date')
                ->orderBy('created_at')
                ->get();

            $balance = 0.0;

            foreach ($ledgers as $ledger) {
                if ($this->ledgerIsIgnoredForBalance($ledger)) {
                    continue;
                }

                $balance += round((float) ($ledger->debit_amount ?? 0), 4);
                $balance -= round((float) ($ledger->credit_amount ?? 0), 4);
            }

            $balance = round($balance, 4);
            $updates = [];

            if (in_array('current_balance', $customerColumns, true)) {
                $updates['current_balance'] = $balance;
            }

            if (in_array('available_credit', $customerColumns, true)) {
                $updates['available_credit'] = round((float) ($customer->credit_limit ?? 0) - $balance, 4);
            }

            if (in_array('updated_at', $customerColumns, true)) {
                $updates['updated_at'] = now();
            }

            if ($updates) {
                DB::table('credit_customers')->where('id', $customer->id)->update($updates);
            }
        }
    }

    private function ledgerIsIgnoredForBalance(object $ledger): bool
    {
        if (in_array((string) ($ledger->entry_type ?? ''), ['sale_void', 'sale_cancel', 'void', 'reversal'], true)) {
            return true;
        }

        if ((string) ($ledger->entry_type ?? '') !== 'sale') {
            return false;
        }

        return $this->saleLedgerIsCancelled($ledger);
    }

    private function saleLedgerIsCancelled(object $saleLedger): bool
    {
        if ((string) ($saleLedger->entry_type ?? '') !== 'sale') {
            return false;
        }

        if (!empty($saleLedger->sales_transaction_id) && Schema::hasTable('sales_transactions')) {
            $sale = DB::table('sales_transactions')->where('id', $saleLedger->sales_transaction_id)->first();

            if ($sale && (
                    in_array((string) ($sale->status ?? ''), ['cancelled', 'voided'], true)
                    || in_array((string) ($sale->credit_status ?? ''), ['cancelled', 'voided', 'cancelled_reversal'], true)
                )) {
                return true;
            }
        }

        return DB::table('credit_customer_ledgers')
            ->where('credit_customer_id', $saleLedger->credit_customer_id)
            ->whereIn('entry_type', ['sale_void', 'sale_cancel', 'void', 'reversal'])
            ->where(function ($query) use ($saleLedger) {
                $query->where('sales_transaction_id', $saleLedger->sales_transaction_id)
                    ->orWhere('reference', $saleLedger->document_number);
            })
            ->exists();
    }
};
