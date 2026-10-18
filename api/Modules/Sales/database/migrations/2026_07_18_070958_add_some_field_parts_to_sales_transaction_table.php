<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('credit_customer_payment_allocations') || !Schema::hasTable('credit_customer_ledgers')) {
            return;
        }

        $allocationColumns = Schema::getColumnListing('credit_customer_payment_allocations');

        if (!in_array('sale_ledger_id', $allocationColumns, true) || !in_array('payment_ledger_id', $allocationColumns, true)) {
            return;
        }

        DB::transaction(function () use ($allocationColumns) {
            $customers = DB::table('credit_customer_ledgers')
                ->select('credit_customer_id')
                ->whereNotNull('credit_customer_id')
                ->distinct()
                ->pluck('credit_customer_id');

            foreach ($customers as $customerId) {
                $paymentLedgers = DB::table('credit_customer_ledgers')
                    ->where('credit_customer_id', $customerId)
                    ->where('entry_type', 'payment')
                    ->where('credit_amount', '>', 0)
                    ->whereNotNull('payment_id')
                    ->orderBy('document_date')
                    ->orderBy('created_at')
                    ->get();

                $saleLedgers = DB::table('credit_customer_ledgers')
                    ->where('credit_customer_id', $customerId)
                    ->where('entry_type', 'sale')
                    ->where('debit_amount', '>', 0)
                    ->orderBy('due_date')
                    ->orderBy('document_date')
                    ->orderBy('created_at')
                    ->get();

                foreach ($paymentLedgers as $paymentLedger) {
                    $paymentAmount = round((float) $paymentLedger->credit_amount, 4);
                    $paymentAllocated = $this->allocatedByPaymentLedger($paymentLedger->id, $allocationColumns);
                    $paymentRemaining = round(max(0, $paymentAmount - $paymentAllocated), 4);

                    if ($paymentRemaining <= 0) {
                        continue;
                    }

                    foreach ($saleLedgers as $saleLedger) {
                        if ($paymentRemaining <= 0) {
                            break;
                        }

                        $saleAmount = round((float) $saleLedger->debit_amount, 4);
                        $saleAllocated = $this->allocatedBySaleLedger($saleLedger->id, $allocationColumns);
                        $saleOutstanding = round(max(0, $saleAmount - $saleAllocated), 4);

                        if ($saleOutstanding <= 0) {
                            continue;
                        }

                        $allocated = round(min($paymentRemaining, $saleOutstanding), 4);
                        if ($allocated <= 0) {
                            continue;
                        }

                        $payload = [
                            'id' => (string) Str::uuid(),
                            'company_id' => $saleLedger->company_id,
                            'branch_id' => $saleLedger->branch_id,
                            'credit_customer_id' => $customerId,
                            'credit_customer_payment_id' => $paymentLedger->payment_id,
                            'payment_ledger_id' => $paymentLedger->id,
                            'sale_ledger_id' => $saleLedger->id,
                            'sales_transaction_id' => $saleLedger->sales_transaction_id,
                            'document_number' => $saleLedger->document_number,
                            'invoice_number' => $saleLedger->document_number,
                            'payment_number' => $paymentLedger->document_number,
                            'payment_date' => now()->toDateString(),
                            'amount' => $allocated,
                            'allocated_amount' => $allocated,
                            'base_allocated_amount' => $allocated,
                            'currency_code' => $saleLedger->currency_code ?: $paymentLedger->currency_code ?: 'USD',
                            'base_currency_code' => $saleLedger->base_currency_code ?: $paymentLedger->base_currency_code ?: $saleLedger->currency_code ?: 'USD',
                            'allocation_status' => round($saleOutstanding - $allocated, 4) <= 0 ? 'paid' : 'partial',
                            'metadata' => json_encode([
                                'source' => 'migration_existing_customer_credit_auto_applied',
                                'reason' => 'Backfilled unapplied customer credit/payment balance onto unpaid credit receipts.',
                                'payment_ledger_id' => $paymentLedger->id,
                                'sale_ledger_id' => $saleLedger->id,
                                'allocated_to_this_sale' => $allocated,
                            ]),
                            'created_by' => null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];

                        DB::table('credit_customer_payment_allocations')->insert(
                            array_intersect_key($payload, array_flip($allocationColumns))
                        );

                        $paymentRemaining = round($paymentRemaining - $allocated, 4);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('credit_customer_payment_allocations')) {
            return;
        }

        DB::table('credit_customer_payment_allocations')
            ->where(function ($query) {
                $query->where('metadata', 'like', '%migration_existing_customer_credit_auto_applied%')
                    ->orWhere('metadata', 'like', '%existing_customer_credit_auto_applied%');
            })
            ->delete();
    }

    private function allocatedByPaymentLedger(string $paymentLedgerId, array $allocationColumns): float
    {
        $amountExpression = $this->amountExpression($allocationColumns);
        if (!$amountExpression) {
            return 0.0;
        }

        $row = DB::table('credit_customer_payment_allocations')
            ->where('payment_ledger_id', $paymentLedgerId)
            ->when(in_array('allocation_status', $allocationColumns, true), function ($query) {
                $query->whereNotIn('allocation_status', ['reversed', 'voided', 'cancelled']);
            })
            ->selectRaw("COALESCE(SUM({$amountExpression}), 0) as total_allocated")
            ->first();

        return round((float) ($row->total_allocated ?? 0), 4);
    }

    private function allocatedBySaleLedger(string $saleLedgerId, array $allocationColumns): float
    {
        $amountExpression = $this->amountExpression($allocationColumns);
        if (!$amountExpression) {
            return 0.0;
        }

        $row = DB::table('credit_customer_payment_allocations')
            ->where('sale_ledger_id', $saleLedgerId)
            ->when(in_array('allocation_status', $allocationColumns, true), function ($query) {
                $query->whereNotIn('allocation_status', ['reversed', 'voided', 'cancelled']);
            })
            ->selectRaw("COALESCE(SUM({$amountExpression}), 0) as total_allocated")
            ->first();

        return round((float) ($row->total_allocated ?? 0), 4);
    }

    private function amountExpression(array $allocationColumns): ?string
    {
        $hasAllocated = in_array('allocated_amount', $allocationColumns, true);
        $hasAmount = in_array('amount', $allocationColumns, true);

        if ($hasAllocated && $hasAmount) {
            return 'COALESCE(NULLIF(allocated_amount, 0), amount, 0)';
        }

        if ($hasAllocated) {
            return 'COALESCE(allocated_amount, 0)';
        }

        if ($hasAmount) {
            return 'COALESCE(amount, 0)';
        }

        return null;
    }
};
