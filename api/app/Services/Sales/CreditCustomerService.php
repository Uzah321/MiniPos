<?php
//
//namespace App\Services\Sales;
//
//use Carbon\Carbon;
//use Illuminate\Support\Facades\DB;
//use Illuminate\Support\Facades\Schema;
//use Illuminate\Support\Str;
//use Modules\Currency\Models\PaymentType;
//use Modules\Sales\Models\CreditCustomer;
//use Modules\Sales\Models\CreditCustomerLedger;
//use Modules\Sales\Models\CreditCustomerNumberSequence;
//use Modules\Sales\Models\CreditCustomerPayment;
//use Modules\Sales\Models\SalesTransaction;
//use function App\Helpers\currencyConverter;
//
//class CreditCustomerService
//{
//    public function generateAccountNumber(string $companyId, ?string $branchId = null, string $prefix = 'CR'): string
//    {
//        return DB::transaction(function () use ($companyId, $branchId, $prefix) {
//            $sequence = CreditCustomerNumberSequence::query()
//                ->where('company_id', $companyId)
//                ->where('branch_id', $branchId)
//                ->where('sequence_type', 'credit_customer_account')
//                ->where('prefix', $prefix)
//                ->lockForUpdate()
//                ->first();
//
//            if (!$sequence) {
//                $sequence = CreditCustomerNumberSequence::create([
//                    'company_id' => $companyId,
//                    'branch_id' => $branchId,
//                    'sequence_type' => 'credit_customer_account',
//                    'prefix' => $prefix,
//                    'last_number' => 0,
//                ]);
//
//                $sequence = CreditCustomerNumberSequence::where('id', $sequence->id)
//                    ->lockForUpdate()
//                    ->first();
//            }
//
//            $next = ((int) $sequence->last_number) + 1;
//            $accountNumber = $prefix . '-' . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
//
//            while (CreditCustomer::where('company_id', $companyId)->where('account_number', $accountNumber)->exists()) {
//                $next++;
//                $accountNumber = $prefix . '-' . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
//            }
//
//            $sequence->update(['last_number' => $next]);
//
//            return $accountNumber;
//        });
//    }
//
//    public function recalculateCustomerBalance(CreditCustomer $customer): CreditCustomer
//    {
//        $ledgers = CreditCustomerLedger::query()
//            ->where('credit_customer_id', $customer->id)
//            ->orderBy('document_date')
//            ->orderBy('created_at')
//            ->get();
//
//        $cancelledSaleIds = $ledgers
//            ->filter(fn (CreditCustomerLedger $row) => in_array((string) $row->entry_type, ['sale_void', 'sale_cancel', 'void', 'reversal'], true))
//            ->map(function (CreditCustomerLedger $row) {
//                $metadata = $this->toArray($row->metadata);
//                return $metadata['original_sales_transaction_id']
//                    ?? $metadata['original_sale_id']
//                    ?? $metadata['cancelled_original_sale_id']
//                    ?? null;
//            })
//            ->filter()
//            ->unique()
//            ->values();
//
//        $balance = 0.0;
//
//        foreach ($ledgers as $ledger) {
//            $isReversalRow = in_array((string) $ledger->entry_type, ['sale_void', 'sale_cancel', 'void', 'reversal'], true);
//            $isCancelledSale = (string) $ledger->entry_type === 'sale' && (
//                    $cancelledSaleIds->contains($ledger->sales_transaction_id)
//                    || $this->saleLedgerIsCancelled($ledger)
//                );
//
//            if ($isReversalRow || $isCancelledSale) {
//                continue;
//            }
//
//            $balance += round((float) $ledger->debit_amount, 4);
//            $balance -= round((float) $ledger->credit_amount, 4);
//        }
//
//        $balance = round($balance, 4);
//        $customer->current_balance = $balance;
//        $customer->available_credit = round((float) $customer->credit_limit - $balance, 4);
//        $customer->save();
//
//        return $customer->fresh();
//    }
//
//    public function assertCanBuyOnCredit(CreditCustomer $customer, float $amount, ?string $poNumber = null): void
//    {
//        if (!$customer->is_active || $customer->status !== 'approved' || !$customer->allow_credit_sales) {
//            throw new \Exception('Customer is not approved for credit sales.');
//        }
//
//        if ($customer->requires_po_number && !trim((string) $poNumber)) {
//            throw new \Exception('Purchase order number is required for this credit customer.');
//        }
//
//        $available = round((float) $customer->credit_limit - (float) $customer->current_balance, 4);
//
//        if ($amount > $available + 0.0001) {
//            throw new \Exception('Credit limit exceeded. Available credit: ' . number_format($available, 2));
//        }
//    }
//
//    public function postCreditSale(
//        SalesTransaction $sale,
//        CreditCustomer $customer,
//        float $amount,
//        string $currencyCode,
//        ?string $baseCurrencyCode = null,
//        float $exchangeRate = 1,
//        ?string $shiftSessionId = null,
//        ?string $tillId = null,
//        ?string $cashierId = null,
//        ?string $poNumber = null,
//        ?string $notes = null
//    ): CreditCustomerLedger {
//        return DB::transaction(function () use ($sale, $customer, $amount, $currencyCode, $baseCurrencyCode, $exchangeRate, $shiftSessionId, $tillId, $cashierId, $poNumber, $notes) {
//            $customer = CreditCustomer::where('id', $customer->id)->lockForUpdate()->firstOrFail();
//
//            $accountCurrencyCode = strtoupper($customer->currency_code ?: $currencyCode ?: 'USD');
//            $saleCurrencyCode = strtoupper($currencyCode ?: $accountCurrencyCode);
//            $accountAmount = $this->convertAmount($saleCurrencyCode, $accountCurrencyCode, $amount, $sale->company_id);
//
//            $this->assertCanBuyOnCredit($customer, $accountAmount, $poNumber);
//
//            $previousBalance = round((float) $customer->current_balance, 4);
//            $existingCreditBeforeSale = round(max(0, abs(min(0, $previousBalance))), 4);
//            $paidByExistingCredit = round(min($accountAmount, $existingCreditBeforeSale), 4);
//            $newBalance = round($previousBalance + $accountAmount, 4);
//            $creditBalanceAfterSale = round(max(0, abs(min(0, $newBalance))), 4);
//            $dueDate = now()->addDays((int) ($customer->payment_terms_days ?: 30))->toDateString();
//
//            $ledger = CreditCustomerLedger::create([
//                'id' => (string) Str::uuid(),
//                'company_id' => $sale->company_id,
//                'branch_id' => $sale->branch_id,
//                'credit_customer_id' => $customer->id,
//                'sales_transaction_id' => $sale->id,
//                'shift_session_id' => $shiftSessionId ?: $sale->shift_session_id,
//                'till_id' => $tillId ?: $sale->till_id,
//                'cashier_id' => $cashierId ?: $sale->cashier_id,
//                'entry_type' => 'sale',
//                'document_number' => $sale->invoice_number,
//                'document_date' => $sale->transaction_date ?: now()->toDateString(),
//                'due_date' => $dueDate,
//                'debit_amount' => round($accountAmount, 4),
//                'credit_amount' => 0,
//                'balance_after' => $newBalance,
//                'currency_code' => $accountCurrencyCode,
//                'base_currency_code' => $baseCurrencyCode ?: $accountCurrencyCode,
//                'exchange_rate' => $saleCurrencyCode === $accountCurrencyCode ? 1 : round($accountAmount / max($amount, 0.0001), 8),
//                'reference' => $poNumber,
//                'notes' => $notes ?: 'Credit sale posted to customer account.',
//                'metadata' => [
//                    'source' => 'pos_credit_sale',
//                    'invoice_number' => $sale->invoice_number,
//                    'po_number' => $poNumber,
//                    'customer_account_number' => $customer->account_number,
//                    'sale_currency_code' => $saleCurrencyCode,
//                    'sale_amount' => round($amount, 4),
//                    'account_currency_code' => $accountCurrencyCode,
//                    'account_amount' => round($accountAmount, 4),
//                    'previous_balance' => $previousBalance,
//                    'existing_credit_before_sale' => $existingCreditBeforeSale,
//                    'paid_by_existing_credit' => $paidByExistingCredit,
//                    'customer_credit_after_sale' => $creditBalanceAfterSale,
//                    'balance_after_sale' => $newBalance,
//                ],
//                'created_by' => auth()->id(),
//            ]);
//
//            $customer->current_balance = $newBalance;
//            $customer->available_credit = round((float) $customer->credit_limit - $newBalance, 4);
//            $customer->save();
//
//            $this->markSaleAsCredit($sale, $customer, $accountAmount, $accountCurrencyCode, $dueDate, $poNumber);
//
//            // If the customer had prepaid credit before this sale, automatically consume it.
//            // Example: balance was -8.00 credit, new sale is 5.00, balance becomes -3.00
//            // and this sale is marked paid because it was covered by existing customer credit.
//            if ($previousBalance < 0) {
//                if ($paidByExistingCredit > 0) {
//                    $this->syncSaleCreditStatus($ledger, $accountAmount, $paidByExistingCredit, $accountCurrencyCode);
//                }
//            }
//
//            return $ledger;
//        });
//    }
//
//    public function recordPayment(array $data): CreditCustomerPayment
//    {
//        return DB::transaction(function () use ($data) {
//            $customer = CreditCustomer::where('id', $data['credit_customer_id'])->lockForUpdate()->firstOrFail();
//
//            $amount = round((float) $data['amount'], 4);
//            if ($amount <= 0) {
//                throw new \Exception('Payment amount must be greater than zero.');
//            }
//
//            $paymentType = null;
//            if (!empty($data['payment_type_id'])) {
//                $paymentType = PaymentType::where('id', $data['payment_type_id'])->first();
//            }
//
//            $paymentCurrencyCode = strtoupper($data['currency_code'] ?? $customer->currency_code ?? 'USD');
//            $accountCurrencyCode = strtoupper($customer->currency_code ?: $paymentCurrencyCode);
//            $baseCurrencyCode = strtoupper($data['base_currency_code'] ?? $accountCurrencyCode);
//
//            /*
//             * The customer balance is always kept in the customer's account currency.
//             * Example: customer account is USD, cashier receives ZWG.
//             * We store the received ZWG in credit_customer_payments, then convert it to USD
//             * and use that USD account amount to reduce the balance and allocate oldest invoices.
//             */
//            $accountAmount = $this->convertAmount($paymentCurrencyCode, $accountCurrencyCode, $amount, $data['company_id']);
//            $baseAmount = $this->convertAmount($paymentCurrencyCode, $baseCurrencyCode, $amount, $data['company_id']);
//
//            if ($accountAmount <= 0) {
//                $fallbackRate = (float) ($data['exchange_rate'] ?? 0);
//                if ($paymentCurrencyCode !== $accountCurrencyCode && $fallbackRate > 0) {
//                    $accountAmount = round($amount * $fallbackRate, 4);
//                }
//            }
//
//            if ($accountAmount <= 0) {
//                throw new \Exception('Payment could not reduce customer balance because the converted account amount resolved to zero. Check currency/exchange rate setup.');
//            }
//
//            if ($baseAmount <= 0) {
//                $baseAmount = $accountCurrencyCode === $baseCurrencyCode
//                    ? $accountAmount
//                    : $this->convertAmount($accountCurrencyCode, $baseCurrencyCode, $accountAmount, $data['company_id']);
//            }
//
//            $exchangeRate = $paymentCurrencyCode === $accountCurrencyCode ? 1 : round($accountAmount / max($amount, 0.0001), 8);
//
//            $paymentNumber = $data['payment_number'] ?? $this->generatePaymentNumber($data['company_id'], $data['branch_id'] ?? null);
//            $paymentName = $paymentType?->name ?? ($data['payment_name'] ?? $data['payment_method'] ?? 'Credit Customer Payment');
//            $moneyCode = $paymentType?->money_code ?? ($data['money_code'] ?? null);
//            $reference = $data['reference'] ?? $data['reference_no'] ?? $paymentNumber;
//
//            $payment = CreditCustomerPayment::create([
//                'id' => (string) Str::uuid(),
//                'company_id' => $data['company_id'],
//                'branch_id' => $data['branch_id'] ?? null,
//                'credit_customer_id' => $customer->id,
//                'till_id' => $data['till_id'] ?? null,
//                'shift_session_id' => $data['shift_session_id'] ?? null,
//                'cashier_id' => $data['cashier_id'] ?? null,
//                'payment_type_id' => $data['payment_type_id'] ?? null,
//                'payment_number' => $paymentNumber,
//                'payment_name' => $paymentName,
//                'money_code' => $moneyCode,
//                'amount' => $amount,
//                'base_amount' => round($baseAmount, 4),
//                'currency_code' => $paymentCurrencyCode,
//                'base_currency_code' => $baseCurrencyCode,
//                'exchange_rate' => $exchangeRate,
//                'reference' => $reference,
//                'approval_code' => $data['approval_code'] ?? null,
//                'card_last_four' => $data['card_last_four'] ?? null,
//                'notes' => $data['notes'] ?? null,
//                'status' => 'posted',
//                'metadata' => array_merge($this->toArray($data['metadata'] ?? []), [
//                    'account_currency_code' => $accountCurrencyCode,
//                    'account_amount' => round($accountAmount, 4),
//                    'payment_currency_code' => $paymentCurrencyCode,
//                    'payment_amount' => round($amount, 4),
//                ]),
//                'created_by' => auth()->id(),
//            ]);
//
//            $previousBalance = round((float) $customer->current_balance, 4);
//            $newBalance = round($previousBalance - $accountAmount, 4);
//            $appliedToDebt = round(min($accountAmount, max(0, $previousBalance)), 4);
//            $creditBalanceAfterPayment = round(max(0, abs(min(0, $newBalance))), 4);
//
//            $ledger = CreditCustomerLedger::create([
//                'id' => (string) Str::uuid(),
//                'company_id' => $data['company_id'],
//                'branch_id' => $data['branch_id'] ?? null,
//                'credit_customer_id' => $customer->id,
//                'payment_id' => $payment->id,
//                'shift_session_id' => $data['shift_session_id'] ?? null,
//                'till_id' => $data['till_id'] ?? null,
//                'cashier_id' => $data['cashier_id'] ?? null,
//                'entry_type' => 'payment',
//                'document_number' => $paymentNumber,
//                'document_date' => now()->toDateString(),
//                'debit_amount' => 0,
//                'credit_amount' => round($accountAmount, 4),
//                'balance_after' => $newBalance,
//                'currency_code' => $accountCurrencyCode,
//                'base_currency_code' => $baseCurrencyCode,
//                'exchange_rate' => $exchangeRate,
//                'reference' => $reference,
//                'notes' => $data['notes'] ?? 'Credit customer payment received.',
//                'metadata' => [
//                    'source' => 'credit_customer_payment',
//                    'payment_number' => $paymentNumber,
//                    'payment_name' => $paymentName,
//                    'money_code' => $moneyCode,
//                    'payment_currency_code' => $paymentCurrencyCode,
//                    'payment_amount' => round($amount, 4),
//                    'account_currency_code' => $accountCurrencyCode,
//                    'account_amount' => round($accountAmount, 4),
//                    'previous_balance' => $previousBalance,
//                    'applied_to_existing_debt' => $appliedToDebt,
//                    'overpayment_credit_created' => $creditBalanceAfterPayment,
//                    'balance_after_payment' => $newBalance,
//                ],
//                'created_by' => auth()->id(),
//            ]);
//
//            $customer->current_balance = $newBalance;
//            $customer->available_credit = round((float) $customer->credit_limit - $newBalance, 4);
//            $customer->save();
//
//            $this->insertCashupPaymentRow($payment, $customer, $data, $paymentName, $moneyCode, $paymentCurrencyCode, $baseCurrencyCode, $exchangeRate, $baseAmount, $reference);
//
//            $this->applyPaymentToOldestSales($customer, $payment, $ledger, $accountAmount, $accountCurrencyCode);
//
//            $this->resyncCustomerSaleCreditStatuses($customer);
//            $this->recalculateCustomerBalance($customer);
//
//            return $payment->fresh();
//        });
//    }
//
//    private function applyPaymentToOldestSales(
//        CreditCustomer $customer,
//        CreditCustomerPayment $payment,
//        CreditCustomerLedger $paymentLedger,
//        float $accountAmount,
//        string $accountCurrencyCode
//    ): void {
//        $remaining = round($accountAmount, 4);
//
//        $saleLedgers = CreditCustomerLedger::query()
//            ->where('credit_customer_id', $customer->id)
//            ->where('entry_type', 'sale')
//            ->where('debit_amount', '>', 0)
//            ->orderBy('due_date')
//            ->orderBy('document_date')
//            ->orderBy('created_at')
//            ->lockForUpdate()
//            ->get();
//
//        foreach ($saleLedgers as $saleLedger) {
//            if ($remaining <= 0) {
//                break;
//            }
//
//            $alreadyAllocated = $this->allocatedAmountForSaleLedger($saleLedger->id);
//            $saleAmount = round((float) $saleLedger->debit_amount, 4);
//            $outstanding = round(max(0, $saleAmount - $alreadyAllocated), 4);
//
//            if ($outstanding <= 0) {
//                $this->syncSaleCreditStatus($saleLedger, $saleAmount, $alreadyAllocated, $accountCurrencyCode);
//                continue;
//            }
//
//            $allocated = round(min($remaining, $outstanding), 4);
//
//            if (Schema::hasTable('credit_customer_payment_allocations')) {
//                $allocationColumns = Schema::getColumnListing('credit_customer_payment_allocations');
//
//                $allocationPayload = [
//                    'id' => (string) Str::uuid(),
//                    'company_id' => $customer->company_id,
//                    'branch_id' => $saleLedger->branch_id,
//                    'credit_customer_id' => $customer->id,
//                    'credit_customer_payment_id' => $payment->id,
//                    'payment_ledger_id' => $paymentLedger->id,
//                    'sale_ledger_id' => $saleLedger->id,
//                    'sales_transaction_id' => $saleLedger->sales_transaction_id,
//                    'document_number' => $saleLedger->document_number,
//                    'invoice_number' => $saleLedger->document_number,
//                    'payment_number' => $payment->payment_number,
//                    'payment_date' => now()->toDateString(),
//                    'amount' => $allocated,
//                    'allocated_amount' => $allocated,
//                    'base_allocated_amount' => $allocated,
//                    'currency_code' => $accountCurrencyCode,
//                    'base_currency_code' => $accountCurrencyCode,
//                    'allocation_status' => round($outstanding - $allocated, 4) <= 0 ? 'paid' : 'partial',
//                    'metadata' => json_encode([
//                        'source' => 'credit_customer_payment_allocation',
//                        'payment_number' => $payment->payment_number,
//                        'sale_document_number' => $saleLedger->document_number,
//                        'sale_amount' => $saleAmount,
//                        'already_allocated_before_payment' => $alreadyAllocated,
//                        'allocated_by_this_payment' => $allocated,
//                        'total_allocated_after_payment' => round($alreadyAllocated + $allocated, 4),
//                        'outstanding_after_payment' => round(max(0, $outstanding - $allocated), 4),
//                    ]),
//                    'created_by' => auth()->id(),
//                    'created_at' => now(),
//                    'updated_at' => now(),
//                ];
//
//                DB::table('credit_customer_payment_allocations')->insert(
//                    array_intersect_key($allocationPayload, array_flip($allocationColumns))
//                );
//            }
//
//            $totalAllocated = round($alreadyAllocated + $allocated, 4);
//            $this->syncSaleCreditStatus($saleLedger, $saleAmount, $totalAllocated, $accountCurrencyCode);
//
//            $remaining = round($remaining - $allocated, 4);
//        }
//    }
//
//    private function creditAllocationAmountExpressionForService(array $columns): ?string
//    {
//        $hasAllocatedAmount = in_array('allocated_amount', $columns, true);
//        $hasLegacyAmount = in_array('amount', $columns, true);
//
//        if ($hasAllocatedAmount && $hasLegacyAmount) {
//            return 'COALESCE(NULLIF(allocated_amount, 0), amount, 0)';
//        }
//
//        if ($hasAllocatedAmount) {
//            return 'COALESCE(allocated_amount, 0)';
//        }
//
//        if ($hasLegacyAmount) {
//            return 'COALESCE(amount, 0)';
//        }
//
//        return null;
//    }
//
//    private function allocatedAmountFromAllocationRowsForSaleLedger(string $saleLedgerId): float
//    {
//        if (!Schema::hasTable('credit_customer_payment_allocations')) {
//            return 0.0;
//        }
//
//        $columns = Schema::getColumnListing('credit_customer_payment_allocations');
//        $amountExpression = $this->creditAllocationAmountExpressionForService($columns);
//
//        if (!$amountExpression) {
//            return 0.0;
//        }
//
//        $row = DB::table('credit_customer_payment_allocations')
//            ->where('sale_ledger_id', $saleLedgerId)
//            ->when(in_array('allocation_status', $columns, true), function ($query) {
//                $query->whereNotIn('allocation_status', ['reversed', 'voided', 'cancelled']);
//            })
//            ->selectRaw("COALESCE(SUM({$amountExpression}), 0) as allocated_total")
//            ->first();
//
//        return round((float) ($row->allocated_total ?? 0), 4);
//    }
//
//    private function allocatedAmountForSaleLedger(string $saleLedgerId): float
//    {
//        $saleLedger = CreditCustomerLedger::query()->where('id', $saleLedgerId)->first();
//        $allocatedFromPayments = $this->allocatedAmountFromAllocationRowsForSaleLedger($saleLedgerId);
//
//        if (!$saleLedger || !$saleLedger->sales_transaction_id || !Schema::hasTable('sales_transactions')) {
//            return $allocatedFromPayments;
//        }
//
//        $salePaid = 0;
//        if (Schema::hasColumn('sales_transactions', 'credit_paid_amount')) {
//            $salePaid = round((float) DB::table('sales_transactions')
//                ->where('id', $saleLedger->sales_transaction_id)
//                ->value('credit_paid_amount'), 4);
//        }
//
//        // Important for overpayments and older allocations: a sale can be paid either
//        // by allocation rows or by the synced sale-level credit_paid_amount.
//        return max($allocatedFromPayments, $salePaid);
//    }
//
//    private function syncSaleCreditStatus(CreditCustomerLedger $saleLedger, float $saleAmount, float $allocatedAmount, string $currencyCode): void
//    {
//        if (!$saleLedger->sales_transaction_id || !Schema::hasTable('sales_transactions')) {
//            return;
//        }
//
//        $sale = SalesTransaction::where('id', $saleLedger->sales_transaction_id)->lockForUpdate()->first();
//        if (!$sale) {
//            return;
//        }
//
//        $paid = round(min($allocatedAmount, $saleAmount), 4);
//        $balance = round(max(0, $saleAmount - $paid), 4);
//        $status = $balance <= 0 ? 'paid' : ($paid > 0 ? 'partially_paid' : 'posted');
//
//        $updates = [
//            'metadata' => array_merge($this->toArray($sale->metadata), [
//                'credit_sale' => true,
//                'credit_customer_id' => $saleLedger->credit_customer_id,
//                'credit_account_number' => data_get($this->toArray($saleLedger->metadata), 'customer_account_number'),
//                'credit_paid_amount' => $paid,
//                'credit_balance_amount' => $balance,
//                'credit_status' => $status,
//                'credit_currency_code' => $currencyCode,
//            ]),
//        ];
//
//        foreach ([
//                     'credit_customer_id' => $saleLedger->credit_customer_id,
//                     'credit_account_number' => data_get($this->toArray($saleLedger->metadata), 'customer_account_number'),
//                     'is_credit_sale' => true,
//                     'credit_status' => $status,
//                     'credit_amount' => $saleAmount,
//                     'credit_paid_amount' => $paid,
//                     'credit_balance_amount' => $balance,
//                     'credit_due_date' => $saleLedger->due_date,
//                 ] as $column => $value) {
//            if (Schema::hasColumn('sales_transactions', $column)) {
//                $updates[$column] = $value;
//            }
//        }
//
//        $sale->forceFill($updates)->save();
//    }
//
//    private function markSaleAsCredit(
//        SalesTransaction $sale,
//        CreditCustomer $customer,
//        float $accountAmount,
//        string $accountCurrencyCode,
//        string $dueDate,
//        ?string $poNumber = null
//    ): void {
//        if (!Schema::hasTable('sales_transactions')) {
//            return;
//        }
//
//        $updates = [
//            'metadata' => array_merge($this->toArray($sale->metadata), [
//                'credit_sale' => true,
//                'credit_customer_id' => $customer->id,
//                'credit_account_number' => $customer->account_number,
//                'credit_customer_name' => $customer->name,
//                'credit_due_date' => $dueDate,
//                'credit_currency_code' => $accountCurrencyCode,
//                'credit_amount' => round($accountAmount, 4),
//                'credit_paid_amount' => 0,
//                'credit_balance_amount' => round($accountAmount, 4),
//                'po_number' => $poNumber,
//            ]),
//        ];
//
//        foreach ([
//                     'credit_customer_id' => $customer->id,
//                     'credit_account_number' => $customer->account_number,
//                     'is_credit_sale' => true,
//                     'credit_status' => 'posted',
//                     'credit_due_date' => $dueDate,
//                     'credit_amount' => round($accountAmount, 4),
//                     'credit_paid_amount' => 0,
//                     'credit_balance_amount' => round($accountAmount, 4),
//                 ] as $column => $value) {
//            if (Schema::hasColumn('sales_transactions', $column)) {
//                $updates[$column] = $value;
//            }
//        }
//
//        $sale->forceFill($updates)->save();
//    }
//
//    private function insertCashupPaymentRow(
//        CreditCustomerPayment $payment,
//        CreditCustomer $customer,
//        array $data,
//        string $paymentName,
//        ?string $moneyCode,
//        string $currencyCode,
//        string $baseCurrencyCode,
//        float $exchangeRate,
//        float $baseAmount,
//        ?string $reference
//    ): void {
//        if (!Schema::hasTable('sales_transaction_payments')) {
//            return;
//        }
//
//        $columns = Schema::getColumnListing('sales_transaction_payments');
//
//        $payload = [
//            'id' => (string) Str::uuid(),
//            'company_id' => $payment->company_id,
//            'branch_id' => $payment->branch_id,
//            'till_id' => $payment->till_id,
//            'shift_id' => $data['shift_id'] ?? null,
//            'shift_session_id' => $payment->shift_session_id,
//            'sales_transaction_id' => null,
//            'payment_type_id' => $payment->payment_type_id,
//            'payment_name' => 'Credit Payment - ' . $paymentName,
//            'money_code' => $moneyCode,
//            'currency_code' => $currencyCode,
//            'base_currency_code' => $baseCurrencyCode,
//            'exchange_rate' => $exchangeRate,
//            'amount' => round((float) $payment->amount, 4),
//            'base_amount' => round($baseAmount, 4),
//            'amount_tendered' => round((float) $payment->amount, 4),
//            'change_amount' => 0,
//            'reference' => $reference,
//            'approval_code' => $payment->approval_code,
//            'card_last_four' => $payment->card_last_four,
//            'metadata' => json_encode([
//                'credit_customer_payment' => true,
//                'credit_customer_id' => $customer->id,
//                'account_number' => $customer->account_number,
//                'payment_id' => $payment->id,
//                'payment_number' => $payment->payment_number,
//            ]),
//            'created_at' => now(),
//            'updated_at' => now(),
//        ];
//
//        $insert = array_intersect_key($payload, array_flip($columns));
//
//        DB::table('sales_transaction_payments')->insert($insert);
//    }
//
//    private function convertAmount(string $from, string $to, float $amount, string $companyId): float
//    {
//        $from = strtoupper($from ?: 'USD');
//        $to = strtoupper($to ?: $from);
//
//        if ($from === $to) {
//            return round($amount, 4);
//        }
//
//        try {
//            $converted = round((float) currencyConverter($from, $to, $amount, $companyId), 4);
//        } catch (\Throwable $e) {
//            $converted = 0.0;
//        }
//
//        return $converted;
//    }
//
//
//    public function reverseCreditSaleForCancellation(
//        SalesTransaction $originalSale,
//        SalesTransaction $cancelTransaction,
//        string $reason,
//        ?string $userId = null
//    ): ?CreditCustomerLedger {
//        return DB::transaction(function () use ($originalSale, $cancelTransaction, $reason, $userId) {
//            $saleLedger = CreditCustomerLedger::query()
//                ->where('sales_transaction_id', $originalSale->id)
//                ->where('entry_type', 'sale')
//                ->lockForUpdate()
//                ->first();
//
//            if (!$saleLedger) {
//                return null;
//            }
//
//            $existingReversal = CreditCustomerLedger::query()
//                ->where('credit_customer_id', $saleLedger->credit_customer_id)
//                ->where('sales_transaction_id', $cancelTransaction->id)
//                ->whereIn('entry_type', ['sale_void', 'sale_cancel', 'void', 'reversal'])
//                ->first();
//
//            if ($existingReversal) {
//                return $existingReversal;
//            }
//
//            $customer = CreditCustomer::where('id', $saleLedger->credit_customer_id)
//                ->lockForUpdate()
//                ->first();
//
//            if (!$customer) {
//                return null;
//            }
//
//            $accountAmount = round((float) $saleLedger->debit_amount, 4);
//            if ($accountAmount <= 0) {
//                return null;
//            }
//
//            $previousBalance = round((float) $customer->current_balance, 4);
//            $newBalance = round($previousBalance - $accountAmount, 4);
//            $accountCurrencyCode = strtoupper($saleLedger->currency_code ?: $customer->currency_code ?: $originalSale->currency_code ?: 'USD');
//            $availableCredit = round((float) $customer->credit_limit - $newBalance, 4);
//
//            $ledger = CreditCustomerLedger::create([
//                'id' => (string) Str::uuid(),
//                'company_id' => $saleLedger->company_id,
//                'branch_id' => $saleLedger->branch_id,
//                'credit_customer_id' => $customer->id,
//                'sales_transaction_id' => $cancelTransaction->id,
//                'shift_session_id' => $cancelTransaction->shift_session_id ?: $saleLedger->shift_session_id,
//                'till_id' => $cancelTransaction->till_id ?: $saleLedger->till_id,
//                'cashier_id' => $cancelTransaction->cashier_id ?: $saleLedger->cashier_id,
//                'entry_type' => 'sale_void',
//                'document_number' => $cancelTransaction->invoice_number,
//                'document_date' => $cancelTransaction->transaction_date ?: now()->toDateString(),
//                'due_date' => null,
//                'debit_amount' => 0,
//                'credit_amount' => $accountAmount,
//                'balance_after' => $newBalance,
//                'currency_code' => $accountCurrencyCode,
//                'base_currency_code' => $saleLedger->base_currency_code ?: $accountCurrencyCode,
//                'exchange_rate' => (float) ($saleLedger->exchange_rate ?: 1),
//                'reference' => $originalSale->invoice_number,
//                'notes' => 'Credit sale reversed because receipt was voided/cancelled. Reason: ' . $reason,
//                'metadata' => [
//                    'source' => 'credit_sale_void',
//                    'original_ledger_id' => $saleLedger->id,
//                    'original_sales_transaction_id' => $originalSale->id,
//                    'original_invoice_number' => $originalSale->invoice_number,
//                    'cancel_transaction_id' => $cancelTransaction->id,
//                    'cancel_invoice_number' => $cancelTransaction->invoice_number,
//                    'previous_balance' => $previousBalance,
//                    'reversed_amount' => $accountAmount,
//                    'balance_after_void' => $newBalance,
//                    'available_credit_after_void' => $availableCredit,
//                    'reason' => $reason,
//                ],
//                'created_by' => $userId ?: auth()->id(),
//            ]);
//
//            $customer->current_balance = $newBalance;
//            $customer->available_credit = $availableCredit;
//            $customer->save();
//
//            $this->markCreditSaleAsCancelled($originalSale, $cancelTransaction, $accountAmount, $newBalance, $accountCurrencyCode, $reason);
//
//            return $ledger->fresh();
//        });
//    }
//
//    private function markCreditSaleAsCancelled(
//        SalesTransaction $originalSale,
//        SalesTransaction $cancelTransaction,
//        float $accountAmount,
//        float $newCustomerBalance,
//        string $accountCurrencyCode,
//        string $reason
//    ): void {
//        if (!Schema::hasTable('sales_transactions')) {
//            return;
//        }
//
//        $originalMetadata = array_merge($this->toArray($originalSale->metadata), [
//            'credit_sale_cancelled' => true,
//            'credit_cancel_transaction_id' => $cancelTransaction->id,
//            'credit_cancel_invoice_number' => $cancelTransaction->invoice_number,
//            'credit_reversed_amount' => round($accountAmount, 4),
//            'credit_balance_after_cancel' => round($newCustomerBalance, 4),
//            'credit_cancel_reason' => $reason,
//            'credit_cancelled_at' => now()->toDateTimeString(),
//        ]);
//
//        $originalUpdates = ['metadata' => $originalMetadata];
//        foreach ([
//                     'credit_status' => 'cancelled',
//                     'credit_paid_amount' => 0,
//                     'credit_balance_amount' => 0,
//                 ] as $column => $value) {
//            if (Schema::hasColumn('sales_transactions', $column)) {
//                $originalUpdates[$column] = $value;
//            }
//        }
//        $originalSale->update($originalUpdates);
//
//        $cancelMetadata = array_merge($this->toArray($cancelTransaction->metadata), [
//            'credit_sale_reversal' => true,
//            'credit_original_sale_id' => $originalSale->id,
//            'credit_original_invoice_number' => $originalSale->invoice_number,
//            'credit_customer_id' => $originalSale->credit_customer_id ?? null,
//            'credit_reversed_amount' => round($accountAmount, 4),
//            'credit_currency_code' => $accountCurrencyCode,
//        ]);
//
//        $cancelUpdates = ['metadata' => $cancelMetadata];
//        foreach ([
//                     'credit_customer_id' => $originalSale->credit_customer_id ?? null,
//                     'credit_account_number' => $originalSale->credit_account_number ?? null,
//                     'is_credit_sale' => true,
//                     'credit_status' => 'cancelled_reversal',
//                     'credit_amount' => round(-1 * $accountAmount, 4),
//                     'credit_paid_amount' => 0,
//                     'credit_balance_amount' => 0,
//                 ] as $column => $value) {
//            if (Schema::hasColumn('sales_transactions', $column)) {
//                $cancelUpdates[$column] = $value;
//            }
//        }
//        $cancelTransaction->update($cancelUpdates);
//    }
//
//    public function generatePaymentNumber(string $companyId, ?string $branchId = null): string
//    {
//        return DB::transaction(function () use ($companyId, $branchId) {
//            $prefix = 'CRP';
//
//            $sequence = CreditCustomerNumberSequence::query()
//                ->where('company_id', $companyId)
//                ->where('branch_id', $branchId)
//                ->where('sequence_type', 'credit_customer_payment')
//                ->where('prefix', $prefix)
//                ->lockForUpdate()
//                ->first();
//
//            if (!$sequence) {
//                $sequence = CreditCustomerNumberSequence::create([
//                    'company_id' => $companyId,
//                    'branch_id' => $branchId,
//                    'sequence_type' => 'credit_customer_payment',
//                    'prefix' => $prefix,
//                    'last_number' => 0,
//                ]);
//
//                $sequence = CreditCustomerNumberSequence::where('id', $sequence->id)->lockForUpdate()->first();
//            }
//
//            $next = ((int) $sequence->last_number) + 1;
//            $paymentNumber = $prefix . '-' . now()->format('ymd') . '-' . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
//            $sequence->update(['last_number' => $next]);
//
//            return $paymentNumber;
//        });
//    }
//
//    public function statement(string $customerId, ?string $dateFrom = null, ?string $dateTo = null): array
//    {
//        $customer = CreditCustomer::findOrFail($customerId);
//
//        $query = CreditCustomerLedger::query()
//            ->where('credit_customer_id', $customer->id)
//            ->when($dateFrom, fn ($q) => $q->whereDate('document_date', '>=', $dateFrom))
//            ->when($dateTo, fn ($q) => $q->whereDate('document_date', '<=', $dateTo))
//            ->orderBy('document_date')
//            ->orderBy('created_at');
//
//        $rows = $query->get();
//
//        $saleIds = $rows
//            ->pluck('sales_transaction_id')
//            ->filter()
//            ->unique()
//            ->values();
//
//        $salesById = collect();
//        $itemsBySale = collect();
//        $allocationsByLedger = collect();
//
//        if (Schema::hasTable('credit_customer_payment_allocations')) {
//            $allocationsByLedger = DB::table('credit_customer_payment_allocations')
//                ->where('credit_customer_id', $customer->id)
//                ->get()
//                ->groupBy('sale_ledger_id');
//        }
//
//        if ($saleIds->isNotEmpty() && Schema::hasTable('sales_transactions')) {
//            $salesById = DB::table('sales_transactions')
//                ->whereIn('id', $saleIds)
//                ->get()
//                ->keyBy('id');
//        }
//
//        if ($saleIds->isNotEmpty() && Schema::hasTable('receipt_lines')) {
//            $receiptLineColumns = Schema::getColumnListing('receipt_lines');
//
//            $lineRows = DB::table('receipt_lines')
//                ->whereIn('sales_transaction_id', $saleIds)
//                ->orderBy('sales_transaction_id')
//                ->when(in_array('line_no', $receiptLineColumns, true), fn ($q) => $q->orderBy('line_no'))
//                ->get();
//
//            $lineIds = $lineRows->pluck('id')->filter()->unique()->values();
//            $taxesByLine = collect();
//
//            $taxTable = collect([
//                'receipt_line_taxes',
//                'receipt_line_tax_lines',
//                'sales_receipt_line_taxes',
//                'sales_transaction_line_taxes',
//            ])->first(fn ($table) => Schema::hasTable($table));
//
//            if ($taxTable && $lineIds->isNotEmpty()) {
//                $taxColumns = Schema::getColumnListing($taxTable);
//                $lineKey = in_array('receipt_line_id', $taxColumns, true)
//                    ? 'receipt_line_id'
//                    : (in_array('line_id', $taxColumns, true) ? 'line_id' : null);
//
//                if ($lineKey) {
//                    $taxesByLine = DB::table($taxTable)
//                        ->whereIn($lineKey, $lineIds)
//                        ->get()
//                        ->groupBy($lineKey)
//                        ->map(function ($taxRows) {
//                            return $taxRows->map(function ($tax) {
//                                return [
//                                    'id' => $tax->id ?? null,
//                                    'tax_id' => $tax->tax_id ?? null,
//                                    'product_tax_id' => $tax->product_tax_id ?? null,
//                                    'fiscal_tax_id' => $tax->fiscal_tax_id ?? $tax->fdms_tax_id ?? null,
//                                    'tax_code' => $tax->tax_code ?? $tax->code ?? null,
//                                    'tax_name' => $tax->tax_name ?? $tax->name ?? 'Tax',
//                                    'type' => $tax->type ?? 'percentage',
//                                    'rate' => round((float) ($tax->rate ?? $tax->tax_rate ?? $tax->tax_percent ?? 0), 4),
//                                    'tax_percent' => round((float) ($tax->tax_percent ?? $tax->rate ?? $tax->tax_rate ?? 0), 4),
//                                    'is_inclusive' => (bool) ($tax->is_inclusive ?? false),
//                                    'taxable_amount' => round((float) ($tax->taxable_amount ?? $tax->net_amount ?? 0), 4),
//                                    'tax_amount' => round((float) ($tax->tax_amount ?? $tax->amount ?? 0), 4),
//                                    'sales_amount_with_tax' => round((float) ($tax->sales_amount_with_tax ?? $tax->gross_amount ?? 0), 4),
//                                ];
//                            })->values();
//                        });
//                }
//            }
//
//            $itemsBySale = $lineRows
//                ->groupBy('sales_transaction_id')
//                ->map(function ($lines) use ($taxesByLine) {
//                    return $lines->map(function ($line) use ($taxesByLine) {
//                        $metadata = $line->metadata ?? null;
//
//                        if (is_string($metadata) && $metadata !== '') {
//                            $metadata = json_decode($metadata, true) ?: null;
//                        }
//
//                        $taxes = $taxesByLine->get($line->id ?? '', collect());
//
//                        $quantity = round((float) ($line->line_quantity ?? $line->quantity ?? 0), 4);
//                        $unitPrice = round((float) ($line->line_price ?? $line->unit_price ?? $line->price ?? 0), 4);
//                        $subtotal = round((float) ($line->line_subtotal ?? $line->subtotal ?? 0), 4);
//                        $discount = round((float) ($line->line_discount ?? $line->discount_amount ?? 0), 4);
//                        $tax = round((float) ($line->line_tax ?? $line->tax_amount ?? $taxes->sum('tax_amount') ?? 0), 4);
//                        $total = round((float) ($line->line_total ?? $line->total ?? 0), 4);
//
//                        return [
//                            'id' => $line->id ?? null,
//                            'receipt_line_id' => $line->id ?? null,
//                            'sales_transaction_id' => $line->sales_transaction_id ?? null,
//                            'product_id' => $line->product_id ?? null,
//                            'product_price_id' => $line->product_price_id ?? null,
//                            'price_level_id' => $line->price_level_id ?? null,
//                            'line_no' => (int) ($line->line_no ?? 0),
//                            'product_name' => $line->line_name ?? $line->product_name ?? $line->name ?? 'Product',
//                            'line_name' => $line->line_name ?? $line->product_name ?? $line->name ?? 'Product',
//                            'product_code' => $line->product_code ?? $line->code ?? null,
//                            'sku' => $line->sku ?? null,
//                            'barcode' => $line->barcode ?? null,
//                            'quantity' => $quantity,
//                            'line_quantity' => $quantity,
//                            'unit_price' => $unitPrice,
//                            'line_price' => $unitPrice,
//                            'subtotal' => $subtotal,
//                            'line_subtotal' => $subtotal,
//                            'discount' => $discount,
//                            'line_discount' => $discount,
//                            'tax' => $tax,
//                            'line_tax' => $tax,
//                            'total' => $total,
//                            'line_total' => $total,
//                            'currency_code' => $line->currency_code ?? null,
//                            'uom' => data_get($metadata, 'uom'),
//                            'uom_code' => data_get($metadata, 'uom_code'),
//                            'taxes' => $taxes->values(),
//                            'tax_breakdown' => $taxes->values(),
//                            'tax_total' => round((float) $taxes->sum('tax_amount'), 4),
//                            'metadata' => $metadata,
//                        ];
//                    })->values();
//                });
//        }
//
//        $cancelledSaleIds = $rows
//            ->filter(function (CreditCustomerLedger $row) {
//                return in_array((string) $row->entry_type, ['sale_void', 'sale_cancel', 'void', 'reversal'], true);
//            })
//            ->map(function (CreditCustomerLedger $row) {
//                $metadata = $this->toArray($row->metadata);
//                return $metadata['original_sales_transaction_id']
//                    ?? $metadata['original_sale_id']
//                    ?? $metadata['cancelled_original_sale_id']
//                    ?? null;
//            })
//            ->filter()
//            ->unique()
//            ->values();
//
//        $runningBalance = 0.0;
//
//        $statementRows = $rows->map(function (CreditCustomerLedger $row) use (&$runningBalance, $itemsBySale, $salesById, $allocationsByLedger, $cancelledSaleIds) {
//            $items = $itemsBySale->get($row->sales_transaction_id, collect())->values();
//            $sale = $row->sales_transaction_id ? $salesById->get($row->sales_transaction_id) : null;
//            $allocations = $allocationsByLedger->get($row->id, collect());
//            $allocatedFromPayments = round((float) $allocations->sum('amount'), 4);
//            $allocatedFromSaleRecord = $sale && isset($sale->credit_paid_amount)
//                ? round((float) $sale->credit_paid_amount, 4)
//                : 0.0;
//            $allocatedTotal = max($allocatedFromPayments, $allocatedFromSaleRecord);
//            $saleIsCancelled = $row->entry_type === 'sale' && (
//                    $cancelledSaleIds->contains($row->sales_transaction_id)
//                    || in_array((string) ($sale->status ?? ''), ['cancelled', 'voided'], true)
//                    || in_array((string) ($sale->credit_status ?? ''), ['cancelled', 'voided'], true)
//                );
//            $isReversalRow = in_array((string) $row->entry_type, ['sale_void', 'sale_cancel', 'void', 'reversal'], true);
//
//            /*
//             * Cancelled / voided credit sales must not affect the customer's live account balance.
//             * Some older cancellations only marked sales_transactions.status = cancelled and did not
//             * create a sale_void ledger row. Other newer cancellations create a sale_void row.
//             * To avoid double-counting, the statement treats both the original cancelled sale and
//             * its sale_void row as informational rows with effective debit/credit of zero.
//             */
//            $effectiveDebit = $saleIsCancelled || $isReversalRow
//                ? 0.0
//                : round((float) $row->debit_amount, 4);
//            $effectiveCredit = $isReversalRow
//                ? 0.0
//                : round((float) $row->credit_amount, 4);
//
//            $outstanding = $row->entry_type === 'sale'
//                ? ($saleIsCancelled ? 0 : round(max(0, $effectiveDebit - $allocatedTotal), 4))
//                : 0;
//
//            $runningBalance = round($runningBalance + $effectiveDebit - $effectiveCredit, 4);
//
//            return [
//                ...$row->toArray(),
//                'original_debit_amount' => round((float) $row->debit_amount, 4),
//                'original_credit_amount' => round((float) $row->credit_amount, 4),
//                'effective_debit_amount' => $effectiveDebit,
//                'effective_credit_amount' => $effectiveCredit,
//                'is_cancelled' => $saleIsCancelled || $isReversalRow,
//                'display_status' => $saleIsCancelled || $isReversalRow ? 'cancelled' : ($sale->status ?? $row->entry_type),
//                'balance_after' => $runningBalance,
//                'sale' => $sale,
//                'sales_transaction' => $sale,
//                'transaction_date' => optional($row->document_date)->toDateString(),
//                'transaction_type' => $row->entry_type,
//                'reference_no' => $row->document_number,
//                'receipt_number' => $sale->invoice_number ?? $sale->receipt_number ?? $row->document_number,
//                'description' => $row->notes,
//                'balance' => $runningBalance,
//                'allocated_amount' => $allocatedTotal,
//                'outstanding_amount' => $outstanding,
//                'allocation_status' => $row->entry_type === 'sale'
//                    ? ($saleIsCancelled ? 'cancelled' : ($outstanding <= 0 ? 'paid' : ($allocatedTotal > 0 ? 'partially_paid' : 'unpaid')))
//                    : null,
//                'payment_allocations' => $allocations->values(),
//                'items' => $items,
//                'line_items' => $items,
//                'receipt_lines' => $items,
//                'item_count' => $items->count(),
//                'quantity_total' => round((float) $items->sum('quantity'), 4),
//                'subtotal_total' => round((float) $items->sum('subtotal'), 4),
//                'discount_total' => round((float) $items->sum('discount'), 4),
//                'tax_total' => round((float) $items->sum('tax'), 4),
//                'line_total' => round((float) $items->sum('total'), 4),
//                'item_summary' => $items->map(function ($item) {
//                    return trim(($item['product_name'] ?? 'Product') . ' x ' . number_format((float) ($item['quantity'] ?? 0), 4, '.', ''));
//                })->implode(', '),
//            ];
//        })->values();
//
//        $debitTotal = round((float) $statementRows->sum('effective_debit_amount'), 4);
//        $creditTotal = round((float) $statementRows->sum('effective_credit_amount'), 4);
//        $closingBalance = round($debitTotal - $creditTotal, 4);
//        $amountDue = round(max(0, $closingBalance), 4);
//        $creditBalance = round(max(0, -$closingBalance), 4);
//        $availableCredit = round((float) $customer->credit_limit - $closingBalance, 4);
//
//        /*
//         * Only sync the master account on a full statement. A date-filtered statement is
//         * a report and must not overwrite the customer's current balance.
//         */
//        if (!$dateFrom && !$dateTo) {
//            $customer->current_balance = $closingBalance;
//            $customer->available_credit = $availableCredit;
//            $customer->save();
//            $customer = $customer->fresh();
//        }
//
//        return [
//            'customer' => $customer,
//            'opening_balance' => round((float) $customer->opening_balance, 4),
//            'debit_total' => $debitTotal,
//            'credit_total' => $creditTotal,
//            'closing_balance' => $closingBalance,
//            'amount_due' => $amountDue,
//            'credit_balance' => $creditBalance,
//            'available_credit' => $availableCredit,
//            'rows' => $statementRows,
//        ];
//    }
//
//    public function ageingReport(string $companyId, ?string $branchId = null): array
//    {
//        $today = Carbon::today();
//
//        return CreditCustomer::query()
//            ->where('company_id', $companyId)
//            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
//            ->with(['ledger' => function ($q) {
//                $q->where('entry_type', 'sale')->where('debit_amount', '>', 0);
//            }])
//            ->get()
//            ->map(function (CreditCustomer $customer) use ($today) {
//                $buckets = [
//                    'current' => 0,
//                    'days_1_30' => 0,
//                    'days_31_60' => 0,
//                    'days_61_90' => 0,
//                    'days_90_plus' => 0,
//                ];
//
//                foreach ($customer->ledger as $row) {
//                    if ($this->saleLedgerIsCancelled($row)) {
//                        continue;
//                    }
//
//                    $amount = (float) $row->debit_amount;
//                    $allocated = $this->allocatedAmountForSaleLedger($row->id);
//                    $outstanding = max(0, $amount - $allocated);
//                    $days = $row->due_date ? Carbon::parse($row->due_date)->diffInDays($today, false) : 0;
//
//                    if ($days <= 0) {
//                        $buckets['current'] += $outstanding;
//                    } elseif ($days <= 30) {
//                        $buckets['days_1_30'] += $outstanding;
//                    } elseif ($days <= 60) {
//                        $buckets['days_31_60'] += $outstanding;
//                    } elseif ($days <= 90) {
//                        $buckets['days_61_90'] += $outstanding;
//                    } else {
//                        $buckets['days_90_plus'] += $outstanding;
//                    }
//                }
//
//                return [
//                    'customer_id' => $customer->id,
//                    'account_number' => $customer->account_number,
//                    'name' => $customer->name,
//                    'currency_code' => $customer->currency_code,
//                    'credit_limit' => round((float) $customer->credit_limit, 4),
//                    'current_balance' => round((float) $customer->current_balance, 4),
//                    'available_credit' => round((float) $customer->available_credit, 4),
//                    'buckets' => array_map(fn ($value) => round((float) $value, 4), $buckets),
//                ];
//            })
//            ->values()
//            ->all();
//    }
//
//
//    private function saleLedgerIsCancelled(CreditCustomerLedger $saleLedger): bool
//    {
//        if ($saleLedger->entry_type !== 'sale') {
//            return false;
//        }
//
//        if ($saleLedger->sales_transaction_id && Schema::hasTable('sales_transactions')) {
//            $sale = DB::table('sales_transactions')
//                ->where('id', $saleLedger->sales_transaction_id)
//                ->first();
//
//            if ($sale && (
//                    in_array((string) ($sale->status ?? ''), ['cancelled', 'voided'], true)
//                    || in_array((string) ($sale->credit_status ?? ''), ['cancelled', 'voided'], true)
//                )) {
//                return true;
//            }
//        }
//
//        return CreditCustomerLedger::query()
//            ->where('credit_customer_id', $saleLedger->credit_customer_id)
//            ->whereIn('entry_type', ['sale_void', 'sale_cancel', 'void', 'reversal'])
//            ->where(function ($query) use ($saleLedger) {
//                $query->where('sales_transaction_id', $saleLedger->sales_transaction_id)
//                    ->orWhere('reference', $saleLedger->document_number);
//            })
//            ->exists();
//    }
//
//    private function isSaleLedgerCancelled(CreditCustomerLedger $row): bool
//    {
//        if ((string) $row->entry_type !== 'sale') {
//            return false;
//        }
//
//        if (!$row->sales_transaction_id || !Schema::hasTable('sales_transactions')) {
//            return false;
//        }
//
//        $sale = DB::table('sales_transactions')
//            ->where('id', $row->sales_transaction_id)
//            ->first();
//
//        if (!$sale) {
//            return false;
//        }
//
//        $metadata = $this->toArray($sale->metadata ?? []);
//
//        return in_array((string) ($sale->status ?? ''), ['cancelled', 'voided'], true)
//            || in_array((string) ($sale->credit_status ?? ''), ['cancelled', 'voided'], true)
//            || (bool) ($metadata['cancelled'] ?? false)
//            || (bool) ($metadata['credit_sale_cancelled'] ?? false);
//    }
//
//
//    private function allocatedAmountFromPaymentRowsForSaleLedger(string $saleLedgerId): float
//    {
//        return $this->allocatedAmountFromAllocationRowsForSaleLedger($saleLedgerId);
//    }
//
//    private function resyncCustomerSaleCreditStatuses(CreditCustomer $customer): void
//    {
//        $saleLedgers = CreditCustomerLedger::query()
//            ->where('credit_customer_id', $customer->id)
//            ->where('entry_type', 'sale')
//            ->where('debit_amount', '>', 0)
//            ->orderBy('document_date')
//            ->orderBy('created_at')
//            ->get();
//
//        foreach ($saleLedgers as $saleLedger) {
//            if ($this->saleLedgerIsCancelled($saleLedger)) {
//                $this->syncSaleCreditStatus(
//                    $saleLedger,
//                    round((float) $saleLedger->debit_amount, 4),
//                    0,
//                    $saleLedger->currency_code ?: $customer->currency_code ?: 'USD'
//                );
//                continue;
//            }
//
//            $allocated = $this->allocatedAmountFromPaymentRowsForSaleLedger($saleLedger->id);
//
//            $this->syncSaleCreditStatus(
//                $saleLedger,
//                round((float) $saleLedger->debit_amount, 4),
//                $allocated,
//                $saleLedger->currency_code ?: $customer->currency_code ?: 'USD'
//            );
//        }
//    }
//
//    private function toArray($value): array
//    {
//        if (is_array($value)) return $value;
//        if (is_object($value)) return json_decode(json_encode($value), true) ?: [];
//        if (is_string($value) && $value !== '') return json_decode($value, true) ?: [];
//        return [];
//    }
//
//    public function reversePayment(
//        CreditCustomerPayment $payment,
//        string $reason,
//        ?string $userId = null
//    ): ?CreditCustomerLedger {
//        return DB::transaction(function () use ($payment, $reason, $userId) {
//            $payment = CreditCustomerPayment::where('id', $payment->id)
//                ->lockForUpdate()
//                ->firstOrFail();
//
//            if ($payment->status === 'reversed') {
//                return CreditCustomerLedger::query()
//                    ->where('payment_id', $payment->id)
//                    ->whereIn('entry_type', ['payment_reversal', 'reversal'])
//                    ->first();
//            }
//
//            $customer = CreditCustomer::where('id', $payment->credit_customer_id)
//                ->lockForUpdate()
//                ->firstOrFail();
//
//            $paymentLedger = CreditCustomerLedger::query()
//                ->where('payment_id', $payment->id)
//                ->where('entry_type', 'payment')
//                ->lockForUpdate()
//                ->first();
//
//            if (!$paymentLedger) {
//                throw new \Exception('Original payment ledger entry was not found.');
//            }
//
//            $accountAmount = round((float) $paymentLedger->credit_amount, 4);
//
//            if ($accountAmount <= 0) {
//                throw new \Exception('Invalid payment amount for reversal.');
//            }
//
//            $previousBalance = round((float) $customer->current_balance, 4);
//            $newBalance = round($previousBalance + $accountAmount, 4);
//            $availableCredit = round((float) $customer->credit_limit - $newBalance, 4);
//
//            $reversalNumber = 'REV-' . $payment->payment_number;
//
//            $ledger = CreditCustomerLedger::create([
//                'id' => (string) Str::uuid(),
//                'company_id' => $payment->company_id,
//                'branch_id' => $payment->branch_id,
//                'credit_customer_id' => $customer->id,
//                'payment_id' => $payment->id,
//                'shift_session_id' => $payment->shift_session_id,
//                'till_id' => $payment->till_id,
//                'cashier_id' => $payment->cashier_id,
//                'entry_type' => 'payment_reversal',
//                'document_number' => $reversalNumber,
//                'document_date' => now()->toDateString(),
//                'debit_amount' => $accountAmount,
//                'credit_amount' => 0,
//                'balance_after' => $newBalance,
//                'currency_code' => $paymentLedger->currency_code,
//                'base_currency_code' => $paymentLedger->base_currency_code,
//                'exchange_rate' => $paymentLedger->exchange_rate,
//                'reference' => $payment->payment_number,
//                'notes' => 'Credit customer payment reversed. Reason: ' . $reason,
//                'metadata' => [
//                    'source' => 'credit_customer_payment_reversal',
//                    'original_payment_id' => $payment->id,
//                    'original_payment_number' => $payment->payment_number,
//                    'original_payment_ledger_id' => $paymentLedger->id,
//                    'previous_balance' => $previousBalance,
//                    'reversed_amount' => $accountAmount,
//                    'balance_after_reversal' => $newBalance,
//                    'available_credit_after_reversal' => $availableCredit,
//                    'reason' => $reason,
//                ],
//                'created_by' => $userId ?: auth()->id(),
//            ]);
//
//            $affectedSaleLedgerIds = collect();
//
//            if (Schema::hasTable('credit_customer_payment_allocations')) {
//                $affectedSaleLedgerIds = DB::table('credit_customer_payment_allocations')
//                    ->where('credit_customer_payment_id', $payment->id)
//                    ->pluck('sale_ledger_id');
//
//                DB::table('credit_customer_payment_allocations')
//                    ->where('credit_customer_payment_id', $payment->id)
//                    ->delete();
//            }
//
//            $customer->current_balance = $newBalance;
//            $customer->available_credit = $availableCredit;
//            $customer->save();
//
//            $payment->status = 'reversed';
//            $payment->notes = trim(($payment->notes ? $payment->notes . "\n" : '') . 'Reversed: ' . $reason);
//            $payment->metadata = array_merge($this->toArray($payment->metadata), [
//                'reversed' => true,
//                'reversed_at' => now()->toDateTimeString(),
//                'reversed_by' => $userId ?: auth()->id(),
//                'reversal_reason' => $reason,
//                'reversal_ledger_id' => $ledger->id,
//            ]);
//            $payment->save();
//
//            // Rebuild sale-level paid/outstanding status after removing this payment's allocations.
//            // This avoids stale credit_paid_amount values making reversed payments still look paid.
//            $this->resyncCustomerSaleCreditStatuses($customer);
//
//            $this->insertCashupPaymentReversalRow($payment, $customer, $ledger, $reason);
//
//            return $ledger->fresh();
//        });
//    }
//    private function insertCashupPaymentReversalRow(
//        CreditCustomerPayment $payment,
//        CreditCustomer $customer,
//        CreditCustomerLedger $reversalLedger,
//        string $reason
//    ): void {
//        if (!Schema::hasTable('sales_transaction_payments')) {
//            return;
//        }
//
//        $columns = Schema::getColumnListing('sales_transaction_payments');
//
//        $payload = [
//            'id' => (string) Str::uuid(),
//            'company_id' => $payment->company_id,
//            'branch_id' => $payment->branch_id,
//            'till_id' => $payment->till_id,
//            'shift_id' => null,
//            'shift_session_id' => $payment->shift_session_id,
//            'sales_transaction_id' => null,
//            'payment_type_id' => $payment->payment_type_id,
//            'payment_name' => 'Credit Payment Reversal - ' . $payment->payment_name,
//            'money_code' => $payment->money_code,
//            'currency_code' => $payment->currency_code,
//            'base_currency_code' => $payment->base_currency_code,
//            'exchange_rate' => $payment->exchange_rate,
//            'amount' => -1 * round((float) $payment->amount, 4),
//            'base_amount' => -1 * round((float) $payment->base_amount, 4),
//            'amount_tendered' => -1 * round((float) $payment->amount, 4),
//            'change_amount' => 0,
//            'reference' => 'REV-' . $payment->payment_number,
//            'approval_code' => $payment->approval_code,
//            'card_last_four' => $payment->card_last_four,
//            'metadata' => json_encode([
//                'credit_customer_payment_reversal' => true,
//                'credit_customer_id' => $customer->id,
//                'account_number' => $customer->account_number,
//                'payment_id' => $payment->id,
//                'payment_number' => $payment->payment_number,
//                'reversal_ledger_id' => $reversalLedger->id,
//                'reason' => $reason,
//            ]),
//            'created_at' => now(),
//            'updated_at' => now(),
//        ];
//
//        DB::table('sales_transaction_payments')
//            ->insert(array_intersect_key($payload, array_flip($columns)));
//    }
//}


