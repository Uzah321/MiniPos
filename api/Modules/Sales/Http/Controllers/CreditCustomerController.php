<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Reports\EnterpriseReportDownloadService;
use App\Services\Sales\CreditCustomerService;
use App\Services\Shifts\ShiftPolicyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Currency\Models\Currency;
use Modules\Currency\Models\PaymentType;
use Modules\Sales\Models\CreditCustomer;
use Modules\Sales\Models\CreditCustomerLedger;
use Modules\Sales\Models\CreditCustomerPayment;
use function App\Helpers\hasPermission;

class CreditCustomerController extends Controller
{
    public function __construct(
        protected CreditCustomerService $creditService,
        protected ShiftPolicyService $shiftPolicyService,
        protected EnterpriseReportDownloadService $reportDownloads
    )
    {
    }

    public function index(Request $request)
    {
        $company = app('company');
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'view_credit_customers', $company->id);
        if ($permission !== true) return $permission;

        $query = CreditCustomer::query()
            ->where('company_id', $company->id)
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->branch_id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = trim($request->q);
                $q->where(function ($sub) use ($term) {
                    $sub->where('name', 'like', "%{$term}%")
                        ->orWhere('trading_name', 'like', "%{$term}%")
                        ->orWhere('account_number', 'like', "%{$term}%")
                        ->orWhere('customer_code', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%");
                });
            })
            ->orderBy('name');

        return response()->json([
            'message' => 'Credit customers fetched successfully.',
            'data' => $query->paginate((int) ($request->per_page ?? 25)),
        ]);
    }

    public function posLookup(Request $request)
    {
        $company = app('company');
        $till = app('till');
        $user = auth()->user();

        $purpose = strtolower(trim((string) $request->input('purpose', 'credit_sale')));
        if (!in_array($purpose, ['credit_sale', 'account_payment'], true)) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => ['purpose' => ['Purpose must be credit_sale or account_payment.']],
            ], 422);
        }

        $permissionName = $purpose === 'account_payment'
            ? 'capture_credit_customer_payment'
            : 'create_sale';
        $permission = hasPermission($user->role_id, $permissionName, $company->id);
        if ($permission !== true) return $permission;

        $query = CreditCustomer::query()
            ->where('company_id', $company->id)
            ->where(function ($q) use ($till) {
                $q->whereNull('branch_id')->orWhere('branch_id', $till->branch_id);
            })
            ->where('is_active', true)
            ->when(
                $purpose === 'credit_sale',
                fn ($q) => $q->where('status', 'approved')->where('allow_credit_sales', true),
                fn ($q) => $q->where(function ($statusQuery) {
                    $statusQuery->where('status', 'approved')
                        ->orWhere(function ($restrictedQuery) {
                            $restrictedQuery
                                ->whereIn('status', ['blocked', 'suspended'])
                                ->where('current_balance', '>', 0);
                        });
                })
            )
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = trim($request->q);
                $q->where(function ($sub) use ($term) {
                    $sub->where('name', 'like', "%{$term}%")
                        ->orWhere('account_number', 'like', "%{$term}%")
                        ->orWhere('customer_code', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%");
                });
            })
            ->orderBy('name')
            ->limit(min(max((int) ($request->limit ?? 50), 1), 100))
            ->get()
            ->map(function (CreditCustomer $customer) {
                $balance = round((float) $customer->current_balance, 4);
                $customer->setAttribute('amount_due', round(max(0, $balance), 4));
                $customer->setAttribute('credit_balance', round(max(0, -$balance), 4));

                return $customer;
            });

        return response()->json([
            'message' => 'POS credit customer lookup fetched successfully.',
            'purpose' => $purpose,
            'data' => $query,
        ]);
    }

    /**
     * Till-bound account summary used by Create Sale. This deliberately exposes
     * no stock operation: it is an account ledger/history view only.
     */
    public function posAccount(Request $request, string $id)
    {
        $company = app('company');
        $till = app('till');
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'capture_credit_customer_payment', $company->id);
        if ($permission !== true) return $permission;

        $customer = CreditCustomer::query()
            ->where('company_id', $company->id)
            ->where('id', $id)
            ->where('is_active', true)
            ->where(function ($statusQuery) {
                $statusQuery->where('status', 'approved')
                    ->orWhere(function ($restrictedQuery) {
                        $restrictedQuery
                            ->whereIn('status', ['blocked', 'suspended'])
                            ->where('current_balance', '>', 0);
                    });
            })
            ->where(function ($query) use ($till) {
                $query->whereNull('branch_id')->orWhere('branch_id', $till->branch_id);
            })
            ->firstOrFail();

        $shift = $this->activeShiftFor($company->id, $till->branch_id, $user->id, $till->id);
        if (!$shift) {
            return response()->json([
                'message' => 'No open shift found for this cashier and till.',
                'code' => 'SHIFT_SESSION_NOT_OPEN',
            ], 422);
        }

        $statement = $this->creditService->statement($customer->id);

        return response()->json([
            'message' => 'POS credit customer account fetched successfully.',
            'data' => [
                'customer' => $customer->fresh(),
                'amount_due' => $statement['amount_due'],
                'credit_balance' => $statement['credit_balance'],
                'available_credit' => $statement['available_credit'],
                'currency_code' => $customer->currency_code,
                'statement' => $statement,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $company = app('company');
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'manage_credit_customers', $company->id);
        if ($permission !== true) return $permission;

        $validator = Validator::make($request->all(), [
            'branch_id' => ['nullable', 'string'],
            'customer_code' => ['nullable', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:255'],
            'trading_name' => ['nullable', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'billing_address' => ['nullable', 'string'],
            'delivery_address' => ['nullable', 'string'],
            'credit_limit' => ['required', 'numeric', 'min:0'],
            'opening_balance' => ['nullable', 'numeric', 'min:0'],
            'currency_code' => ['required', Rule::exists('currencies', 'code')->where('company_id', $company->id)],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
            'allow_credit_sales' => ['nullable', 'boolean'],
            'requires_po_number' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error.', 'errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();

        try {
            $opening = round((float) ($request->opening_balance ?? 0), 4);
            $limit = round((float) $request->credit_limit, 4);
            $currencyCode = strtoupper($request->currency_code);

            $customer = CreditCustomer::create([
                'company_id' => $company->id,
                'branch_id' => $request->branch_id ?: null,
                'account_number' => $this->creditService->generateAccountNumber($company->id, $request->branch_id ?: null),
                'customer_code' => $request->customer_code,
                'name' => $request->name,
                'trading_name' => $request->trading_name,
                'tax_number' => $request->tax_number,
                'phone' => $request->phone,
                'email' => $request->email,
                'billing_address' => $request->billing_address,
                'delivery_address' => $request->delivery_address,
                'credit_limit' => $limit,
                'opening_balance' => $opening,
                'current_balance' => $opening,
                'available_credit' => round($limit - $opening, 4),
                'currency_code' => $currencyCode,
                'payment_terms_days' => $request->payment_terms_days,
                'allow_credit_sales' => $request->boolean('allow_credit_sales', false),
                'requires_po_number' => $request->boolean('requires_po_number', false),
                'is_active' => $request->boolean('is_active', true),
                'status' => $request->boolean('allow_credit_sales', false) ? 'approved' : 'pending',
                'approved_by' => $request->boolean('allow_credit_sales', false) ? $user->id : null,
                'approved_at' => $request->boolean('allow_credit_sales', false) ? now() : null,
                'created_by' => $user->id,
                'metadata' => $request->metadata,
            ]);

            if ($opening > 0) {
                CreditCustomerLedger::create([
                    'company_id' => $company->id,
                    'branch_id' => $customer->branch_id,
                    'credit_customer_id' => $customer->id,
                    'entry_type' => 'opening_balance',
                    'document_number' => 'OPENING-' . $customer->account_number,
                    'document_date' => now()->toDateString(),
                    'debit_amount' => $opening,
                    'credit_amount' => 0,
                    'balance_after' => $opening,
                    'currency_code' => $customer->currency_code,
                    'base_currency_code' => $customer->currency_code,
                    'exchange_rate' => 1,
                    'reference' => 'opening_balance',
                    'notes' => 'Opening credit customer balance.',
                    'created_by' => $user->id,
                ]);
            }

            DB::commit();

            return response()->json(['message' => 'Credit customer created successfully.', 'data' => $customer->fresh()], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Credit customer creation failed.', 'error' => $e->getMessage()], 500);
        }
    }

    public function show(Request $request, string $id)
    {
        $company = app('company');
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'view_credit_customers', $company->id);
        if ($permission !== true) return $permission;

        $customer = CreditCustomer::where('company_id', $company->id)
            ->with([
                'ledger' => fn ($q) => $q->latest('document_date')->limit(20),
                'payments' => fn ($q) => $q->latest()->limit(20),
            ])
            ->findOrFail($id);

        $this->authorizeCustomerBranch($request, $customer, $company->id);
        $customer->payments->each(function (CreditCustomerPayment $payment) use ($customer) {
            $path = "/api/pos/credit-customers/{$customer->id}/payments/{$payment->id}/receipt/pdf";
            $payment->setAttribute('receipt_print_url', $path . '?paper=80mm&download=0');
            $payment->setAttribute('receipt_pdf_url', $path . '?paper=80mm&download=1');
        });

        return response()->json(['message' => 'Credit customer fetched successfully.', 'data' => $customer]);
    }

    public function update(Request $request, string $id)
    {
        $company = app('company');
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'manage_credit_customers', $company->id);
        if ($permission !== true) return $permission;

        $customer = CreditCustomer::where('company_id', $company->id)->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'customer_code' => ['nullable', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:255'],
            'trading_name' => ['nullable', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'billing_address' => ['nullable', 'string'],
            'delivery_address' => ['nullable', 'string'],
            'credit_limit' => ['required', 'numeric', 'min:0'],
            'currency_code' => ['required', Rule::exists('currencies', 'code')->where('company_id', $company->id)],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
            'allow_credit_sales' => ['nullable', 'boolean'],
            'requires_po_number' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error.', 'errors' => $validator->errors()], 422);
        }

        $customer->fill([
            'customer_code' => $request->customer_code,
            'name' => $request->name,
            'trading_name' => $request->trading_name,
            'tax_number' => $request->tax_number,
            'phone' => $request->phone,
            'email' => $request->email,
            'billing_address' => $request->billing_address,
            'delivery_address' => $request->delivery_address,
            'credit_limit' => round((float) $request->credit_limit, 4),
            'currency_code' => strtoupper($request->currency_code),
            'payment_terms_days' => $request->payment_terms_days,
            'allow_credit_sales' => $request->boolean('allow_credit_sales', false),
            'requires_po_number' => $request->boolean('requires_po_number', false),
            'is_active' => $request->boolean('is_active', true),
            'metadata' => $request->metadata,
            'updated_by' => $user->id,
        ]);

        $customer->available_credit = round((float) $customer->credit_limit - (float) $customer->current_balance, 4);
        $customer->save();

        return response()->json(['message' => 'Credit customer updated successfully.', 'data' => $customer->fresh()]);
    }

    public function approve(string $id)
    {
        $company = app('company');
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'approve_credit_customers', $company->id);
        if ($permission !== true) return $permission;

        $customer = CreditCustomer::where('company_id', $company->id)->findOrFail($id);
        $customer->update([
            'status' => 'approved',
            'allow_credit_sales' => true,
            'is_active' => true,
            'approved_by' => $user->id,
            'approved_at' => now(),
            'blocked_by' => null,
            'blocked_at' => null,
            'block_reason' => null,
        ]);

        return response()->json(['message' => 'Credit customer approved successfully.', 'data' => $customer->fresh()]);
    }

    public function block(Request $request, string $id)
    {
        $company = app('company');
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'manage_credit_customers', $company->id);
        if ($permission !== true) return $permission;

        $customer = CreditCustomer::where('company_id', $company->id)->findOrFail($id);
        $customer->update([
            'status' => 'blocked',
            'allow_credit_sales' => false,
            'blocked_by' => $user->id,
            'blocked_at' => now(),
            'block_reason' => $request->reason ?: 'Credit account blocked',
        ]);

        return response()->json(['message' => 'Credit customer blocked successfully.', 'data' => $customer->fresh()]);
    }

    public function statement(Request $request, string $id)
    {
        $company = app('company');
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'view_credit_customer_statement', $company->id);
        if ($permission !== true) return $permission;

        $customer = CreditCustomer::where('company_id', $company->id)->findOrFail($id);
        $this->authorizeCustomerBranch($request, $customer, $company->id);

        return response()->json([
            'message' => 'Credit customer statement fetched successfully.',
            'data' => $this->creditService->statement($customer->id, $request->date_from, $request->date_to),
        ]);
    }

    public function statementPdf(Request $request, string $id)
    {
        $company = app('company');
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'view_credit_customer_statement', $company->id);
        if ($permission !== true) return $permission;

        $validator = Validator::make($request->all(), [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'paper' => ['nullable', Rule::in(['a4', '80mm'])],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error.', 'errors' => $validator->errors()], 422);
        }

        $customer = CreditCustomer::query()
            ->where('company_id', $company->id)
            ->findOrFail($id);
        $this->authorizeCustomerBranch($request, $customer, $company->id);

        $statement = $this->creditService->statement(
            $customer->id,
            $request->input('date_from'),
            $request->input('date_to')
        );
        $paper = $request->input('paper', 'a4');
        $filename = $this->statementFilename($customer, $paper);
        $title = 'Credit Customer Statement';

        if ($paper === '80mm') {
            return $this->reportDownloads->downloadPdf(
                $title,
                $filename,
                $this->receiptStatementSections($statement),
                null,
                [0, 0, 226.77, 841.89],
                'portrait',
                true
            );
        }

        return $this->reportDownloads->downloadPdf(
            $title,
            $filename,
            $this->a4StatementSections($statement),
            null,
            'a4',
            'landscape'
        );
    }

    public function paymentReceiptPdf(Request $request, string $id, string $paymentId)
    {
        $company = app('company');
        $user = auth()->user();

        $capturePermission = hasPermission($user->role_id, 'capture_credit_customer_payment', $company->id);
        $statementPermission = hasPermission($user->role_id, 'view_credit_customer_statement', $company->id);
        if ($capturePermission !== true && $statementPermission !== true) {
            return $statementPermission;
        }

        $validator = Validator::make($request->all(), [
            'paper' => ['nullable', Rule::in(['80mm', 'a4'])],
            'download' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error.', 'errors' => $validator->errors()], 422);
        }

        $customer = CreditCustomer::query()
            ->where('company_id', $company->id)
            ->findOrFail($id);
        $this->authorizeCustomerBranch($request, $customer, $company->id);

        $payment = CreditCustomerPayment::withTrashed()
            ->where('company_id', $company->id)
            ->where('credit_customer_id', $customer->id)
            ->findOrFail($paymentId);
        $this->authorizePaymentBranch($request, $payment->branch_id, $company->id);

        $paymentLedger = CreditCustomerLedger::query()
            ->where('company_id', $company->id)
            ->where('credit_customer_id', $customer->id)
            ->where('payment_id', $payment->id)
            ->where('entry_type', 'payment')
            ->first();
        $reversalLedger = CreditCustomerLedger::query()
            ->where('company_id', $company->id)
            ->where('credit_customer_id', $customer->id)
            ->where('payment_id', $payment->id)
            ->whereIn('entry_type', ['payment_reversal', 'reversal'])
            ->latest('created_at')
            ->first();

        $allocations = collect();
        if (Schema::hasTable('credit_customer_payment_allocations')) {
            $allocations = DB::table('credit_customer_payment_allocations')
                ->where('company_id', $company->id)
                ->where('credit_customer_id', $customer->id)
                ->where('credit_customer_payment_id', $payment->id)
                ->orderBy('created_at')
                ->get();
        }

        $metadata = (array) ($payment->metadata ?? []);
        $cashierName = Schema::hasTable('pos_users') && $payment->cashier_id
            ? DB::table('pos_users')->where('company_id', $company->id)->where('id', $payment->cashier_id)->value('name')
            : null;
        $recordedById = $payment->created_by ?: data_get($metadata, 'recorded_by');
        $recordedByName = null;
        if ($recordedById && Schema::hasTable('pos_users')) {
            $recordedBy = DB::table('pos_users')
                ->where('company_id', $company->id)
                ->where('id', $recordedById)
                ->first(['name', 'username']);
            $recordedByName = $recordedBy?->name ?: $recordedBy?->username;
        }
        if (!$recordedByName && $recordedById && Schema::hasTable('users')) {
            $recordedByName = DB::table('users')->where('id', $recordedById)->value('name');
        }
        $branchName = Schema::hasTable('company_branches') && $payment->branch_id
            ? DB::table('company_branches')->where('company_id', $company->id)->where('id', $payment->branch_id)->value('branch_name')
            : null;

        $accountCurrency = strtoupper((string) data_get($metadata, 'account_currency_code', $customer->currency_code));
        $paymentCurrency = strtoupper((string) ($payment->currency_code ?: $accountCurrency));
        $baseCurrency = strtoupper((string) ($payment->base_currency_code ?: data_get($metadata, 'base_currency_code', $accountCurrency)));
        $accountAmount = (float) data_get($metadata, 'account_amount', $paymentLedger?->credit_amount ?? 0);
        $paper = $request->input('paper', '80mm');
        $download = $request->boolean('download', true);

        $sections = [
            [
                'title' => 'Payment Confirmation',
                'headers' => ['Field', 'Value'],
                'rows' => [
                    ['Payment Number', $payment->payment_number],
                    ['Payment Date', optional($payment->created_at)->format('Y-m-d H:i:s')],
                    ['Status', strtoupper((string) $payment->status)],
                    ['Customer', $customer->name],
                    ['Account Number', $customer->account_number],
                    ['Method', $payment->payment_name ?: $payment->money_code ?: '-'],
                    ['Physical Tender', $this->statementMoney($payment->amount, $paymentCurrency)],
                    ['Reference', $payment->reference ?: '-'],
                    ['Cashier', $cashierName ?: '-'],
                    ['Recorded By', $recordedByName ?: $cashierName ?: '-'],
                    ['Branch', $branchName ?: '-'],
                    ['Source', data_get($metadata, 'source', 'credit_customer_payment')],
                    ['Source Invoice', data_get($metadata, 'source_invoice_number', '-')],
                    ['Source Sale ID', data_get($metadata, 'source_sales_transaction_id', '-')],
                ],
            ],
            [
                'title' => 'Account and Currency Effect',
                'headers' => ['Field', 'Value'],
                'rows' => [
                    ['Account Value', $this->statementMoney($accountAmount, $accountCurrency)],
                    ['Company Base Value', $this->statementMoney($payment->base_amount, $baseCurrency)],
                    ['Payment to Account Rate', number_format((float) data_get($metadata, 'payment_to_account_rate', 1), 8, '.', '')],
                    ['Payment to Base Rate', number_format((float) data_get($metadata, 'payment_to_base_rate', $payment->exchange_rate), 8, '.', '')],
                    ['Account to Base Rate', number_format((float) data_get($metadata, 'account_to_base_rate', 1), 8, '.', '')],
                    ['Rate Captured', data_get($metadata, 'rate_snapshot_captured_at', '-')],
                    ['Previous Balance', $this->statementMoney(data_get($metadata, 'previous_balance', 0), $accountCurrency)],
                    ['Applied to Debt', $this->statementMoney(data_get($metadata, 'applied_to_existing_debt', 0), $accountCurrency)],
                    ['New Prepaid Credit', $this->statementMoney(data_get($metadata, 'overpayment_credit_created', 0), $accountCurrency)],
                    ['Total Prepaid Credit', $this->statementMoney(data_get($metadata, 'customer_credit_after_payment', 0), $accountCurrency)],
                    ['Resulting Balance', $this->statementMoney(data_get($metadata, 'balance_after_payment', $paymentLedger?->balance_after ?? 0), $accountCurrency)],
                ],
            ],
        ];

        if ($allocations->isNotEmpty()) {
            $sections[] = [
                'title' => 'Debt Allocation',
                'headers' => ['Invoice', 'Applied', 'Status'],
                'rows' => $allocations->map(fn ($allocation) => [
                    $allocation->invoice_number ?? $allocation->document_number ?? '-',
                    $this->statementMoney($allocation->allocated_amount ?? $allocation->amount ?? 0, $allocation->currency_code ?? $accountCurrency),
                    strtoupper((string) ($allocation->allocation_status ?? 'POSTED')),
                ])->all(),
            ];
        }

        if ($reversalLedger || $payment->status === 'reversed') {
            $reversalMetadata = (array) ($reversalLedger?->metadata ?? []);
            $sections[] = [
                'title' => 'Reversal Audit',
                'headers' => ['Field', 'Value'],
                'rows' => [
                    ['Reversal Number', $reversalLedger?->document_number ?: '-'],
                    ['Reversed At', optional($reversalLedger?->created_at)->format('Y-m-d H:i:s') ?: data_get($metadata, 'reversed_at', '-')],
                    ['Disposition', data_get($reversalMetadata, 'financial_disposition', data_get($metadata, 'reversal_financial_disposition', 'accounting_only'))],
                    ['Reason', data_get($reversalMetadata, 'reason', data_get($metadata, 'reversal_reason', '-'))],
                    ['Original Till', data_get($reversalMetadata, 'original_till_id', $payment->till_id ?: '-')],
                    ['Original Shift', data_get($reversalMetadata, 'original_shift_session_id', $payment->shift_session_id ?: '-')],
                    ['Refund Till', data_get($reversalMetadata, 'refund_context.till_id', '-')],
                    ['Refund Shift', data_get($reversalMetadata, 'refund_context.shift_session_id', '-')],
                ],
            ];
        }

        $safePaymentNumber = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $payment->payment_number) ?: 'account-payment';

        return $this->reportDownloads->downloadPdf(
            'Credit Account Payment Receipt',
            $safePaymentNumber . '-' . strtolower($paper) . '.pdf',
            $sections,
            null,
            $paper === '80mm' ? $this->paymentReceiptPaper($sections) : 'a4',
            'portrait',
            $paper === '80mm',
            $download
        );
    }

    public function recordPayment(Request $request, string $id)
    {
        $company = app('company');
        $till = app()->bound('till') ? app('till') : null;
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'capture_credit_customer_payment', $company->id);
        if ($permission !== true) return $permission;

        $customer = CreditCustomer::where('company_id', $company->id)->findOrFail($id);
        $this->authorizeCustomerBranch($request, $customer, $company->id);

        if ($till && (!$customer->is_active || ($customer->branch_id && (string) $customer->branch_id !== (string) $till->branch_id))) {
            return response()->json([
                'message' => 'This customer account is not active for this till branch.',
            ], 422);
        }

        $branchId = $till?->branch_id
            ?: $request->input('branch_id')
            ?: $customer->branch_id
            ?: $this->validatedCompanyBranchId($request);

        $validator = Validator::make($request->all(), [
            'payment_type_id' => [
                'required',
                Rule::exists('payment_types', 'id')->where(function ($query) use ($company, $branchId) {
                    $query->where('company_id', $company->id)
                        ->where('is_active', true)
                        ->where(function ($branchQuery) use ($branchId) {
                            $branchQuery->whereNull('branch_id');
                            if ($branchId) {
                                $branchQuery->orWhere('branch_id', $branchId);
                            }
                        });
                }),
            ],
            'amount' => ['required', 'numeric', 'min:0.0001'],
            'currency_code' => ['required', Rule::exists('currencies', 'code')->where('company_id', $company->id)],
            'branch_id' => ['nullable', Rule::exists('company_branches', 'id')->where('company_id', $company->id)],
            'client_payment_id' => [$till ? 'required' : 'nullable', 'string', 'max:100'],
            'confirm_overpayment' => ['nullable', 'boolean'],
            'reference' => ['nullable', 'string', 'max:255'],
            'reference_no' => ['nullable', 'string', 'max:255'],
            'approval_code' => ['nullable', 'string', 'max:255'],
            'card_last_four' => ['nullable', 'string', 'max:4'],
            'notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error.', 'errors' => $validator->errors()], 422);
        }

        $paymentType = PaymentType::query()
            ->where('id', $request->payment_type_id)
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->where(function ($query) use ($branchId) {
                $query->whereNull('branch_id');
                if ($branchId) {
                    $query->orWhere('branch_id', $branchId);
                }
            })
            ->first();
        if (!$paymentType) {
            return response()->json(['message' => 'Selected payment type was not found.'], 422);
        }

        if (in_array(strtolower((string) $paymentType->money_code), [
            'customer_credit',
            'credit_customer',
            'credit_account',
            'account',
        ], true)) {
            return response()->json([
                'message' => 'Choose the physical tender received (for example cash, card or bank transfer). Customer account is not a payment tender for settling its own bill.',
            ], 422);
        }

        $shift = null;

        // A POS collection belongs to its till and open cashier shift. A Back Office
        // collection is an account transaction and therefore remains valid without
        // pretending that the management browser is a registered selling device.
        if ($till) {
            $shift = $this->activeShiftFor($company->id, $branchId, $user->id, $till->id);
            if (!$shift) {
                return response()->json(['message' => 'No open shift found for this cashier and till.'], 422);
            }

            if ($shiftBlockReason = $this->shiftPolicyService->salesBlockReason($company->id, $branchId, $shift)) {
                return response()->json([
                    'message' => 'Shift cashup required before recording this account payment.',
                    'error' => $shiftBlockReason,
                    'code' => 'SHIFT_SALES_CUTOFF_REACHED',
                ], 423);
            }
        }

        $paymentCurrency = strtoupper($request->currency_code);
        $accountCurrency = strtoupper($customer->currency_code ?: $paymentCurrency);
        $baseCurrency = Currency::query()
            ->where('company_id', $company->id)
            ->where('is_base_currency', true)
            ->where('is_active', true)
            ->first();

        if (!$baseCurrency) {
            return response()->json(['message' => 'Base currency is not configured.'], 422);
        }

        $baseCurrencyCode = strtoupper($baseCurrency->code);
        $reference = $request->reference ?: $request->reference_no;

        try {
            $payment = $this->creditService->recordPayment([
                'company_id' => $company->id,
                'branch_id' => $branchId,
                'credit_customer_id' => $customer->id,
                'till_id' => $till?->id,
                'shift_id' => $shift?->shift_configuration_id,
                'shift_session_id' => $shift?->id,
                'cashier_id' => $till ? $user->id : null,
                'payment_type_id' => $request->payment_type_id,
                'amount' => (float) $request->amount,
                'currency_code' => $paymentCurrency,

                // Payment, account and company-base currencies remain distinct.
                // The service snapshots both conversions before allocating debt.
                'base_currency_code' => $baseCurrencyCode,

                'reference' => $reference,
                'approval_code' => $request->approval_code,
                'card_last_four' => $request->card_last_four,
                'notes' => $request->notes,
                'idempotency_key' => $request->client_payment_id,
                'allow_account_credit' => $request->boolean('confirm_overpayment'),
                'metadata' => [
                    'source' => $till ? 'pos_credit_customer_payment' : 'back_office_credit_customer_payment',
                    'recorded_by' => $user->id,
                    'account_currency_code' => $accountCurrency,
                    'payment_currency_code' => $paymentCurrency,
                    'base_currency_code' => $baseCurrencyCode,
                    'payment_type_name' => $paymentType->name,
                    'money_code' => $paymentType->money_code,
                ],
            ]);

            // Cashup/payment-row insertion is handled inside CreditCustomerService::recordPayment().
            // Do not insert again here or the payment will appear twice in cashup.

            $idempotentReplay = (bool) $payment->getAttribute('idempotent_replay');
            $paymentData = $payment->fresh() ?: $payment;
            $freshCustomer = $customer->fresh();
            $paymentReceiptPath = "/api/pos/credit-customers/{$customer->id}/payments/{$paymentData->id}/receipt/pdf";
            $paymentData->setAttribute('receipt_pdf_url', $paymentReceiptPath . '?paper=80mm&download=1');
            $paymentData->setAttribute('receipt_print_url', $paymentReceiptPath . '?paper=80mm&download=0');

            return response()->json([
                'message' => $idempotentReplay
                    ? 'Credit customer payment was already captured; the original payment was returned.'
                    : 'Credit customer payment captured successfully.',
                'idempotent_replay' => $idempotentReplay,
                'data' => $paymentData,
                'payment_receipt' => [
                    'payment_id' => $paymentData->id,
                    'payment_number' => $paymentData->payment_number,
                    'print_url' => $paymentReceiptPath . '?paper=80mm&download=0',
                    'download_url' => $paymentReceiptPath . '?paper=80mm&download=1',
                    'a4_download_url' => $paymentReceiptPath . '?paper=a4&download=1',
                ],
                'customer' => $freshCustomer,
                'account' => [
                    'currency_code' => $accountCurrency,
                    'base_currency_code' => $baseCurrencyCode,
                    'payment_currency_code' => $paymentData->currency_code,
                    'amount_received' => round((float) $paymentData->amount, 4),
                    'base_amount' => round((float) $paymentData->base_amount, 4),
                    'account_amount' => round((float) data_get($paymentData->metadata, 'account_amount', 0), 4),
                    'applied_to_debt' => round((float) data_get($paymentData->metadata, 'applied_to_existing_debt', 0), 4),
                    'applied_to_existing_debt' => round((float) data_get($paymentData->metadata, 'applied_to_existing_debt', 0), 4),
                    'credit_created' => round((float) data_get($paymentData->metadata, 'overpayment_credit_created', 0), 4),
                    'overpayment_credit_created' => round((float) data_get($paymentData->metadata, 'overpayment_credit_created', 0), 4),
                    'customer_credit_after_payment' => round((float) data_get($paymentData->metadata, 'customer_credit_after_payment', 0), 4),
                    'balance_after_payment' => round((float) data_get($paymentData->metadata, 'balance_after_payment', 0), 4),
                    'current_balance' => round((float) $freshCustomer->current_balance, 4),
                    'balance_after' => round((float) $freshCustomer->current_balance, 4),
                ],
                'statement' => $this->creditService->statement($customer->id),
            ], $idempotentReplay ? 200 : 201);
        } catch (\Throwable $e) {
            $requiresConfirmation = $e instanceof \InvalidArgumentException
                && str_contains(strtolower($e->getMessage()), 'confirm the overpayment');

            return response()->json(array_filter([
                'message' => 'Credit customer payment failed.',
                'error' => $e->getMessage(),
                'code' => $requiresConfirmation ? 'ACCOUNT_CREDIT_CONFIRMATION_REQUIRED' : null,
                'requires_overpayment_confirmation' => $requiresConfirmation ?: null,
            ], static fn ($value) => $value !== null), $e instanceof \InvalidArgumentException ? 422 : 500);
        }
    }

    public function report(Request $request)
    {
        $company = app('company');
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'credit_customer_reports', $company->id);
        if ($permission !== true) return $permission;

        $branchId = $request->branch_id
            ?: $this->validatedCompanyBranchId($request);

        $customers = CreditCustomer::query()
            ->where('company_id', $company->id)
            ->when($branchId, fn ($q) => $q->where(function ($sub) use ($branchId) {
                $sub->whereNull('branch_id')->orWhere('branch_id', $branchId);
            }))
            ->get();

        $summaryByCurrency = $customers
            ->groupBy(fn ($customer) => strtoupper((string) ($customer->currency_code ?: 'N/A')))
            ->map(fn ($currencyCustomers, $currencyCode) => [
                'currency_code' => $currencyCode,
                'customer_count' => $currencyCustomers->count(),
                'credit_limit' => round((float) $currencyCustomers->sum('credit_limit'), 4),
                'net_balance' => round((float) $currencyCustomers->sum('current_balance'), 4),
                'current_balance' => round((float) $currencyCustomers->sum('current_balance'), 4),
                'amount_due' => round((float) $currencyCustomers->sum(fn ($customer) => max(0, (float) $customer->current_balance)), 4),
                'customer_credit' => round((float) $currencyCustomers->sum(fn ($customer) => max(0, -1 * (float) $customer->current_balance)), 4),
                'available_credit' => round((float) $currencyCustomers->sum(fn ($customer) => (float) $customer->credit_limit - (float) $customer->current_balance), 4),
            ])
            ->values();
        $summary = $this->currencySafeCreditReportSummary($customers->count(), $summaryByCurrency);

        return response()->json([
            'message' => 'Credit customer report fetched successfully.',
            'data' => [
                'summary' => $summary,
                'summary_by_currency' => $summaryByCurrency,
                'ageing' => $this->creditService->ageingReport($company->id, $branchId),
            ],
        ]);
    }

    private function currencySafeCreditReportSummary(int $customerCount, $summaryByCurrency): array
    {
        $currencyCount = $summaryByCurrency->count();
        $hasMixedCurrencies = $currencyCount > 1;
        $singleCurrency = $currencyCount === 1 ? $summaryByCurrency->first() : null;
        $value = static fn (string $key) => $hasMixedCurrencies
            ? null
            : round((float) data_get($singleCurrency, $key, 0), 4);

        return [
            'customer_count' => $customerCount,
            'currency_count' => $currencyCount,
            'currency_code' => $currencyCount === 1 ? data_get($singleCurrency, 'currency_code') : null,
            'has_mixed_currencies' => $hasMixedCurrencies,
            'totals_are_currency_safe' => true,
            'mixed_currency_message' => $hasMixedCurrencies
                ? 'Headline monetary totals are unavailable because the accounts use different currencies. Use summary_by_currency.'
                : null,
            'credit_limit' => $value('credit_limit'),
            'net_balance' => $value('net_balance'),
            'current_balance' => $value('current_balance'),
            'amount_due' => $value('amount_due'),
            'customer_credit' => $value('customer_credit'),
            'credit_balance' => $value('customer_credit'),
            'available_credit' => $value('available_credit'),
        ];
    }

    private function activeShiftFor(string $companyId, ?string $branchId, string $userId, ?string $tillId = null): ?object
    {
        if (!Schema::hasTable('pos_user_shift_sessions')) {
            return null;
        }

        return DB::table('pos_user_shift_sessions')
            ->where('company_id', $companyId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when(
                $tillId && Schema::hasColumn('pos_user_shift_sessions', 'till_id'),
                fn ($q) => $q->where('till_id', $tillId)
            )
            ->where('pos_user_id', $userId)
            ->where('status', 'open')
            ->whereNull('closed_at')
            ->first();
    }

    public function reversePayment(Request $request, string $id, string $paymentId)
    {
        $company = app('company');
        $user = auth()->user();
        $till = app()->bound('till') ? app('till') : null;

        $permission = hasPermission($user->role_id, 'reverse_credit_customer_payment', $company->id);
        if ($permission !== true) return $permission;

        $validator = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'max:500'],
            'disposition' => ['required', Rule::in(['accounting_only', 'cash_refund'])],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $customer = CreditCustomer::where('company_id', $company->id)
            ->findOrFail($id);
        $this->authorizeCustomerBranch($request, $customer, $company->id);

        $payment = CreditCustomerPayment::where('company_id', $company->id)
            ->where('credit_customer_id', $customer->id)
            ->findOrFail($paymentId);

        $reversalContext = [
            'disposition' => $request->disposition,
            'reversed_by' => $user->id,
        ];
        $idempotentReplay = $payment->status === 'reversed';

        if ($request->disposition === 'cash_refund' && !$idempotentReplay) {
            if (!$till) {
                return response()->json([
                    'message' => 'A cash refund must be recorded from a registered POS till.',
                    'code' => 'CASH_REFUND_REQUIRES_POS_TILL',
                ], 422);
            }

            if ($payment->branch_id && (string) $payment->branch_id !== (string) $till->branch_id) {
                return response()->json([
                    'message' => 'The refund till must belong to the same branch as the original payment.',
                ], 422);
            }

            $refundShift = $this->activeShiftFor($company->id, $till->branch_id, $user->id, $till->id);
            if (!$refundShift) {
                return response()->json([
                    'message' => 'No open shift found for the cashier issuing this refund.',
                    'code' => 'SHIFT_SESSION_NOT_OPEN',
                ], 422);
            }

            if ($shiftBlockReason = $this->shiftPolicyService->salesBlockReason($company->id, $till->branch_id, $refundShift)) {
                return response()->json([
                    'message' => 'Shift cashup required before issuing this refund.',
                    'error' => $shiftBlockReason,
                    'code' => 'SHIFT_SALES_CUTOFF_REACHED',
                ], 423);
            }

            $reversalContext = array_merge($reversalContext, [
                'branch_id' => $till->branch_id,
                'till_id' => $till->id,
                'shift_id' => $refundShift->shift_configuration_id ?? null,
                'shift_session_id' => $refundShift->id,
                'cashier_id' => $user->id,
            ]);
        }

        try {
            $ledger = $this->creditService->reversePayment(
                $payment,
                $request->reason,
                $user->id,
                $reversalContext
            );

            return response()->json([
                'message' => $idempotentReplay
                    ? 'Credit customer payment was already reversed; the original reversal was returned.'
                    : 'Credit customer payment reversed successfully.',
                'data' => $ledger,
                'disposition' => data_get($ledger?->metadata, 'financial_disposition', $request->disposition),
                'idempotent_replay' => $idempotentReplay,
                'customer' => $customer->fresh(),
                'statement' => $this->creditService->statement($customer->id),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Credit customer payment reversal failed.',
                'error' => $e->getMessage(),
            ], $e instanceof \InvalidArgumentException ? 422 : 500);
        }
    }

    private function authorizeCustomerBranch(Request $request, CreditCustomer $customer, string $companyId): void
    {
        $branchIds = $this->authorizedBranchIds($request, $companyId);

        $requestedBranchId = $request->input('branch_id');
        if ($requestedBranchId) {
            abort_unless($branchIds->contains((string) $requestedBranchId), 403, 'You are not allocated to the selected branch.');
            abort_if(
                $customer->branch_id && (string) $customer->branch_id !== (string) $requestedBranchId,
                403,
                'This credit account does not belong to the selected branch.'
            );
        }

        if ($customer->branch_id) {
            abort_unless(
                $branchIds->contains((string) $customer->branch_id),
                403,
                'You are not allocated to this credit account branch.'
            );
        }
    }

    /**
     * Keep an 80mm payment docket close to its actual content length so a
     * thermal printer does not feed an A4-height blank tail. DomPDF will still
     * add another page when an unusually large allocation/audit section needs
     * more room.
     */
    private function paymentReceiptPaper(array $sections): array
    {
        $rowCount = collect($sections)->sum(
            fn ($section) => count((array) ($section['rows'] ?? []))
        );
        $wrappedLineAllowance = collect($sections)
            ->flatMap(fn ($section) => (array) ($section['rows'] ?? []))
            ->sum(function ($row) {
                return collect((array) $row)->sum(
                    fn ($value) => max(0, (int) ceil(strlen((string) $value) / 38) - 1)
                );
            });
        $height = 115
            + (count($sections) * 34)
            + ($rowCount * 13)
            + ($wrappedLineAllowance * 8);

        return [0, 0, 226.77, min(1200, max(420, $height))];
    }

    private function authorizePaymentBranch(Request $request, ?string $paymentBranchId, string $companyId): void
    {
        $branchIds = $this->authorizedBranchIds($request, $companyId);
        $requestedBranchId = $request->input('branch_id');

        if ($requestedBranchId) {
            abort_unless($branchIds->contains((string) $requestedBranchId), 403, 'You are not allocated to the selected branch.');
            abort_if(
                $paymentBranchId && (string) $paymentBranchId !== (string) $requestedBranchId,
                403,
                'This credit payment does not belong to the selected branch.'
            );
        }

        if ($paymentBranchId) {
            abort_unless(
                $branchIds->contains((string) $paymentBranchId),
                403,
                'You are not allocated to this credit payment branch.'
            );
        }
    }

    private function authorizedBranchIds(Request $request, string $companyId)
    {
        $user = $request->user();
        abort_if(!$user, 401, 'Unauthenticated.');

        $normalise = static fn ($value) => str_replace([' ', '-'], '_', strtolower(trim((string) $value)));
        $roleName = $normalise(data_get($user, 'role.name'));
        $userType = $normalise(data_get($user, 'user_type'));
        $isSuperAdmin = in_array($roleName, ['super_admin', 'super_administrator'], true)
            || in_array($userType, ['super_admin', 'super_administrator'], true);

        $branchIds = $isSuperAdmin
            ? DB::table('company_branches')
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->pluck('id')
            : collect();

        if (!$isSuperAdmin && method_exists($user, 'branches')) {
            $branchIds = $user->branches()
                ->where('company_branches.company_id', $companyId)
                ->pluck('company_branches.id');
        }

        $validatedBranchId = $this->validatedCompanyBranchId($request);
        if (!$isSuperAdmin && $validatedBranchId !== null) {
            $branchIds->push($validatedBranchId);
        }

        $primaryBranchId = is_scalar(data_get($user, 'branch_id'))
            ? trim((string) data_get($user, 'branch_id'))
            : '';
        if (!$isSuperAdmin
            && $primaryBranchId !== ''
            && $primaryBranchId !== $validatedBranchId
            && Schema::hasTable('company_branches')
            && DB::table('company_branches')
                ->where('company_id', $companyId)
                ->where('id', $primaryBranchId)
                ->when(
                    Schema::hasColumn('company_branches', 'deleted_at'),
                    fn ($query) => $query->whereNull('deleted_at')
                )
                ->exists()) {
            $branchIds->push($primaryBranchId);
        }

        $branchIds = $branchIds
            ->map(fn ($branchId) => (string) $branchId)
            ->filter()
            ->unique()
            ->values();

        abort_if($branchIds->isEmpty(), 403, 'No branches are allocated to this user.');

        return $branchIds;
    }

    /**
     * ResolveCompanyFromDomain only sets this attribute after proving the
     * branch belongs to the active company (and, for authenticated requests,
     * is accessible to the current user).
     */
    private function validatedCompanyBranchId(Request $request): ?string
    {
        $branchId = $request->attributes->get('company_branch_id');

        if (! is_scalar($branchId)) {
            return null;
        }

        $branchId = trim((string) $branchId);

        return $branchId !== '' ? $branchId : null;
    }

    private function a4StatementSections(array $statement): array
    {
        $customer = $statement['customer'] ?? null;
        $currency = strtoupper((string) (data_get($customer, 'currency_code') ?: 'USD'));
        $rows = collect($statement['rows'] ?? []);

        $ledgerRows = $rows->map(function ($row, $index) use ($currency) {
            $rowCurrency = strtoupper((string) (data_get($row, 'currency_code') ?: $currency));

            return [
                $index + 1,
                $this->statementDate(data_get($row, 'transaction_date') ?: data_get($row, 'document_date') ?: data_get($row, 'created_at')),
                strtoupper((string) (data_get($row, 'transaction_type') ?: data_get($row, 'entry_type') ?: '-')),
                data_get($row, 'reference_no') ?: data_get($row, 'document_number') ?: data_get($row, 'receipt_number') ?: '-',
                data_get($row, 'description') ?: data_get($row, 'notes') ?: data_get($row, 'item_summary') ?: '-',
                $this->statementMoney(data_get($row, 'effective_debit_amount', data_get($row, 'debit_amount', 0)), $rowCurrency),
                $this->statementMoney(data_get($row, 'effective_credit_amount', data_get($row, 'credit_amount', 0)), $rowCurrency),
                $this->statementMoney(data_get($row, 'balance', data_get($row, 'balance_after', 0)), $rowCurrency),
            ];
        })->all();

        $itemRows = $rows->flatMap(function ($row) use ($currency) {
            $reference = data_get($row, 'reference_no') ?: data_get($row, 'document_number') ?: data_get($row, 'receipt_number') ?: '-';
            $rowCurrency = strtoupper((string) (data_get($row, 'currency_code') ?: $currency));

            return collect(data_get($row, 'items', []))->map(function ($item) use ($reference, $rowCurrency) {
                $itemCurrency = strtoupper((string) (data_get($item, 'currency_code') ?: $rowCurrency));

                return [
                    $reference,
                    data_get($item, 'line_no') ?: '-',
                    data_get($item, 'product_name') ?: data_get($item, 'line_name') ?: 'Product',
                    collect([data_get($item, 'product_code'), data_get($item, 'sku'), data_get($item, 'barcode')])->filter()->implode(' / ') ?: '-',
                    number_format((float) data_get($item, 'quantity', data_get($item, 'line_quantity', 0)), 4, '.', ''),
                    $this->statementMoney(data_get($item, 'unit_price', data_get($item, 'line_price', 0)), $itemCurrency),
                    $this->statementMoney(data_get($item, 'discount', data_get($item, 'line_discount', 0)), $itemCurrency),
                    $this->statementMoney(data_get($item, 'tax', data_get($item, 'line_tax', 0)), $itemCurrency),
                    $this->statementMoney(data_get($item, 'total', data_get($item, 'line_total', 0)), $itemCurrency),
                ];
            });
        })->values()->all();

        return [
            [
                'title' => 'Account Summary',
                'headers' => ['Field', 'Value', 'Field', 'Value'],
                'rows' => [
                    ['Customer', data_get($customer, 'name') ?: '-', 'Account', data_get($customer, 'account_number') ?: '-'],
                    ['Phone', data_get($customer, 'phone') ?: '-', 'Email', data_get($customer, 'email') ?: '-'],
                    ['Currency', $currency, 'Payment Terms', ((int) data_get($customer, 'payment_terms_days', 0)) . ' days'],
                    ['Credit Limit', $this->statementMoney(data_get($customer, 'credit_limit', 0), $currency), 'Opening Balance', $this->statementMoney($statement['opening_balance'] ?? 0, $currency)],
                    ['Debit Total', $this->statementMoney($statement['debit_total'] ?? 0, $currency), 'Credit Total', $this->statementMoney($statement['credit_total'] ?? 0, $currency)],
                    ['Amount Due', $this->statementMoney($statement['amount_due'] ?? 0, $currency), 'Customer Credit', $this->statementMoney($statement['credit_balance'] ?? 0, $currency)],
                    ['Closing Balance', $this->statementMoney($statement['closing_balance'] ?? 0, $currency), 'Available Credit', $this->statementMoney($statement['available_credit'] ?? 0, $currency)],
                ],
            ],
            [
                'title' => 'Account Ledger',
                'headers' => ['#', 'Date', 'Type', 'Reference', 'Description', 'Debit', 'Credit', 'Balance'],
                'rows' => $ledgerRows,
            ],
            [
                'title' => 'Goods Taken',
                'headers' => ['Receipt', '#', 'Product', 'Code / SKU', 'Quantity', 'Unit Price', 'Discount', 'Tax', 'Total'],
                'rows' => $itemRows,
            ],
        ];
    }

    private function receiptStatementSections(array $statement): array
    {
        $customer = $statement['customer'] ?? null;
        $currency = strtoupper((string) (data_get($customer, 'currency_code') ?: 'USD'));
        $rows = collect($statement['rows'] ?? [])->map(function ($row, $index) use ($currency) {
            $rowCurrency = strtoupper((string) (data_get($row, 'currency_code') ?: $currency));
            $type = strtoupper((string) (data_get($row, 'transaction_type') ?: data_get($row, 'entry_type') ?: '-'));
            $reference = data_get($row, 'reference_no') ?: data_get($row, 'document_number') ?: data_get($row, 'receipt_number') ?: '-';
            $description = trim(implode("\n", array_filter([
                ($index + 1) . '. ' . $type,
                $this->statementDate(data_get($row, 'transaction_date') ?: data_get($row, 'document_date') ?: data_get($row, 'created_at')),
                'Ref: ' . $reference,
                data_get($row, 'description') ?: data_get($row, 'notes') ?: null,
            ])));
            $amounts = implode("\n", [
                'Debit ' . $this->statementMoney(data_get($row, 'effective_debit_amount', data_get($row, 'debit_amount', 0)), $rowCurrency),
                'Credit ' . $this->statementMoney(data_get($row, 'effective_credit_amount', data_get($row, 'credit_amount', 0)), $rowCurrency),
                'Balance ' . $this->statementMoney(data_get($row, 'balance', data_get($row, 'balance_after', 0)), $rowCurrency),
            ]);

            return [$description, $amounts];
        })->all();

        return [
            [
                'title' => 'Account',
                'headers' => ['Field', 'Value'],
                'rows' => [
                    ['Customer', data_get($customer, 'name') ?: '-'],
                    ['Account', data_get($customer, 'account_number') ?: '-'],
                    ['Phone', data_get($customer, 'phone') ?: '-'],
                    ['Currency', $currency],
                    ['Credit Limit', $this->statementMoney(data_get($customer, 'credit_limit', 0), $currency)],
                    ['Opening', $this->statementMoney($statement['opening_balance'] ?? 0, $currency)],
                    ['Debit Total', $this->statementMoney($statement['debit_total'] ?? 0, $currency)],
                    ['Credit Total', $this->statementMoney($statement['credit_total'] ?? 0, $currency)],
                    ['Closing', $this->statementMoney($statement['closing_balance'] ?? 0, $currency)],
                    ['Amount Due', $this->statementMoney($statement['amount_due'] ?? 0, $currency)],
                    ['Customer Credit', $this->statementMoney($statement['credit_balance'] ?? 0, $currency)],
                    ['Available', $this->statementMoney($statement['available_credit'] ?? 0, $currency)],
                ],
            ],
            [
                'title' => 'Activity',
                'headers' => ['Transaction', 'Amounts'],
                'rows' => $rows,
            ],
        ];
    }

    private function statementMoney($amount, string $currency): string
    {
        return strtoupper($currency) . ' ' . number_format((float) $amount, 2, '.', ',');
    }

    private function statementDate($value): string
    {
        if (!$value) return '-';
        if ($value instanceof \DateTimeInterface) return $value->format('Y-m-d H:i');

        try {
            return \Illuminate\Support\Carbon::parse((string) $value)->format('Y-m-d H:i');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private function statementFilename(CreditCustomer $customer, string $paper): string
    {
        $account = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($customer->account_number ?: $customer->name ?: 'credit-customer'));
        $account = trim((string) $account, '-') ?: 'credit-customer';

        return sprintf('%s-statement-%s-%s.pdf', $account, strtolower($paper), now()->format('Ymd-His'));
    }
}