namespace App\Services\Sales;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Currency\Models\PaymentType;
use Modules\Sales\Models\CreditCustomer;
use Modules\Sales\Models\CreditCustomerLedger;
use Modules\Sales\Models\CreditCustomerNumberSequence;
use Modules\Sales\Models\CreditCustomerPayment;
use Modules\Sales\Models\SalesTransaction;
use function App\Helpers\currencyConverter;

class CreditCustomerService
{
    /**
     * Calculate the account-currency effect of a real payment. A positive balance
     * means the customer owes money; a negative balance means customer credit.
     */
    public function calculatePaymentImpact(float $previousBalance, float $accountAmount): array
    {
        $previousBalance = round($previousBalance, 4);
        $accountAmount = round($accountAmount, 4);

        if ($accountAmount <= 0) {
            throw new \InvalidArgumentException('Payment account amount must be greater than zero.');
        }

        $newBalance = round($previousBalance - $accountAmount, 4);
        $appliedToDebt = round(min($accountAmount, max(0, $previousBalance)), 4);

        return [
            'previous_balance' => $previousBalance,
            'new_balance' => $newBalance,
            'applied_to_existing_debt' => $appliedToDebt,
            'overpayment_credit_created' => round(max(0, $accountAmount - $appliedToDebt), 4),
            'customer_credit_after_payment' => round(max(0, -$newBalance), 4),
        ];
    }

    /**
     * Apply the account-status and explicit-overpayment rules after conversion
     * into the customer's account currency. The locked customer row is the
     * authoritative balance used by recordPayment().
     */
    public function assessPaymentPolicy(
        string $status,
        bool $isActive,
        float $previousBalance,
        float $accountAmount,
        bool $allowAccountCredit = false
    ): array {
        if (!$isActive) {
            throw new \InvalidArgumentException('Customer account is inactive.');
        }

        $status = strtolower(trim($status));
        $impact = $this->calculatePaymentImpact($previousBalance, $accountAmount);

        if ($status === 'pending') {
            throw new \InvalidArgumentException('Pending customer accounts cannot receive payments yet. Approve the account first.');
        }

        if (in_array($status, ['blocked', 'suspended'], true)) {
            if ($impact['previous_balance'] <= 0) {
                throw new \InvalidArgumentException('Blocked or suspended accounts may only settle an existing outstanding debt.');
            }

            if ($impact['overpayment_credit_created'] > 0) {
                throw new \InvalidArgumentException(sprintf(
                    'Blocked or suspended accounts cannot be overpaid. The maximum account-currency payment is %.4f.',
                    $impact['previous_balance']
                ));
            }

            return $impact;
        }

        if ($status !== 'approved') {
            throw new \InvalidArgumentException('Customer account is not approved to receive payments.');
        }

        if ($impact['overpayment_credit_created'] > 0 && !$allowAccountCredit) {
            throw new \InvalidArgumentException('This payment creates customer account credit. Confirm the overpayment before posting it.');
        }

        return $impact;
    }

    public function generateAccountNumber(string $companyId, ?string $branchId = null, string $prefix = 'CR'): string
    {
        return DB::transaction(function () use ($companyId, $branchId, $prefix) {
            $sequence = CreditCustomerNumberSequence::query()
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('sequence_type', 'credit_customer_account')
                ->where('prefix', $prefix)
                ->lockForUpdate()
                ->first();

            if (!$sequence) {
                $sequence = CreditCustomerNumberSequence::create([
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'sequence_type' => 'credit_customer_account',
                    'prefix' => $prefix,
                    'last_number' => 0,
                ]);

                $sequence = CreditCustomerNumberSequence::where('id', $sequence->id)
                    ->lockForUpdate()
                    ->first();
            }

            $next = ((int)$sequence->last_number) + 1;
            $accountNumber = $prefix . '-' . str_pad((string)$next, 6, '0', STR_PAD_LEFT);

            while (CreditCustomer::where('company_id', $companyId)->where('account_number', $accountNumber)->exists()) {
                $next++;
                $accountNumber = $prefix . '-' . str_pad((string)$next, 6, '0', STR_PAD_LEFT);
            }

            $sequence->update(['last_number' => $next]);

            return $accountNumber;
        });
    }

    public function recalculateCustomerBalance(CreditCustomer $customer): CreditCustomer
    {
        $ledgers = CreditCustomerLedger::query()
            ->where('credit_customer_id', $customer->id)
            ->orderBy('document_date')
            ->orderBy('created_at')
            ->get();

        $cancelledSaleIds = $ledgers
            ->filter(fn(CreditCustomerLedger $row) => in_array((string)$row->entry_type, ['sale_void', 'sale_cancel', 'void', 'reversal'], true))
            ->map(function (CreditCustomerLedger $row) {
                $metadata = $this->toArray($row->metadata);
                return $metadata['original_sales_transaction_id']
                    ?? $metadata['original_sale_id']
                    ?? $metadata['cancelled_original_sale_id']
                    ?? null;
            })
            ->filter()
            ->unique()
            ->values();

        $balance = 0.0;

        foreach ($ledgers as $ledger) {
            $isReversalRow = in_array((string)$ledger->entry_type, ['sale_void', 'sale_cancel', 'void', 'reversal'], true);
            $isCancelledSale = (string)$ledger->entry_type === 'sale' && (
                    $cancelledSaleIds->contains($ledger->sales_transaction_id)
                    || $this->saleLedgerIsCancelled($ledger)
                );

            if ($isReversalRow || $isCancelledSale) {
                continue;
            }

            $balance += round((float)$ledger->debit_amount, 4);
            $balance -= round((float)$ledger->credit_amount, 4);
        }

        $balance = round($balance, 4);
        $customer->current_balance = $balance;
        $customer->available_credit = round((float)$customer->credit_limit - $balance, 4);
        $customer->save();

        return $customer->fresh();
    }

    public function assertCanBuyOnCredit(CreditCustomer $customer, float $amount, ?string $poNumber = null): void
    {
        if (!$customer->is_active || $customer->status !== 'approved' || !$customer->allow_credit_sales) {
            throw new \Exception('Customer is not approved for credit sales.');
        }

        if ($customer->requires_po_number && !trim((string)$poNumber)) {
            throw new \Exception('Purchase order number is required for this credit customer.');
        }

        $available = round((float)$customer->credit_limit - (float)$customer->current_balance, 4);

        if ($amount > $available + 0.0001) {
            throw new \Exception('Credit limit exceeded. Available credit: ' . number_format($available, 2));
        }
    }

    public function postCreditSale(
        SalesTransaction $sale,
        CreditCustomer   $customer,
        float            $amount,
        string           $currencyCode,
        ?string          $baseCurrencyCode = null,
        float            $exchangeRate = 1,
        ?string          $shiftSessionId = null,
        ?string          $tillId = null,
        ?string          $cashierId = null,
        ?string          $poNumber = null,
        ?string          $notes = null
    ): CreditCustomerLedger
    {
        return DB::transaction(function () use ($sale, $customer, $amount, $currencyCode, $baseCurrencyCode, $exchangeRate, $shiftSessionId, $tillId, $cashierId, $poNumber, $notes) {
            $customer = CreditCustomer::where('id', $customer->id)->lockForUpdate()->firstOrFail();

            $accountCurrencyCode = strtoupper($customer->currency_code ?: $currencyCode ?: 'USD');
            $saleCurrencyCode = strtoupper($currencyCode ?: $accountCurrencyCode);
            $accountAmount = $this->convertAmount(
                $saleCurrencyCode,
                $accountCurrencyCode,
                $amount,
                $sale->company_id,
                $sale->branch_id
            );

            $this->assertCanBuyOnCredit($customer, $accountAmount, $poNumber);

            $previousBalance = round((float)$customer->current_balance, 4);
            $existingCreditBeforeSale = round(max(0, abs(min(0, $previousBalance))), 4);
            $paidByExistingCredit = round(min($accountAmount, $existingCreditBeforeSale), 4);
            $newBalance = round($previousBalance + $accountAmount, 4);
            $creditBalanceAfterSale = round(max(0, abs(min(0, $newBalance))), 4);
            $dueDate = now()->addDays((int)($customer->payment_terms_days ?: 30))->toDateString();

            $ledger = CreditCustomerLedger::create([
                'id' => (string)Str::uuid(),
                'company_id' => $sale->company_id,
                'branch_id' => $sale->branch_id,
                'credit_customer_id' => $customer->id,
                'sales_transaction_id' => $sale->id,
                'shift_session_id' => $shiftSessionId ?: $sale->shift_session_id,
                'till_id' => $tillId ?: $sale->till_id,
                'cashier_id' => $cashierId ?: $sale->cashier_id,
                'entry_type' => 'sale',
                'document_number' => $sale->invoice_number,
                'document_date' => $sale->transaction_date ?: now()->toDateString(),
                'due_date' => $dueDate,
                'debit_amount' => round($accountAmount, 4),
                'credit_amount' => 0,
                'balance_after' => $newBalance,
                'currency_code' => $accountCurrencyCode,
                'base_currency_code' => $baseCurrencyCode ?: $accountCurrencyCode,
                'exchange_rate' => $saleCurrencyCode === $accountCurrencyCode ? 1 : round($accountAmount / max($amount, 0.0001), 8),
                'reference' => $poNumber,
                'notes' => $notes ?: 'Credit sale posted to customer account.',
                'metadata' => [
                    'source' => 'pos_credit_sale',
                    'invoice_number' => $sale->invoice_number,
                    'po_number' => $poNumber,
                    'customer_account_number' => $customer->account_number,
                    'sale_currency_code' => $saleCurrencyCode,
                    'sale_amount' => round($amount, 4),
                    'account_currency_code' => $accountCurrencyCode,
                    'account_amount' => round($accountAmount, 4),
                    'previous_balance' => $previousBalance,
                    'existing_credit_before_sale' => $existingCreditBeforeSale,
                    'paid_by_existing_credit' => $paidByExistingCredit,
                    'customer_credit_after_sale' => $creditBalanceAfterSale,
                    'balance_after_sale' => $newBalance,
                ],
                'created_by' => auth()->id(),
            ]);

            $customer->current_balance = $newBalance;
            $customer->available_credit = round((float)$customer->credit_limit - $newBalance, 4);
            $customer->save();

            $this->markSaleAsCredit($sale, $customer, $accountAmount, $accountCurrencyCode, $dueDate, $poNumber);

            // If the customer had prepaid credit before this sale, automatically consume it.
            // Example: balance was -50.00 credit, new sale is 90.00:
            // - customer balance becomes 40.00 owing
            // - 50.00 is allocated to this receipt/product sale immediately
            // - the report recognises only the paid portion and keeps 40.00 outstanding
            if ($paidByExistingCredit > 0) {
                $autoAllocated = $this->applyExistingCustomerCreditToNewSale(
                    customer: $customer,
                    saleLedger: $ledger,
                    saleAmount: $accountAmount,
                    maxAmountToApply: $paidByExistingCredit,
                    accountCurrencyCode: $accountCurrencyCode
                );

                // If the allocation table is unavailable, still keep the sale status correct.
                // When the table exists, reports will read the allocation rows created above.
                $this->syncSaleCreditStatus(
                    $ledger,
                    $accountAmount,
                    max($autoAllocated, $paidByExistingCredit),
                    $accountCurrencyCode
                );
            }

            return $ledger;
        });
    }

    public function recordPayment(array $data): CreditCustomerPayment
    {
        return DB::transaction(function () use ($data) {
            $customer = CreditCustomer::query()
                ->where('id', $data['credit_customer_id'])
                ->where('company_id', $data['company_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if (!$customer->is_active) {
                throw new \InvalidArgumentException('Customer account is inactive.');
            }

            if (
                $customer->branch_id
                && !empty($data['branch_id'])
                && (string) $customer->branch_id !== (string) $data['branch_id']
            ) {
                throw new \InvalidArgumentException('Customer account does not belong to this branch.');
            }

            /*
             * POS requests can be retried after a timeout or double click. The
             * locked customer row serialises payments for this account, while the
             * client key stored in immutable payment metadata lets us return the
             * original event instead of posting the money twice.
             */
            $idempotencyKey = trim((string) ($data['idempotency_key'] ?? ''));
            if ($idempotencyKey !== '') {
                $existingPayment = CreditCustomerPayment::query()
                    ->withTrashed()
                    ->where('company_id', $data['company_id'])
                    ->where('credit_customer_id', $customer->id)
                    ->orderBy('created_at')
                    ->get()
                    ->first(function (CreditCustomerPayment $candidate) use ($idempotencyKey) {
                        return hash_equals(
                            (string) data_get($candidate->metadata, 'idempotency_key', ''),
                            $idempotencyKey
                        );
                    });

                if ($existingPayment) {
                    $existingPayment->setAttribute('idempotent_replay', true);
                    return $existingPayment;
                }
            }

            $amount = round((float)$data['amount'], 4);
            if ($amount <= 0) {
                throw new \InvalidArgumentException('Payment amount must be greater than zero.');
            }

            $paymentType = null;
            if (!empty($data['payment_type_id'])) {
                $paymentType = PaymentType::query()
                    ->where('id', $data['payment_type_id'])
                    ->where('company_id', $data['company_id'])
                    ->where('is_active', true)
                    ->where(function ($branchQuery) use ($data) {
                        $branchQuery->whereNull('branch_id');
                        if (!empty($data['branch_id'])) {
                            $branchQuery->orWhere('branch_id', $data['branch_id']);
                        }
                    })
                    ->first();

                if (!$paymentType) {
                    throw new \InvalidArgumentException('Payment type is not active for this company and branch.');
                }

                if (in_array(strtolower((string) $paymentType->money_code), [
                    'customer_credit',
                    'credit_customer',
                    'credit_account',
                    'account',
                ], true)) {
                    throw new \InvalidArgumentException('A customer account payment must use a physical tender.');
                }
            }

            $paymentCurrencyCode = strtoupper($data['currency_code'] ?? $customer->currency_code ?? 'USD');
            $accountCurrencyCode = strtoupper($customer->currency_code ?: $paymentCurrencyCode);
            $baseCurrencyCode = strtoupper($data['base_currency_code'] ?? $accountCurrencyCode);

            /*
             * The customer balance is always kept in the customer's account currency.
             * Example: customer account is USD, cashier receives ZWG.
             * We store the received ZWG in credit_customer_payments, then convert it to USD
             * and use that USD account amount to reduce the balance and allocate oldest invoices.
             */
            $accountAmount = isset($data['account_amount'])
                ? round((float) $data['account_amount'], 4)
                : $this->convertAmount(
                    $paymentCurrencyCode,
                    $accountCurrencyCode,
                    $amount,
                    $data['company_id'],
                    $data['branch_id'] ?? null
                );
            $baseAmount = isset($data['base_amount'])
                ? round((float) $data['base_amount'], 4)
                : $this->convertAmount(
                    $paymentCurrencyCode,
                    $baseCurrencyCode,
                    $amount,
                    $data['company_id'],
                    $data['branch_id'] ?? null
                );

            if ($accountAmount <= 0) {
                $fallbackRate = (float)($data['exchange_rate'] ?? 0);
                if ($paymentCurrencyCode !== $accountCurrencyCode && $fallbackRate > 0) {
                    $accountAmount = round($amount * $fallbackRate, 4);
                }
            }

            if ($accountAmount <= 0) {
                throw new \InvalidArgumentException('Payment could not reduce customer balance because the converted account amount resolved to zero. Check currency/exchange rate setup.');
            }

            if ($baseAmount <= 0) {
                $baseAmount = $accountCurrencyCode === $baseCurrencyCode
                    ? $accountAmount
                    : $this->convertAmount(
                        $accountCurrencyCode,
                        $baseCurrencyCode,
                        $accountAmount,
                        $data['company_id'],
                        $data['branch_id'] ?? null
                    );
            }

            if ($baseAmount <= 0) {
                throw new \InvalidArgumentException('Payment could not be converted to company base currency. Check currency/exchange rate setup.');
            }

            $accountExchangeRate = $paymentCurrencyCode === $accountCurrencyCode
                ? 1
                : round($accountAmount / max($amount, 0.0001), 8);
            $baseExchangeRate = $paymentCurrencyCode === $baseCurrencyCode
                ? 1
                : round($amount / max($baseAmount, 0.0001), 8);
            $accountToBaseRate = $accountCurrencyCode === $baseCurrencyCode
                ? 1
                : round($accountAmount / max($baseAmount, 0.0001), 8);

            $paymentNumber = $data['payment_number'] ?? $this->generatePaymentNumber($data['company_id'], $data['branch_id'] ?? null);
            $paymentName = $paymentType?->name ?? ($data['payment_name'] ?? $data['payment_method'] ?? 'Credit Customer Payment');
            $moneyCode = $paymentType?->money_code ?? ($data['money_code'] ?? null);
            $reference = $data['reference'] ?? $data['reference_no'] ?? $paymentNumber;
            $allowAccountCredit = (bool) ($data['allow_account_credit'] ?? false);
            $impact = $this->assessPaymentPolicy(
                (string) $customer->status,
                (bool) $customer->is_active,
                (float) $customer->current_balance,
                $accountAmount,
                $allowAccountCredit
            );
            $previousBalance = $impact['previous_balance'];
            $newBalance = $impact['new_balance'];
            $appliedToDebt = $impact['applied_to_existing_debt'];
            $creditBalanceAfterPayment = $impact['customer_credit_after_payment'];
            $creditCreatedByPayment = $impact['overpayment_credit_created'];
            $customMetadata = $this->toArray($data['metadata'] ?? []);
            $eventSource = (string) ($customMetadata['source'] ?? 'credit_customer_payment');

            $payment = CreditCustomerPayment::create([
                'id' => (string)Str::uuid(),
                'company_id' => $data['company_id'],
                'branch_id' => $data['branch_id'] ?? null,
                'credit_customer_id' => $customer->id,
                'till_id' => $data['till_id'] ?? null,
                'shift_session_id' => $data['shift_session_id'] ?? null,
                'cashier_id' => $data['cashier_id'] ?? null,
                'payment_type_id' => $data['payment_type_id'] ?? null,
                'payment_number' => $paymentNumber,
                'payment_name' => $paymentName,
                'money_code' => $moneyCode,
                'amount' => $amount,
                'base_amount' => round($baseAmount, 4),
                'currency_code' => $paymentCurrencyCode,
                'base_currency_code' => $baseCurrencyCode,
                // Match POS sales convention: physical currency units per one
                // company-base unit (base -> physical transaction currency).
                'exchange_rate' => $baseExchangeRate,
                'reference' => $reference,
                'approval_code' => $data['approval_code'] ?? null,
                'card_last_four' => $data['card_last_four'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'posted',
                'metadata' => array_merge($customMetadata, [
                    'source' => $eventSource,
                    'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
                    'account_credit_confirmed' => $allowAccountCredit,
                    'account_currency_code' => $accountCurrencyCode,
                    'account_amount' => round($accountAmount, 4),
                    'payment_currency_code' => $paymentCurrencyCode,
                    'payment_amount' => round($amount, 4),
                    'base_currency_code' => $baseCurrencyCode,
                    'base_amount' => round($baseAmount, 4),
                    'payment_to_account_rate' => $accountExchangeRate,
                    'payment_to_base_rate' => $baseExchangeRate,
                    'account_to_base_rate' => $accountToBaseRate,
                    'payment_to_base_multiplier' => round($baseAmount / max($amount, 0.0001), 8),
                    'account_to_base_multiplier' => round($baseAmount / max($accountAmount, 0.0001), 8),
                    'rate_snapshot_captured_at' => now()->toIso8601String(),
                    'previous_balance' => $previousBalance,
                    'applied_to_existing_debt' => $appliedToDebt,
                    'overpayment_credit_created' => $creditCreatedByPayment,
                    'customer_credit_after_payment' => $creditBalanceAfterPayment,
                    'balance_after_payment' => $newBalance,
                    'cashup_amount_tendered' => round((float) ($data['cashup_amount_tendered'] ?? $amount), 4),
                ]),
                'created_by' => auth()->id(),
            ]);

            $ledger = CreditCustomerLedger::create([
                'id' => (string)Str::uuid(),
                'company_id' => $data['company_id'],
                'branch_id' => $data['branch_id'] ?? null,
                'credit_customer_id' => $customer->id,
                'payment_id' => $payment->id,
                'shift_session_id' => $data['shift_session_id'] ?? null,
                'till_id' => $data['till_id'] ?? null,
                'cashier_id' => $data['cashier_id'] ?? null,
                'entry_type' => 'payment',
                'document_number' => $paymentNumber,
                'document_date' => now()->toDateString(),
                'debit_amount' => 0,
                'credit_amount' => round($accountAmount, 4),
                'balance_after' => $newBalance,
                'currency_code' => $accountCurrencyCode,
                'base_currency_code' => $baseCurrencyCode,
                // Match POS convention for the account-denominated ledger:
                // account currency units per one company-base unit.
                'exchange_rate' => $accountToBaseRate,
                'reference' => $reference,
                'notes' => $data['notes'] ?? 'Credit customer payment received.',
                'metadata' => array_merge($customMetadata, [
                    'source' => $eventSource,
                    'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
                    'account_credit_confirmed' => $allowAccountCredit,
                    'payment_number' => $paymentNumber,
                    'payment_name' => $paymentName,
                    'money_code' => $moneyCode,
                    'payment_currency_code' => $paymentCurrencyCode,
                    'payment_amount' => round($amount, 4),
                    'account_currency_code' => $accountCurrencyCode,
                    'account_amount' => round($accountAmount, 4),
                    'base_currency_code' => $baseCurrencyCode,
                    'base_amount' => round($baseAmount, 4),
                    'payment_to_account_rate' => $accountExchangeRate,
                    'payment_to_base_rate' => $baseExchangeRate,
                    'account_to_base_rate' => $accountToBaseRate,
                    'payment_to_base_multiplier' => round($baseAmount / max($amount, 0.0001), 8),
                    'account_to_base_multiplier' => round($baseAmount / max($accountAmount, 0.0001), 8),
                    'rate_snapshot_captured_at' => now()->toIso8601String(),
                    'previous_balance' => $previousBalance,
                    'applied_to_existing_debt' => $appliedToDebt,
                    'overpayment_credit_created' => $creditCreatedByPayment,
                    'customer_credit_after_payment' => $creditBalanceAfterPayment,
                    'balance_after_payment' => $newBalance,
                ]),
                'created_by' => auth()->id(),
            ]);

            $customer->current_balance = $newBalance;
            $customer->available_credit = round((float)$customer->credit_limit - $newBalance, 4);
            $customer->save();

            $this->insertCashupPaymentRow($payment, $customer, $data, $paymentName, $moneyCode, $paymentCurrencyCode, $baseCurrencyCode, $baseExchangeRate, $baseAmount, $reference);

            $this->applyPaymentToOldestSales($customer, $payment, $ledger, $accountAmount, $accountCurrencyCode);

            $this->resyncCustomerSaleCreditStatuses($customer);
            $this->recalculateCustomerBalance($customer);

            return $payment->fresh();
        });
    }


    private function applyExistingCustomerCreditToNewSale(
        CreditCustomer       $customer,
        CreditCustomerLedger $saleLedger,
        float                $saleAmount,
        float                $maxAmountToApply,
        string               $accountCurrencyCode
    ): float
    {
        if ($maxAmountToApply <= 0 || !Schema::hasTable('credit_customer_payment_allocations')) {
            return 0.0;
        }

        $allocationColumns = Schema::getColumnListing('credit_customer_payment_allocations');
        $remaining = round(min($saleAmount, $maxAmountToApply), 4);
        $totalApplied = 0.0;

        $paymentLedgers = CreditCustomerLedger::query()
            ->where('credit_customer_id', $customer->id)
            ->where('entry_type', 'payment')
            ->where('credit_amount', '>', 0)
            ->orderBy('document_date')
            ->orderBy('created_at')
            ->lockForUpdate()
            ->get();

        foreach ($paymentLedgers as $paymentLedger) {
            if ($remaining <= 0) {
                break;
            }

            /*
             * Only use genuinely available customer credit from old payments.
             * Example: customer paid 100 while only owing 60. Allocation rows already use 60,
             * so 40 remains available for the next credit sale.
             */
            $paymentAmount = round((float)$paymentLedger->credit_amount, 4);
            $paymentAlreadyAllocated = $this->allocatedAmountFromAllocationRowsForPaymentLedger($paymentLedger->id);
            $availableFromThisPayment = round(max(0, $paymentAmount - $paymentAlreadyAllocated), 4);

            if ($availableFromThisPayment <= 0) {
                continue;
            }

            $allocated = round(min($remaining, $availableFromThisPayment), 4);
            if ($allocated <= 0) {
                continue;
            }

            if (in_array('credit_customer_payment_id', $allocationColumns, true) && empty($paymentLedger->payment_id)) {
                // Older ledgers without a payment_id cannot satisfy the existing NOT NULL allocation schema safely.
                continue;
            }

            $allocationPayload = [
                'id' => (string)Str::uuid(),
                'company_id' => $customer->company_id,
                'branch_id' => $saleLedger->branch_id,
                'credit_customer_id' => $customer->id,
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
                'currency_code' => $accountCurrencyCode,
                'base_currency_code' => $accountCurrencyCode,
                'allocation_status' => round(($saleAmount - ($totalApplied + $allocated)), 4) <= 0 ? 'paid' : 'partial',
                'metadata' => json_encode([
                    'source' => 'existing_customer_credit_auto_applied',
                    'reason' => 'Customer had a credit/prepaid balance before this sale.',
                    'sale_ledger_id' => $saleLedger->id,
                    'sale_document_number' => $saleLedger->document_number,
                    'payment_ledger_id' => $paymentLedger->id,
                    'payment_document_number' => $paymentLedger->document_number,
                    'payment_credit_amount' => $paymentAmount,
                    'payment_allocated_before' => $paymentAlreadyAllocated,
                    'allocated_to_this_sale' => $allocated,
                    'payment_credit_remaining_after' => round(max(0, $availableFromThisPayment - $allocated), 4),
                    'sale_amount' => round($saleAmount, 4),
                    'sale_allocated_after' => round($totalApplied + $allocated, 4),
                    'sale_outstanding_after' => round(max(0, $saleAmount - ($totalApplied + $allocated)), 4),
                ]),
                'created_by' => auth()->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            DB::table('credit_customer_payment_allocations')->insert(
                array_intersect_key($allocationPayload, array_flip($allocationColumns))
            );

            $remaining = round($remaining - $allocated, 4);
            $totalApplied = round($totalApplied + $allocated, 4);
        }

        return round($totalApplied, 4);
    }

    private function applyPaymentToOldestSales(
        CreditCustomer        $customer,
        CreditCustomerPayment $payment,
        CreditCustomerLedger  $paymentLedger,
        float                 $accountAmount,
        string                $accountCurrencyCode
    ): void
    {
        $remaining = round($accountAmount, 4);

        $saleLedgers = CreditCustomerLedger::query()
            ->where('credit_customer_id', $customer->id)
            ->where('entry_type', 'sale')
            ->where('debit_amount', '>', 0)
            ->orderBy('due_date')
            ->orderBy('document_date')
            ->orderBy('created_at')
            ->lockForUpdate()
            ->get();

        foreach ($saleLedgers as $saleLedger) {
            if ($remaining <= 0) {
                break;
            }

            $alreadyAllocated = $this->allocatedAmountForSaleLedger($saleLedger->id);
            $saleAmount = round((float)$saleLedger->debit_amount, 4);
            $outstanding = round(max(0, $saleAmount - $alreadyAllocated), 4);

            if ($outstanding <= 0) {
                $this->syncSaleCreditStatus($saleLedger, $saleAmount, $alreadyAllocated, $accountCurrencyCode);
                continue;
            }

            $allocated = round(min($remaining, $outstanding), 4);

            if (Schema::hasTable('credit_customer_payment_allocations')) {
                $allocationColumns = Schema::getColumnListing('credit_customer_payment_allocations');

                $allocationPayload = [
                    'id' => (string)Str::uuid(),
                    'company_id' => $customer->company_id,
                    'branch_id' => $saleLedger->branch_id,
                    'credit_customer_id' => $customer->id,
                    'credit_customer_payment_id' => $payment->id,
                    'payment_ledger_id' => $paymentLedger->id,
                    'sale_ledger_id' => $saleLedger->id,
                    'sales_transaction_id' => $saleLedger->sales_transaction_id,
                    'document_number' => $saleLedger->document_number,
                    'invoice_number' => $saleLedger->document_number,
                    'payment_number' => $payment->payment_number,
                    'payment_date' => now()->toDateString(),
                    'amount' => $allocated,
                    'allocated_amount' => $allocated,
                    'base_allocated_amount' => $allocated,
                    'currency_code' => $accountCurrencyCode,
                    'base_currency_code' => $accountCurrencyCode,
                    'allocation_status' => round($outstanding - $allocated, 4) <= 0 ? 'paid' : 'partial',
                    'metadata' => json_encode([
                        'source' => 'credit_customer_payment_allocation',
                        'payment_number' => $payment->payment_number,
                        'sale_document_number' => $saleLedger->document_number,
                        'sale_amount' => $saleAmount,
                        'already_allocated_before_payment' => $alreadyAllocated,
                        'allocated_by_this_payment' => $allocated,
                        'total_allocated_after_payment' => round($alreadyAllocated + $allocated, 4),
                        'outstanding_after_payment' => round(max(0, $outstanding - $allocated), 4),
                    ]),
                    'created_by' => auth()->id(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                DB::table('credit_customer_payment_allocations')->insert(
                    array_intersect_key($allocationPayload, array_flip($allocationColumns))
                );
            }

            $totalAllocated = round($alreadyAllocated + $allocated, 4);
            $this->syncSaleCreditStatus($saleLedger, $saleAmount, $totalAllocated, $accountCurrencyCode);

            $remaining = round($remaining - $allocated, 4);
        }
    }

    private function creditAllocationAmountExpressionForService(array $columns): ?string
    {
        $hasAllocatedAmount = in_array('allocated_amount', $columns, true);
        $hasLegacyAmount = in_array('amount', $columns, true);

        if ($hasAllocatedAmount && $hasLegacyAmount) {
            return 'COALESCE(NULLIF(allocated_amount, 0), amount, 0)';
        }

        if ($hasAllocatedAmount) {
            return 'COALESCE(allocated_amount, 0)';
        }

        if ($hasLegacyAmount) {
            return 'COALESCE(amount, 0)';
        }

        return null;
    }


    private function allocatedAmountFromAllocationRowsForPaymentLedger(string $paymentLedgerId): float
    {
        if (!Schema::hasTable('credit_customer_payment_allocations')) {
            return 0.0;
        }

        $columns = Schema::getColumnListing('credit_customer_payment_allocations');

        if (!in_array('payment_ledger_id', $columns, true)) {
            return 0.0;
        }

        $amountExpression = $this->creditAllocationAmountExpressionForService($columns);

        if (!$amountExpression) {
            return 0.0;
        }

        $row = DB::table('credit_customer_payment_allocations')
            ->where('payment_ledger_id', $paymentLedgerId)
            ->when(in_array('allocation_status', $columns, true), function ($query) {
                $query->whereNotIn('allocation_status', ['reversed', 'voided', 'cancelled']);
            })
            ->selectRaw("COALESCE(SUM({$amountExpression}), 0) as allocated_total")
            ->first();

        return round((float)($row->allocated_total ?? 0), 4);
    }

    private function allocatedAmountFromAllocationRowsForSaleLedger(string $saleLedgerId): float
    {
        if (!Schema::hasTable('credit_customer_payment_allocations')) {
            return 0.0;
        }

        $columns = Schema::getColumnListing('credit_customer_payment_allocations');
        $amountExpression = $this->creditAllocationAmountExpressionForService($columns);

        if (!$amountExpression) {
            return 0.0;
        }

        $row = DB::table('credit_customer_payment_allocations')
            ->where('sale_ledger_id', $saleLedgerId)
            ->when(in_array('allocation_status', $columns, true), function ($query) {
                $query->whereNotIn('allocation_status', ['reversed', 'voided', 'cancelled']);
            })
            ->selectRaw("COALESCE(SUM({$amountExpression}), 0) as allocated_total")
            ->first();

        return round((float)($row->allocated_total ?? 0), 4);
    }

    private function allocatedAmountForSaleLedger(string $saleLedgerId): float
    {
        $saleLedger = CreditCustomerLedger::query()->where('id', $saleLedgerId)->first();
        $allocatedFromPayments = $this->allocatedAmountFromAllocationRowsForSaleLedger($saleLedgerId);

        if (!$saleLedger || !$saleLedger->sales_transaction_id || !Schema::hasTable('sales_transactions')) {
            return $allocatedFromPayments;
        }

        $salePaid = 0;
        if (Schema::hasColumn('sales_transactions', 'credit_paid_amount')) {
            $salePaid = round((float)DB::table('sales_transactions')
                ->where('id', $saleLedger->sales_transaction_id)
                ->value('credit_paid_amount'), 4);
        }

        // Important for overpayments and older allocations: a sale can be paid either
        // by allocation rows or by the synced sale-level credit_paid_amount.
        return max($allocatedFromPayments, $salePaid);
    }

    private function syncSaleCreditStatus(CreditCustomerLedger $saleLedger, float $saleAmount, float $allocatedAmount, string $currencyCode): void
    {
        if (!$saleLedger->sales_transaction_id || !Schema::hasTable('sales_transactions')) {
            return;
        }

        $sale = SalesTransaction::where('id', $saleLedger->sales_transaction_id)->lockForUpdate()->first();
        if (!$sale) {
            return;
        }

        $paid = round(min($allocatedAmount, $saleAmount), 4);
        $balance = round(max(0, $saleAmount - $paid), 4);
        $status = $balance <= 0 ? 'paid' : ($paid > 0 ? 'partially_paid' : 'posted');

        $updates = [
            'metadata' => array_merge($this->toArray($sale->metadata), [
                'credit_sale' => true,
                'credit_customer_id' => $saleLedger->credit_customer_id,
                'credit_account_number' => data_get($this->toArray($saleLedger->metadata), 'customer_account_number'),
                'credit_paid_amount' => $paid,
                'credit_balance_amount' => $balance,
                'credit_status' => $status,
                'credit_currency_code' => $currencyCode,
            ]),
        ];

        foreach ([
                     'credit_customer_id' => $saleLedger->credit_customer_id,
                     'credit_account_number' => data_get($this->toArray($saleLedger->metadata), 'customer_account_number'),
                     'is_credit_sale' => true,
                     'credit_status' => $status,
                     'credit_amount' => $saleAmount,
                     'credit_paid_amount' => $paid,
                     'credit_balance_amount' => $balance,
                     'credit_due_date' => $saleLedger->due_date,
                 ] as $column => $value) {
            if (Schema::hasColumn('sales_transactions', $column)) {
                $updates[$column] = $value;
            }
        }

        $sale->forceFill($updates)->save();
    }

    private function markSaleAsCredit(
        SalesTransaction $sale,
        CreditCustomer   $customer,
        float            $accountAmount,
        string           $accountCurrencyCode,
        string           $dueDate,
        ?string          $poNumber = null
    ): void
    {
        if (!Schema::hasTable('sales_transactions')) {
            return;
        }

        $updates = [
            'metadata' => array_merge($this->toArray($sale->metadata), [
                'credit_sale' => true,
                'credit_customer_id' => $customer->id,
                'credit_account_number' => $customer->account_number,
                'credit_customer_name' => $customer->name,
                'credit_due_date' => $dueDate,
                'credit_currency_code' => $accountCurrencyCode,
                'credit_amount' => round($accountAmount, 4),
                'credit_paid_amount' => 0,
                'credit_balance_amount' => round($accountAmount, 4),
                'po_number' => $poNumber,
            ]),
        ];

        foreach ([
                     'credit_customer_id' => $customer->id,
                     'credit_account_number' => $customer->account_number,
                     'is_credit_sale' => true,
                     'credit_status' => 'posted',
                     'credit_due_date' => $dueDate,
                     'credit_amount' => round($accountAmount, 4),
                     'credit_paid_amount' => 0,
                     'credit_balance_amount' => round($accountAmount, 4),
                 ] as $column => $value) {
            if (Schema::hasColumn('sales_transactions', $column)) {
                $updates[$column] = $value;
            }
        }

        $sale->forceFill($updates)->save();
    }

    private function insertCashupPaymentRow(
        CreditCustomerPayment $payment,
        CreditCustomer        $customer,
        array                 $data,
        string                $paymentName,
        ?string               $moneyCode,
        string                $currencyCode,
        string                $baseCurrencyCode,
        float                 $exchangeRate,
        float                 $baseAmount,
        ?string               $reference
    ): void
    {
        if (!Schema::hasTable('sales_transaction_payments')) {
            return;
        }

        $columns = Schema::getColumnListing('sales_transaction_payments');
        $eventSource = (string) data_get($payment->metadata, 'source', 'credit_customer_payment');
        $cashupLabel = $eventSource === 'pos_sale_change_credit'
            ? 'Customer Change Credit - ' . $paymentName
            : 'Credit Payment - ' . $paymentName;

        $payload = [
            'id' => (string)Str::uuid(),
            'company_id' => $payment->company_id,
            'branch_id' => $payment->branch_id,
            'till_id' => $payment->till_id,
            'shift_id' => $data['shift_id'] ?? null,
            'shift_session_id' => $payment->shift_session_id,
            'sales_transaction_id' => null,
            'payment_type_id' => $payment->payment_type_id,
            'payment_name' => $cashupLabel,
            'money_code' => $moneyCode,
            'currency_code' => $currencyCode,
            'base_currency_code' => $baseCurrencyCode,
            'exchange_rate' => $exchangeRate,
            'amount' => round((float)$payment->amount, 4),
            'base_amount' => round($baseAmount, 4),
            'amount_tendered' => round((float) ($data['cashup_amount_tendered'] ?? $payment->amount), 4),
            'change_amount' => 0,
            'reference' => $reference,
            'approval_code' => $payment->approval_code,
            'card_last_four' => $payment->card_last_four,
            'metadata' => json_encode([
                'credit_customer_payment' => true,
                'credit_customer_id' => $customer->id,
                'account_number' => $customer->account_number,
                'payment_id' => $payment->id,
                'payment_number' => $payment->payment_number,
                'source' => $eventSource,
                'source_sales_transaction_id' => data_get($payment->metadata, 'source_sales_transaction_id'),
                'source_invoice_number' => data_get($payment->metadata, 'source_invoice_number'),
                'idempotency_key' => data_get($payment->metadata, 'idempotency_key'),
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $insert = array_intersect_key($payload, array_flip($columns));

        DB::table('sales_transaction_payments')->insert($insert);
    }

    private function convertAmount(
        string $from,
        string $to,
        float $amount,
        string $companyId,
        ?string $branchId = null
    ): float
    {
        $from = strtoupper($from ?: 'USD');
        $to = strtoupper($to ?: $from);

        if ($from === $to) {
            return round($amount, 4);
        }

        try {
            $converted = round((float)currencyConverter($from, $to, $amount, $companyId, $branchId), 4);
        } catch (\Throwable $e) {
            $converted = 0.0;
        }

        return $converted;
    }


    public function reverseCreditSaleForCancellation(
        SalesTransaction $originalSale,
        SalesTransaction $cancelTransaction,
        string           $reason,
        ?string          $userId = null
    ): ?CreditCustomerLedger
    {
        return DB::transaction(function () use ($originalSale, $cancelTransaction, $reason, $userId) {
            $saleLedger = CreditCustomerLedger::query()
                ->where('sales_transaction_id', $originalSale->id)
                ->where('entry_type', 'sale')
                ->lockForUpdate()
                ->first();

            if (!$saleLedger) {
                return null;
            }

            $existingReversal = CreditCustomerLedger::query()
                ->where('credit_customer_id', $saleLedger->credit_customer_id)
                ->where('sales_transaction_id', $cancelTransaction->id)
                ->whereIn('entry_type', ['sale_void', 'sale_cancel', 'void', 'reversal'])
                ->first();

            if ($existingReversal) {
                return $existingReversal;
            }

            $customer = CreditCustomer::where('id', $saleLedger->credit_customer_id)
                ->lockForUpdate()
                ->first();

            if (!$customer) {
                return null;
            }

            $accountAmount = round((float)$saleLedger->debit_amount, 4);
            if ($accountAmount <= 0) {
                return null;
            }

            $previousBalance = round((float)$customer->current_balance, 4);
            $newBalance = round($previousBalance - $accountAmount, 4);
            $accountCurrencyCode = strtoupper($saleLedger->currency_code ?: $customer->currency_code ?: $originalSale->currency_code ?: 'USD');
            $availableCredit = round((float)$customer->credit_limit - $newBalance, 4);

            $ledger = CreditCustomerLedger::create([
                'id' => (string)Str::uuid(),
                'company_id' => $saleLedger->company_id,
                'branch_id' => $saleLedger->branch_id,
                'credit_customer_id' => $customer->id,
                'sales_transaction_id' => $cancelTransaction->id,
                'shift_session_id' => $cancelTransaction->shift_session_id ?: $saleLedger->shift_session_id,
                'till_id' => $cancelTransaction->till_id ?: $saleLedger->till_id,
                'cashier_id' => $cancelTransaction->cashier_id ?: $saleLedger->cashier_id,
                'entry_type' => 'sale_void',
                'document_number' => $cancelTransaction->invoice_number,
                'document_date' => $cancelTransaction->transaction_date ?: now()->toDateString(),
                'due_date' => null,
                'debit_amount' => 0,
                'credit_amount' => $accountAmount,
                'balance_after' => $newBalance,
                'currency_code' => $accountCurrencyCode,
                'base_currency_code' => $saleLedger->base_currency_code ?: $accountCurrencyCode,
                'exchange_rate' => (float)($saleLedger->exchange_rate ?: 1),
                'reference' => $originalSale->invoice_number,
                'notes' => 'Credit sale reversed because receipt was voided/cancelled. Reason: ' . $reason,
                'metadata' => [
                    'source' => 'credit_sale_void',
                    'original_ledger_id' => $saleLedger->id,
                    'original_sales_transaction_id' => $originalSale->id,
                    'original_invoice_number' => $originalSale->invoice_number,
                    'cancel_transaction_id' => $cancelTransaction->id,
                    'cancel_invoice_number' => $cancelTransaction->invoice_number,
                    'previous_balance' => $previousBalance,
                    'reversed_amount' => $accountAmount,
                    'balance_after_void' => $newBalance,
                    'available_credit_after_void' => $availableCredit,
                    'reason' => $reason,
                ],
                'created_by' => $userId ?: auth()->id(),
            ]);

            $customer->current_balance = $newBalance;
            $customer->available_credit = $availableCredit;
            $customer->save();

            $this->markCreditSaleAsCancelled($originalSale, $cancelTransaction, $accountAmount, $newBalance, $accountCurrencyCode, $reason);

            return $ledger->fresh();
        });
    }

    private function markCreditSaleAsCancelled(
        SalesTransaction $originalSale,
        SalesTransaction $cancelTransaction,
        float            $accountAmount,
        float            $newCustomerBalance,
        string           $accountCurrencyCode,
        string           $reason
    ): void
    {
        if (!Schema::hasTable('sales_transactions')) {
            return;
        }

        $originalMetadata = array_merge($this->toArray($originalSale->metadata), [
            'credit_sale_cancelled' => true,
            'credit_cancel_transaction_id' => $cancelTransaction->id,
            'credit_cancel_invoice_number' => $cancelTransaction->invoice_number,
            'credit_reversed_amount' => round($accountAmount, 4),
            'credit_balance_after_cancel' => round($newCustomerBalance, 4),
            'credit_cancel_reason' => $reason,
            'credit_cancelled_at' => now()->toDateTimeString(),
        ]);

        $originalUpdates = ['metadata' => $originalMetadata];
        foreach ([
                     'credit_status' => 'cancelled',
                     'credit_paid_amount' => 0,
                     'credit_balance_amount' => 0,
                 ] as $column => $value) {
            if (Schema::hasColumn('sales_transactions', $column)) {
                $originalUpdates[$column] = $value;
            }
        }
        $originalSale->update($originalUpdates);

        $cancelMetadata = array_merge($this->toArray($cancelTransaction->metadata), [
            'credit_sale_reversal' => true,
            'credit_original_sale_id' => $originalSale->id,
            'credit_original_invoice_number' => $originalSale->invoice_number,
            'credit_customer_id' => $originalSale->credit_customer_id ?? null,
            'credit_reversed_amount' => round($accountAmount, 4),
            'credit_currency_code' => $accountCurrencyCode,
        ]);

        $cancelUpdates = ['metadata' => $cancelMetadata];
        foreach ([
                     'credit_customer_id' => $originalSale->credit_customer_id ?? null,
                     'credit_account_number' => $originalSale->credit_account_number ?? null,
                     'is_credit_sale' => true,
                     'credit_status' => 'cancelled_reversal',
                     'credit_amount' => round(-1 * $accountAmount, 4),
                     'credit_paid_amount' => 0,
                     'credit_balance_amount' => 0,
                 ] as $column => $value) {
            if (Schema::hasColumn('sales_transactions', $column)) {
                $cancelUpdates[$column] = $value;
            }
        }
        $cancelTransaction->update($cancelUpdates);
    }

    public function generatePaymentNumber(string $companyId, ?string $branchId = null): string
    {
        return DB::transaction(function () use ($companyId, $branchId) {
            $prefix = 'CRP';

            $sequence = CreditCustomerNumberSequence::query()
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('sequence_type', 'credit_customer_payment')
                ->where('prefix', $prefix)
                ->lockForUpdate()
                ->first();

            if (!$sequence) {
                $sequence = CreditCustomerNumberSequence::create([
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'sequence_type' => 'credit_customer_payment',
                    'prefix' => $prefix,
                    'last_number' => 0,
                ]);

                $sequence = CreditCustomerNumberSequence::where('id', $sequence->id)->lockForUpdate()->first();
            }

            $next = ((int)$sequence->last_number) + 1;
            $paymentNumber = $prefix . '-' . now()->format('ymd') . '-' . str_pad((string)$next, 6, '0', STR_PAD_LEFT);
            $sequence->update(['last_number' => $next]);

            return $paymentNumber;
        });
    }

    public function statement(string $customerId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $customer = CreditCustomer::findOrFail($customerId);

        $query = CreditCustomerLedger::query()
            ->where('credit_customer_id', $customer->id)
            ->when($dateFrom, fn($q) => $q->whereDate('document_date', '>=', $dateFrom))
            ->when($dateTo, fn($q) => $q->whereDate('document_date', '<=', $dateTo))
            ->orderBy('document_date')
            ->orderBy('created_at');

        $rows = $query->get();

        $saleIds = $rows
            ->pluck('sales_transaction_id')
            ->filter()
            ->unique()
            ->values();

        $salesById = collect();
        $itemsBySale = collect();
        $allocationsByLedger = collect();

        if (Schema::hasTable('credit_customer_payment_allocations')) {
            $allocationColumns = Schema::getColumnListing('credit_customer_payment_allocations');
            $allocationsByLedger = DB::table('credit_customer_payment_allocations')
                ->where('credit_customer_id', $customer->id)
                ->when(in_array('allocation_status', $allocationColumns, true), function ($query) {
                    $query->whereNotIn('allocation_status', ['reversed', 'voided', 'cancelled']);
                })
                ->get()
                ->groupBy('sale_ledger_id');
        }

        if ($saleIds->isNotEmpty() && Schema::hasTable('sales_transactions')) {
            $salesById = DB::table('sales_transactions')
                ->whereIn('id', $saleIds)
                ->get()
                ->keyBy('id');
        }

        if ($saleIds->isNotEmpty() && Schema::hasTable('receipt_lines')) {
            $receiptLineColumns = Schema::getColumnListing('receipt_lines');

            $lineRows = DB::table('receipt_lines')
                ->whereIn('sales_transaction_id', $saleIds)
                ->orderBy('sales_transaction_id')
                ->when(in_array('line_no', $receiptLineColumns, true), fn($q) => $q->orderBy('line_no'))
                ->get();

            $lineIds = $lineRows->pluck('id')->filter()->unique()->values();
            $taxesByLine = collect();

            $taxTable = collect([
                'receipt_line_taxes',
                'receipt_line_tax_lines',
                'sales_receipt_line_taxes',
                'sales_transaction_line_taxes',
            ])->first(fn($table) => Schema::hasTable($table));

            if ($taxTable && $lineIds->isNotEmpty()) {
                $taxColumns = Schema::getColumnListing($taxTable);
                $lineKey = in_array('receipt_line_id', $taxColumns, true)
                    ? 'receipt_line_id'
                    : (in_array('line_id', $taxColumns, true) ? 'line_id' : null);

                if ($lineKey) {
                    $taxesByLine = DB::table($taxTable)
                        ->whereIn($lineKey, $lineIds)
                        ->get()
                        ->groupBy($lineKey)
                        ->map(function ($taxRows) {
                            return $taxRows->map(function ($tax) {
                                return [
                                    'id' => $tax->id ?? null,
                                    'tax_id' => $tax->tax_id ?? null,
                                    'product_tax_id' => $tax->product_tax_id ?? null,
                                    'fiscal_tax_id' => $tax->fiscal_tax_id ?? $tax->fdms_tax_id ?? null,
                                    'tax_code' => $tax->tax_code ?? $tax->code ?? null,
                                    'tax_name' => $tax->tax_name ?? $tax->name ?? 'Tax',
                                    'type' => $tax->type ?? 'percentage',
                                    'rate' => round((float)($tax->rate ?? $tax->tax_rate ?? $tax->tax_percent ?? 0), 4),
                                    'tax_percent' => round((float)($tax->tax_percent ?? $tax->rate ?? $tax->tax_rate ?? 0), 4),
                                    'is_inclusive' => (bool)($tax->is_inclusive ?? false),
                                    'taxable_amount' => round((float)($tax->taxable_amount ?? $tax->net_amount ?? 0), 4),
                                    'tax_amount' => round((float)($tax->tax_amount ?? $tax->amount ?? 0), 4),
                                    'sales_amount_with_tax' => round((float)($tax->sales_amount_with_tax ?? $tax->gross_amount ?? 0), 4),
                                ];
                            })->values();
                        });
                }
            }

            $itemsBySale = $lineRows
                ->groupBy('sales_transaction_id')
                ->map(function ($lines) use ($taxesByLine) {
                    return $lines->map(function ($line) use ($taxesByLine) {
                        $metadata = $line->metadata ?? null;

                        if (is_string($metadata) && $metadata !== '') {
                            $metadata = json_decode($metadata, true) ?: null;
                        }

                        $taxes = $taxesByLine->get($line->id ?? '', collect());

                        $quantity = round((float)($line->line_quantity ?? $line->quantity ?? 0), 4);
                        $unitPrice = round((float)($line->line_price ?? $line->unit_price ?? $line->price ?? 0), 4);
                        $subtotal = round((float)($line->line_subtotal ?? $line->subtotal ?? 0), 4);
                        $discount = round((float)($line->line_discount ?? $line->discount_amount ?? 0), 4);
                        $tax = round((float)($line->line_tax ?? $line->tax_amount ?? $taxes->sum('tax_amount') ?? 0), 4);
                        $total = round((float)($line->line_total ?? $line->total ?? 0), 4);

                        return [
                            'id' => $line->id ?? null,
                            'receipt_line_id' => $line->id ?? null,
                            'sales_transaction_id' => $line->sales_transaction_id ?? null,
                            'product_id' => $line->product_id ?? null,
                            'product_price_id' => $line->product_price_id ?? null,
                            'price_level_id' => $line->price_level_id ?? null,
                            'line_no' => (int)($line->line_no ?? 0),
                            'product_name' => $line->line_name ?? $line->product_name ?? $line->name ?? 'Product',
                            'line_name' => $line->line_name ?? $line->product_name ?? $line->name ?? 'Product',
                            'product_code' => $line->product_code ?? $line->code ?? null,
                            'sku' => $line->sku ?? null,
                            'barcode' => $line->barcode ?? null,
                            'quantity' => $quantity,
                            'line_quantity' => $quantity,
                            'unit_price' => $unitPrice,
                            'line_price' => $unitPrice,
                            'subtotal' => $subtotal,
                            'line_subtotal' => $subtotal,
                            'discount' => $discount,
                            'line_discount' => $discount,
                            'tax' => $tax,
                            'line_tax' => $tax,
                            'total' => $total,
                            'line_total' => $total,
                            'currency_code' => $line->currency_code ?? null,
                            'uom' => data_get($metadata, 'uom'),
                            'uom_code' => data_get($metadata, 'uom_code'),
                            'taxes' => $taxes->values(),
                            'tax_breakdown' => $taxes->values(),
                            'tax_total' => round((float)$taxes->sum('tax_amount'), 4),
                            'metadata' => $metadata,
                        ];
                    })->values();
                });
        }

        $cancelledSaleIds = $rows
            ->filter(function (CreditCustomerLedger $row) {
                return in_array((string)$row->entry_type, ['sale_void', 'sale_cancel', 'void', 'reversal'], true);
            })
            ->map(function (CreditCustomerLedger $row) {
                $metadata = $this->toArray($row->metadata);
                return $metadata['original_sales_transaction_id']
                    ?? $metadata['original_sale_id']
                    ?? $metadata['cancelled_original_sale_id']
                    ?? null;
            })
            ->filter()
            ->unique()
            ->values();

        $runningBalance = 0.0;

        $statementRows = $rows->map(function (CreditCustomerLedger $row) use (&$runningBalance, $itemsBySale, $salesById, $allocationsByLedger, $cancelledSaleIds) {
            $items = $itemsBySale->get($row->sales_transaction_id, collect())->values();
            $sale = $row->sales_transaction_id ? $salesById->get($row->sales_transaction_id) : null;
            $allocations = $allocationsByLedger->get($row->id, collect());
            $allocatedFromPayments = round((float)$allocations->sum('amount'), 4);
            $allocatedFromSaleRecord = $sale && isset($sale->credit_paid_amount)
                ? round((float)$sale->credit_paid_amount, 4)
                : 0.0;
            $allocatedTotal = max($allocatedFromPayments, $allocatedFromSaleRecord);
            $saleIsCancelled = $row->entry_type === 'sale' && (
                    $cancelledSaleIds->contains($row->sales_transaction_id)
                    || in_array((string)($sale->status ?? ''), ['cancelled', 'voided'], true)
                    || in_array((string)($sale->credit_status ?? ''), ['cancelled', 'voided'], true)
                );
            $isReversalRow = in_array((string)$row->entry_type, ['sale_void', 'sale_cancel', 'void', 'reversal'], true);

            /*
             * Cancelled / voided credit sales must not affect the customer's live account balance.
             * Some older cancellations only marked sales_transactions.status = cancelled and did not
             * create a sale_void ledger row. Other newer cancellations create a sale_void row.
             * To avoid double-counting, the statement treats both the original cancelled sale and
             * its sale_void row as informational rows with effective debit/credit of zero.
             */
            $effectiveDebit = $saleIsCancelled || $isReversalRow
                ? 0.0
                : round((float)$row->debit_amount, 4);
            $effectiveCredit = $isReversalRow
                ? 0.0
                : round((float)$row->credit_amount, 4);

            $outstanding = $row->entry_type === 'sale'
                ? ($saleIsCancelled ? 0 : round(max(0, $effectiveDebit - $allocatedTotal), 4))
                : 0;

            $runningBalance = round($runningBalance + $effectiveDebit - $effectiveCredit, 4);

            return [
                ...$row->toArray(),
                'original_debit_amount' => round((float)$row->debit_amount, 4),
                'original_credit_amount' => round((float)$row->credit_amount, 4),
                'effective_debit_amount' => $effectiveDebit,
                'effective_credit_amount' => $effectiveCredit,
                'is_cancelled' => $saleIsCancelled || $isReversalRow,
                'display_status' => $saleIsCancelled || $isReversalRow ? 'cancelled' : ($sale->status ?? $row->entry_type),
                'balance_after' => $runningBalance,
                'sale' => $sale,
                'sales_transaction' => $sale,
                'transaction_date' => optional($row->document_date)->toDateString(),
                'transaction_type' => $row->entry_type,
                'reference_no' => $row->document_number,
                'payment_receipt_print_url' => $row->payment_id
                    ? "/api/pos/credit-customers/{$row->credit_customer_id}/payments/{$row->payment_id}/receipt/pdf?paper=80mm&download=0"
                    : null,
                'payment_receipt_pdf_url' => $row->payment_id
                    ? "/api/pos/credit-customers/{$row->credit_customer_id}/payments/{$row->payment_id}/receipt/pdf?paper=80mm&download=1"
                    : null,
                'receipt_number' => $sale->invoice_number ?? $sale->receipt_number ?? $row->document_number,
                'description' => $row->notes,
                'balance' => $runningBalance,
                'allocated_amount' => $allocatedTotal,
                'outstanding_amount' => $outstanding,
                'allocation_status' => $row->entry_type === 'sale'
                    ? ($saleIsCancelled ? 'cancelled' : ($outstanding <= 0 ? 'paid' : ($allocatedTotal > 0 ? 'partially_paid' : 'unpaid')))
                    : null,
                'payment_allocations' => $allocations->values(),
                'items' => $items,
                'line_items' => $items,
                'receipt_lines' => $items,
                'item_count' => $items->count(),
                'quantity_total' => round((float)$items->sum('quantity'), 4),
                'subtotal_total' => round((float)$items->sum('subtotal'), 4),
                'discount_total' => round((float)$items->sum('discount'), 4),
                'tax_total' => round((float)$items->sum('tax'), 4),
                'line_total' => round((float)$items->sum('total'), 4),
                'item_summary' => $items->map(function ($item) {
                    return trim(($item['product_name'] ?? 'Product') . ' x ' . number_format((float)($item['quantity'] ?? 0), 4, '.', ''));
                })->implode(', '),
            ];
        })->values();

        $debitTotal = round((float)$statementRows->sum('effective_debit_amount'), 4);
        $creditTotal = round((float)$statementRows->sum('effective_credit_amount'), 4);
        $closingBalance = round($debitTotal - $creditTotal, 4);
        $amountDue = round(max(0, $closingBalance), 4);
        $creditBalance = round(max(0, -$closingBalance), 4);
        $availableCredit = round((float)$customer->credit_limit - $closingBalance, 4);

        /*
         * Only sync the master account on a full statement. A date-filtered statement is
         * a report and must not overwrite the customer's current balance.
         */
        if (!$dateFrom && !$dateTo) {
            $customer->current_balance = $closingBalance;
            $customer->available_credit = $availableCredit;
            $customer->save();
            $customer = $customer->fresh();
        }

        return [
            'customer' => $customer,
            'opening_balance' => round((float)$customer->opening_balance, 4),
            'debit_total' => $debitTotal,
            'credit_total' => $creditTotal,
            'closing_balance' => $closingBalance,
            'amount_due' => $amountDue,
            'credit_balance' => $creditBalance,
            'available_credit' => $availableCredit,
            'rows' => $statementRows,
        ];
    }

    public function ageingReport(string $companyId, ?string $branchId = null): array
    {
        $today = Carbon::today();

        return CreditCustomer::query()
            ->where('company_id', $companyId)
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->with(['ledger' => function ($q) {
                $q->where('entry_type', 'sale')->where('debit_amount', '>', 0);
            }])
            ->get()
            ->map(function (CreditCustomer $customer) use ($today) {
                $buckets = [
                    'current' => 0,
                    'days_1_30' => 0,
                    'days_31_60' => 0,
                    'days_61_90' => 0,
                    'days_90_plus' => 0,
                ];

                foreach ($customer->ledger as $row) {
                    if ($this->saleLedgerIsCancelled($row)) {
                        continue;
                    }

                    $amount = (float)$row->debit_amount;
                    $allocated = $this->allocatedAmountForSaleLedger($row->id);
                    $outstanding = max(0, $amount - $allocated);
                    $days = $row->due_date ? Carbon::parse($row->due_date)->diffInDays($today, false) : 0;

                    if ($days <= 0) {
                        $buckets['current'] += $outstanding;
                    } elseif ($days <= 30) {
                        $buckets['days_1_30'] += $outstanding;
                    } elseif ($days <= 60) {
                        $buckets['days_31_60'] += $outstanding;
                    } elseif ($days <= 90) {
                        $buckets['days_61_90'] += $outstanding;
                    } else {
                        $buckets['days_90_plus'] += $outstanding;
                    }
                }

                return [
                    'customer_id' => $customer->id,
                    'account_number' => $customer->account_number,
                    'name' => $customer->name,
                    'currency_code' => $customer->currency_code,
                    'credit_limit' => round((float)$customer->credit_limit, 4),
                    'current_balance' => round((float)$customer->current_balance, 4),
                    'available_credit' => round((float)$customer->available_credit, 4),
                    'buckets' => array_map(fn($value) => round((float)$value, 4), $buckets),
                ];
            })
            ->values()
            ->all();
    }


    private function saleLedgerIsCancelled(CreditCustomerLedger $saleLedger): bool
    {
        if ($saleLedger->entry_type !== 'sale') {
            return false;
        }

        if ($saleLedger->sales_transaction_id && Schema::hasTable('sales_transactions')) {
            $sale = DB::table('sales_transactions')
                ->where('id', $saleLedger->sales_transaction_id)
                ->first();

            if ($sale && (
                    in_array((string)($sale->status ?? ''), ['cancelled', 'voided'], true)
                    || in_array((string)($sale->credit_status ?? ''), ['cancelled', 'voided'], true)
                )) {
                return true;
            }
        }

        return CreditCustomerLedger::query()
            ->where('credit_customer_id', $saleLedger->credit_customer_id)
            ->whereIn('entry_type', ['sale_void', 'sale_cancel', 'void', 'reversal'])
            ->where(function ($query) use ($saleLedger) {
                $query->where('sales_transaction_id', $saleLedger->sales_transaction_id)
                    ->orWhere('reference', $saleLedger->document_number);
            })
            ->exists();
    }

    private function isSaleLedgerCancelled(CreditCustomerLedger $row): bool
    {
        if ((string)$row->entry_type !== 'sale') {
            return false;
        }

        if (!$row->sales_transaction_id || !Schema::hasTable('sales_transactions')) {
            return false;
        }

        $sale = DB::table('sales_transactions')
            ->where('id', $row->sales_transaction_id)
            ->first();

        if (!$sale) {
            return false;
        }

        $metadata = $this->toArray($sale->metadata ?? []);

        return in_array((string)($sale->status ?? ''), ['cancelled', 'voided'], true)
            || in_array((string)($sale->credit_status ?? ''), ['cancelled', 'voided'], true)
            || (bool)($metadata['cancelled'] ?? false)
            || (bool)($metadata['credit_sale_cancelled'] ?? false);
    }


    private function allocatedAmountFromPaymentRowsForSaleLedger(string $saleLedgerId): float
    {
        return $this->allocatedAmountFromAllocationRowsForSaleLedger($saleLedgerId);
    }

    private function resyncCustomerSaleCreditStatuses(CreditCustomer $customer): void
    {
        $saleLedgers = CreditCustomerLedger::query()
            ->where('credit_customer_id', $customer->id)
            ->where('entry_type', 'sale')
            ->where('debit_amount', '>', 0)
            ->orderBy('document_date')
            ->orderBy('created_at')
            ->get();

        foreach ($saleLedgers as $saleLedger) {
            if ($this->saleLedgerIsCancelled($saleLedger)) {
                $this->syncSaleCreditStatus(
                    $saleLedger,
                    round((float)$saleLedger->debit_amount, 4),
                    0,
                    $saleLedger->currency_code ?: $customer->currency_code ?: 'USD'
                );
                continue;
            }

            $allocated = $this->allocatedAmountFromPaymentRowsForSaleLedger($saleLedger->id);

            $this->syncSaleCreditStatus(
                $saleLedger,
                round((float)$saleLedger->debit_amount, 4),
                $allocated,
                $saleLedger->currency_code ?: $customer->currency_code ?: 'USD'
            );
        }
    }

    private function toArray($value): array
    {
        if (is_array($value)) return $value;
        if (is_object($value)) return json_decode(json_encode($value), true) ?: [];
        if (is_string($value) && $value !== '') return json_decode($value, true) ?: [];
        return [];
    }

    public function reversePayment(
        CreditCustomerPayment $payment,
        string                $reason,
        ?string               $userId = null,
        array                 $context = []
    ): ?CreditCustomerLedger
    {
        return DB::transaction(function () use ($payment, $reason, $userId, $context) {
            $disposition = strtolower(trim((string) ($context['disposition'] ?? '')));
            if (!in_array($disposition, ['accounting_only', 'cash_refund'], true)) {
                throw new \InvalidArgumentException('Payment reversal disposition must be accounting_only or cash_refund.');
            }

            $payment = CreditCustomerPayment::where('id', $payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($payment->status === 'reversed') {
                $existingReversal = CreditCustomerLedger::query()
                    ->where('payment_id', $payment->id)
                    ->whereIn('entry_type', ['payment_reversal', 'reversal'])
                    ->first();

                if (!$existingReversal) {
                    throw new \RuntimeException('Payment is marked reversed but its reversal ledger entry is missing.');
                }

                $existingDisposition = (string) data_get($existingReversal->metadata, 'financial_disposition', 'accounting_only');
                if ($existingDisposition !== $disposition) {
                    throw new \InvalidArgumentException("This payment was already reversed with {$existingDisposition} disposition.");
                }

                return $existingReversal;
            }

            if ($disposition === 'cash_refund') {
                foreach (['branch_id', 'till_id', 'shift_session_id', 'cashier_id'] as $requiredContext) {
                    if (empty($context[$requiredContext])) {
                        throw new \InvalidArgumentException('Cash refund reversals require a current POS till, cashier and open shift.');
                    }
                }
            }

            $customer = CreditCustomer::where('id', $payment->credit_customer_id)
                ->lockForUpdate()
                ->firstOrFail();

            $paymentLedger = CreditCustomerLedger::query()
                ->where('payment_id', $payment->id)
                ->where('entry_type', 'payment')
                ->lockForUpdate()
                ->first();

            if (!$paymentLedger) {
                throw new \Exception('Original payment ledger entry was not found.');
            }

            $accountAmount = round((float)$paymentLedger->credit_amount, 4);

            if ($accountAmount <= 0) {
                throw new \Exception('Invalid payment amount for reversal.');
            }

            $previousBalance = round((float)$customer->current_balance, 4);
            $newBalance = round($previousBalance + $accountAmount, 4);
            $availableCredit = round((float)$customer->credit_limit - $newBalance, 4);

            $reversalNumber = 'REV-' . $payment->payment_number;
            $refundContext = $disposition === 'cash_refund' ? [
                'branch_id' => $context['branch_id'],
                'till_id' => $context['till_id'],
                'shift_id' => $context['shift_id'] ?? null,
                'shift_session_id' => $context['shift_session_id'],
                'cashier_id' => $context['cashier_id'],
            ] : null;

            $ledger = CreditCustomerLedger::create([
                'id' => (string)Str::uuid(),
                'company_id' => $payment->company_id,
                'branch_id' => $refundContext['branch_id'] ?? $payment->branch_id,
                'credit_customer_id' => $customer->id,
                'payment_id' => $payment->id,
                'shift_session_id' => $refundContext['shift_session_id'] ?? null,
                'till_id' => $refundContext['till_id'] ?? null,
                'cashier_id' => $refundContext['cashier_id'] ?? null,
                'entry_type' => 'payment_reversal',
                'document_number' => $reversalNumber,
                'document_date' => now()->toDateString(),
                'debit_amount' => $accountAmount,
                'credit_amount' => 0,
                'balance_after' => $newBalance,
                'currency_code' => $paymentLedger->currency_code,
                'base_currency_code' => $paymentLedger->base_currency_code,
                'exchange_rate' => $paymentLedger->exchange_rate,
                'reference' => $payment->payment_number,
                'notes' => 'Credit customer payment reversed. Reason: ' . $reason,
                'metadata' => [
                    'source' => 'credit_customer_payment_reversal',
                    'original_payment_id' => $payment->id,
                    'original_payment_number' => $payment->payment_number,
                    'original_payment_ledger_id' => $paymentLedger->id,
                    'previous_balance' => $previousBalance,
                    'reversed_amount' => $accountAmount,
                    'balance_after_reversal' => $newBalance,
                    'available_credit_after_reversal' => $availableCredit,
                    'reason' => $reason,
                    'financial_disposition' => $disposition,
                    'original_branch_id' => $payment->branch_id,
                    'original_till_id' => $payment->till_id,
                    'original_shift_session_id' => $payment->shift_session_id,
                    'original_cashier_id' => $payment->cashier_id,
                    'refund_context' => $refundContext,
                ],
                'created_by' => $userId ?: auth()->id(),
            ]);

            $affectedSaleLedgerIds = collect();

            if (Schema::hasTable('credit_customer_payment_allocations')) {
                $affectedSaleLedgerIds = DB::table('credit_customer_payment_allocations')
                    ->where('credit_customer_payment_id', $payment->id)
                    ->pluck('sale_ledger_id');

                $allocationQuery = DB::table('credit_customer_payment_allocations')
                    ->where('credit_customer_payment_id', $payment->id);
                $allocationColumns = Schema::getColumnListing('credit_customer_payment_allocations');

                if (in_array('allocation_status', $allocationColumns, true)) {
                    $allocationUpdates = [
                        'allocation_status' => 'reversed',
                        'updated_at' => now(),
                    ];

                    if (in_array('reversed_at', $allocationColumns, true)) {
                        $allocationUpdates['reversed_at'] = now();
                    }
                    if (in_array('reversed_by', $allocationColumns, true)) {
                        $allocationUpdates['reversed_by'] = $userId ?: auth()->id();
                    }
                    if (in_array('reversal_reason', $allocationColumns, true)) {
                        $allocationUpdates['reversal_reason'] = $reason;
                    }

                    $allocationQuery->update($allocationUpdates);
                } else {
                    // Compatibility for very old installations which predate the
                    // audit columns. Current schemas retain and mark every row.
                    $allocationQuery->delete();
                }
            }

            $customer->current_balance = $newBalance;
            $customer->available_credit = $availableCredit;
            $customer->save();

            $payment->status = 'reversed';
            $payment->notes = trim(($payment->notes ? $payment->notes . "\n" : '') . 'Reversed: ' . $reason);
            $payment->metadata = array_merge($this->toArray($payment->metadata), [
                'reversed' => true,
                'reversed_at' => now()->toDateTimeString(),
                'reversed_by' => $userId ?: auth()->id(),
                'reversal_reason' => $reason,
                'reversal_ledger_id' => $ledger->id,
                'reversal_financial_disposition' => $disposition,
                'original_till_id' => $payment->till_id,
                'original_shift_session_id' => $payment->shift_session_id,
                'refund_context' => $refundContext,
            ]);
            $payment->save();

            // Rebuild sale-level paid/outstanding status after removing this payment's allocations.
            // This avoids stale credit_paid_amount values making reversed payments still look paid.
            $this->resyncCustomerSaleCreditStatuses($customer);

            if ($disposition === 'cash_refund') {
                $this->insertCashupPaymentReversalRow($payment, $customer, $ledger, $reason, $refundContext);
            }

            return $ledger->fresh();
        });
    }

    private function insertCashupPaymentReversalRow(
        CreditCustomerPayment $payment,
        CreditCustomer        $customer,
        CreditCustomerLedger  $reversalLedger,
        string                $reason,
        array                 $refundContext
    ): void
    {
        if (!Schema::hasTable('sales_transaction_payments')) {
            return;
        }

        $columns = Schema::getColumnListing('sales_transaction_payments');

        $payload = [
            'id' => (string)Str::uuid(),
            'company_id' => $payment->company_id,
            'branch_id' => $refundContext['branch_id'],
            'till_id' => $refundContext['till_id'],
            'shift_id' => $refundContext['shift_id'] ?? null,
            'shift_session_id' => $refundContext['shift_session_id'],
            'sales_transaction_id' => null,
            'payment_type_id' => $payment->payment_type_id,
            'payment_name' => data_get($payment->metadata, 'source') === 'pos_sale_change_credit'
                ? 'Customer Change Credit Refund - ' . $payment->payment_name
                : 'Credit Payment Refund - ' . $payment->payment_name,
            'money_code' => $payment->money_code,
            'currency_code' => $payment->currency_code,
            'base_currency_code' => $payment->base_currency_code,
            'exchange_rate' => $payment->exchange_rate,
            'amount' => -1 * round((float)$payment->amount, 4),
            'base_amount' => -1 * round((float)$payment->base_amount, 4),
            'amount_tendered' => -1 * round((float) $payment->amount, 4),
            'change_amount' => 0,
            'reference' => 'REV-' . $payment->payment_number,
            'approval_code' => $payment->approval_code,
            'card_last_four' => $payment->card_last_four,
            'metadata' => json_encode([
                'credit_customer_payment_reversal' => true,
                'credit_customer_id' => $customer->id,
                'account_number' => $customer->account_number,
                'payment_id' => $payment->id,
                'payment_number' => $payment->payment_number,
                'reversal_ledger_id' => $reversalLedger->id,
                'reason' => $reason,
                'financial_disposition' => 'cash_refund',
                'original_branch_id' => $payment->branch_id,
                'original_till_id' => $payment->till_id,
                'original_shift_session_id' => $payment->shift_session_id,
                'original_cashier_id' => $payment->cashier_id,
                'refund_context' => $refundContext,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('sales_transaction_payments')
            ->insert(array_intersect_key($payload, array_flip($columns)));
    }
}
