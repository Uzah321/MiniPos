<?php
//
//namespace Modules\Sales\Http\Controllers;
//
//use App\Http\Controllers\Controller;
//use App\Services\Receipt\ReceiptService;
//use App\Services\Sales\CreditCustomerService;
//use App\Services\Sales\DiscountEngineService;
//use App\Services\Zimra\ZimraReceiptService;
//use Carbon\Carbon;
//use Illuminate\Http\Request;
//use Illuminate\Support\Facades\DB;
//use Illuminate\Support\Facades\Schema;
//use Illuminate\Support\Facades\Validator;
//use Illuminate\Support\Str;
//use Illuminate\Validation\Rule;
//use Modules\Authentication\Models\GeneralSetting;
//use Modules\Authentication\Models\PosUser;
//use Modules\Currency\Models\Currency;
//use Modules\Currency\Models\PaymentType;
//use Modules\Product\Models\Product;
//use Modules\Product\Models\ProductBreakRule;
//use Modules\Product\Models\ProductCost;
//use Modules\Product\Models\ProductPrice;
//use Modules\Product\Models\ProductStock;
//use Modules\Sales\Models\CreditCustomer;
//use Modules\Sales\Models\ReceiptLine;
//use Modules\Sales\Models\ReceiptLineTaxe;
//use Modules\Sales\Models\ReceiptNumberSequence;
//use Modules\Sales\Models\SalesTransaction;
//use Modules\ZimraIntegration\Models\ZimraCompanySetting;
//use Modules\ZimraIntegration\Models\ZimraReceipt;
//use App\Services\CompanySettingService;
//use Modules\FoodManufacturing\Models\FoodRecipe;
//use Modules\Sales\Support\FoodIndustryTradingDaySupport;
//use Modules\Sales\Support\FastFoodOrderSupport;
//use Modules\Sales\Http\Controllers\Concerns\FastFoodSalesIntegration;
//
//use function App\Helpers\currencyConverter;
//use function App\Helpers\hasPermission;
//
//class SalesController extends Controller
//{
//    use FoodIndustryTradingDaySupport, FastFoodOrderSupport, FastFoodSalesIntegration;
//
////    protected ReceiptService $receiptService;
////    protected ZimraReceiptService $zimraReceiptService;
////
////    public function __construct(
////        ReceiptService      $receiptService,
////        ZimraReceiptService $zimraReceiptService
////    )
////    {
////        $this->receiptService = $receiptService;
////        $this->zimraReceiptService = $zimraReceiptService;
////    }
//    protected ReceiptService $receiptService;
//    protected ZimraReceiptService $zimraReceiptService;
//    protected CompanySettingService $companySettingService;
//
//    public function __construct(
//        ReceiptService        $receiptService,
//        ZimraReceiptService   $zimraReceiptService,
//        CompanySettingService $companySettingService
//    )
//    {
//        $this->receiptService = $receiptService;
//        $this->zimraReceiptService = $zimraReceiptService;
//        $this->companySettingService = $companySettingService;
//    }
//
//
////    public function store(Request $request)
////    {
////        $company = app('company');
////        $till = app('till');
////        $user = auth()->user();
////
////        $permission = hasPermission($user->role_id, 'create_sale', $company->id);
////
////        if ($permission !== true) {
////            return $permission;
////        }
////
////        $validator = Validator::make($request->all(), array_merge([
////            'currency_code' => [
////                'required',
////                Rule::exists('currencies', 'code')->where('company_id', $company->id),
////            ],
////
////            'payment_type_id' => [
////                'required',
////                Rule::exists('payment_types', 'id'),
////            ],
////
////            'amount_tendered' => ['required', 'numeric', 'min:0'],
////
////            'customer_name' => ['nullable', 'string', 'max:255'],
////            'customer_phone' => ['nullable', 'string', 'max:255'],
////            'customer_tax_number' => ['nullable', 'string', 'max:255'],
////            'notes' => ['nullable', 'string'],
////
////            'products' => ['required', 'array', 'min:1'],
////            'products.*.product_id' => [
////                'required',
////                Rule::exists('products', 'id')->where('company_id', $company->id),
////            ],
////            'products.*.quantity' => ['required', 'numeric', 'min:0.0001'],
////            'products.*.product_price_id' => ['nullable', 'string'],
////            'products.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
////
////            /*
////             |--------------------------------------------------------------------------
////             | Price Level Override
////             |--------------------------------------------------------------------------
////             | This is not manual price typing. The frontend sends the approved
////             | product_price_id and the audit_id created by applyPriceLevelOverride().
////             */
////            'products.*.price_level_override' => ['nullable', 'array'],
////            'products.*.price_level_override.audit_id' => ['nullable', 'string', 'max:36'],
////            'products.*.price_level_override.manager_approval_id' => ['nullable', 'string', 'max:36'],
////            'products.*.price_level_override.product_price_id' => ['nullable', 'string', 'max:36'],
////            'products.*.price_level_override.price_level_id' => ['nullable', 'string', 'max:36'],
////            'products.*.price_level_override.price' => ['nullable', 'numeric'],
////            'products.*.price_level_override.currency' => ['nullable', 'string', 'max:10'],
////
////            'products.*.discount_payload' => ['nullable', 'array'],
////            'products.*.discount_payload.discount_id' => ['nullable', 'string', 'max:36'],
////            'products.*.discount_payload.discount_code' => ['nullable', 'string', 'max:100'],
////            'products.*.discount_payload.discount_name' => ['nullable', 'string', 'max:255'],
////            'products.*.discount_payload.discount_type_id' => ['nullable', 'string', 'max:36'],
////            'products.*.discount_payload.scope' => ['nullable', 'in:line,cart,customer,employee,coupon,promotion,manual'],
////            'products.*.discount_payload.original_scope' => ['nullable', 'string', 'max:50'],
////            'products.*.discount_payload.discount_basis' => ['nullable', 'in:percentage,fixed_amount,fixed_price'],
////            'products.*.discount_payload.discount_value' => ['nullable', 'numeric'],
////            'products.*.discount_payload.gross_amount' => ['nullable', 'numeric'],
////            'products.*.discount_payload.discount_amount' => ['nullable', 'numeric'],
////            'products.*.discount_payload.net_amount' => ['nullable', 'numeric'],
////            'products.*.discount_payload.manager_approval_id' => ['nullable', 'string', 'max:36'],
////            'products.*.discount_payload.approved_by' => ['nullable', 'string', 'max:36'],
////            'products.*.discount_payload.reason' => ['nullable', 'string', 'max:1000'],
////
////            'credit_customer_id' => ['nullable', 'string', 'exists:credit_customers,id'],
////            'credit_po_number' => ['nullable', 'string', 'max:255'],
////        ]);
////
////        if ($validator->fails()) {
////            return response()->json([
////                'message' => 'Validation error.',
////                'errors' => $validator->errors(),
////            ], 422);
////        }
////
////        $shift = $this->getOpenShift(
////            companyId: $company->id,
////            branchId: $till->branch_id,
////            tillId: $till->id,
////            cashierId: $user->id
////        );
////
////        if (!$shift) {
////            return response()->json([
////                'message' => 'No open shift found for this cashier and till.',
////            ], 422);
////        }
////
////        $saleCurrency = Currency::where('company_id', $company->id)
////            ->where('code', $request->currency_code)
////            ->where('is_active', true)
////            ->first();
////
////        if (!$saleCurrency) {
////            return response()->json([
////                'message' => 'Selected currency is invalid or inactive.',
////            ], 422);
////        }
////
////        $baseCurrency = Currency::where('company_id', $company->id)
////            ->where('is_base_currency', true)
////            ->where('is_active', true)
////            ->first();
////
////        if (!$baseCurrency) {
////            return response()->json([
////                'message' => 'Base currency is not configured.',
////            ], 422);
////        }
////
////        $paymentType = PaymentType::where('id', $request->payment_type_id)
////            ->first();
////
////        if (!$paymentType) {
////            return response()->json([
////                'message' => 'Invalid payment type.',
////            ], 422);
////        }
////
////        $isCustomerCreditPayment = in_array(strtolower((string)$paymentType->money_code), [
////                'customer_credit',
////                'credit_customer',
////                'credit_account',
////                'account',
////                'CUSTOMER_CREDIT'
////            ], true) || str_contains(strtolower((string)$paymentType->name), 'credit');
////
////        $creditCustomer = null;
////
////        if ($isCustomerCreditPayment) {
////            if (!$request->credit_customer_id) {
////                return response()->json([
////                    'message' => 'Select a credit customer for customer account sale.',
////                ], 422);
////            }
////
////            $creditCustomer = CreditCustomer::query()
////                ->where('company_id', $company->id)
////                ->where('id', $request->credit_customer_id)
////                ->where(function ($query) use ($till) {
////                    $query->whereNull('branch_id')->orWhere('branch_id', $till->branch_id);
////                })
////                ->lockForUpdate()
////                ->first();
////
////            if (!$creditCustomer) {
////                return response()->json([
////                    'message' => 'Credit customer not found for this company or branch.',
////                ], 422);
////            }
////        }
////
////
////        $paymentValidation = $this->validatePaymentCurrency(
////            paymentType: $paymentType,
////            currencyCode: $request->currency_code
////        );
////
////        if ($paymentValidation !== true) {
////            return $paymentValidation;
////        }
////
////        DB::beginTransaction();
////
////        try {
////            $receiptNumber = $this->generateReceiptNumber(
////                companyId: $company->id,
////                branchId: $till->branch_id,
////                tillId: $till->id,
////                tillCode: $till->till_code ?? null
////            );
////
////            $exchangeRate = $this->getExchangeRate(
////                fromCode: $baseCurrency->code,
////                toCode: $saleCurrency->code,
////                companyId: $company->id
////            );
////
////            $shiftConfigurationId = $shift->shift_configuration_id ?? null;
////            $shiftSessionId = $shift->id ?? null;
////
////            $sale = SalesTransaction::create([
////                'id' => (string)Str::uuid(),
////
////                'company_id' => $company->id,
////                'branch_id' => $till->branch_id,
////                'till_id' => $till->id,
////
////                /*
////                 |--------------------------------------------------------------------------
////                 | Shift storage
////                 |--------------------------------------------------------------------------
////                 | shift_id stores the shift configuration ID.
////                 | shift_session_id stores the real cashier opened shift session ID.
////                 | Cashup and shift reports must use shift_session_id.
////                 */
////                'shift_id' => $shiftConfigurationId,
////                'shift_session_id' => $shiftSessionId,
////
////                'cashier_id' => $user->id,
////                'user_id' => $user->id,
////
////                'invoice_number' => $receiptNumber,
////                'transaction_date' => now(),
////
////                'document_type' => 'receipt',
////                'sale_type' => 'sale',
////                'status' => 'completed',
////
////                'currency_code' => $saleCurrency->code,
////                'base_currency_code' => $baseCurrency->code,
////                'exchange_rate' => $exchangeRate,
////
////                'subtotal_amount' => 0,
////                'discount_amount' => 0,
////                'tax_amount' => 0,
////                'total_amount' => 0,
////
////                'base_subtotal_amount' => 0,
////                'base_discount_amount' => 0,
////                'base_tax_amount' => 0,
////                'base_total_amount' => 0,
////
////                'payment_type_id' => $paymentType->id,
////                'payment_method' => $paymentType->name,
////
////                'amount_tendered' => (float)$request->amount_tendered,
////                'change_amount' => 0,
////                'change' => 0,
////
////                'customer_name' => $request->customer_name ?: 'Walk-in Customer',
////                'customer_phone' => $request->customer_phone,
////                'customer_tax_number' => $request->customer_tax_number,
////                'notes' => $request->notes,
////
////                'credit_customer_id' => $creditCustomer?->id,
////                'credit_account_number' => $creditCustomer?->account_number,
////                'is_credit_sale' => $isCustomerCreditPayment,
////                'credit_status' => $isCustomerCreditPayment ? 'pending' : null,
////
////                'metadata' => [
////                    'normal_sale' => true,
////                    'fiscalised' => false,
////                    'till_code' => $till->till_code ?? null,
////                    'till_name' => $till->till_name ?? null,
////                    'cashier_name' => $user->name ?? null,
////                    'shift_session_id' => $shiftSessionId,
////                    'shift_configuration_id' => $shiftConfigurationId,
////                ],
////            ]);
////
////            $totals = $this->createReceiptLines(
////                sale: $sale,
////                products: $request->products,
////                companyId: $company->id,
////                branchId: $till->branch_id,
////                saleCurrencyCode: $saleCurrency->code,
////                baseCurrencyCode: $baseCurrency->code,
////                cashierId: $user->id
////            );
////
//////            $amountTendered = (float)$request->amount_tendered;
//////            $saleTotal = round($totals['total'], 4);
//////
//////            if ($amountTendered + 0.0001 < $saleTotal) {
//////                throw new \Exception('Amount tendered is less than sale total.');
//////            }
////
////            $amountTendered = (float)$request->amount_tendered;
////            $saleTotal = round($totals['total'], 4);
////
////            if (!$isCustomerCreditPayment && $amountTendered + 0.0001 < $saleTotal) {
////                throw new \Exception('Amount tendered is less than sale total.');
////            }
////
////            $change = $isCustomerCreditPayment ? 0 : max($amountTendered - $saleTotal, 0);
////
////
////            $sale->update([
////                'subtotal_amount' => round($totals['subtotal'], 4),
////                'discount_amount' => round($totals['discount'], 4),
////                'tax_amount' => round($totals['tax'], 4),
////                'total_amount' => round($totals['total'], 4),
////
////                'base_subtotal_amount' => round($totals['base_subtotal'], 4),
////                'base_discount_amount' => round($totals['base_discount'], 4),
////                'base_tax_amount' => round($totals['base_tax'], 4),
////                'base_total_amount' => round($totals['base_total'], 4),
////
////                'change_amount' => round($change, 4),
////                'change' => round($change, 4),
////            ]);
////
////            if ($isCustomerCreditPayment) {
////                app(CreditCustomerService::class)->postCreditSale(
////                    sale: $sale,
////                    customer: $creditCustomer,
////                    amount: $saleTotal,
////                    currencyCode: $saleCurrency->code,
////                    baseCurrencyCode: $baseCurrency->code,
////                    exchangeRate: $exchangeRate,
////                    shiftSessionId: $shiftSessionId,
////                    tillId: $till->id,
////                    cashierId: $user->id,
////                    poNumber: $request->credit_po_number,
////                    notes: $request->notes
////                );
////
////                /* This line makes cashup know this amount was CUSTOMER CREDIT.
////                   It is not cash, but it appears in payment summary as Customer Credit. */
////                $this->saveSalePayment(
////                    sale: $sale,
////                    paymentType: $paymentType,
////                    companyId: $company->id,
////                    branchId: $till->branch_id,
////                    tillId: $till->id,
////                    shiftId: $shiftConfigurationId,
////                    shiftSessionId: $shiftSessionId,
////                    currencyCode: $saleCurrency->code,
////                    baseCurrencyCode: $baseCurrency->code,
////                    amountTendered: 0,
////                    saleTotal: $saleTotal,
////                    change: 0
////                );
////            } else {
////                $this->saveSalePayment(
////                    sale: $sale,
////                    paymentType: $paymentType,
////                    companyId: $company->id,
////                    branchId: $till->branch_id,
////                    tillId: $till->id,
////                    shiftId: $shiftConfigurationId,
////                    shiftSessionId: $shiftSessionId,
////                    currencyCode: $saleCurrency->code,
////                    baseCurrencyCode: $baseCurrency->code,
////                    amountTendered: $amountTendered,
////                    saleTotal: $saleTotal,
////                    change: $change
////                );
////            }
////
////            DB::commit();
////
////            /*
////             |--------------------------------------------------------------------------
////             | ZIMRA fiscalisation
////             |--------------------------------------------------------------------------
////             | The local sale is already safely committed. We now submit the fiscal
////             | receipt to FDMS. If fiscalisation is disabled, the sale is marked
////             | as skipped. If it fails, behaviour depends on company ZIMRA settings.
////             */
////            $fiscalisation = $this->fiscaliseSaleAfterCommit($sale);
////
////            if (($fiscalisation['blocked'] ?? false) === true) {
////                return response()->json([
////                    'message' => 'Sale saved, but ZIMRA fiscalisation failed.',
////                    'error' => $fiscalisation['error'] ?? 'Fiscalisation failed.',
////                    'data' => [
////                        'sale' => $fiscalisation['sale'] ?? $sale->fresh(),
////                        'fiscalisation' => $fiscalisation,
////                    ],
////                ], 409);
////            }
////
////            $sale = $fiscalisation['sale'] ?? $sale->fresh();
////
////            return $this->buildSaleCreatedResponse(
////                sale: $sale,
////                receiptNumber: $receiptNumber,
////                saleCurrencyCode: $saleCurrency->code,
////                baseCurrencyCode: $baseCurrency->code,
////                totals: $totals,
////                amountTendered: $amountTendered,
////                change: $change,
////                till: $till,
////                shift: $shift,
////                fiscalisation: $fiscalisation
////            );
////        } catch (\Throwable $e) {
////            if (DB::transactionLevel() > 0) {
////                DB::rollBack();
////            }
////
////            return response()->json([
////                'message' => 'Sale creation failed.',
////                'error' => $e->getMessage(),
////            ], 500);
////        }
////    }
//    public function store(Request $request)
//    {
//        $company = app('company');
//        $till = app('till');
//        $user = auth()->user();
//
//        $permission = hasPermission($user->role_id, 'create_sale', $company->id);
//
//        if ($permission !== true) {
//            return $permission;
//        }
//
//        $validator = Validator::make($request->all(), array_merge([
//            'currency_code' => [
//                'required',
//                Rule::exists('currencies', 'code')->where('company_id', $company->id),
//            ],
//
//            /*
//             |--------------------------------------------------------------------------
//             | Backward-compatible single payment fields
//             |--------------------------------------------------------------------------
//             | These remain required so your old frontend still works.
//             | New frontend can also send payments[] for split tender.
//             */
//            'payment_type_id' => [
//                'required',
//                Rule::exists('payment_types', 'id'),
//            ],
//
//            'amount_tendered' => ['required', 'numeric', 'min:0'],
//
//            /*
//             |--------------------------------------------------------------------------
//             | Optional multi-payment fields
//             |--------------------------------------------------------------------------
//             | Example:
//             | payments: [
//             |   { payment_type_id: "...cash...", currency_code: "USD", amount: 8 },
//             |   { payment_type_id: "...card...", currency_code: "USD", amount: 2 }
//             | ]
//             */
//            'payments' => ['nullable', 'array'],
//            'payments.*.payment_type_id' => [
//                'required_with:payments',
//                Rule::exists('payment_types', 'id'),
//            ],
//            'payments.*.currency_code' => [
//                'required_with:payments',
//                'string',
//                'max:10',
//            ],
//            'payments.*.amount' => [
//                'required_with:payments',
//                'numeric',
//                'min:0.0001',
//            ],
//
//            'customer_name' => ['nullable', 'string', 'max:255'],
//            'customer_phone' => ['nullable', 'string', 'max:255'],
//            'customer_tax_number' => ['nullable', 'string', 'max:255'],
//            'notes' => ['nullable', 'string'],
//
//            'products' => ['required', 'array', 'min:1'],
//            'products.*.product_id' => [
//                'required',
//                Rule::exists('products', 'id')->where('company_id', $company->id),
//            ],
//            'products.*.quantity' => ['required', 'numeric', 'min:0.0001'],
//            'products.*.product_price_id' => ['nullable', 'string'],
//            'products.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
//
//            'products.*.price_level_override' => ['nullable', 'array'],
//            'products.*.price_level_override.audit_id' => ['nullable', 'string', 'max:36'],
//            'products.*.price_level_override.manager_approval_id' => ['nullable', 'string', 'max:36'],
//            'products.*.price_level_override.product_price_id' => ['nullable', 'string', 'max:36'],
//            'products.*.price_level_override.price_level_id' => ['nullable', 'string', 'max:36'],
//            'products.*.price_level_override.price' => ['nullable', 'numeric'],
//            'products.*.price_level_override.currency' => ['nullable', 'string', 'max:10'],
//
//            'products.*.discount_payload' => ['nullable', 'array'],
//            'products.*.discount_payload.discount_id' => ['nullable', 'string', 'max:36'],
//            'products.*.discount_payload.discount_code' => ['nullable', 'string', 'max:100'],
//            'products.*.discount_payload.discount_name' => ['nullable', 'string', 'max:255'],
//            'products.*.discount_payload.discount_type_id' => ['nullable', 'string', 'max:36'],
//            'products.*.discount_payload.scope' => ['nullable', 'in:line,cart,customer,employee,coupon,promotion,manual'],
//            'products.*.discount_payload.original_scope' => ['nullable', 'string', 'max:50'],
//            'products.*.discount_payload.discount_basis' => ['nullable', 'in:percentage,fixed_amount,fixed_price'],
//            'products.*.discount_payload.discount_value' => ['nullable', 'numeric'],
//            'products.*.discount_payload.gross_amount' => ['nullable', 'numeric'],
//            'products.*.discount_payload.discount_amount' => ['nullable', 'numeric'],
//            'products.*.discount_payload.net_amount' => ['nullable', 'numeric'],
//            'products.*.discount_payload.manager_approval_id' => ['nullable', 'string', 'max:36'],
//            'products.*.discount_payload.approved_by' => ['nullable', 'string', 'max:36'],
//            'products.*.discount_payload.reason' => ['nullable', 'string', 'max:1000'],
//
//            'credit_customer_id' => ['nullable', 'string', 'exists:credit_customers,id'],
//            'credit_po_number' => ['nullable', 'string', 'max:255'],
//        ], $this->fastFoodSaleValidationRules()));
//
//        if ($validator->fails()) {
//            return response()->json([
//                'message' => 'Validation error.',
//                'errors' => $validator->errors(),
//            ], 422);
//        }
//
//        $shift = $this->getOpenShift(
//            companyId: $company->id,
//            branchId: $till->branch_id,
//            tillId: $till->id,
//            cashierId: $user->id
//        );
//
//        if (!$shift) {
//            return response()->json([
//                'message' => 'No open shift found for this cashier and till.',
//            ], 422);
//        }
//
//        $saleCurrency = Currency::where('company_id', $company->id)
//            ->where('code', $request->currency_code)
//            ->where('is_active', true)
//            ->first();
//
//        if (!$saleCurrency) {
//            return response()->json([
//                'message' => 'Selected currency is invalid or inactive.',
//            ], 422);
//        }
//
//        $baseCurrency = Currency::where('company_id', $company->id)
//            ->where('is_base_currency', true)
//            ->where('is_active', true)
//            ->first();
//
//        if (!$baseCurrency) {
//            return response()->json([
//                'message' => 'Base currency is not configured.',
//            ], 422);
//        }
//
//        $paymentType = PaymentType::where('id', $request->payment_type_id)
//            ->first();
//
//        if (!$paymentType) {
//            return response()->json([
//                'message' => 'Invalid payment type.',
//            ], 422);
//        }
//
//        /*
//         |--------------------------------------------------------------------------
//         | Build payment rows
//         |--------------------------------------------------------------------------
//         | If payments[] is not sent, we create one row from the old fields.
//         */
//        $paymentRows = collect($request->input('payments', []));
//
//        if ($paymentRows->isEmpty()) {
//            $paymentRows = collect([
//                [
//                    'payment_type_id' => $request->payment_type_id,
//                    'currency_code' => $request->currency_code,
//                    'amount' => (float)$request->amount_tendered,
//                ],
//            ]);
//        } else {
//            $paymentRows = $paymentRows->values();
//        }
//
//        $multiPaymentValidation = $this->validateSaleMultiPayments(
//            paymentRows: $paymentRows,
//            saleCurrencyCode: $saleCurrency->code
//        );
//
//        if ($multiPaymentValidation !== true) {
//            return $multiPaymentValidation;
//        }
//
//        $paymentTypeIds = $paymentRows
//            ->pluck('payment_type_id')
//            ->filter()
//            ->unique()
//            ->values();
//
//        $paymentTypesById = PaymentType::query()
//            ->whereIn('id', $paymentTypeIds)
//            ->get()
//            ->keyBy('id');
//
//        if ($paymentTypesById->count() !== $paymentTypeIds->count()) {
//            return response()->json([
//                'message' => 'One or more payment types are invalid.',
//            ], 422);
//        }
//
//        foreach ($paymentRows as $index => $paymentRow) {
//            $rowPaymentType = $paymentTypesById->get($paymentRow['payment_type_id']);
//
//            $paymentValidation = $this->validatePaymentCurrency(
//                paymentType: $rowPaymentType,
//                currencyCode: $saleCurrency->code
//            );
//
//            if ($paymentValidation !== true) {
//                return $paymentValidation;
//            }
//        }
//
//        $amountTendered = round(
//            $paymentRows->sum(fn($row) => (float)($row['amount'] ?? 0)),
//            4
//        );
//
//        $isCustomerCreditPayment = $this->isCustomerCreditPaymentType($paymentType);
//
//        /*
//         |--------------------------------------------------------------------------
//         | Customer credit limitation
//         |--------------------------------------------------------------------------
//         | Keep credit sale as single-payment for now because it posts to customer
//         | account ledger. Cash + card multi-payment is supported.
//         */
//        if ($paymentRows->count() > 1) {
//            foreach ($paymentRows as $paymentRow) {
//                $rowPaymentType = $paymentTypesById->get($paymentRow['payment_type_id']);
//
//                if ($this->isCustomerCreditPaymentType($rowPaymentType)) {
//                    return response()->json([
//                        'message' => 'Customer credit cannot be mixed with other payment methods.',
//                        'error' => 'Use customer credit as a single payment method.',
//                    ], 422);
//                }
//            }
//        }
//
//        $creditCustomer = null;
//
//        if ($isCustomerCreditPayment) {
//            if (!$request->credit_customer_id) {
//                return response()->json([
//                    'message' => 'Select a credit customer for customer account sale.',
//                ], 422);
//            }
//
//            $creditCustomer = CreditCustomer::query()
//                ->where('company_id', $company->id)
//                ->where('id', $request->credit_customer_id)
//                ->where(function ($query) use ($till) {
//                    $query->whereNull('branch_id')->orWhere('branch_id', $till->branch_id);
//                })
//                ->lockForUpdate()
//                ->first();
//
//            if (!$creditCustomer) {
//                return response()->json([
//                    'message' => 'Credit customer not found for this company or branch.',
//                ], 422);
//            }
//        }
//
//        DB::beginTransaction();
//
//        try {
//            $receiptNumber = $this->generateReceiptNumber(
//                companyId: $company->id,
//                branchId: $till->branch_id,
//                tillId: $till->id,
//                tillCode: $till->till_code ?? null
//            );
//
//            $exchangeRate = $this->getExchangeRate(
//                fromCode: $baseCurrency->code,
//                toCode: $saleCurrency->code,
//                companyId: $company->id
//            );
//
//            $shiftConfigurationId = $shift->shift_configuration_id ?? null;
//            $shiftSessionId = $shift->id ?? null;
//
//            /*
//             |--------------------------------------------------------------------------
//             | Food industry business trading date
//             |--------------------------------------------------------------------------
//             | Retail remains unchanged. Only BOH + Fast Food mode uses the open
//             | trading day as business_date. transaction_date stays as real system
//             | timestamp for audit.
//             */
//            $foodBusinessDatePayload = $this->foodIndustrySaleDatePayload(
//                companyId: $company->id,
//                branchId: $till->branch_id
//            );
//
//            if (($foodBusinessDatePayload['sale_blocked'] ?? false) === true) {
//                if (DB::transactionLevel() > 0) {
//                    DB::rollBack();
//                }
//
//                return response()->json([
//                    'message' => 'Sale rejected by trading day control.',
//                    'error' => $foodBusinessDatePayload['sale_block_reason']
//                        ?? 'The current trading day is not allowed to sell.',
//                    'data' => [
//                        'business_date' => $foodBusinessDatePayload['business_date'] ?? null,
//                        'system_date' => $foodBusinessDatePayload['system_date'] ?? null,
//                        'trading_day_id' => $foodBusinessDatePayload['trading_day_id'] ?? null,
//                        'trading_day_status' => $foodBusinessDatePayload['trading_day_status_at_sale'] ?? null,
//                        'trading_date_is_future' => $foodBusinessDatePayload['trading_date_is_future'] ?? false,
//                        'trading_date_is_past' => $foodBusinessDatePayload['trading_date_is_past'] ?? false,
//                    ],
//                ], 422);
//            }
//
//            $saleCreatePayload = [
//                'id' => (string)Str::uuid(),
//
//                'company_id' => $company->id,
//                'branch_id' => $till->branch_id,
//                'till_id' => $till->id,
//
//                'shift_id' => $shiftConfigurationId,
//                'shift_session_id' => $shiftSessionId,
//
//                'cashier_id' => $user->id,
//                'user_id' => $user->id,
//
//                'invoice_number' => $receiptNumber,
//                'transaction_date' => now(),
//
//                'document_type' => 'receipt',
//                'sale_type' => 'sale',
//                'status' => 'completed',
//
//                'currency_code' => $saleCurrency->code,
//                'base_currency_code' => $baseCurrency->code,
//                'exchange_rate' => $exchangeRate,
//
//                'subtotal_amount' => 0,
//                'discount_amount' => 0,
//                'tax_amount' => 0,
//                'total_amount' => 0,
//
//                'base_subtotal_amount' => 0,
//                'base_discount_amount' => 0,
//                'base_tax_amount' => 0,
//                'base_total_amount' => 0,
//
//                /*
//                 |--------------------------------------------------------------------------
//                 | Primary payment fields
//                 |--------------------------------------------------------------------------
//                 | Store the first/selected payment type for backward compatibility.
//                 | Detailed split payments are saved into sale payment records below.
//                 */
//                'payment_type_id' => $paymentType->id,
//                'payment_method' => $paymentType->name,
//
//                'amount_tendered' => $amountTendered,
//                'change_amount' => 0,
//                'change' => 0,
//
//                'customer_name' => $request->customer_name ?: 'Walk-in Customer',
//                'customer_phone' => $request->customer_phone,
//                'customer_tax_number' => $request->customer_tax_number,
//                'notes' => $request->notes,
//
//                'credit_customer_id' => $creditCustomer?->id,
//                'credit_account_number' => $creditCustomer?->account_number,
//                'is_credit_sale' => $isCustomerCreditPayment,
//                'credit_status' => $isCustomerCreditPayment ? 'pending' : null,
//
//                'metadata' => [
//                    'normal_sale' => true,
//                    'fiscalised' => false,
//                    'till_code' => $till->till_code ?? null,
//                    'till_name' => $till->till_name ?? null,
//                    'cashier_name' => $user->name ?? null,
//                    'shift_session_id' => $shiftSessionId,
//                    'shift_configuration_id' => $shiftConfigurationId,
//                    'multi_payment' => $paymentRows->count() > 1,
//                    'payment_count' => $paymentRows->count(),
//                    'food_industry_business_date_enabled' => $foodBusinessDatePayload['food_industry_business_date_enabled'],
//                    'business_date' => $foodBusinessDatePayload['business_date'],
//                    'trading_day_id' => $foodBusinessDatePayload['trading_day_id'],
//                    'trading_day_status_at_sale' => $foodBusinessDatePayload['trading_day_status_at_sale'],
//                    'system_date_at_sale' => $foodBusinessDatePayload['system_date'] ?? null,
//                    'trading_date_is_future' => $foodBusinessDatePayload['trading_date_is_future'] ?? false,
//                    'trading_date_is_past' => $foodBusinessDatePayload['trading_date_is_past'] ?? false,
//                    'trading_date_matches_system_date' => $foodBusinessDatePayload['trading_date_matches_system_date'] ?? false,
//                ],
//            ];
//
//            /*
//             |--------------------------------------------------------------------------
//             | Store business trading date on the sale row immediately
//             |--------------------------------------------------------------------------
//             | Do not wait for metadata only. Reports must read sales_transactions.business_date
//             | and trading_day_id directly. transaction_date remains the real system timestamp.
//             */
//            if (($foodBusinessDatePayload['food_industry_business_date_enabled'] ?? false) === true) {
//                if (Schema::hasColumn('sales_transactions', 'business_date')) {
//                    $saleCreatePayload['business_date'] = $foodBusinessDatePayload['business_date'];
//                }
//
//                if (Schema::hasColumn('sales_transactions', 'trading_day_id')) {
//                    $saleCreatePayload['trading_day_id'] = $foodBusinessDatePayload['trading_day_id'];
//                }
//            }
//
//            $sale = SalesTransaction::create($saleCreatePayload);
//
//            $sale = $this->assignFastFoodOrderAfterSaleCreate(
//                sale: $sale,
//                companyId: $company->id,
//                branchId: $till->branch_id,
//                tillId: $till->id,
//                tillCode: $till->till_code ?? null
//            );
//
//            $this->applyFoodIndustryBusinessDateToSale($sale, $foodBusinessDatePayload);
//
//            $totals = $this->createReceiptLines(
//                sale: $sale,
//                products: $request->products,
//                companyId: $company->id,
//                branchId: $till->branch_id,
//                saleCurrencyCode: $saleCurrency->code,
//                baseCurrencyCode: $baseCurrency->code,
//                cashierId: $user->id
//            );
//
//            $saleTotal = round($totals['total'], 4);
//
//            if (!$isCustomerCreditPayment && $amountTendered + 0.0001 < $saleTotal) {
//                throw new \Exception('Amount tendered is less than sale total.');
//            }
//
//            $change = $isCustomerCreditPayment ? 0 : max($amountTendered - $saleTotal, 0);
//
//            $sale->update([
//                'subtotal_amount' => round($totals['subtotal'], 4),
//                'discount_amount' => round($totals['discount'], 4),
//                'tax_amount' => round($totals['tax'], 4),
//                'total_amount' => round($totals['total'], 4),
//
//                'base_subtotal_amount' => round($totals['base_subtotal'], 4),
//                'base_discount_amount' => round($totals['base_discount'], 4),
//                'base_tax_amount' => round($totals['base_tax'], 4),
//                'base_total_amount' => round($totals['base_total'], 4),
//
//                'amount_tendered' => round($amountTendered, 4),
//                'change_amount' => round($change, 4),
//                'change' => round($change, 4),
//            ]);
//
//            if ($isCustomerCreditPayment) {
//                app(CreditCustomerService::class)->postCreditSale(
//                    sale: $sale,
//                    customer: $creditCustomer,
//                    amount: $saleTotal,
//                    currencyCode: $saleCurrency->code,
//                    baseCurrencyCode: $baseCurrency->code,
//                    exchangeRate: $exchangeRate,
//                    shiftSessionId: $shiftSessionId,
//                    tillId: $till->id,
//                    cashierId: $user->id,
//                    poNumber: $request->credit_po_number,
//                    notes: $request->notes
//                );
//
//                $this->saveSalePayment(
//                    sale: $sale,
//                    paymentType: $paymentType,
//                    companyId: $company->id,
//                    branchId: $till->branch_id,
//                    tillId: $till->id,
//                    shiftId: $shiftConfigurationId,
//                    shiftSessionId: $shiftSessionId,
//                    currencyCode: $saleCurrency->code,
//                    baseCurrencyCode: $baseCurrency->code,
//                    amountTendered: 0,
//                    saleTotal: $saleTotal,
//                    change: 0
//                );
//            } else {
//                /*
//                 |--------------------------------------------------------------------------
//                 | Save each split payment
//                 |--------------------------------------------------------------------------
//                 | Example total 10:
//                 | Cash 8, Card 2 => two payment records.
//                 |
//                 | If overpaid on last line:
//                 | Cash 8, Card 5, total 10 => Card line carries change 3.
//                 */
//                $remainingTotal = $saleTotal;
//                $lastPaymentIndex = $paymentRows->count() - 1;
//
//                foreach ($paymentRows as $index => $paymentRow) {
//                    $rowPaymentType = $paymentTypesById->get($paymentRow['payment_type_id']);
//                    $rowAmount = round((float)$paymentRow['amount'], 4);
//                    $isLastPayment = ((int)$index === $lastPaymentIndex);
//
//                    $rowSaleTotal = $isLastPayment
//                        ? max($remainingTotal, 0)
//                        : min($rowAmount, $remainingTotal);
//
//                    $rowChange = $isLastPayment ? $change : 0;
//
//                    $this->saveSalePayment(
//                        sale: $sale,
//                        paymentType: $rowPaymentType,
//                        companyId: $company->id,
//                        branchId: $till->branch_id,
//                        tillId: $till->id,
//                        shiftId: $shiftConfigurationId,
//                        shiftSessionId: $shiftSessionId,
//                        currencyCode: $saleCurrency->code,
//                        baseCurrencyCode: $baseCurrency->code,
//                        amountTendered: $rowAmount,
//                        saleTotal: $rowSaleTotal,
//                        change: $rowChange
//                    );
//
//                    $remainingTotal = round($remainingTotal - $rowSaleTotal, 4);
//                }
//            }
//
//            DB::commit();
//
//            $fiscalisation = $this->fiscaliseSaleAfterCommit($sale);
//
//            if (($fiscalisation['blocked'] ?? false) === true) {
//                return response()->json([
//                    'message' => 'Sale saved, but ZIMRA fiscalisation failed.',
//                    'error' => $fiscalisation['error'] ?? 'Fiscalisation failed.',
//                    'data' => [
//                        'sale' => $fiscalisation['sale'] ?? $sale->fresh(),
//                        'fiscalisation' => $fiscalisation,
//                    ],
//                ], 409);
//            }
//
//            $sale = $fiscalisation['sale'] ?? $sale->fresh();
//
//            return $this->buildSaleCreatedResponse(
//                sale: $sale,
//                receiptNumber: $receiptNumber,
//                saleCurrencyCode: $saleCurrency->code,
//                baseCurrencyCode: $baseCurrency->code,
//                totals: $totals,
//                amountTendered: $amountTendered,
//                change: $change,
//                till: $till,
//                shift: $shift,
//                fiscalisation: $fiscalisation
//            );
//        } catch (\Throwable $e) {
//            if (DB::transactionLevel() > 0) {
//                DB::rollBack();
//            }
//
//            return response()->json([
//                'message' => 'Sale creation failed.',
//                'error' => $e->getMessage(),
//            ], 500);
//        }
//    }
//
//    private function validateSaleMultiPayments($paymentRows, string $saleCurrencyCode)
//    {
//        if ($paymentRows->isEmpty()) {
//            return response()->json([
//                'message' => 'At least one payment method is required.',
//            ], 422);
//        }
//
//        $saleCurrency = strtoupper((string)$saleCurrencyCode);
//
//        foreach ($paymentRows as $index => $paymentRow) {
//            $lineNumber = $index + 1;
//
//            $paymentCurrency = strtoupper((string)($paymentRow['currency_code'] ?? ''));
//
//            if ($paymentCurrency === '') {
//                return response()->json([
//                    'message' => "Payment line {$lineNumber} currency is required.",
//                ], 422);
//            }
//
//            if ($paymentCurrency !== $saleCurrency) {
//                return response()->json([
//                    'message' => 'Multi-payment currency mismatch.',
//                    'error' => "Payment line {$lineNumber} uses {$paymentCurrency}, but sale currency is {$saleCurrency}. All split payments must use the same currency.",
//                ], 422);
//            }
//
//            $amount = (float)($paymentRow['amount'] ?? 0);
//
//            if ($amount <= 0) {
//                return response()->json([
//                    'message' => "Payment line {$lineNumber} amount must be greater than zero.",
//                ], 422);
//            }
//
//            if (empty($paymentRow['payment_type_id'])) {
//                return response()->json([
//                    'message' => "Payment line {$lineNumber} payment type is required.",
//                ], 422);
//            }
//        }
//
//        return true;
//    }
//
//    private function isCustomerCreditPaymentType($paymentType): bool
//    {
//        $moneyCode = strtolower((string)($paymentType->money_code ?? ''));
//        $name = strtolower((string)($paymentType->name ?? ''));
//
//        return in_array($moneyCode, [
//                'customer_credit',
//                'credit_customer',
//                'credit_account',
//                'account',
//            ], true) || str_contains($name, 'credit');
//    }
//
//    private function createReceiptLines(
//        SalesTransaction $sale,
//        array            $products,
//        string           $companyId,
//        string           $branchId,
//        string           $saleCurrencyCode,
//        string           $baseCurrencyCode,
//        string           $cashierId
//    ): array
//    {
//        $subtotal = 0;
//        $discount = 0;
//        $tax = 0;
//        $total = 0;
//
//        $baseSubtotal = 0;
//        $baseDiscount = 0;
//        $baseTax = 0;
//        $baseTotal = 0;
//
//        $lineNo = 1;
//
//        foreach ($products as $item) {
//            $product = Product::where('company_id', $companyId)
//                ->where('id', $item['product_id'])
//                ->with([
//                    'uom',
//                    'tax', // legacy single tax, safe to keep
//                    'taxes' => function ($query) use ($branchId) {
//                        $query->where(function ($sub) use ($branchId) {
//                            $sub->whereNull('branch_id')
//                                ->orWhere('branch_id', $branchId);
//                        })
//                            ->where('is_active', true)
//                            ->where(function ($sub) {
//                                $sub->whereNull('starts_at')
//                                    ->orWhere('starts_at', '<=', now());
//                            })
//                            ->where(function ($sub) {
//                                $sub->whereNull('ends_at')
//                                    ->orWhere('ends_at', '>=', now());
//                            })
//                            ->with('tax')
//                            ->orderByRaw('CASE WHEN branch_id IS NULL THEN 1 ELSE 0 END');
//                    },
//                ])
//                ->lockForUpdate()
//                ->first();
//
//            if (!$product) {
//                throw new \Exception('Product not found.');
//            }
//
//            $quantity = (float)$item['quantity'];
//
//            $fastFoodCustomisation = $this->resolveFastFoodSaleLineCustomisationOrEmpty(
//                item: $item,
//                product: $product,
//                companyId: $companyId,
//                branchId: $branchId
//            );
//
//            $price = $this->resolveProductPrice(
//                product: $product,
//                productPriceId: $item['product_price_id'] ?? null,
//                companyId: $companyId,
//                branchId: $branchId
//            );
//
//            $priceLevelOverride = $this->validatePriceLevelOverrideForSaleLine(
//                line: $item,
//                selectedPrice: $price,
//                product: $product,
//                companyId: $companyId,
//                branchId: $branchId,
//                cashierId: $cashierId
//            );
//
//            $priceCurrency = $price->currency ?? $baseCurrencyCode;
//
//            $unitPrice = (float)currencyConverter(
//                $priceCurrency,
//                $saleCurrencyCode,
//                (float)$price->price,
//                $companyId
//            );
//
//            $baseUnitPrice = (float)currencyConverter(
//                $priceCurrency,
//                $baseCurrencyCode,
//                (float)$price->price,
//                $companyId
//            );
//
//            /*
//             |--------------------------------------------------------------------------
//             | Fast food modifier price uplift
//             |--------------------------------------------------------------------------
//             | Modifiers are not separate selling products, but they can carry prices.
//             | The selected modifier prices increase the parent menu item unit price.
//             | Example: Burger 5.00 + Extra Cheese 0.50 x 2 = unit price 6.00.
//             */
//            $baseMenuUnitPrice = $unitPrice;
//            $baseMenuBaseUnitPrice = $baseUnitPrice;
//            $modifierUnitPrice = $this->fastFoodModifierUnitPriceForSale(
//                customisation: $fastFoodCustomisation,
//                saleCurrencyCode: $saleCurrencyCode,
//                companyId: $companyId
//            );
//            $modifierBaseUnitPrice = $this->fastFoodModifierBaseUnitPriceForSale(
//                customisation: $fastFoodCustomisation,
//                saleCurrencyCode: $saleCurrencyCode,
//                baseCurrencyCode: $baseCurrencyCode,
//                companyId: $companyId
//            );
//
//            $unitPrice += $modifierUnitPrice;
//            $baseUnitPrice += $modifierBaseUnitPrice;
//
//            $discountPayload = $item['discount_payload'] ?? null;
//            $discountApproval = null;
//
//            $lineGrossAmount = round($unitPrice * $quantity, 4);
//            $lineDiscount = (float)($item['discount_amount'] ?? 0);
//
//            if ($discountPayload && (float)($discountPayload['discount_amount'] ?? 0) > 0) {
//                /** @var DiscountEngineService $discountEngine */
//                $discountEngine = app(DiscountEngineService::class);
//
//                $discountApproval = $discountEngine->validateDiscountApproval(
//                    line: $item,
//                    companyId: $companyId,
//                    branchId: $branchId,
//                    cashierId: $cashierId
//                );
//
//                $lineDiscount = min((float)$discountPayload['discount_amount'], $lineGrossAmount);
//            } else {
//                $lineDiscount = min($lineDiscount, $lineGrossAmount);
//            }
//
//            $lineCalc = $this->calculateLine(
//                product: $product,
//                unitPrice: $unitPrice,
//                quantity: $quantity,
//                discountAmount: $lineDiscount
//            );
//
//            $baseLineDiscount = (float)currencyConverter(
//                $saleCurrencyCode,
//                $baseCurrencyCode,
//                $lineDiscount,
//                $companyId
//            );
//
//            $baseLineCalc = $this->calculateLine(
//                product: $product,
//                unitPrice: $baseUnitPrice,
//                quantity: $quantity,
//                discountAmount: $baseLineDiscount
//            );
//
//            $receiptLine = ReceiptLine::create([
//                'id' => (string)Str::uuid(),
//                'company_id' => $companyId,
//                'branch_id' => $branchId,
//                'sales_transaction_id' => $sale->id,
//                'product_id' => $product->id,
//                'product_price_id' => $price->id,
//                'price_level_id' => $price->price_level_id,
//                'line_no' => $lineNo++,
//                'line_name' => $product->name,
//                'product_code' => $product->code,
//                'sku' => $product->sku,
//                'barcode' => $product->barcode,
//                'hs_code' => $product->hs_code ?? null,
//                'line_quantity' => $quantity,
//                'line_price' => round($unitPrice, 4),
//                'line_subtotal' => round($lineCalc['subtotal'], 4),
//                'line_discount' => round($lineDiscount, 4),
//                'line_tax' => round($lineCalc['tax'], 4),
//                'line_total' => round($lineCalc['total'], 4),
//                'base_line_price' => round($baseUnitPrice, 4),
//                'base_line_subtotal' => round($baseLineCalc['subtotal'], 4),
//                'base_line_discount' => round($baseLineDiscount, 4),
//                'base_line_tax' => round($baseLineCalc['tax'], 4),
//                'base_line_total' => round($baseLineCalc['total'], 4),
//                'currency_code' => $saleCurrencyCode,
//                'base_currency_code' => $baseCurrencyCode,
//                'metadata' => [
//                    'price_currency' => $priceCurrency,
//                    'uom' => $product->uom?->name ?? null,
//                    'uom_code' => $product->uom?->code ?? null,
//                    'product_type' => $product->product_type ?? null,
//                    'parent_product_id' => $product->parent_product_id ?? null,
//                    'price_level_override' => $priceLevelOverride,
//                    'discount_payload' => $discountPayload,
//                    'discount_approval' => $discountApproval,
//                    'fast_food' => $fastFoodCustomisation,
//                    'base_menu_unit_price' => round($baseMenuUnitPrice, 4),
//                    'modifier_unit_price' => round($modifierUnitPrice, 4),
//                    'prep_messages' => $fastFoodCustomisation['prep_messages'] ?? [],
//                    'prep_note' => $fastFoodCustomisation['prep_note'] ?? null,
//                    'modifiers' => $fastFoodCustomisation['modifiers'] ?? [],
//                    'kitchen' => [
//                        'print_required' => (bool) ($fastFoodCustomisation['enabled'] ?? false),
//                        'prep_messages' => $fastFoodCustomisation['prep_messages'] ?? [],
//                        'prep_note' => $fastFoodCustomisation['prep_note'] ?? null,
//                        'modifiers' => $fastFoodCustomisation['modifiers'] ?? [],
//                    ],
//                    'tax_summary' => [
//                        'gross' => round($lineCalc['gross'], 4),
//                        'subtotal_excluding_tax' => round($lineCalc['subtotal'], 4),
//                        'inclusive_tax' => round($lineCalc['inclusive_tax'], 4),
//                        'exclusive_tax' => round($lineCalc['exclusive_tax'], 4),
//                        'tax' => round($lineCalc['tax'], 4),
//                        'total' => round($lineCalc['total'], 4),
//                    ],
//                ],
//            ]);
//
//            $this->applyFastFoodLineCustomisationToReceiptLine(
//                receiptLine: $receiptLine,
//                sale: $sale,
//                customisation: $fastFoodCustomisation
//            );
//
//            if ($discountPayload && (float)($discountPayload['discount_amount'] ?? 0) > 0) {
//                /** @var DiscountEngineService $discountEngine */
//                $discountEngine = app(DiscountEngineService::class);
//
//                $originalScope = $discountPayload['original_scope'] ?? $discountPayload['scope'] ?? 'line';
//                $saleScope = ($discountPayload['scope'] ?? 'line') === 'cart' ? 'cart' : 'line';
//
//                $discountEngine->recordSaleDiscount([
//                    'company_id' => $companyId,
//                    'branch_id' => $branchId,
//                    'sales_transaction_id' => $sale->id,
//                    'receipt_line_id' => $receiptLine->id,
//                    'discount_id' => $discountPayload['discount_id'] ?? null,
//                    'discount_type_id' => $discountPayload['discount_type_id'] ?? null,
//                    'discount_code' => $discountPayload['discount_code'] ?? null,
//                    'discount_name' => $discountPayload['discount_name'] ?? null,
//                    'scope' => $saleScope,
//                    'original_scope' => $originalScope,
//                    'discount_basis' => $discountPayload['discount_basis'] ?? 'percentage',
//                    'discount_value' => $discountPayload['discount_value'] ?? 0,
//                    'gross_amount' => $lineGrossAmount,
//                    'discount_amount' => $lineDiscount,
//                    'net_amount' => max(0, $lineGrossAmount - $lineDiscount),
//                    'currency' => $saleCurrencyCode,
//                    'cashier_id' => $cashierId,
//                    'approved_by' => $discountApproval['approved_by'] ?? null,
//                    'manager_approval_id' => $discountPayload['manager_approval_id'] ?? null,
//                    'reason' => $discountPayload['reason'] ?? null,
//                    'metadata' => [
//                        'approval' => $discountApproval,
//                        'payload' => $discountPayload,
//                        'original_scope' => $originalScope,
//                        'line_no' => $receiptLine->line_no,
//                        'product_id' => $product->id,
//                        'product_name' => $product->name,
//                    ],
//                ]);
//            }
//
//            foreach ($lineCalc['taxes'] as $taxRow) {
//                ReceiptLineTaxe::create([
//                    'id' => (string)Str::uuid(),
//                    'company_id' => $companyId,
//                    'branch_id' => $branchId,
//                    'sales_transaction_id' => $sale->id,
//                    'receipt_line_id' => $receiptLine->id,
//                    'tax_id' => $taxRow['tax_id'],
//                    'tax_code' => $taxRow['tax_code'],
//                    'tax_percent' => $taxRow['tax_percent'],
//                    'is_inclusive' => $taxRow['is_inclusive'],
//                    'obliged_to_zimra' => $taxRow['obliged_to_zimra'],
//                    'tax_amount' => round($taxRow['tax_amount'], 4),
//                    'sales_amount_with_tax' => round($taxRow['sales_amount_with_tax'], 4),
//                    'currency_code' => $saleCurrencyCode,
//                    'metadata' => [
//                        'fiscal_tax_id' => $taxRow['fiscal_tax_id'],
//                        'tax_name' => $taxRow['tax_name'],
//                        'tax_type' => $taxRow['tax_type'],
//                        'taxable_amount' => round($taxRow['taxable_amount'], 4),
//                        'inclusive_tax' => $taxRow['is_inclusive'],
//                    ],
//                ]);
//            }
//
////            if ((bool)$product->stock_tracking_enabled) {
////                $this->deductStock(
////                    product: $product,
////                    branchId: $branchId,
////                    quantity: $quantity,
////                    saleId: $sale->id
////                );
////            }
//
//            $this->deductSaleStockAccordingToMode(
//                product: $product,
//                branchId: $branchId,
//                quantity: $quantity,
//                saleId: $sale->id,
//                receiptLineId: $receiptLine->id,
//                shiftSessionId: $sale->shift_session_id,
//                companyId: $companyId
//            );
//
//            $this->deductFastFoodModifierRecipesForLine(
//                menuProduct: $product,
//                branchId: $branchId,
//                soldQuantity: $quantity,
//                saleId: $sale->id,
//                receiptLineId: $receiptLine->id,
//                shiftSessionId: $sale->shift_session_id,
//                customisation: $fastFoodCustomisation
//            );
//
//
//            $subtotal += $lineCalc['subtotal'];
//            $discount += $lineDiscount;
//            $tax += $lineCalc['tax'];
//            $total += $lineCalc['total'];
//
//            $baseSubtotal += $baseLineCalc['subtotal'];
//            $baseDiscount += $baseLineDiscount;
//            $baseTax += $baseLineCalc['tax'];
//            $baseTotal += $baseLineCalc['total'];
//        }
//
//        return [
//            'subtotal' => $subtotal,
//            'discount' => $discount,
//            'tax' => $tax,
//            'total' => $total,
//            'base_subtotal' => $baseSubtotal,
//            'base_discount' => $baseDiscount,
//            'base_tax' => $baseTax,
//            'base_total' => $baseTotal,
//        ];
//    }
//
//    private function calculateLine(
//        Product $product,
//        float   $unitPrice,
//        float   $quantity,
//        float   $discountAmount = 0
//    ): array
//    {
//        $grossBeforeDiscount = max($unitPrice * $quantity, 0);
//        $discountAmount = min(max($discountAmount, 0), $grossBeforeDiscount);
//        $gross = max($grossBeforeDiscount - $discountAmount, 0);
//
//        $productTaxes = $product->taxes ?? collect();
//
//        $inclusivePercentRate = 0.0;
//        $inclusiveFixedTotal = 0.0;
//
//        foreach ($productTaxes as $productTax) {
//            $tax = $productTax->tax;
//
//            if (!$tax || !(bool)($tax->is_active ?? true)) {
//                continue;
//            }
//
//            $taxType = $tax->type ?? 'percentage';
//            $taxRate = (float)($tax->rate ?? $tax->tax_rate ?? 0);
//
//            if ((bool)$productTax->is_inclusive) {
//                if ($taxType === 'fixed') {
//                    $inclusiveFixedTotal += $taxRate * $quantity;
//                } else {
//                    $inclusivePercentRate += $taxRate / 100;
//                }
//            }
//        }
//
//        /*
//         * Inclusive logic:
//         * If a product is inclusive, its selling price already contains those taxes.
//         * Example: price 115 with 15% inclusive tax -> base = 100, tax = 15, total = 115.
//         * Fixed inclusive tax is also already inside the price, so remove it before
//         * extracting percentage-inclusive tax.
//         */
//        $amountAfterInclusiveFixed = max($gross - $inclusiveFixedTotal, 0);
//
//        $taxableBase = $inclusivePercentRate > 0
//            ? $amountAfterInclusiveFixed / (1 + $inclusivePercentRate)
//            : $amountAfterInclusiveFixed;
//
//        $taxRows = [];
//        $inclusiveTaxTotal = 0.0;
//        $exclusiveTaxTotal = 0.0;
//
//        foreach ($productTaxes as $productTax) {
//            $tax = $productTax->tax;
//
//            if (!$tax || !(bool)($tax->is_active ?? true)) {
//                continue;
//            }
//
//            $taxType = $tax->type ?? 'percentage';
//            $taxRate = (float)($tax->rate ?? $tax->tax_rate ?? 0);
//            $isInclusive = (bool)$productTax->is_inclusive;
//
//            if ($taxType === 'fixed') {
//                $taxAmount = $taxRate * $quantity;
//                $taxPercent = 0;
//            } else {
//                $taxAmount = $taxableBase * ($taxRate / 100);
//                $taxPercent = $taxRate;
//            }
//
//            if ($isInclusive) {
//                $inclusiveTaxTotal += $taxAmount;
//                $salesAmountWithTax = $taxableBase + $inclusiveTaxTotal;
//            } else {
//                $exclusiveTaxTotal += $taxAmount;
//                $salesAmountWithTax = $taxableBase + $inclusiveTaxTotal + $taxAmount;
//            }
//
//            $taxRows[] = [
//                'tax_id' => $tax->id,
//                'tax_name' => $tax->name ?? null,
//                'tax_code' => $tax->tax_code ?? null,
//                'tax_type' => $taxType,
//                'tax_percent' => $taxPercent,
//                'taxable_amount' => $taxableBase,
//                'tax_amount' => $taxAmount,
//                'sales_amount_with_tax' => $salesAmountWithTax,
//                'is_inclusive' => $isInclusive,
//                'obliged_to_zimra' => (bool)($tax->obliged_to_zimra ?? false),
//                'fiscal_tax_id' => $tax->tax_id ?? null,
//            ];
//        }
//
//        $totalTax = $inclusiveTaxTotal + $exclusiveTaxTotal;
//        $lineTotal = $taxableBase + $inclusiveTaxTotal + $exclusiveTaxTotal;
//
//        return [
//            'gross' => $gross,
//            'subtotal' => $taxableBase,
//            'inclusive_tax' => $inclusiveTaxTotal,
//            'exclusive_tax' => $exclusiveTaxTotal,
//            'tax' => $totalTax,
//            'total' => $lineTotal,
//            'taxes' => $taxRows,
//        ];
//    }
//
//
//    private function resolveProductPrice(
//        Product $product,
//        ?string $productPriceId,
//        string  $companyId,
//        string  $branchId
//    )
//    {
//        $query = ProductPrice::where('company_id', $companyId)
//            ->where('product_id', $product->id)
//            ->where('is_active', true);
//
//        if ($productPriceId) {
//            $price = (clone $query)
//                ->where('id', $productPriceId)
//                ->first();
//
//            if (!$price) {
//                throw new \Exception("Selected price is not valid for {$product->name}.");
//            }
//
//            return $price;
//        }
//
//        $price = $query
//            ->with('priceLevel')
//            ->where(function ($sub) use ($branchId) {
//                $sub->whereNull('branch_id')
//                    ->orWhere('branch_id', $branchId);
//            })
//            ->orderByRaw(
//                "CASE
//                    WHEN EXISTS (
//                        SELECT 1 FROM price_levels
//                        WHERE price_levels.id = product_prices.price_level_id
//                        AND LOWER(price_levels.name) = 'standard'
//                    ) THEN 0 ELSE 1
//                END"
//            )
//            ->orderByRaw('CASE WHEN branch_id = ? THEN 0 ELSE 1 END', [$branchId])
//            ->orderByDesc('is_default')
//            ->orderBy('price')
//            ->first();
//
//        if (!$price) {
//            throw new \Exception("No active selling price found for {$product->name}.");
//        }
//
//        return $price;
//    }
//
//    private function validatePriceLevelOverrideForSaleLine(
//        array        $line,
//        ProductPrice $selectedPrice,
//        Product      $product,
//        string       $companyId,
//        ?string      $branchId,
//        string       $cashierId
//    ): ?array
//    {
//        $override = $line['price_level_override'] ?? null;
//
//        /*
//         * No override payload means normal sale using the selected product_price_id.
//         * If you want to block non-standard prices without override, enforce it here.
//         */
//        if (!$override) {
//            return null;
//        }
//
//        if (!$this->priceLevelOverrideSettingEnabled($companyId, $branchId, 'price_level_override_enabled', false)) {
//            throw new \Exception('Price level override is disabled in General Settings.');
//        }
//
//        $auditId = $override['audit_id'] ?? null;
//        $managerApprovalId = $override['manager_approval_id'] ?? null;
//        $overrideProductPriceId = $override['product_price_id'] ?? null;
//        $overridePriceLevelId = $override['price_level_id'] ?? null;
//
//        if (!$auditId || !$overrideProductPriceId || !$overridePriceLevelId) {
//            throw new \Exception('Invalid price level override payload.');
//        }
//
//        if ((string)$selectedPrice->id !== (string)$overrideProductPriceId) {
//            throw new \Exception('Selected product price does not match the approved price level override.');
//        }
//
//        if ((string)$selectedPrice->price_level_id !== (string)$overridePriceLevelId) {
//            throw new \Exception('Selected price level does not match the approved price level override.');
//        }
//
//        if ((string)$selectedPrice->product_id !== (string)$product->id) {
//            throw new \Exception('Selected override price does not belong to this product.');
//        }
//
//        $audit = DB::table('audit_trails')
//            ->where('id', $auditId)
//            ->where('company_id', $companyId)
//            ->where('module', 'sales')
//            ->where('action', 'price_level_override_applied')
//            ->where('status', 'success')
//            ->first();
//
//        if (!$audit) {
//            throw new \Exception('Price level override audit was not found or not successful.');
//        }
//
//        if ($branchId && $audit->branch_id && (string)$audit->branch_id !== (string)$branchId) {
//            throw new \Exception('Price level override audit belongs to another branch.');
//        }
//
//        $auditMetadata = $this->jsonToArray($audit->metadata ?? null);
//        $auditNewValues = $this->jsonToArray($audit->new_values ?? null);
//
//        if (($auditMetadata['product_id'] ?? null) && (string)$auditMetadata['product_id'] !== (string)$product->id) {
//            throw new \Exception('Price level override audit belongs to another product.');
//        }
//
//        if (($auditNewValues['price_level_id'] ?? null) && (string)$auditNewValues['price_level_id'] !== (string)$selectedPrice->price_level_id) {
//            throw new \Exception('Price level override audit belongs to another price level.');
//        }
//
//        if (($audit->auditable_id ?? null) && (string)$audit->auditable_id !== (string)$selectedPrice->id) {
//            throw new \Exception('Price level override audit belongs to another product price.');
//        }
//
//        $requiresManager = $this->priceLevelOverrideSettingEnabled(
//            companyId: $companyId,
//            branchId: $branchId,
//            settingKey: 'price_level_override_requires_manager_approval',
//            default: true
//        );
//
//        $cashierCanOverride = $this->priceLevelOverrideSettingEnabled(
//            companyId: $companyId,
//            branchId: $branchId,
//            settingKey: 'cashier_can_price_level_override_without_manager',
//            default: false
//        );
//
//        /*
//         * Determine which role must be eligible for the selected price level.
//         * - If manager approval is required: approving manager role is used.
//         * - If cashier override is allowed: cashier role is used.
//         */
//        $approvalRoleId = null;
//        $approvalUserId = null;
//        $approvalUserName = null;
//
//        if ($requiresManager && !$cashierCanOverride) {
//            if (!$managerApprovalId) {
//                throw new \Exception('Manager approval is required for price level override.');
//            }
//
//            $managerApproval = DB::table('audit_trails')
//                ->where('id', $managerApprovalId)
//                ->where('company_id', $companyId)
//                ->where('module', 'manager-approval')
//                ->where('action', 'manager_approval_price_level_override')
//                ->where('status', 'success')
//                ->first();
//
//            /*
//             * Backward compatibility:
//             * Some older manager approval controllers may save the action as
//             * manager_approval_price_override.
//             */
//            if (!$managerApproval) {
//                $managerApproval = DB::table('audit_trails')
//                    ->where('id', $managerApprovalId)
//                    ->where('company_id', $companyId)
//                    ->where('module', 'manager-approval')
//                    ->where('action', 'manager_approval_price_override')
//                    ->where('status', 'success')
//                    ->first();
//            }
//
//            if (!$managerApproval) {
//                throw new \Exception('Invalid manager approval for price level override.');
//            }
//
//            if ($branchId && $managerApproval->branch_id && (string)$managerApproval->branch_id !== (string)$branchId) {
//                throw new \Exception('Manager approval belongs to another branch.');
//            }
//
//            $managerApprovalMetadata = $this->jsonToArray($managerApproval->metadata ?? null);
//            $managerApprovalNewValues = $this->jsonToArray($managerApproval->new_values ?? null);
//
//            $requiredPermission = $managerApprovalMetadata['required_permission']
//                ?? $managerApprovalNewValues['required_permission']
//                ?? null;
//
//            if ($requiredPermission !== 'approve_price_override') {
//                throw new \Exception('Manager approval does not have approve_price_override permission.');
//            }
//
//            $manager = PosUser::query()
//                ->where('company_id', $companyId)
//                ->where('id', $managerApproval->user_id)
//                ->where('is_active', true)
//                ->where('can_login_pos', true)
//                ->whereNull('deleted_at')
//                ->first();
//
//            if (!$manager) {
//                throw new \Exception('Approving manager is no longer active.');
//            }
//
//            $approvalRoleId = $manager->role_id;
//            $approvalUserId = $manager->id;
//            $approvalUserName = $manager->name ?? $manager->username;
//        } else {
//            $cashier = PosUser::query()
//                ->where('company_id', $companyId)
//                ->where('id', $cashierId)
//                ->first();
//
//            if (!$cashier) {
//                throw new \Exception('Cashier not found for price level override validation.');
//            }
//
//            $permission = hasPermission($cashier->role_id, 'price_level_override', $companyId);
//
//            if ($permission !== true) {
//                throw new \Exception('Cashier role is not allowed to apply price level override.');
//            }
//
//            $approvalRoleId = $cashier->role_id;
//            $approvalUserId = $cashier->id;
//            $approvalUserName = $cashier->name ?? $cashier->username;
//        }
//
//        $roleCanUsePriceLevel = DB::table('role_price_levels')
//            ->where('company_id', $companyId)
//            ->where('role_id', $approvalRoleId)
//            ->where('price_level_id', $selectedPrice->price_level_id)
//            ->exists();
//
//        if (!$roleCanUsePriceLevel) {
//            throw new \Exception('Approving role is not eligible for the selected price level.');
//        }
//
//        return [
//            'enabled' => true,
//            'audit_id' => $auditId,
//            'manager_approval_id' => $managerApprovalId,
//            'approved_by_user_id' => $approvalUserId,
//            'approved_by_name' => $approvalUserName,
//            'approved_by_role_id' => $approvalRoleId,
//            'product_price_id' => $selectedPrice->id,
//            'price_level_id' => $selectedPrice->price_level_id,
//            'price' => (float)$selectedPrice->price,
//            'currency' => $selectedPrice->currency,
//        ];
//    }
//
//    private function priceLevelOverrideSettingEnabled(
//        string  $companyId,
//        ?string $branchId,
//        string  $settingKey,
//        bool    $default = false
//    ): bool
//    {
//        $setting = GeneralSetting::query()
//            ->where('company_id', $companyId)
//            ->where('setting_key', $settingKey)
//            ->where('is_active', true)
//            ->where(function ($query) use ($branchId) {
//                $query->whereNull('branch_id');
//
//                if ($branchId) {
//                    $query->orWhere('branch_id', $branchId);
//                }
//            })
//            ->orderByRaw('CASE WHEN branch_id IS NULL THEN 1 ELSE 0 END')
//            ->first();
//
//        return $setting
//            ? filter_var($setting->setting_value, FILTER_VALIDATE_BOOLEAN)
//            : $default;
//    }
//
//    private function jsonToArray($value): array
//    {
//        if (is_array($value)) {
//            return $value;
//        }
//
//        if (is_object($value)) {
//            return json_decode(json_encode($value), true) ?: [];
//        }
//
//        if (is_string($value) && $value !== '') {
//            return json_decode($value, true) ?: [];
//        }
//
//        return [];
//    }
//
//
//    private function fiscaliseSaleAfterCommit(SalesTransaction $sale): array
//    {
//        $setting = ZimraCompanySetting::where('company_id', $sale->company_id)->first();
//
//        if (!$setting || !(bool)$setting->enabled) {
//            $this->updateSaleFiscalState(
//                sale: $sale,
//                values: [
//                    'is_fiscalised' => false,
//                    'fiscal_status' => 'skipped',
//                    'fiscal_error' => null,
//                ],
//                metadata: [
//                    'fiscalised' => false,
//                    'fiscalisation_status' => 'skipped',
//                    'fiscalisation_message' => 'ZIMRA fiscalisation is disabled for this company.',
//                ]
//            );
//
//            return [
//                'status' => 'skipped',
//                'blocked' => false,
//                'enabled' => false,
//                'message' => 'ZIMRA fiscalisation is disabled for this company.',
//                'sale' => $sale->fresh(),
//            ];
//        }
//
//        try {
//            /*
//             * ZimraReceiptService must:
//             * - find active fiscal device for this branch
//             * - ensure there is an open fiscal day
//             * - build the receipt payload from receiptLines.taxes
//             * - submit to FDMS
//             * - update sales_transactions fiscal fields
//             * - create/update zimra_receipts
//             */
//            $fiscalisedSale = $this->zimraReceiptService->submitSale($sale->id);
//
//            $sale = $fiscalisedSale instanceof SalesTransaction
//                ? $fiscalisedSale
//                : $sale->fresh();
//
//            $sale = $this->hydrateSaleFiscalFieldsFromZimraReceipt($sale);
//
//            $this->mergeSaleMetadata($sale, [
//                'fiscalised' => true,
//                'fiscalisation_status' => 'success',
//                'fiscalisation_message' => 'Receipt submitted to ZIMRA successfully.',
//                'qr_code_data' => $sale->qr_code_data ?? null,
//                'qr_url' => $sale->qr_url ?? null,
//                'receipt_global_no' => $sale->receipt_global_no ?? null,
//            ]);
//
//            return [
//                'status' => 'success',
//                'blocked' => false,
//                'enabled' => true,
//                'message' => 'Receipt submitted to ZIMRA successfully.',
//                'fdms_receipt_id' => $sale->fdms_receipt_id ?? null,
//                'receipt_global_no' => $sale->receipt_global_no ?? null,
//                'receipt_counter' => $sale->receipt_counter ?? null,
//                'qr_url' => $sale->qr_url ?? null,
//                'qr_code_data' => $sale->qr_code_data ?? null,
//                'zimra_signature_hash' => $sale->zimra_signature_hash ?? null,
//                'sale' => $sale->fresh(),
//            ];
//        } catch (\Throwable $e) {
//            $this->updateSaleFiscalState(
//                sale: $sale,
//                values: [
//                    'is_fiscalised' => false,
//                    'fiscal_status' => 'failed',
//                    'fiscal_error' => $e->getMessage(),
//                ],
//                metadata: [
//                    'fiscalised' => false,
//                    'fiscalisation_status' => 'failed',
//                    'fiscalisation_error' => $e->getMessage(),
//                ]
//            );
//
//            return [
//                'status' => 'failed',
//                'blocked' => (bool)$setting->block_sale_if_fiscalisation_fails,
//                'enabled' => true,
//                'error' => $e->getMessage(),
//                'sale' => $sale->fresh(),
//            ];
//        }
//    }
//
//
//    private function fiscaliseCancellationAfterCommit(
//        SalesTransaction $cancelTransaction,
//        SalesTransaction $originalSale,
//        ?string          $reason = null
//    ): array
//    {
//        $setting = ZimraCompanySetting::where('company_id', $cancelTransaction->company_id)->first();
//
//        if (!$setting || !(bool)$setting->enabled) {
//            $this->updateSaleFiscalState(
//                sale: $cancelTransaction,
//                values: [
//                    'is_fiscalised' => false,
//                    'fiscal_status' => 'skipped',
//                    'fiscal_error' => null,
//                ],
//                metadata: [
//                    'fiscalised' => false,
//                    'fiscalisation_status' => 'skipped',
//                    'fiscalisation_message' => 'ZIMRA fiscalisation is disabled for this company.',
//                    'fdms_receipt_type' => 'CreditNote',
//                    'original_sale_id' => $originalSale->id,
//                    'original_invoice_number' => $originalSale->invoice_number,
//                ]
//            );
//
//            return [
//                'status' => 'skipped',
//                'blocked' => false,
//                'enabled' => false,
//                'receipt_type' => 'CreditNote',
//                'message' => 'ZIMRA fiscalisation is disabled for this company.',
//                'sale' => $cancelTransaction->fresh(),
//            ];
//        }
//
//        try {
//            /*
//             |--------------------------------------------------------------------------
//             | CreditNote submission
//             |--------------------------------------------------------------------------
//             | ZimraReceiptService::submitCreditNote() must:
//             | - find the original submitted zimra_receipts row
//             | - add receiptNotes
//             | - add creditDebitNote reference
//             | - send negative receiptTotal and negative paymentAmount
//             | - send only taxes used on the original fiscal invoice
//             | - update fiscal counters after FDMS accepts the CreditNote
//             */
//            if (!method_exists($this->zimraReceiptService, 'submitCreditNote')) {
//                throw new \Exception('ZimraReceiptService::submitCreditNote() is missing. Replace ZimraReceiptService with the FDMS CreditNote version.');
//            }
//
//            $fiscalisedSale = $this->zimraReceiptService->submitCreditNote(
//                cancelSaleId: $cancelTransaction->id,
//                originalSaleId: $originalSale->id,
//                reason: $reason ?: ('Cancellation of ' . $originalSale->invoice_number)
//            );
//
//            $sale = $fiscalisedSale instanceof SalesTransaction
//                ? $fiscalisedSale
//                : $cancelTransaction->fresh();
//
//            $sale = $this->hydrateSaleFiscalFieldsFromZimraReceipt($sale);
//
//            $this->mergeSaleMetadata($sale, [
//                'fiscalised' => true,
//                'fiscalisation_status' => 'success',
//                'fiscalisation_message' => 'CreditNote submitted to ZIMRA successfully.',
//                'fdms_receipt_type' => 'CreditNote',
//                'original_sale_id' => $originalSale->id,
//                'original_invoice_number' => $originalSale->invoice_number,
//                'qr_code_data' => $sale->qr_code_data ?? null,
//                'qr_url' => $sale->qr_url ?? null,
//                'receipt_global_no' => $sale->receipt_global_no ?? null,
//            ]);
//
//            return [
//                'status' => 'success',
//                'blocked' => false,
//                'enabled' => true,
//                'receipt_type' => 'CreditNote',
//                'message' => 'CreditNote submitted to ZIMRA successfully.',
//                'fdms_receipt_id' => $sale->fdms_receipt_id ?? null,
//                'receipt_global_no' => $sale->receipt_global_no ?? null,
//                'receipt_counter' => $sale->receipt_counter ?? null,
//                'qr_url' => $sale->qr_url ?? null,
//                'qr_code_data' => $sale->qr_code_data ?? null,
//                'zimra_signature_hash' => $sale->zimra_signature_hash ?? null,
//                'sale' => $sale->fresh(),
//            ];
//        } catch (\Throwable $e) {
//            $this->updateSaleFiscalState(
//                sale: $cancelTransaction,
//                values: [
//                    'is_fiscalised' => false,
//                    'fiscal_status' => 'failed',
//                    'fiscal_error' => $e->getMessage(),
//                ],
//                metadata: [
//                    'fiscalised' => false,
//                    'fiscalisation_status' => 'failed',
//                    'fiscalisation_error' => $e->getMessage(),
//                    'fdms_receipt_type' => 'CreditNote',
//                    'original_sale_id' => $originalSale->id,
//                    'original_invoice_number' => $originalSale->invoice_number,
//                ]
//            );
//
//            return [
//                'status' => 'failed',
//                'blocked' => (bool)$setting->block_sale_if_fiscalisation_fails,
//                'enabled' => true,
//                'receipt_type' => 'CreditNote',
//                'error' => $e->getMessage(),
//                'sale' => $cancelTransaction->fresh(),
//            ];
//        }
//    }
//
//    private function updateSaleFiscalState(
//        SalesTransaction $sale,
//        array            $values = [],
//        array            $metadata = []
//    ): void
//    {
//        $updates = [];
//
//        foreach ($values as $column => $value) {
//            if (Schema::hasColumn('sales_transactions', $column)) {
//                $updates[$column] = $value;
//            }
//        }
//
//        if (!empty($updates)) {
//            $sale->update($updates);
//        }
//
//        if (!empty($metadata)) {
//            $this->mergeSaleMetadata($sale->fresh(), $metadata);
//        }
//    }
//
//    private function mergeSaleMetadata(SalesTransaction $sale, array $metadata): void
//    {
//        if (!Schema::hasColumn('sales_transactions', 'metadata')) {
//            return;
//        }
//
//        $current = $sale->metadata;
//
//        if (!is_array($current)) {
//            $current = json_decode($current ?? '[]', true) ?: [];
//        }
//
//        $sale->metadata = array_merge($current, $metadata);
//        $sale->save();
//    }
//
//
//    private function hydrateSaleFiscalFieldsFromZimraReceipt(SalesTransaction $sale): SalesTransaction
//    {
//        /*
//         |--------------------------------------------------------------------------
//         | QR / fiscal field repair
//         |--------------------------------------------------------------------------
//         | ZIMRAReceiptService creates the local signed FDMS receipt before the HTTP
//         | SubmitReceipt response. That record already has qr_string, qr_url,
//         | receipt_global_no, receipt_counter and receipt_hash. If the sale row was
//         | reloaded before those values reached sales_transactions, the receipt
//         | renderer can say "Invoice is not yet received". This method repairs the
//         | sale row from zimra_receipts before preparing the PDF.
//         */
//        $zimraReceipt = ZimraReceipt::where('sales_transaction_id', $sale->id)
//            ->orderByDesc('created_at')
//            ->first();
//
//        if (!$zimraReceipt) {
//            return $sale->fresh() ?: $sale;
//        }
//
//        $updates = [];
//
//        $map = [
//            'fiscal_device_id' => $zimraReceipt->fiscal_device_id ?? null,
//            'fiscal_day_id' => $zimraReceipt->fiscal_day_id ?? null,
//            'receipt_counter' => $zimraReceipt->receipt_counter ?? null,
//            'receipt_global_no' => $zimraReceipt->receipt_global_no ?? null,
//            'fdms_receipt_id' => $zimraReceipt->fdms_receipt_id ?? null,
//            'qr_url' => $zimraReceipt->qr_url ?? null,
//            'qr_code_data' => $zimraReceipt->qr_string ?: ($zimraReceipt->qr_code_data ?? null),
//            'zimra_signature_hash' => $zimraReceipt->receipt_hash ?? null,
//            'fiscal_response' => $zimraReceipt->response ?? null,
//            'fiscalised_at' => $zimraReceipt->submitted_at ?? null,
//        ];
//
//        foreach ($map as $column => $value) {
//            if ($value !== null && Schema::hasColumn('sales_transactions', $column)) {
//                $updates[$column] = $value;
//            }
//        }
//
//        if (Schema::hasColumn('sales_transactions', 'is_fiscalised')) {
//            $updates['is_fiscalised'] = $zimraReceipt->status === 'submitted';
//        }
//
//        if (Schema::hasColumn('sales_transactions', 'zimra_submitted')) {
//            $updates['zimra_submitted'] = $zimraReceipt->status === 'submitted';
//        }
//
//        if (Schema::hasColumn('sales_transactions', 'zimra_submitted_at') && $zimraReceipt->submitted_at) {
//            $updates['zimra_submitted_at'] = $zimraReceipt->submitted_at;
//        }
//
//        if (Schema::hasColumn('sales_transactions', 'fiscal_status')) {
//            $updates['fiscal_status'] = match ($zimraReceipt->status) {
//                'submitted' => 'success',
//                'failed' => 'failed',
//                default => 'pending',
//            };
//        }
//
//        if (Schema::hasColumn('sales_transactions', 'fiscal_error')) {
//            $updates['fiscal_error'] = $zimraReceipt->error;
//        }
//
//        if (!empty($updates)) {
//            $sale->update($updates);
//        }
//
//        $freshSale = $sale->fresh();
//
//        if ($freshSale) {
//            $this->mergeSaleMetadata($freshSale, [
//                'fiscalisation_status' => $freshSale->fiscal_status ?? null,
//                'fdms_receipt_id' => $freshSale->fdms_receipt_id ?? null,
//                'receipt_global_no' => $freshSale->receipt_global_no ?? null,
//                'receipt_counter' => $freshSale->receipt_counter ?? null,
//                'qr_url' => $freshSale->qr_url ?? null,
//                'qr_code_data' => $freshSale->qr_code_data ?? null,
//            ]);
//
//            return $freshSale->fresh() ?: $freshSale;
//        }
//
//        return $sale;
//    }
//
//    private function prepareReceiptAfterFiscalisation(SalesTransaction $sale): array
//    {
//        /*
//         * Always hydrate from zimra_receipts first, then prepare the PDF.
//         * This fixes the "Invoice is not yet received" case where the local
//         * ZIMRA receipt already has QR data but the sales_transactions row does not.
//         */
//        $sale = $this->hydrateSaleFiscalFieldsFromZimraReceipt($sale);
//
//        $saleForReceipt = SalesTransaction::with([
//            'company',
//            'branch',
//            'till',
//            'receiptLines.taxes.tax',
//        ])->where('id', $sale->id)->firstOrFail();
//
//        try {
//            return $this->receiptService->prepareReceipt($saleForReceipt);
//        } catch (\Throwable $e) {
//            /*
//             * One more repair attempt in case fiscalisation completed between the
//             * first hydrate and the receipt renderer.
//             */
//            $saleForReceipt = $this->hydrateSaleFiscalFieldsFromZimraReceipt($saleForReceipt);
//
//            $saleForReceipt = SalesTransaction::with([
//                'company',
//                'branch',
//                'till',
//                'receiptLines.taxes.tax',
//            ])->where('id', $saleForReceipt->id)->firstOrFail();
//
//            return $this->receiptService->prepareReceipt($saleForReceipt);
//        }
//    }
//
//
//    private function buildSaleCreatedResponse(
//        SalesTransaction $sale,
//        string           $receiptNumber,
//        string           $saleCurrencyCode,
//        string           $baseCurrencyCode,
//        array            $totals,
//        float            $amountTendered,
//        float            $change,
//                         $till,
//                         $shift,
//        ?array           $fiscalisation = null
//    )
//    {
//        $saleForReceipt = SalesTransaction::with([
//            'company',
//            'branch',
//            'till',
//            'receiptLines.taxes.tax',
//        ])
//            ->where('id', $sale->id)
//            ->firstOrFail();
//
//        $saleForReceipt = $this->hydrateSaleFiscalFieldsFromZimraReceipt($saleForReceipt);
//
//        $receipt = $this->prepareReceiptAfterFiscalisation($saleForReceipt);
//
//        return response()->json([
//            'message' => 'Sale created successfully.',
//            'data' => [
//                'sale' => $saleForReceipt,
//
//                'receipt_number' => $receiptNumber,
//                'receipt_url' => $receipt['receipt_url'],
//
//                'currency_code' => $saleCurrencyCode,
//                'base_currency_code' => $baseCurrencyCode,
//
//                'subtotal' => round($totals['subtotal'], 4),
//                'discount' => round($totals['discount'], 4),
//                'tax' => round($totals['tax'], 4),
//                'total' => round($totals['total'], 4),
//
//                'amount_tendered' => round($amountTendered, 4),
//                'change' => round($change, 4),
//
//                'receipt_settings' => $receipt['receipt_settings'],
//
//                'order_number' => $this->fastFoodSaleResponseExtras($saleForReceipt)['order_number'] ?? null,
//                'fast_food' => $this->fastFoodSaleResponseExtras($saleForReceipt),
//                'kitchen_print' => $this->fastFoodSaleResponseExtras($saleForReceipt)['kitchen_print'] ?? null,
//
//                'fiscalisation' => $fiscalisation ?: [
//                    'status' => $saleForReceipt->fiscal_status ?? data_get($saleForReceipt->metadata, 'fiscalisation_status'),
//                    'enabled' => null,
//                    'blocked' => false,
//                    'message' => data_get($saleForReceipt->metadata, 'fiscalisation_message'),
//                    'error' => $saleForReceipt->fiscal_error ?? data_get($saleForReceipt->metadata, 'fiscalisation_error'),
//                    'is_fiscalised' => (bool)($saleForReceipt->is_fiscalised ?? false),
//                    'fdms_receipt_id' => $saleForReceipt->fdms_receipt_id ?? null,
//                    'receipt_global_no' => $saleForReceipt->receipt_global_no ?? null,
//                    'receipt_counter' => $saleForReceipt->receipt_counter ?? null,
//                    'qr_url' => $saleForReceipt->qr_url ?? null,
//                    'qr_code_data' => $saleForReceipt->qr_code_data ?? null,
//                    'zimra_signature_hash' => $saleForReceipt->zimra_signature_hash ?? null,
//                ],
//
//                'print' => [
//                    'auto_print' => $receipt['auto_print'],
//                    'printer' => $receipt['printer'],
//                ],
//
//                'till' => [
//                    'id' => $till->id,
//                    'till_code' => $till->till_code ?? null,
//                    'till_name' => $till->till_name ?? null,
//                ],
//
//                'shift' => [
//                    'id' => $shift->shift_configuration_id ?? null,
//                    'session_id' => $shift->id ?? null,
//                ],
//            ],
//        ], 201);
//    }
//
//
////    private function deductStock(
////        Product $product,
////        string  $branchId,
////        float   $quantity,
////        string  $saleId
////    ): void
////    {
////        $stock = ProductStock::where('company_id', $product->company_id)
////            ->where('branch_id', $branchId)
////            ->where('product_id', $product->id)
////            ->lockForUpdate()
////            ->first();
////
////        if (!$stock) {
////            $stock = ProductStock::create([
////                'id' => (string)Str::uuid(),
////                'company_id' => $product->company_id,
////                'branch_id' => $branchId,
////                'product_id' => $product->id,
////                'quantity_on_hand' => 0,
////                'reserved_quantity' => 0,
////                'available_quantity' => 0,
////            ]);
////
////            $stock = ProductStock::where('id', $stock->id)
////                ->lockForUpdate()
////                ->first();
////        }
////
////        if ($this->shouldAutoBreakBeforeSale($product, $stock, $quantity)) {
////            $this->autoBreakChildForSale(
////                child: $product,
////                branchId: $branchId,
////                requiredSaleQuantity: $quantity,
////                currentChildAvailable: (float)$stock->available_quantity,
////                saleId: $saleId
////            );
////
////            $stock = ProductStock::where('company_id', $product->company_id)
////                ->where('branch_id', $branchId)
////                ->where('product_id', $product->id)
////                ->lockForUpdate()
////                ->first();
////        }
////
////        if (!$product->allow_negative_stock && (float)$stock->available_quantity < $quantity) {
////            throw new \Exception("Insufficient stock for {$product->name}.");
////        }
////
////        $stock->quantity_on_hand = (float)$stock->quantity_on_hand - $quantity;
////        $stock->available_quantity = (float)$stock->available_quantity - $quantity;
////        $stock->save();
////
////        DB::table('stock_movements')->insert([
////            'id' => (string)Str::uuid(),
////            'company_id' => $product->company_id,
////            'branch_id' => $branchId,
////            'product_id' => $product->id,
////            'movement_type' => 'sale',
////            'quantity_in' => 0,
////            'quantity_out' => $quantity,
////            'unit_cost' => 0,
////            'total_cost' => 0,
////            'reference_type' => 'sale',
////            'reference_id' => $saleId,
////            'notes' => 'Sale stock deduction',
////            'created_by' => auth()->id(),
////            'created_at' => now(),
////            'updated_at' => now(),
////        ]);
////    }
////    private function deductStock(
////        Product $product,
////        string  $branchId,
////        float   $quantity,
////        string  $saleId
////    ): void {
////        $stock = ProductStock::where('company_id', $product->company_id)
////            ->where('branch_id', $branchId)
////            ->where('product_id', $product->id)
////            ->lockForUpdate()
////            ->first();
////
////        if (!$stock) {
////            $stock = ProductStock::create([
////                'id' => (string) Str::uuid(),
////                'company_id' => $product->company_id,
////                'branch_id' => $branchId,
////                'product_id' => $product->id,
////                'quantity_on_hand' => 0,
////                'reserved_quantity' => 0,
////                'available_quantity' => 0,
////            ]);
////
////            $stock = ProductStock::where('id', $stock->id)
////                ->lockForUpdate()
////                ->first();
////        }
////
////        /*
////         |--------------------------------------------------------------------------
////         | Auto break parent product before sale
////         |--------------------------------------------------------------------------
////         | This keeps your existing parent/child stock behaviour. If the child stock
////         | is not enough, Salexa can automatically break parent stock into child units
////         | before final sale deduction.
////         */
////        if ($this->shouldAutoBreakBeforeSale($product, $stock, $quantity)) {
////            $this->autoBreakChildForSale(
////                child: $product,
////                branchId: $branchId,
////                requiredSaleQuantity: $quantity,
////                currentChildAvailable: (float) $stock->available_quantity,
////                saleId: $saleId
////            );
////
////            $stock = ProductStock::where('company_id', $product->company_id)
////                ->where('branch_id', $branchId)
////                ->where('product_id', $product->id)
////                ->lockForUpdate()
////                ->first();
////
////            if (!$stock) {
////                throw new \Exception("Stock record could not be resolved after auto-break for {$product->name}.");
////            }
////        }
////
////        if (!$product->allow_negative_stock && (float) $stock->available_quantity < $quantity) {
////            throw new \Exception("Insufficient stock for {$product->name}.");
////        }
////
////        /*
////         |--------------------------------------------------------------------------
////         | Resolve product cost for COGS
////         |--------------------------------------------------------------------------
////         | Previously this was saved as zero:
////         | unit_cost = 0
////         | total_cost = 0
////         |
////         | That makes sales revenue visible but hides real gross profit.
////         | Now the sale movement carries the current weighted average cost.
////         */
////        $cost = ProductCost::where('company_id', $product->company_id)
////            ->where('branch_id', $branchId)
////            ->where('product_id', $product->id)
////            ->lockForUpdate()
////            ->first();
////
////        $unitCost = (float) (
////        $cost?->average_cost
////            ?: $cost?->last_cost
////            ?: 0
////        );
////
////        $currency = $cost?->currency ?? null;
////        $totalCost = round($unitCost * $quantity, 4);
////
////        $previousQuantityOnHand = (float) $stock->quantity_on_hand;
////        $previousAvailableQuantity = (float) $stock->available_quantity;
////
////        $stock->quantity_on_hand = $previousQuantityOnHand - $quantity;
////        $stock->available_quantity = $previousAvailableQuantity - $quantity;
////        $stock->save();
////
////        /*
////         |--------------------------------------------------------------------------
////         | Optional: keep ProductCost quantity/value aligned with stock leaving
////         |--------------------------------------------------------------------------
////         | If your ProductCost.total_quantity and total_value are used as inventory
////         | valuation totals, reduce them when selling stock.
////         |
////         | This is usually correct for weighted average inventory:
////         | sale reduces quantity and value at current average cost.
////         */
////        if ($cost) {
////            $newCostQuantity = max(0, (float) $cost->total_quantity - $quantity);
////            $newCostValue = max(0, (float) $cost->total_value - $totalCost);
////
////            $cost->total_quantity = $newCostQuantity;
////            $cost->total_value = $newCostValue;
////
////            /*
////             * Keep average cost stable when remaining quantity exists.
////             * If stock is exhausted, keep last_cost and reset average_cost to 0.
////             */
////            $cost->average_cost = $newCostQuantity > 0
////                ? $newCostValue / $newCostQuantity
////                : 0;
////
////            $cost->save();
////        }
////
////        DB::table('stock_movements')->insert([
////            'id' => (string) Str::uuid(),
////
////            'company_id' => $product->company_id,
////            'branch_id' => $branchId,
////
////            /*
////             * These columns exist in some of your other stock movement inserts.
////             * If your stock_movements table does not have them, remove them here.
////             */
////            'till_id' => app('till')?->id ?? null,
////            'shift_session_id' => null,
////
////            'product_id' => $product->id,
////
////            'movement_type' => 'sale',
////
////            'quantity_in' => 0,
////            'quantity_out' => $quantity,
////            'balance_after' => $stock->quantity_on_hand,
////
////            'unit_cost' => round($unitCost, 6),
////            'total_cost' => $totalCost,
////
////            'currency' => $currency,
////            'currency_code' => $currency,
////
////            'reference_type' => 'sale',
////            'reference_id' => $saleId,
////
////            'notes' => 'Sale stock deduction with COGS.',
////
////            'metadata' => json_encode([
////                'source' => 'sales',
////                'sale_id' => $saleId,
////                'product_id' => $product->id,
////                'product_name' => $product->name,
////                'product_code' => $product->code,
////                'sku' => $product->sku,
////                'barcode' => $product->barcode,
////
////                'previous_quantity_on_hand' => $previousQuantityOnHand,
////                'previous_available_quantity' => $previousAvailableQuantity,
////
////                'quantity_sold' => $quantity,
////                'balance_after' => $stock->quantity_on_hand,
////                'available_after' => $stock->available_quantity,
////
////                'unit_cost' => round($unitCost, 6),
////                'total_cost' => $totalCost,
////                'currency' => $currency,
////
////                'costing_method' => 'weighted_average',
////                'product_cost_id' => $cost?->id,
////            ]),
////
////            'created_by' => auth()->id(),
////            'created_at' => now(),
////            'updated_at' => now(),
////        ]);
////    }
//
//    private function deductStock(
//        Product $product,
//        string  $branchId,
//        float   $quantity,
//        string  $saleId,
//        ?string $shiftSessionId = null
//    ): void
//    {
//        $till = null;
//
//        try {
//            $till = app('till');
//        } catch (\Throwable $e) {
//            $till = null;
//        }
//
//        $stock = ProductStock::where('company_id', $product->company_id)
//            ->where('branch_id', $branchId)
//            ->where('product_id', $product->id)
//            ->lockForUpdate()
//            ->first();
//
//        if (!$stock) {
//            $stock = ProductStock::create([
//                'id' => (string)Str::uuid(),
//                'company_id' => $product->company_id,
//                'branch_id' => $branchId,
//                'product_id' => $product->id,
//                'quantity_on_hand' => 0,
//                'reserved_quantity' => 0,
//                'available_quantity' => 0,
//            ]);
//
//            $stock = ProductStock::where('id', $stock->id)
//                ->lockForUpdate()
//                ->first();
//        }
//
//        if (!$stock) {
//            throw new \Exception("Stock record could not be created for {$product->name}.");
//        }
//
//        /*
//         |--------------------------------------------------------------------------
//         | Auto break child product before sale
//         |--------------------------------------------------------------------------
//         | If this is a child product and stock is not enough, Salexa tries to break
//         | parent stock into child stock before deducting the sold quantity.
//         */
//        if ($this->shouldAutoBreakBeforeSale($product, $stock, $quantity)) {
//            $this->autoBreakChildForSale(
//                child: $product,
//                branchId: $branchId,
//                requiredSaleQuantity: $quantity,
//                currentChildAvailable: (float)$stock->available_quantity,
//                saleId: $saleId
//            );
//
//            $stock = ProductStock::where('company_id', $product->company_id)
//                ->where('branch_id', $branchId)
//                ->where('product_id', $product->id)
//                ->lockForUpdate()
//                ->first();
//
//            if (!$stock) {
//                throw new \Exception("Stock record could not be resolved after auto-break for {$product->name}.");
//            }
//        }
//
//        if (!$product->allow_negative_stock && (float)$stock->available_quantity < $quantity) {
//            throw new \Exception("Insufficient stock for {$product->name}.");
//        }
//
//        /*
//         |--------------------------------------------------------------------------
//         | Resolve weighted average cost for COGS
//         |--------------------------------------------------------------------------
//         */
//        $cost = ProductCost::where('company_id', $product->company_id)
//            ->where('branch_id', $branchId)
//            ->where('product_id', $product->id)
//            ->lockForUpdate()
//            ->first();
//
//        $unitCost = (float)(
//        $cost?->average_cost
//            ?: $cost?->last_cost
//            ?: 0
//        );
//
//        $currency = $cost?->currency ?? null;
//        $totalCost = round($unitCost * $quantity, 4);
//
//        $previousQuantityOnHand = (float)$stock->quantity_on_hand;
//        $previousAvailableQuantity = (float)$stock->available_quantity;
//        $previousReservedQuantity = (float)$stock->reserved_quantity;
//
//        $oldCostQuantity = $cost ? (float)$cost->total_quantity : null;
//        $oldCostValue = $cost ? (float)$cost->total_value : null;
//        $oldAverageCost = $cost ? (float)$cost->average_cost : null;
//        $oldLastCost = $cost ? (float)$cost->last_cost : null;
//
//        /*
//         |--------------------------------------------------------------------------
//         | Deduct physical stock
//         |--------------------------------------------------------------------------
//         */
//        $stock->quantity_on_hand = $previousQuantityOnHand - $quantity;
//        $stock->available_quantity = $previousAvailableQuantity - $quantity;
//        $stock->save();
//
//        /*
//         |--------------------------------------------------------------------------
//         | Reduce inventory valuation
//         |--------------------------------------------------------------------------
//         | This keeps ProductCost.total_quantity and ProductCost.total_value aligned
//         | with stock leaving through sales.
//         */
//        $newCostQuantity = null;
//        $newCostValue = null;
//        $newAverageCost = null;
//
//        if ($cost) {
//            $newCostQuantity = max(0, $oldCostQuantity - $quantity);
//            $newCostValue = max(0, $oldCostValue - $totalCost);
//            $newAverageCost = $newCostQuantity > 0
//                ? $newCostValue / $newCostQuantity
//                : 0;
//
//            $cost->total_quantity = $newCostQuantity;
//            $cost->total_value = $newCostValue;
//            $cost->average_cost = $newAverageCost;
//            $cost->last_cost = $oldLastCost ?? $unitCost;
//            $cost->save();
//        }
//
//        /*
//         |--------------------------------------------------------------------------
//         | Record stock movement with COGS
//         |--------------------------------------------------------------------------
//         */
//        DB::table('stock_movements')->insert([
//            'id' => (string)Str::uuid(),
//
//            'company_id' => $product->company_id,
//            'branch_id' => $branchId,
//            'till_id' => $till?->id,
//            'shift_session_id' => $shiftSessionId,
//
//            'product_id' => $product->id,
//            'movement_type' => 'sale',
//
//            'quantity_in' => 0,
//            'quantity_out' => round($quantity, 4),
//            'balance_after' => round((float)$stock->quantity_on_hand, 4),
//
//            'unit_cost' => round($unitCost, 4),
//            'total_cost' => round($totalCost, 4),
//
//            'currency' => $currency,
//            'currency_code' => $currency,
//
//            'reference_type' => 'sale',
//            'reference_id' => $saleId,
//
//            'notes' => 'Sale stock deduction with COGS.',
//
//            'metadata' => json_encode([
//                'source' => 'sales',
//                'movement_reason' => 'sale_stock_deduction',
//                'costing_method' => 'weighted_average',
//
//                'sale_id' => $saleId,
//                'till_id' => $till?->id,
//                'shift_session_id' => $shiftSessionId,
//
//                'product' => [
//                    'id' => $product->id,
//                    'name' => $product->name,
//                    'code' => $product->code,
//                    'sku' => $product->sku,
//                    'barcode' => $product->barcode,
//                    'product_type' => $product->product_type,
//                    'parent_product_id' => $product->parent_product_id,
//                ],
//
//                'before' => [
//                    'quantity_on_hand' => round($previousQuantityOnHand, 4),
//                    'available_quantity' => round($previousAvailableQuantity, 4),
//                    'reserved_quantity' => round($previousReservedQuantity, 4),
//
//                    'cost_total_quantity' => $oldCostQuantity !== null ? round($oldCostQuantity, 4) : null,
//                    'cost_total_value' => $oldCostValue !== null ? round($oldCostValue, 4) : null,
//                    'average_cost' => $oldAverageCost !== null ? round($oldAverageCost, 4) : null,
//                    'last_cost' => $oldLastCost !== null ? round($oldLastCost, 4) : null,
//                ],
//
//                'movement' => [
//                    'quantity_out' => round($quantity, 4),
//                    'unit_cost' => round($unitCost, 4),
//                    'total_cost' => round($totalCost, 4),
//                    'currency' => $currency,
//                ],
//
//                'after' => [
//                    'quantity_on_hand' => round((float)$stock->quantity_on_hand, 4),
//                    'available_quantity' => round((float)$stock->available_quantity, 4),
//                    'reserved_quantity' => round((float)$stock->reserved_quantity, 4),
//
//                    'cost_total_quantity' => $newCostQuantity !== null ? round($newCostQuantity, 4) : null,
//                    'cost_total_value' => $newCostValue !== null ? round($newCostValue, 4) : null,
//                    'average_cost' => $newAverageCost !== null ? round($newAverageCost, 4) : null,
//                ],
//
//                'product_cost_id' => $cost?->id,
//            ]),
//
//            'created_by' => auth()->id(),
//            'created_at' => now(),
//            'updated_at' => now(),
//        ]);
//    }
//
//    private function shouldAutoBreakBeforeSale(
//        Product      $product,
//        ProductStock $childStock,
//        float        $saleQuantity
//    ): bool
//    {
//        if ($product->product_type !== 'child') {
//            return false;
//        }
//
//        if (!$product->parent_product_id) {
//            return false;
//        }
//
//        $projectedAvailable = (float)$childStock->available_quantity - $saleQuantity;
//
//        if ($projectedAvailable > 0) {
//            return false;
//        }
//
//        return ProductBreakRule::where('company_id', $product->company_id)
//            ->where('child_product_id', $product->id)
//            ->where('is_auto_break', true)
//            ->where('is_active', true)
//            ->exists();
//    }
//
//    private function autoBreakChildForSale(
//        Product $child,
//        string  $branchId,
//        float   $requiredSaleQuantity,
//        float   $currentChildAvailable,
//        string  $saleId
//    ): void
//    {
//        $rule = ProductBreakRule::where('company_id', $child->company_id)
//            ->where('child_product_id', $child->id)
//            ->where('is_auto_break', true)
//            ->where('is_active', true)
//            ->lockForUpdate()
//            ->first();
//
//        if (!$rule) {
//            return;
//        }
//
//        $parent = Product::where('company_id', $child->company_id)
//            ->where('id', $rule->parent_product_id)
//            ->lockForUpdate()
//            ->first();
//
//        if (!$parent) {
//            throw new \Exception("Parent product not found for {$child->name}.");
//        }
//
//        $unitsPerParent = (float)$rule->units_per_parent;
//        $parentQuantityPerBreak = (float)$rule->parent_quantity;
//
//        if ($unitsPerParent <= 0 || $parentQuantityPerBreak <= 0) {
//            throw new \Exception("Invalid break rule configured for {$child->name}.");
//        }
//
//        $shortage = max($requiredSaleQuantity - $currentChildAvailable, 0);
//
//        if ($shortage <= 0) {
//            $breakTimes = 1;
//        } else {
//            $breakTimes = (int)ceil($shortage / $unitsPerParent);
//        }
//
//        $parentQtyNeeded = $parentQuantityPerBreak * $breakTimes;
//        $childQtyCreated = $unitsPerParent * $breakTimes;
//
//        $parentStock = ProductStock::where('company_id', $parent->company_id)
//            ->where('branch_id', $branchId)
//            ->where('product_id', $parent->id)
//            ->lockForUpdate()
//            ->first();
//
//        if (!$parentStock) {
//            throw new \Exception("No parent stock found for {$parent->name}.");
//        }
//
//        if (!$parent->allow_negative_stock && (float)$parentStock->available_quantity < $parentQtyNeeded) {
//            throw new \Exception(
//                "Insufficient parent stock for auto-break. Parent: {$parent->name}, required: {$parentQtyNeeded}."
//            );
//        }
//
//        $childStock = ProductStock::where('company_id', $child->company_id)
//            ->where('branch_id', $branchId)
//            ->where('product_id', $child->id)
//            ->lockForUpdate()
//            ->first();
//
//        if (!$childStock) {
//            $childStock = ProductStock::create([
//                'id' => (string)Str::uuid(),
//                'company_id' => $child->company_id,
//                'branch_id' => $branchId,
//                'product_id' => $child->id,
//                'quantity_on_hand' => 0,
//                'reserved_quantity' => 0,
//                'available_quantity' => 0,
//            ]);
//
//            $childStock = ProductStock::where('id', $childStock->id)
//                ->lockForUpdate()
//                ->first();
//        }
//
//        $parentCost = ProductCost::where('company_id', $parent->company_id)
//            ->where('branch_id', $branchId)
//            ->where('product_id', $parent->id)
//            ->lockForUpdate()
//            ->first();
//
//        $parentUnitCost = (float)($parentCost?->average_cost ?? 0);
//        $totalCostMoved = $parentUnitCost * $parentQtyNeeded;
//        $childUnitCost = $childQtyCreated > 0
//            ? $totalCostMoved / $childQtyCreated
//            : 0;
//
//        $parentStock->quantity_on_hand = (float)$parentStock->quantity_on_hand - $parentQtyNeeded;
//        $parentStock->available_quantity = (float)$parentStock->available_quantity - $parentQtyNeeded;
//        $parentStock->save();
//
//        $childStock->quantity_on_hand = (float)$childStock->quantity_on_hand + $childQtyCreated;
//        $childStock->available_quantity = (float)$childStock->available_quantity + $childQtyCreated;
//        $childStock->save();
//
//        $this->addAverageCostForAutoBreak(
//            product: $child,
//            branchId: $branchId,
//            quantity: $childQtyCreated,
//            unitCost: $childUnitCost
//        );
//
//        DB::table('stock_movements')->insert([
//            [
//                'id' => (string)Str::uuid(),
//                'company_id' => $parent->company_id,
//                'branch_id' => $branchId,
//                'product_id' => $parent->id,
//                'movement_type' => 'break_parent',
//                'quantity_in' => 0,
//                'quantity_out' => $parentQtyNeeded,
//                'unit_cost' => $parentUnitCost,
//                'total_cost' => $totalCostMoved,
//                'reference_type' => 'sale_auto_break',
//                'reference_id' => $saleId,
//                'notes' => "Auto break during sale for child product: {$child->name}",
//                'created_by' => auth()->id(),
//                'created_at' => now(),
//                'updated_at' => now(),
//            ],
//            [
//                'id' => (string)Str::uuid(),
//                'company_id' => $child->company_id,
//                'branch_id' => $branchId,
//                'product_id' => $child->id,
//                'movement_type' => 'create_child',
//                'quantity_in' => $childQtyCreated,
//                'quantity_out' => 0,
//                'unit_cost' => $childUnitCost,
//                'total_cost' => $totalCostMoved,
//                'reference_type' => 'sale_auto_break',
//                'reference_id' => $saleId,
//                'notes' => "Auto-created child stock from parent during sale: {$parent->name}",
//                'created_by' => auth()->id(),
//                'created_at' => now(),
//                'updated_at' => now(),
//            ],
//        ]);
//    }
//
//    private function addAverageCostForAutoBreak(
//        Product $product,
//        string  $branchId,
//        float   $quantity,
//        float   $unitCost
//    ): void
//    {
//        $cost = ProductCost::firstOrCreate(
//            [
//                'company_id' => $product->company_id,
//                'branch_id' => $branchId,
//                'product_id' => $product->id,
//            ],
//            [
//                'average_cost' => 0,
//                'last_cost' => 0,
//                'total_quantity' => 0,
//                'total_value' => 0,
//            ]
//        );
//
//        $oldQuantity = (float)$cost->total_quantity;
//        $oldValue = (float)$cost->total_value;
//
//        $newValue = $quantity * $unitCost;
//        $newTotalQuantity = $oldQuantity + $quantity;
//        $newTotalValue = $oldValue + $newValue;
//
//        $cost->average_cost = $newTotalQuantity > 0
//            ? $newTotalValue / $newTotalQuantity
//            : 0;
//
//        $cost->last_cost = $unitCost;
//        $cost->total_quantity = $newTotalQuantity;
//        $cost->total_value = $newTotalValue;
//        $cost->save();
//    }
//
//    private function saveSalePayment(
//        SalesTransaction $sale,
//        PaymentType      $paymentType,
//        string           $companyId,
//        string           $branchId,
//        ?string          $tillId,
//        ?string          $shiftId,
//        ?string          $shiftSessionId,
//        string           $currencyCode,
//        string           $baseCurrencyCode,
//        float            $amountTendered,
//        float            $saleTotal,
//        float            $change
//    ): void
//    {
//        $baseAmount = currencyConverter(
//            $currencyCode,
//            $baseCurrencyCode,
//            $saleTotal,
//            $companyId
//        );
//
//        $exchangeRate = $this->getExchangeRate(
//            fromCode: $baseCurrencyCode,
//            toCode: $currencyCode,
//            companyId: $companyId
//        );
//
//        DB::table('sales_transaction_payments')->insert([
//            'id' => (string)Str::uuid(),
//
//            'company_id' => $companyId,
//            'branch_id' => $branchId,
//            'till_id' => $tillId,
//            'shift_id' => $shiftId,
//            'shift_session_id' => $shiftSessionId,
//
//            'sales_transaction_id' => $sale->id,
//            'payment_type_id' => $paymentType->id,
//
//            'payment_name' => $paymentType->name,
//            'money_code' => $paymentType->money_code,
//
//            'currency_code' => $currencyCode,
//            'base_currency_code' => $baseCurrencyCode,
//            'exchange_rate' => $exchangeRate,
//
//            'amount' => round($saleTotal, 4),
//            'base_amount' => round($baseAmount, 4),
//
//            'amount_tendered' => round($amountTendered, 4),
//            'change_amount' => round($change, 4),
//
//            'reference' => null,
//            'approval_code' => null,
//            'card_last_four' => null,
//
//            'metadata' => json_encode([
//                'normal_sale' => true,
//                'shift_session_id' => $shiftSessionId,
//                'shift_configuration_id' => $shiftId,
//            ]),
//
//            'created_at' => now(),
//            'updated_at' => now(),
//        ]);
//    }
//
//    private function validatePaymentCurrency(PaymentType $paymentType, string $currencyCode)
//    {
//        if ((bool)$paymentType->supportsMultiCurrency) {
//            return true;
//        }
//
//        if (!$paymentType->default_currency_code) {
//            return response()->json([
//                'message' => 'This payment type does not support multi-currency and has no default currency configured.',
//            ], 422);
//        }
//
//        if ($paymentType->default_currency_code !== $currencyCode) {
//            return response()->json([
//                'message' => "{$paymentType->name} only supports {$paymentType->default_currency_code}.",
//            ], 422);
//        }
//
//        return true;
//    }
//
//    private function generateReceiptNumber(
//        string  $companyId,
//        string  $branchId,
//        ?string $tillId,
//        ?string $tillCode = null
//    ): string
//    {
//        return DB::transaction(function () use ($companyId, $branchId, $tillId, $tillCode) {
//            $today = Carbon::today()->toDateString();
//            $datePart = Carbon::today()->format('ymd');
//
//            $prefix = 'SAL';
//
//            if ($tillCode) {
//                $cleanTillCode = strtoupper(preg_replace('/[^A-Z0-9]/', '', $tillCode));
//                $prefix = 'SAL-' . $cleanTillCode;
//            }
//
//            $sequence = ReceiptNumberSequence::where('company_id', $companyId)
//                ->where('branch_id', $branchId)
//                ->where('till_id', $tillId)
//                ->where('sequence_type', 'sale_receipt')
//                ->whereDate('sequence_date', $today)
//                ->lockForUpdate()
//                ->first();
//
//            if (!$sequence) {
//                $sequence = ReceiptNumberSequence::create([
//                    'company_id' => $companyId,
//                    'branch_id' => $branchId,
//                    'till_id' => $tillId,
//                    'sequence_type' => 'sale_receipt',
//                    'prefix' => $prefix,
//                    'sequence_date' => $today,
//                    'last_number' => 0,
//                ]);
//
//                $sequence = ReceiptNumberSequence::where('id', $sequence->id)
//                    ->lockForUpdate()
//                    ->first();
//            }
//
//            $nextNumber = ((int)$sequence->last_number) + 1;
//
//            do {
//                $receiptNumber = $prefix . '-' . $datePart . '-' . str_pad($nextNumber, 6, '0', STR_PAD_LEFT);
//
//                $exists = SalesTransaction::where('invoice_number', $receiptNumber)->exists();
//
//                if (!$exists) {
//                    break;
//                }
//
//                $nextNumber++;
//            } while (true);
//
//            $sequence->update([
//                'prefix' => $prefix,
//                'last_number' => $nextNumber,
//            ]);
//
//            return $receiptNumber;
//        });
//    }
//
//    private function getExchangeRate(
//        string $fromCode,
//        string $toCode,
//        string $companyId
//    ): float
//    {
//        if ($fromCode === $toCode) {
//            return 1;
//        }
//
//        return (float)currencyConverter($fromCode, $toCode, 1, $companyId);
//    }
//
//    private function getOpenShift(
//        string $companyId,
//        string $branchId,
//        string $tillId,
//        string $cashierId
//    )
//    {
//        return DB::table('pos_user_shift_sessions')
//            ->where('company_id', $companyId)
//            ->where('branch_id', $branchId)
//            /*
//             |--------------------------------------------------------------------------
//             | If you later add till_id to pos_user_shift_sessions, enable this.
//             |--------------------------------------------------------------------------
//             */
//            // ->where('till_id', $tillId)
//            ->where('pos_user_id', $cashierId)
//            ->where('status', 'open')
//            ->whereNull('closed_at')
//            ->first();
//    }
//
//
//    public function findReceiptForAction(Request $request)
//    {
//        $company = app('company');
//        $till = app('till');
//        $user = auth()->user();
//
//        $permission = hasPermission($user->role_id, 'view_sale', $company->id);
//
//        if ($permission !== true) {
//            return $permission;
//        }
//
//        $validator = Validator::make($request->all(), [
//            'invoice_number' => ['required', 'string', 'max:255'],
//        ]);
//
//        if ($validator->fails()) {
//            return response()->json([
//                'message' => 'Validation error.',
//                'errors' => $validator->errors(),
//            ], 422);
//        }
//
//        $sale = SalesTransaction::with([
//            'company',
//            'branch',
//            'till',
//            'receiptLines.taxes.tax',
//            'receiptLines.product',
//        ])
//            ->where('company_id', $company->id)
//            ->where('invoice_number', trim($request->invoice_number))
//            ->where(function ($query) use ($till) {
//                $query->where('branch_id', $till->branch_id)
//                    ->orWhereNull('branch_id');
//            })
//            ->first();
//
//        if (!$sale) {
//            return response()->json([
//                'message' => 'Receipt not found.',
//            ], 404);
//        }
//
//        return response()->json([
//            'message' => 'Receipt fetched successfully.',
//            'data' => [
//                'sale' => $sale,
//                'can_cancel' => $sale->status === 'completed',
//                'can_reprint' => in_array($sale->status, ['completed', 'cancelled', 'voided'], true),
//            ],
//        ]);
//    }
//
//    public function reprintReceipt(Request $request)
//    {
//        $company = app('company');
//        $till = app('till');
//        $user = auth()->user();
//
//        $permission = hasPermission($user->role_id, 'reprint_receipt', $company->id);
//
//        if ($permission !== true) {
//            return $permission;
//        }
//
//        $validator = Validator::make($request->all(), [
//            'invoice_number' => ['required_without:sale_id', 'nullable', 'string', 'max:255'],
//            'sale_id' => ['required_without:invoice_number', 'nullable', 'string'],
//        ]);
//
//        if ($validator->fails()) {
//            return response()->json([
//                'message' => 'Validation error.',
//                'errors' => $validator->errors(),
//            ], 422);
//        }
//
//        $sale = $this->receiptQueryForAction($company->id, $till->branch_id)
//            ->when($request->sale_id, fn($q) => $q->where('id', $request->sale_id))
//            ->when($request->invoice_number, fn($q) => $q->where('invoice_number', trim($request->invoice_number)))
//            ->first();
//
//        if (!$sale) {
//            return response()->json([
//                'message' => 'Receipt not found.',
//            ], 404);
//        }
//
//        $receipt = $this->prepareReceiptAfterFiscalisation($sale);
//
//        return response()->json([
//            'message' => 'Receipt prepared for reprint successfully.',
//            'data' => [
//                'sale' => $sale,
//                'receipt_url' => $receipt['receipt_url'],
//                'receipt_settings' => $receipt['receipt_settings'],
//                'print' => [
//                    'auto_print' => true,
//                    'printer' => $receipt['printer'],
//                ],
//            ],
//        ]);
//    }
//
//    public function cancelReceipt(Request $request)
//    {
//        $company = app('company');
//        $till = app('till');
//        $user = auth()->user();
//
//        $permission = hasPermission($user->role_id, 'cancel_receipt', $company->id);
//
//        if ($permission !== true) {
//            return $permission;
//        }
//
//        $validator = Validator::make($request->all(), [
//            'invoice_number' => ['required_without:sale_id', 'nullable', 'string', 'max:255'],
//            'sale_id' => ['required_without:invoice_number', 'nullable', 'string'],
//            'reason' => ['required', 'string', 'min:3', 'max:1000'],
//            'manager_pin' => ['nullable', 'string', 'max:255'],
//            'reprint_cancelled_receipt' => ['nullable', 'boolean'],
//        ]);
//
//        if ($validator->fails()) {
//            return response()->json([
//                'message' => 'Validation error.',
//                'errors' => $validator->errors(),
//            ], 422);
//        }
//
//        DB::beginTransaction();
//
//        try {
//            $sale = $this->receiptQueryForAction($company->id, $till->branch_id)
//                ->where('status', 'completed')
//                ->when($request->sale_id, fn($q) => $q->where('id', $request->sale_id))
//                ->when($request->invoice_number, fn($q) => $q->where('invoice_number', trim($request->invoice_number)))
//                ->lockForUpdate()
//                ->first();
//
//            if (!$sale) {
//                DB::rollBack();
//
//                return response()->json([
//                    'message' => 'Completed receipt not found or already cancelled.',
//                ], 404);
//            }
//
//            $existingCancel = SalesTransaction::where('company_id', $company->id)
//                ->where('metadata->cancelled_original_sale_id', $sale->id)
//                ->where('sale_type', 'sale_cancel')
//                ->first();
//
//            if ($existingCancel) {
//                DB::rollBack();
//
//                return response()->json([
//                    'message' => 'This receipt has already been cancelled.',
//                    'data' => [
//                        'cancel_transaction' => $existingCancel,
//                    ],
//                ], 422);
//            }
//
//            $cancelNumber = $this->generateCancellationReceiptNumber(
//                companyId: $company->id,
//                branchId: $sale->branch_id,
//                tillId: $sale->till_id,
//                tillCode: $till->till_code ?? null
//            );
//
//            $cancelBusinessDatePayload = $this->foodIndustrySaleDatePayload(
//                companyId: $company->id,
//                branchId: $sale->branch_id
//            );
//
//            $cancelTransaction = SalesTransaction::create([
//                'id' => (string)Str::uuid(),
//
//                'company_id' => $sale->company_id,
//                'branch_id' => $sale->branch_id,
//                'till_id' => $sale->till_id,
//
//                'shift_id' => $sale->shift_id,
//                'shift_session_id' => $sale->shift_session_id,
//
//                'cashier_id' => $user->id,
//                'user_id' => $user->id,
//
//                'invoice_number' => $cancelNumber,
//                'transaction_date' => now(),
//
//                'document_type' => 'cancel_receipt',
//                'sale_type' => 'sale_cancel',
//                'status' => 'completed',
//
//                'currency_code' => $sale->currency_code,
//                'base_currency_code' => $sale->base_currency_code,
//                'exchange_rate' => $sale->exchange_rate,
//
//                'subtotal_amount' => -1 * (float)$sale->subtotal_amount,
//                'discount_amount' => -1 * (float)$sale->discount_amount,
//                'tax_amount' => -1 * (float)$sale->tax_amount,
//                'total_amount' => -1 * (float)$sale->total_amount,
//
//                'base_subtotal_amount' => -1 * (float)$sale->base_subtotal_amount,
//                'base_discount_amount' => -1 * (float)$sale->base_discount_amount,
//                'base_tax_amount' => -1 * (float)$sale->base_tax_amount,
//                'base_total_amount' => -1 * (float)$sale->base_total_amount,
//
//                'payment_type_id' => $sale->payment_type_id,
//                'payment_method' => $sale->payment_method,
//
//                'amount_tendered' => 0,
//                'change_amount' => 0,
//                'change' => 0,
//
//                'customer_name' => $sale->customer_name,
//                'customer_phone' => $sale->customer_phone,
//                'customer_tax_number' => $sale->customer_tax_number,
//                'notes' => 'Cancellation of ' . $sale->invoice_number . '. Reason: ' . $request->reason,
//
//                'metadata' => [
//                    'cancelled_original_sale_id' => $sale->id,
//                    'cancelled_original_invoice_number' => $sale->invoice_number,
//                    'cancelled_by' => $user->id,
//                    'cancelled_by_name' => $user->name ?? $user->username ?? null,
//                    'cancel_reason' => $request->reason,
//                    'cancelled_at' => now()->toDateTimeString(),
//                    'till_code' => $till->till_code ?? null,
//                    'till_name' => $till->till_name ?? null,
//                    'food_industry_business_date_enabled' => $cancelBusinessDatePayload['food_industry_business_date_enabled'],
//                    'business_date' => $cancelBusinessDatePayload['business_date'],
//                    'trading_day_id' => $cancelBusinessDatePayload['trading_day_id'],
//                    'trading_day_status_at_sale' => $cancelBusinessDatePayload['trading_day_status_at_sale'],
//                ],
//            ]);
//
//            $this->applyFoodIndustryBusinessDateToSale($cancelTransaction, $cancelBusinessDatePayload);
//
//            $this->createCancellationReceiptLines(
//                originalSale: $sale,
//                cancelTransaction: $cancelTransaction
//            );
//
//            $this->restoreCancelledSaleStock(
//                sale: $sale,
//                cancelTransaction: $cancelTransaction,
//                userId: $user->id,
//                reason: $request->reason
//            );
//
//            $this->reverseSalePayments(
//                originalSale: $sale,
//                cancelTransaction: $cancelTransaction,
//                userId: $user->id
//            );
//
//            if ((bool)($sale->is_credit_sale ?? false) || $sale->credit_customer_id || $sale->credit_account_number) {
//                app(CreditCustomerService::class)->reverseCreditSaleForCancellation(
//                    originalSale: $sale,
//                    cancelTransaction: $cancelTransaction,
//                    reason: $request->reason,
//                    userId: $user->id
//                );
//            }
//
//            $metadata = $sale->metadata ?: [];
//            if (!is_array($metadata)) {
//                $metadata = json_decode($metadata, true) ?: [];
//            }
//
//            $metadata['cancelled'] = true;
//            $metadata['cancel_transaction_id'] = $cancelTransaction->id;
//            $metadata['cancel_invoice_number'] = $cancelTransaction->invoice_number;
//            $metadata['cancelled_by'] = $user->id;
//            $metadata['cancelled_by_name'] = $user->name ?? $user->username ?? null;
//            $metadata['cancel_reason'] = $request->reason;
//            $metadata['cancelled_at'] = now()->toDateTimeString();
//
//            $sale->status = 'cancelled';
//            $sale->metadata = $metadata;
//            $sale->save();
//
//            DB::commit();
//
//            /*
//             |--------------------------------------------------------------------------
//             | ZIMRA fiscalisation for cancellation / CreditNote
//             |--------------------------------------------------------------------------
//             | The cancellation is already committed locally. For FDMS this must be
//             | submitted as a CreditNote and must reference the original submitted
//             | fiscal receipt using receiptID or deviceID + receiptGlobalNo + fiscalDayNo.
//             */
//            $fiscalisation = $this->fiscaliseCancellationAfterCommit(
//                cancelTransaction: $cancelTransaction,
//                originalSale: $sale->fresh(),
//                reason: $request->reason
//            );
//
//            if (($fiscalisation['blocked'] ?? false) === true) {
//                return response()->json([
//                    'message' => 'Receipt cancelled locally, but ZIMRA fiscalisation failed.',
//                    'error' => $fiscalisation['error'] ?? 'Fiscalisation failed.',
//                    'data' => [
//                        'original_sale' => $sale->fresh(),
//                        'cancel_transaction' => $fiscalisation['sale'] ?? $cancelTransaction->fresh(),
//                        'fiscalisation' => $fiscalisation,
//                    ],
//                ], 409);
//            }
//
//            $cancelForReceipt = SalesTransaction::with([
//                'company',
//                'branch',
//                'till',
//                'receiptLines.taxes.tax',
//            ])->where('id', ($fiscalisation['sale']->id ?? $cancelTransaction->id))->firstOrFail();
//
//            $cancelForReceipt = $this->hydrateSaleFiscalFieldsFromZimraReceipt($cancelForReceipt);
//
//            $receipt = $this->prepareReceiptAfterFiscalisation($cancelForReceipt);
//
//            return response()->json([
//                'message' => 'Receipt cancelled successfully.',
//                'data' => [
//                    'original_sale' => $sale->fresh(),
//                    'cancel_transaction' => $cancelForReceipt,
//                    'receipt_url' => $receipt['receipt_url'],
//                    'receipt_settings' => $receipt['receipt_settings'],
//                    'print' => [
//                        'auto_print' => (bool)$request->boolean('reprint_cancelled_receipt', true),
//                        'printer' => $receipt['printer'],
//                    ],
//
//                    'fiscalisation' => $fiscalisation,
//                ],
//            ]);
//        } catch (\Throwable $e) {
//            if (DB::transactionLevel() > 0) {
//                DB::rollBack();
//            }
//
//            return response()->json([
//                'message' => 'Receipt cancellation failed.',
//                'error' => $e->getMessage(),
//            ], 500);
//        }
//    }
//
//    public function cancelledReceipts(Request $request)
//    {
//        $company = app('company');
//        $till = app('till');
//        $user = auth()->user();
//
//        $permission = hasPermission($user->role_id, 'view_cancelled_receipts', $company->id);
//
//        if ($permission !== true) {
//            return $permission;
//        }
//
//        $dateFrom = $request->date_from ?: now()->toDateString();
//        $dateTo = $request->date_to ?: now()->toDateString();
//
//        $query = SalesTransaction::query()
//            ->with(['branch', 'till'])
//            ->where('company_id', $company->id)
//            ->where(function ($q) {
//                $q->where('sale_type', 'sale_cancel')
//                    ->orWhere('document_type', 'cancel_receipt')
//                    ->orWhere('status', 'cancelled');
//            })
//            ->whereDate('transaction_date', '>=', $dateFrom)
//            ->whereDate('transaction_date', '<=', $dateTo)
//            ->when($request->branch_id, fn($q) => $q->where('branch_id', $request->branch_id))
//            ->when($request->till_id, fn($q) => $q->where('till_id', $request->till_id))
//            ->when($request->q, function ($q) use ($request) {
//                $term = trim($request->q);
//
//                $q->where(function ($sub) use ($term) {
//                    $sub->where('invoice_number', 'like', "%{$term}%")
//                        ->orWhere('customer_name', 'like', "%{$term}%")
//                        ->orWhere('metadata->cancelled_original_invoice_number', 'like', "%{$term}%")
//                        ->orWhere('metadata->cancel_reason', 'like', "%{$term}%");
//                });
//            })
//            ->orderByDesc('transaction_date');
//
//        return response()->json([
//            'message' => 'Cancelled receipts fetched successfully.',
//            'data' => $query->paginate($request->per_page ?? 25),
//        ]);
//    }
//
//    private function receiptQueryForAction(string $companyId, ?string $branchId = null)
//    {
//        return SalesTransaction::with([
//            'company',
//            'branch',
//            'till',
//            'receiptLines.taxes.tax',
//            'receiptLines.product',
//        ])
//            ->where('company_id', $companyId)
//            ->when($branchId, fn($q) => $q->where('branch_id', $branchId));
//    }
//
//    private function createCancellationReceiptLines(
//        SalesTransaction $originalSale,
//        SalesTransaction $cancelTransaction
//    ): void
//    {
//        $lines = ReceiptLine::where('sales_transaction_id', $originalSale->id)
//            ->orderBy('line_no')
//            ->get();
//
//        foreach ($lines as $line) {
//            $cancelLine = ReceiptLine::create([
//                'id' => (string)Str::uuid(),
//
//                'company_id' => $line->company_id,
//                'branch_id' => $line->branch_id,
//                'sales_transaction_id' => $cancelTransaction->id,
//
//                'product_id' => $line->product_id,
//                'product_price_id' => $line->product_price_id,
//                'price_level_id' => $line->price_level_id,
//
//                'line_no' => $line->line_no,
//                'line_name' => 'CANCEL - ' . $line->line_name,
//                'product_code' => $line->product_code,
//                'sku' => $line->sku,
//                'barcode' => $line->barcode,
//                'hs_code' => $line->hs_code,
//
//                'line_quantity' => -1 * (float)$line->line_quantity,
//                'line_price' => (float)$line->line_price,
//                'line_subtotal' => -1 * (float)$line->line_subtotal,
//                'line_discount' => -1 * (float)$line->line_discount,
//                'line_tax' => -1 * (float)$line->line_tax,
//                'line_total' => -1 * (float)$line->line_total,
//
//                'base_line_price' => (float)$line->base_line_price,
//                'base_line_subtotal' => -1 * (float)$line->base_line_subtotal,
//                'base_line_discount' => -1 * (float)$line->base_line_discount,
//                'base_line_tax' => -1 * (float)$line->base_line_tax,
//                'base_line_total' => -1 * (float)$line->base_line_total,
//
//                'currency_code' => $line->currency_code,
//                'base_currency_code' => $line->base_currency_code,
//
//                'metadata' => array_merge(
//                    is_array($line->metadata) ? $line->metadata : (json_decode($line->metadata ?? '[]', true) ?: []),
//                    [
//                        'cancel_line' => true,
//                        'original_receipt_line_id' => $line->id,
//                        'original_sale_id' => $originalSale->id,
//                    ]
//                ),
//            ]);
//
//            $taxes = ReceiptLineTaxe::where('receipt_line_id', $line->id)->get();
//
//            foreach ($taxes as $tax) {
//                ReceiptLineTaxe::create([
//                    'id' => (string)Str::uuid(),
//
//                    'company_id' => $tax->company_id,
//                    'branch_id' => $tax->branch_id,
//                    'sales_transaction_id' => $cancelTransaction->id,
//                    'receipt_line_id' => $cancelLine->id,
//
//                    'tax_id' => $tax->tax_id,
//                    'tax_code' => $tax->tax_code,
//                    'tax_percent' => $tax->tax_percent,
//                    'is_inclusive' => $tax->is_inclusive,
//                    'obliged_to_zimra' => $tax->obliged_to_zimra,
//
//                    'tax_amount' => -1 * (float)$tax->tax_amount,
//                    'sales_amount_with_tax' => -1 * (float)$tax->sales_amount_with_tax,
//
//                    'currency_code' => $tax->currency_code,
//
//                    'metadata' => array_merge(
//                        is_array($tax->metadata) ? $tax->metadata : (json_decode($tax->metadata ?? '[]', true) ?: []),
//                        [
//                            'cancel_tax_line' => true,
//                            'original_receipt_line_tax_id' => $tax->id,
//                        ]
//                    ),
//                ]);
//            }
//        }
//    }
//
////    private function restoreCancelledSaleStock(
////        SalesTransaction $sale,
////        SalesTransaction $cancelTransaction,
////        string           $userId,
////        string           $reason
////    ): void
////    {
////        $lines = ReceiptLine::where('sales_transaction_id', $sale->id)->get();
////
////        foreach ($lines as $line) {
////            $product = Product::where('company_id', $sale->company_id)
////                ->where('id', $line->product_id)
////                ->first();
////
////            if (!$product || !(bool)$product->stock_tracking_enabled) {
////                continue;
////            }
////
////            $quantity = abs((float)$line->line_quantity);
////
////            $stock = ProductStock::firstOrCreate(
////                [
////                    'company_id' => $sale->company_id,
////                    'branch_id' => $sale->branch_id,
////                    'product_id' => $product->id,
////                ],
////                [
////                    'id' => (string)Str::uuid(),
////                    'quantity_on_hand' => 0,
////                    'reserved_quantity' => 0,
////                    'available_quantity' => 0,
////                ]
////            );
////
////            $stock = ProductStock::where('id', $stock->id)->lockForUpdate()->first();
////
////            $stock->quantity_on_hand = (float)$stock->quantity_on_hand + $quantity;
////            $stock->available_quantity = (float)$stock->available_quantity + $quantity;
////            $stock->save();
////
////            $cost = ProductCost::where('company_id', $sale->company_id)
////                ->where('branch_id', $sale->branch_id)
////                ->where('product_id', $product->id)
////                ->first();
////
////            $unitCost = (float)($cost?->average_cost ?: $cost?->last_cost ?: 0);
////
////            DB::table('stock_movements')->insert([
////                'id' => (string)Str::uuid(),
////                'company_id' => $sale->company_id,
////                'branch_id' => $sale->branch_id,
////                'till_id' => $sale->till_id,
////                'shift_session_id' => $sale->shift_session_id,
////                'product_id' => $product->id,
////                'movement_type' => 'sale_cancel',
////                'quantity_in' => $quantity,
////                'quantity_out' => 0,
////                'balance_after' => $stock->quantity_on_hand,
////                'unit_cost' => $unitCost,
////                'total_cost' => $unitCost * $quantity,
////                'currency' => $cost?->currency,
////                'currency_code' => $cost?->currency,
////                'reference_type' => 'sale_cancel',
////                'reference_id' => $cancelTransaction->id,
////                'notes' => 'Receipt cancellation stock restore. Original invoice: ' . $sale->invoice_number . '. Reason: ' . $reason,
////                'metadata' => json_encode([
////                    'original_sale_id' => $sale->id,
////                    'original_invoice_number' => $sale->invoice_number,
////                    'cancel_invoice_number' => $cancelTransaction->invoice_number,
////                ]),
////                'created_by' => $userId,
////                'created_at' => now(),
////                'updated_at' => now(),
////            ]);
////        }
////    }
//    private function restoreCancelledSaleStock(
//        SalesTransaction $sale,
//        SalesTransaction $cancelTransaction,
//        string           $userId,
//        string           $reason
//    ): void
//    {
//        $lines = ReceiptLine::where('sales_transaction_id', $sale->id)->get();
//
//        foreach ($lines as $line) {
//            $product = Product::where('company_id', $sale->company_id)
//                ->where('id', $line->product_id)
//                ->first();
//
//            if (!$product) {
//                continue;
//            }
//
//            $quantity = abs((float)$line->line_quantity);
//
//            if ($this->shouldUseRecipeFrontStockForSale($sale->company_id, $sale->branch_id, $product)) {
//                $restoredByRecipe = $this->restoreMenuRecipeIngredientsToFrontStock(
//                    menuProduct: $product,
//                    branchId: $sale->branch_id,
//                    soldQuantity: $quantity,
//                    originalSaleId: $sale->id,
//                    cancelTransactionId: $cancelTransaction->id,
//                    receiptLineId: $line->id,
//                    shiftSessionId: $sale->shift_session_id,
//                    userId: $userId,
//                    reason: $reason
//                );
//
//                if ($restoredByRecipe) {
//                    continue;
//                }
//            }
//
//            if (!(bool)$product->stock_tracking_enabled) {
//                continue;
//            }
//
//            $stock = ProductStock::firstOrCreate(
//                [
//                    'company_id' => $sale->company_id,
//                    'branch_id' => $sale->branch_id,
//                    'product_id' => $product->id,
//                ],
//                [
//                    'id' => (string)Str::uuid(),
//                    'quantity_on_hand' => 0,
//                    'reserved_quantity' => 0,
//                    'available_quantity' => 0,
//                ]
//            );
//
//            $stock = ProductStock::where('id', $stock->id)->lockForUpdate()->first();
//
//            $stock->quantity_on_hand = (float)$stock->quantity_on_hand + $quantity;
//            $stock->available_quantity = (float)$stock->available_quantity + $quantity;
//            $stock->save();
//
//            $cost = ProductCost::where('company_id', $sale->company_id)
//                ->where('branch_id', $sale->branch_id)
//                ->where('product_id', $product->id)
//                ->first();
//
//            $unitCost = (float)($cost?->average_cost ?: $cost?->last_cost ?: 0);
//
//            DB::table('stock_movements')->insert([
//                'id' => (string)Str::uuid(),
//                'company_id' => $sale->company_id,
//                'branch_id' => $sale->branch_id,
//                'till_id' => $sale->till_id,
//                'shift_session_id' => $sale->shift_session_id,
//                'product_id' => $product->id,
//                'movement_type' => 'sale_cancel',
//                'quantity_in' => $quantity,
//                'quantity_out' => 0,
//                'balance_after' => $stock->quantity_on_hand,
//                'unit_cost' => $unitCost,
//                'total_cost' => $unitCost * $quantity,
//                'currency' => $cost?->currency,
//                'currency_code' => $cost?->currency,
//                'reference_type' => 'sale_cancel',
//                'reference_id' => $cancelTransaction->id,
//                'notes' => 'Receipt cancellation stock restore. Original invoice: ' . $sale->invoice_number . '. Reason: ' . $reason,
//                'metadata' => json_encode([
//                    'original_sale_id' => $sale->id,
//                    'original_invoice_number' => $sale->invoice_number,
//                    'cancel_invoice_number' => $cancelTransaction->invoice_number,
//                ]),
//                'created_by' => $userId,
//                'created_at' => now(),
//                'updated_at' => now(),
//            ]);
//        }
//    }
//
//    private function reverseSalePayments(
//        SalesTransaction $originalSale,
//        SalesTransaction $cancelTransaction,
//        string           $userId
//    ): void
//    {
//        if (!Schema::hasTable('sales_transaction_payments')) {
//            return;
//        }
//
//        $payments = DB::table('sales_transaction_payments')
//            ->where('sales_transaction_id', $originalSale->id)
//            ->get();
//
//        foreach ($payments as $payment) {
//            DB::table('sales_transaction_payments')->insert([
//                'id' => (string)Str::uuid(),
//
//                'company_id' => $payment->company_id,
//                'branch_id' => $payment->branch_id,
//                'till_id' => $payment->till_id,
//                'shift_id' => $payment->shift_id,
//                'shift_session_id' => $payment->shift_session_id,
//
//                'sales_transaction_id' => $cancelTransaction->id,
//                'payment_type_id' => $payment->payment_type_id,
//
//                'payment_name' => $payment->payment_name,
//                'money_code' => $payment->money_code,
//
//                'currency_code' => $payment->currency_code,
//                'base_currency_code' => $payment->base_currency_code,
//                'exchange_rate' => $payment->exchange_rate,
//
//                'amount' => -1 * (float)$payment->amount,
//                'base_amount' => -1 * (float)$payment->base_amount,
//
//                'amount_tendered' => 0,
//                'change_amount' => 0,
//
//                'reference' => 'CANCEL-' . $originalSale->invoice_number,
//                'approval_code' => null,
//                'card_last_four' => $payment->card_last_four ?? null,
//
//                'metadata' => json_encode([
//                    'payment_reversal' => true,
//                    'original_sale_id' => $originalSale->id,
//                    'original_payment_id' => $payment->id,
//                    'cancel_transaction_id' => $cancelTransaction->id,
//                    'created_by' => $userId,
//                ]),
//
//                'created_at' => now(),
//                'updated_at' => now(),
//            ]);
//        }
//    }
//
//    private function generateCancellationReceiptNumber(
//        string  $companyId,
//        string  $branchId,
//        ?string $tillId,
//        ?string $tillCode = null
//    ): string
//    {
//        return DB::transaction(function () use ($companyId, $branchId, $tillId, $tillCode) {
//            $today = Carbon::today()->toDateString();
//            $datePart = Carbon::today()->format('ymd');
//
//            $prefix = 'CAN';
//
//            if ($tillCode) {
//                $cleanTillCode = strtoupper(preg_replace('/[^A-Z0-9]/', '', $tillCode));
//                $prefix = 'CAN-' . $cleanTillCode;
//            }
//
//            $sequence = ReceiptNumberSequence::where('company_id', $companyId)
//                ->where('branch_id', $branchId)
//                ->where('till_id', $tillId)
//                ->where('sequence_type', 'cancel_receipt')
//                ->whereDate('sequence_date', $today)
//                ->lockForUpdate()
//                ->first();
//
//            if (!$sequence) {
//                $sequence = ReceiptNumberSequence::create([
//                    'company_id' => $companyId,
//                    'branch_id' => $branchId,
//                    'till_id' => $tillId,
//                    'sequence_type' => 'cancel_receipt',
//                    'prefix' => $prefix,
//                    'sequence_date' => $today,
//                    'last_number' => 0,
//                ]);
//
//                $sequence = ReceiptNumberSequence::where('id', $sequence->id)
//                    ->lockForUpdate()
//                    ->first();
//            }
//
//            $nextNumber = ((int)$sequence->last_number) + 1;
//
//            do {
//                $receiptNumber = $prefix . '-' . $datePart . '-' . str_pad($nextNumber, 6, '0', STR_PAD_LEFT);
//
//                $exists = SalesTransaction::where('invoice_number', $receiptNumber)->exists();
//
//                if (!$exists) {
//                    break;
//                }
//
//                $nextNumber++;
//            } while (true);
//
//            $sequence->update([
//                'prefix' => $prefix,
//                'last_number' => $nextNumber,
//            ]);
//
//            return $receiptNumber;
//        });
//    }
//
//
//    public function receiptRegister(Request $request)
//    {
//        $company = app('company');
//        $till = app('till');
//        $user = auth()->user();
//
//        $permission = hasPermission($user->role_id, 'view_sale', $company->id);
//
//        if ($permission !== true) {
//            return $permission;
//        }
//
//        $dateFrom = $request->date_from ?: now()->toDateString();
//        $dateTo = $request->date_to ?: $dateFrom;
//
//        $query = SalesTransaction::query()
//            ->with([
//                'branch',
//                'till',
//            ])
//            ->where('company_id', $company->id)
//            ->where('branch_id', $till->branch_id)
//            ->whereDate('transaction_date', '>=', $dateFrom)
//            ->whereDate('transaction_date', '<=', $dateTo)
//            ->when($request->status, fn($q) => $q->where('status', $request->status))
//            ->when($request->currency_code, fn($q) => $q->where('currency_code', $request->currency_code))
//            ->when($request->till_id, fn($q) => $q->where('till_id', $request->till_id))
//            ->when($request->q, function ($q) use ($request) {
//                $term = trim($request->q);
//
//                $q->where(function ($sub) use ($term) {
//                    $sub->where('invoice_number', 'like', "%{$term}%")
//                        ->orWhere('customer_name', 'like', "%{$term}%")
//                        ->orWhere('customer_phone', 'like', "%{$term}%")
//                        ->orWhere('payment_method', 'like', "%{$term}%");
//                });
//            })
//            ->orderByDesc('transaction_date');
//
//        return response()->json([
//            'message' => 'Receipt register fetched successfully.',
//            'data' => $query->paginate($request->per_page ?? 25),
//        ]);
//    }
//
//    public function receiptRegisterShow(string $saleId)
//    {
//        $company = app('company');
//        $till = app('till');
//        $user = auth()->user();
//
//        $permission = hasPermission($user->role_id, 'view_sale', $company->id);
//
//        if ($permission !== true) {
//            return $permission;
//        }
//
//        $sale = SalesTransaction::with([
//            'company',
//            'branch',
//            'till',
//            'receiptLines.taxes.tax',
//            'receiptLines.product',
//        ])
//            ->where('company_id', $company->id)
//            ->where('branch_id', $till->branch_id)
//            ->where('id', $saleId)
//            ->first();
//
//        if (!$sale) {
//            return response()->json([
//                'message' => 'Receipt not found.',
//            ], 404);
//        }
//
//        return response()->json([
//            'message' => 'Receipt fetched successfully.',
//            'data' => [
//                'sale' => $sale,
//            ],
//        ]);
//    }
//
//    public function receiptPdfForSharing(Request $request, string $saleId)
//    {
//        $company = app('company');
//        $till = app('till');
//        $user = auth()->user();
//
//        $permission = hasPermission($user->role_id, 'view_sale', $company->id);
//
//        if ($permission !== true) {
//            return $permission;
//        }
//
//        $sale = SalesTransaction::with([
//            'company',
//            'branch',
//            'till',
//            'receiptLines.taxes.tax',
//            'receiptLines.product',
//        ])
//            ->where('company_id', $company->id)
//            ->where('branch_id', $till->branch_id)
//            ->where('id', $saleId)
//            ->first();
//
//        if (!$sale) {
//            return response()->json([
//                'message' => 'Receipt not found.',
//            ], 404);
//        }
//
//        $receipt = $this->prepareReceiptAfterFiscalisation($sale);
//
//        return response()->json([
//            'message' => 'Receipt PDF prepared successfully.',
//            'data' => [
//                'sale' => $sale,
//                'receipt_url' => $receipt['receipt_url'],
//                'receipt_settings' => $receipt['receipt_settings'],
//                'print' => [
//                    'auto_print' => false,
//                    'printer' => $receipt['printer'] ?? null,
//                ],
//            ],
//        ]);
//    }
//
//
//    public function receiptPdfFileForSharing(Request $request, string $saleId)
//    {
//        $company = app('company');
//        $till = app('till');
//        $user = auth()->user();
//
//        $permission = hasPermission($user->role_id, 'view_sale', $company->id);
//
//        if ($permission !== true) {
//            return $permission;
//        }
//
//        $sale = SalesTransaction::with([
//            'company',
//            'branch',
//            'till',
//            'receiptLines.taxes.tax',
//            'receiptLines.product',
//        ])
//            ->where('company_id', $company->id)
//            ->where('branch_id', $till->branch_id)
//            ->where('id', $saleId)
//            ->first();
//
//        if (!$sale) {
//            return response()->json([
//                'message' => 'Receipt not found.',
//            ], 404);
//        }
//
//        $receipt = $this->prepareReceiptAfterFiscalisation($sale);
//        $filePath = $this->resolveReceiptPdfPath($receipt['receipt_url'] ?? null, $sale);
//
//        if (!$filePath || !is_file($filePath)) {
//            return response()->json([
//                'message' => 'Receipt PDF file was generated but could not be found on the server.',
//                'receipt_url' => $receipt['receipt_url'] ?? null,
//                'checked_paths' => $this->receiptPdfCandidatePaths($receipt['receipt_url'] ?? null, $sale),
//            ], 404);
//        }
//
//        $fileName = ($sale->invoice_number ?: 'receipt') . '.pdf';
//
//        return response()->file($filePath, [
//            'Content-Type' => 'application/pdf',
//            'Content-Disposition' => 'inline; filename="' . $fileName . '"',
//            'Cache-Control' => 'private, max-age=0, must-revalidate',
//        ]);
//    }
//
//    private function resolveReceiptPdfPath(?string $receiptUrl, SalesTransaction $sale): ?string
//    {
//        foreach ($this->receiptPdfCandidatePaths($receiptUrl, $sale) as $path) {
//            if ($path && is_file($path)) {
//                return $path;
//            }
//        }
//
//        return null;
//    }
//
//    private function receiptPdfCandidatePaths(?string $receiptUrl, SalesTransaction $sale): array
//    {
//        $paths = [];
//
//        $invoice = $sale->invoice_number ?: 'receipt';
//        $companyId = $sale->company_id;
//        $branchId = $sale->branch_id;
//
//        $relativeFromConvention = "receipts/{$companyId}/{$branchId}/{$invoice}.pdf";
//
//        /*
//         |--------------------------------------------------------------------------
//         | These candidates cover the receipt paths normally used by the receipt
//         | service: base path, public path, and storage public path.
//         |--------------------------------------------------------------------------
//         */
//        $paths[] = base_path("assets/{$relativeFromConvention}");
//        $paths[] = public_path("assets/{$relativeFromConvention}");
//        $paths[] = storage_path("app/public/assets/{$relativeFromConvention}");
//        $paths[] = storage_path("app/assets/{$relativeFromConvention}");
//
//        if ($receiptUrl) {
//            $urlPath = parse_url($receiptUrl, PHP_URL_PATH) ?: '';
//
//            /*
//             |--------------------------------------------------------------------------
//             | Example URL:
//             | /salexaapi/api/assets/receipts/company/branch/invoice.pdf
//             |--------------------------------------------------------------------------
//             */
//            $assetMarker = '/api/assets/';
//            $assetPos = strpos($urlPath, $assetMarker);
//
//            if ($assetPos !== false) {
//                $relativeAfterApiAssets = substr($urlPath, $assetPos + strlen($assetMarker));
//
//                $paths[] = base_path("assets/{$relativeAfterApiAssets}");
//                $paths[] = public_path("assets/{$relativeAfterApiAssets}");
//                $paths[] = storage_path("app/public/assets/{$relativeAfterApiAssets}");
//                $paths[] = storage_path("app/assets/{$relativeAfterApiAssets}");
//            }
//
//            $receiptsMarker = '/receipts/';
//            $receiptsPos = strpos($urlPath, $receiptsMarker);
//
//            if ($receiptsPos !== false) {
//                $relativeAfterReceipts = substr($urlPath, $receiptsPos + 1);
//
//                $paths[] = base_path("assets/{$relativeAfterReceipts}");
//                $paths[] = public_path("assets/{$relativeAfterReceipts}");
//                $paths[] = storage_path("app/public/assets/{$relativeAfterReceipts}");
//                $paths[] = storage_path("app/assets/{$relativeAfterReceipts}");
//            }
//        }
//
//        return array_values(array_unique(array_filter($paths)));
//    }
//
//    private function priceOverrideRequiresApproval(string $companyId, ?string $branchId): bool
//    {
//        $setting = \Modules\Authentication\Models\GeneralSetting::query()
//            ->where('company_id', $companyId)
//            ->where('setting_key', 'price_override_requires_manager_approval')
//            ->where('is_active', true)
//            ->where(function ($query) use ($branchId) {
//                $query->whereNull('branch_id');
//
//                if ($branchId) {
//                    $query->orWhere('branch_id', $branchId);
//                }
//            })
//            ->orderByRaw('CASE WHEN branch_id IS NULL THEN 1 ELSE 0 END')
//            ->first();
//
//        return $setting ? filter_var($setting->setting_value, FILTER_VALIDATE_BOOLEAN) : false;
//    }
//
//    private function validatePriceOverrideApproval(array $line, string $companyId, ?string $branchId): void
//    {
//        if (!$this->priceOverrideRequiresApproval($companyId, $branchId)) {
//            return;
//        }
//
//        $systemPrice = (float)($line['system_price'] ?? $line['original_price'] ?? $line['selling_price'] ?? 0);
//        $sellingPrice = (float)($line['price'] ?? $line['selling_price'] ?? 0);
//
//        if (round($systemPrice, 4) === round($sellingPrice, 4)) {
//            return;
//        }
//
//        $approvalId = $line['manager_approval']['approval_id'] ?? $line['price_override_approval_id'] ?? null;
//
//        if (!$approvalId) {
//            throw \Illuminate\Validation\ValidationException::withMessages([
//                'price_override' => ['Manager approval is required for price override.'],
//            ]);
//        }
//
//        $approval = \Illuminate\Support\Facades\DB::table('audit_trails')
//            ->where('id', $approvalId)
//            ->where('company_id', $companyId)
//            ->where('branch_id', $branchId)
//            ->where('module', 'manager-approval')
//            ->where('action', 'manager_approval_price_override')
//            ->where('status', 'success')
//            ->first();
//
//        if (!$approval) {
//            throw \Illuminate\Validation\ValidationException::withMessages([
//                'price_override' => ['Invalid or missing manager approval for price override.'],
//            ]);
//        }
//    }
//
//    /*
//    Usage inside sale line loop:
//
//    foreach ($request->items as $line) {
//        $this->validatePriceOverrideApproval($line, $company->id, $branchId);
//    }
//    */
//
//
//    public function convertCurrency(Request $request)
//    {
//        $company = app('company');
//
//        $validator = Validator::make($request->all(), [
//            'from' => [
//                'required',
//                'string',
//                Rule::exists('currencies', 'code')
//                    ->where('company_id', $company->id)
//                    ->where('is_active', true),
//            ],
//            'to' => [
//                'required',
//                'string',
//                Rule::exists('currencies', 'code')
//                    ->where('company_id', $company->id)
//                    ->where('is_active', true),
//            ],
//            'amount' => ['required', 'numeric', 'min:0'],
//        ]);
//
//        if ($validator->fails()) {
//            return response()->json([
//                'success' => false,
//                'message' => 'Validation error.',
//                'errors' => $validator->errors(),
//            ], 422);
//        }
//
//        try {
//            $from = strtoupper(trim($request->from));
//            $to = strtoupper(trim($request->to));
//            $amount = (float)$request->amount;
//
//            $convertedAmount = currencyConverter(
//                $from,
//                $to,
//                $amount,
//                $company->id
//            );
//
//            return response()->json([
//                'success' => true,
//                'message' => 'Currency converted successfully.',
//                'data' => [
//                    'from' => $from,
//                    'to' => $to,
//                    'amount' => round($amount, 4),
//                    'converted_amount' => round($convertedAmount, 4),
//                ],
//            ], 200);
//        } catch (\Throwable $e) {
//            return response()->json([
//                'success' => false,
//                'message' => 'Currency conversion failed.',
//                'error' => $e->getMessage(),
//            ], 500);
//        }
//    }
//
//
//    private function deductSaleStockAccordingToMode(
//        Product $product,
//        string  $branchId,
//        float   $quantity,
//        string  $saleId,
//        ?string $receiptLineId,
//        ?string $shiftSessionId,
//        string  $companyId
//    ): void
//    {
//        if ($this->shouldUseRecipeFrontStockForSale($companyId, $branchId, $product)) {
//            $deductedByRecipe = $this->deductMenuRecipeIngredientsFromFrontStock(
//                menuProduct: $product,
//                branchId: $branchId,
//                soldQuantity: $quantity,
//                saleId: $saleId,
//                receiptLineId: $receiptLineId,
//                shiftSessionId: $shiftSessionId
//            );
//
//            if ($deductedByRecipe) {
//                return;
//            }
//        }
//
//        if ((bool)$product->stock_tracking_enabled) {
//            $this->deductStock(
//                product: $product,
//                branchId: $branchId,
//                quantity: $quantity,
//                saleId: $saleId,
//                shiftSessionId: $shiftSessionId
//            );
//        }
//    }
//
//    private function shouldUseRecipeFrontStockForSale(
//        string  $companyId,
//        ?string $branchId,
//        Product $product
//    ): bool
//    {
//        if (!$this->companySettingService->isBohEnabled($companyId)) {
//            return false;
//        }
//
//        if (!$this->companySettingService->isFastFoodMode($companyId)) {
//            return false;
//        }
//
//        if (!$this->companySettingService->enabled($companyId, 'receive_stock_to_boh_first')) {
//            return false;
//        }
//
//        if (!$this->companySettingService->enabled($companyId, 'recipe_consumes_front_stock')) {
//            return false;
//        }
//
////        return in_array((string)$product->product_type, ['finished_good', 'service'], true);
//        return (string)$product->product_type === 'finished_good';
//    }
//
//    private function deductMenuRecipeIngredientsFromFrontStock(
//        Product $menuProduct,
//        string  $branchId,
//        float   $soldQuantity,
//        string  $saleId,
//        ?string $receiptLineId = null,
//        ?string $shiftSessionId = null
//    ): bool
//    {
//        $recipe = FoodRecipe::query()
//            ->where('company_id', $menuProduct->company_id)
//            ->where('menu_product_id', $menuProduct->id)
//            ->where('recipe_type', 'menu')
//            ->where('is_active', true)
//            ->with(['ingredients.ingredientProduct'])
//            ->latest()
//            ->first();
//
//        if (!$recipe) {
//            return false;
//        }
//
//        $lines = $recipe->ingredients ?? collect();
//
//        if ($lines->isEmpty()) {
//            return false;
//        }
//
//        $yieldQuantity = max((float)($recipe->yield_quantity ?? 1), 0.0001);
//        $till = null;
//
//        try {
//            $till = app('till');
//        } catch (\Throwable $e) {
//            $till = null;
//        }
//
//        foreach ($lines as $line) {
//            $ingredient = $line->ingredientProduct ?? null;
//
//            if (!$ingredient) {
//                throw new \Exception("Recipe ingredient product not found for menu item {$menuProduct->name}.");
//            }
//
//            $recipeNetQty = (float)($line->quantity ?? 0);
//            $wastagePercent = (float)($line->wastage_percent ?? 0);
//            $grossQtyPerYield = $recipeNetQty + ($recipeNetQty * $wastagePercent / 100);
//            $requiredQty = ($grossQtyPerYield / $yieldQuantity) * $soldQuantity;
//
//            if ($requiredQty <= 0) {
//                continue;
//            }
//
//            $stock = ProductStock::where('company_id', $menuProduct->company_id)
//                ->where('branch_id', $branchId)
//                ->where('product_id', $ingredient->id)
//                ->where('stock_area', 'front')
//                ->lockForUpdate()
//                ->first();
//
//            if (!$stock) {
//                $stock = ProductStock::create([
//                    'id' => (string)Str::uuid(),
//                    'company_id' => $menuProduct->company_id,
//                    'branch_id' => $branchId,
//                    'product_id' => $ingredient->id,
//                    'stock_area' => 'front',
//                    'quantity_on_hand' => 0,
//                    'reserved_quantity' => 0,
//                    'available_quantity' => 0,
//                ]);
//
//                $stock = ProductStock::where('id', $stock->id)
//                    ->lockForUpdate()
//                    ->first();
//            }
//
//            if (!$stock) {
//                throw new \Exception("FRONT stock record could not be created for ingredient {$ingredient->name}.");
//            }
//
//            if (!(bool)$ingredient->allow_negative_stock && (float)$stock->available_quantity < $requiredQty) {
//                throw new \Exception("Insufficient FRONT stock for ingredient {$ingredient->name}. Required: {$requiredQty}, Available: {$stock->available_quantity}.");
//            }
//
//            $cost = ProductCost::where('company_id', $menuProduct->company_id)
//                ->where('branch_id', $branchId)
//                ->where('product_id', $ingredient->id)
//                ->lockForUpdate()
//                ->first();
//
//            $unitCost = (float)(
//            $cost?->average_cost
//                ?: $cost?->last_cost
//                ?: 0
//            );
//
//            $currency = $cost?->currency ?? null;
//            $totalCost = round($unitCost * $requiredQty, 4);
//
//            if ($cost) {
//                $oldCostQuantity = (float)$cost->total_quantity;
//                $oldCostValue = (float)$cost->total_value;
//
//                $newCostQuantity = max(0, $oldCostQuantity - $requiredQty);
//                $newCostValue = max(0, $oldCostValue - $totalCost);
//
//                $cost->total_quantity = $newCostQuantity;
//                $cost->total_value = $newCostValue;
//                $cost->average_cost = $newCostQuantity > 0
//                    ? $newCostValue / $newCostQuantity
//                    : 0;
//
//                $cost->save();
//            }
//
//            $previousQuantityOnHand = (float)$stock->quantity_on_hand;
//            $previousAvailableQuantity = (float)$stock->available_quantity;
//            $previousReservedQuantity = (float)$stock->reserved_quantity;
//
//            $stock->quantity_on_hand = $previousQuantityOnHand - $requiredQty;
//            $stock->available_quantity = $previousAvailableQuantity - $requiredQty;
//            $stock->save();
//
//            DB::table('stock_movements')->insert([
//                'id' => (string)Str::uuid(),
//                'company_id' => $menuProduct->company_id,
//                'branch_id' => $branchId,
//                'till_id' => $till?->id,
//                'shift_session_id' => $shiftSessionId,
//                'product_id' => $ingredient->id,
//                'stock_area' => 'front',
//                'movement_type' => 'sale',
//                'quantity_in' => 0,
//                'quantity_out' => round($requiredQty, 4),
//                'balance_after' => round((float)$stock->quantity_on_hand, 4),
//                'unit_cost' => round($unitCost, 4),
//                'total_cost' => round($totalCost, 4),
//                'currency' => $currency,
//                'currency_code' => $currency,
//                'reference_type' => 'sale_recipe',
//                'reference_id' => $saleId,
//                'notes' => "Recipe ingredient consumed from FRONT stock for menu item: {$menuProduct->name}",
//                'metadata' => json_encode([
//                    'source' => 'sales',
//                    'movement_reason' => 'sale_recipe_front_stock_consumption',
//                    'sale_id' => $saleId,
//                    'receipt_line_id' => $receiptLineId,
//                    'recipe_id' => $recipe->id,
//                    'recipe_name' => $recipe->name ?? null,
//                    'menu_product' => [
//                        'id' => $menuProduct->id,
//                        'name' => $menuProduct->name,
//                        'code' => $menuProduct->code,
//                        'sku' => $menuProduct->sku,
//                    ],
//                    'ingredient_product' => [
//                        'id' => $ingredient->id,
//                        'name' => $ingredient->name,
//                        'code' => $ingredient->code,
//                        'sku' => $ingredient->sku,
//                    ],
//                    'recipe_line' => [
//                        'id' => $line->id ?? null,
//                        'net_quantity' => round($recipeNetQty, 4),
//                        'wastage_percent' => round($wastagePercent, 4),
//                        'gross_quantity_per_yield' => round($grossQtyPerYield, 4),
//                        'yield_quantity' => round($yieldQuantity, 4),
//                        'sold_quantity' => round($soldQuantity, 4),
//                        'required_quantity' => round($requiredQty, 4),
//                    ],
//                    'before' => [
//                        'quantity_on_hand' => round($previousQuantityOnHand, 4),
//                        'available_quantity' => round($previousAvailableQuantity, 4),
//                        'reserved_quantity' => round($previousReservedQuantity, 4),
//                    ],
//                    'after' => [
//                        'quantity_on_hand' => round((float)$stock->quantity_on_hand, 4),
//                        'available_quantity' => round((float)$stock->available_quantity, 4),
//                        'reserved_quantity' => round((float)$stock->reserved_quantity, 4),
//                    ],
//                    'cost' => [
//                        'unit_cost' => round($unitCost, 4),
//                        'total_cost' => round($totalCost, 4),
//                        'currency' => $currency,
//                        'product_cost_id' => $cost?->id,
//                    ],
//                ]),
//                'created_by' => auth()->id(),
//                'created_at' => now(),
//                'updated_at' => now(),
//            ]);
//        }
//
//        return true;
//    }
//
//    private function restoreMenuRecipeIngredientsToFrontStock(
//        Product $menuProduct,
//        string  $branchId,
//        float   $soldQuantity,
//        string  $originalSaleId,
//        string  $cancelTransactionId,
//        ?string $receiptLineId = null,
//        ?string $shiftSessionId = null,
//        ?string $userId = null,
//        ?string $reason = null
//    ): bool
//    {
//        $recipe = FoodRecipe::query()
//            ->where('company_id', $menuProduct->company_id)
//            ->where('menu_product_id', $menuProduct->id)
//            ->where('recipe_type', 'menu')
//            ->where('is_active', true)
//            ->with(['ingredients.ingredientProduct'])
//            ->latest()
//            ->first();
//
//        if (!$recipe || ($recipe->ingredients ?? collect())->isEmpty()) {
//            return false;
//        }
//
//        $yieldQuantity = max((float)($recipe->yield_quantity ?? 1), 0.0001);
//        $till = null;
//
//        try {
//            $till = app('till');
//        } catch (\Throwable $e) {
//            $till = null;
//        }
//
//        foreach ($recipe->ingredients as $line) {
//            $ingredient = $line->ingredientProduct ?? null;
//
//            if (!$ingredient) {
//                continue;
//            }
//
//            $recipeNetQty = (float)($line->quantity ?? 0);
//            $wastagePercent = (float)($line->wastage_percent ?? 0);
//            $grossQtyPerYield = $recipeNetQty + ($recipeNetQty * $wastagePercent / 100);
//            $restoreQty = ($grossQtyPerYield / $yieldQuantity) * $soldQuantity;
//
//            if ($restoreQty <= 0) {
//                continue;
//            }
//
//            $stock = ProductStock::firstOrCreate(
//                [
//                    'company_id' => $menuProduct->company_id,
//                    'branch_id' => $branchId,
//                    'product_id' => $ingredient->id,
//                    'stock_area' => 'front',
//                ],
//                [
//                    'id' => (string)Str::uuid(),
//                    'quantity_on_hand' => 0,
//                    'reserved_quantity' => 0,
//                    'available_quantity' => 0,
//                ]
//            );
//
//            $stock = ProductStock::where('id', $stock->id)->lockForUpdate()->first();
//
//            $previousQuantityOnHand = (float)$stock->quantity_on_hand;
//            $previousAvailableQuantity = (float)$stock->available_quantity;
//
//            $stock->quantity_on_hand = $previousQuantityOnHand + $restoreQty;
//            $stock->available_quantity = $previousAvailableQuantity + $restoreQty;
//            $stock->save();
//
//            $cost = ProductCost::where('company_id', $menuProduct->company_id)
//                ->where('branch_id', $branchId)
//                ->where('product_id', $ingredient->id)
//                ->first();
//
//            $unitCost = (float)(
//            $cost?->average_cost
//                ?: $cost?->last_cost
//                ?: 0
//            );
//            $totalCost = round($unitCost * $restoreQty, 4);
//
//            if ($cost) {
//                $oldCostQuantity = (float)$cost->total_quantity;
//                $oldCostValue = (float)$cost->total_value;
//
//                $newCostQuantity = $oldCostQuantity + $restoreQty;
//                $newCostValue = $oldCostValue + $totalCost;
//
//                $cost->total_quantity = $newCostQuantity;
//                $cost->total_value = $newCostValue;
//                $cost->average_cost = $newCostQuantity > 0
//                    ? $newCostValue / $newCostQuantity
//                    : 0;
//
//                $cost->save();
//            }
//
//            DB::table('stock_movements')->insert([
//                'id' => (string)Str::uuid(),
//                'company_id' => $menuProduct->company_id,
//                'branch_id' => $branchId,
//                'till_id' => $till?->id,
//                'shift_session_id' => $shiftSessionId,
//                'product_id' => $ingredient->id,
//                'stock_area' => 'front',
//                'movement_type' => 'sale_cancel',
//                'quantity_in' => round($restoreQty, 4),
//                'quantity_out' => 0,
//                'balance_after' => round((float)$stock->quantity_on_hand, 4),
//                'unit_cost' => round($unitCost, 4),
////                'total_cost' => round($unitCost * $restoreQty, 4),
//                'total_cost' => $totalCost,
//                'currency' => $cost?->currency ?? null,
//                'currency_code' => $cost?->currency ?? null,
//                'reference_type' => 'sale_cancel_recipe',
//                'reference_id' => $cancelTransactionId,
//                'notes' => "Cancellation restore of recipe ingredient to FRONT stock for menu item: {$menuProduct->name}",
//                'metadata' => json_encode([
//                    'source' => 'sales',
//                    'movement_reason' => 'sale_cancel_recipe_front_stock_restore',
//                    'original_sale_id' => $originalSaleId,
//                    'cancel_transaction_id' => $cancelTransactionId,
//                    'receipt_line_id' => $receiptLineId,
//                    'recipe_id' => $recipe->id,
//                    'recipe_name' => $recipe->name ?? null,
//                    'reason' => $reason,
//                    'menu_product_id' => $menuProduct->id,
//                    'menu_product_name' => $menuProduct->name,
//                    'ingredient_product_id' => $ingredient->id,
//                    'ingredient_product_name' => $ingredient->name,
//                    'sold_quantity' => round($soldQuantity, 4),
//                    'restore_quantity' => round($restoreQty, 4),
//                    'before' => [
//                        'quantity_on_hand' => round($previousQuantityOnHand, 4),
//                        'available_quantity' => round($previousAvailableQuantity, 4),
//                    ],
//                    'after' => [
//                        'quantity_on_hand' => round((float)$stock->quantity_on_hand, 4),
//                        'available_quantity' => round((float)$stock->available_quantity, 4),
//                    ],
//                ]),
//                'created_by' => $userId ?: auth()->id(),
//                'created_at' => now(),
//                'updated_at' => now(),
//            ]);
//        }
//
//        return true;
//    }
//
//
//}
//
//


namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Receipt\ReceiptService;
use App\Services\Enterprise\HospitalityMenuPriceService;
use App\Services\Sales\CreditCustomerService;
use App\Services\Sync\SyncSaleBundleService;
use App\Services\Sales\DiscountEngineService;
use App\Models\SyncNode;
use App\Models\MobileSaleReconciliation;
use App\Services\Sync\MobileSaleReconciliationService;
use App\Services\Sync\MobileSaleReplayWindow;
use App\Services\Shifts\ShiftPolicyService;
use App\Services\Shifts\ShiftSessionOpener;
use App\Services\Sync\MobileSaleSnapshot;
use App\Services\Zimra\ZimraReceiptService;
use App\Services\Zimra\MobileFiscalReceiptService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Authentication\Models\GeneralSetting;
use Modules\Authentication\Models\PosUser;
use Modules\Currency\Models\Currency;
use Modules\Currency\Models\PaymentType;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductBreakRule;
use Modules\Product\Models\ProductCost;
use Modules\Product\Models\ProductPrice;
use Modules\Product\Models\ProductStock;
use Modules\Product\Models\TradingDay;
use Modules\Sales\Models\CreditCustomer;
use Modules\Sales\Models\CreditCustomerPayment;
use Modules\Sales\Models\ReceiptLine;
use Modules\Sales\Models\ReceiptLineTaxe;
use Modules\Sales\Models\ReceiptNumberSequence;
use Modules\Sales\Models\SalesTransaction;
use Modules\Sales\Exceptions\HospitalityTableAuthorizationException;
use Modules\Sales\Exceptions\HospitalityTableConflictException;
use Modules\Sales\Exceptions\HospitalityTableScopeException;
use Modules\Sales\Services\HospitalityTableService;
use Modules\ZimraIntegration\Models\ZimraCompanySetting;
use Modules\ZimraIntegration\Models\ZimraReceipt;
use App\Services\CompanySettingService;
use App\Services\Inventory\InventoryAllocationService;
use Modules\FoodManufacturing\Models\FoodRecipe;
use Modules\Sales\Support\FoodIndustryTradingDaySupport;
use Modules\Sales\Support\FastFoodOrderSupport;
use Modules\Sales\Http\Controllers\Concerns\FastFoodSalesIntegration;

use function App\Helpers\currencyConverter;
use function App\Helpers\hasPermission;

class SalesController extends Controller
{
    use FoodIndustryTradingDaySupport, FastFoodOrderSupport, FastFoodSalesIntegration;

    private const MOBILE_SALE_MAX_FUTURE_SKEW_MINUTES = 5;

//    protected ReceiptService $receiptService;
//    protected ZimraReceiptService $zimraReceiptService;
//
//    public function __construct(
//        ReceiptService      $receiptService,
//        ZimraReceiptService $zimraReceiptService
//    )
//    {
//        $this->receiptService = $receiptService;
//        $this->zimraReceiptService = $zimraReceiptService;
//    }
    protected ReceiptService $receiptService;
    protected ZimraReceiptService $zimraReceiptService;
    protected CompanySettingService $companySettingService;
    protected ShiftPolicyService $shiftPolicyService;
    protected HospitalityMenuPriceService $hospitalityMenuPrices;
    protected HospitalityTableService $hospitalityTables;

    public function __construct(
        ReceiptService        $receiptService,
        ZimraReceiptService   $zimraReceiptService,
        CompanySettingService $companySettingService,
        ShiftPolicyService $shiftPolicyService,
        HospitalityMenuPriceService $hospitalityMenuPrices,
        HospitalityTableService $hospitalityTables
    )
    {
        $this->receiptService = $receiptService;
        $this->zimraReceiptService = $zimraReceiptService;
        $this->companySettingService = $companySettingService;
        $this->shiftPolicyService = $shiftPolicyService;
        $this->hospitalityMenuPrices = $hospitalityMenuPrices;
        $this->hospitalityTables = $hospitalityTables;
    }


//    public function store(Request $request)
//    {
//        $company = app('company');
//        $till = app('till');
//        $user = auth()->user();
//
//        $permission = hasPermission($user->role_id, 'create_sale', $company->id);
//
//        if ($permission !== true) {
//            return $permission;
//        }
//
//        $validator = Validator::make($request->all(), array_merge([
//            'currency_code' => [
//                'required',
//                Rule::exists('currencies', 'code')->where('company_id', $company->id),
//            ],
//
//            'payment_type_id' => [
//                'required',
//                Rule::exists('payment_types', 'id'),
//            ],
//
//            'amount_tendered' => ['required', 'numeric', 'min:0'],
//
//            'customer_name' => ['nullable', 'string', 'max:255'],
//            'customer_phone' => ['nullable', 'string', 'max:255'],
//            'customer_tax_number' => ['nullable', 'string', 'max:255'],
//            'notes' => ['nullable', 'string'],
//
//            'products' => ['required', 'array', 'min:1'],
//            'products.*.product_id' => [
//                'required',
//                Rule::exists('products', 'id')->where('company_id', $company->id),
//            ],
//            'products.*.quantity' => ['required', 'numeric', 'min:0.0001'],
//            'products.*.product_price_id' => ['nullable', 'string'],
//            'products.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
//
//            /*
//             |--------------------------------------------------------------------------
//             | Price Level Override
//             |--------------------------------------------------------------------------
//             | This is not manual price typing. The frontend sends the approved
//             | product_price_id and the audit_id created by applyPriceLevelOverride().
//             */
//            'products.*.price_level_override' => ['nullable', 'array'],
//            'products.*.price_level_override.audit_id' => ['nullable', 'string', 'max:36'],
//            'products.*.price_level_override.manager_approval_id' => ['nullable', 'string', 'max:36'],
//            'products.*.price_level_override.product_price_id' => ['nullable', 'string', 'max:36'],
//            'products.*.price_level_override.price_level_id' => ['nullable', 'string', 'max:36'],
//            'products.*.price_level_override.price' => ['nullable', 'numeric'],
//            'products.*.price_level_override.currency' => ['nullable', 'string', 'max:10'],
//
//            'products.*.discount_payload' => ['nullable', 'array'],
//            'products.*.discount_payload.discount_id' => ['nullable', 'string', 'max:36'],
//            'products.*.discount_payload.discount_code' => ['nullable', 'string', 'max:100'],
//            'products.*.discount_payload.discount_name' => ['nullable', 'string', 'max:255'],
//            'products.*.discount_payload.discount_type_id' => ['nullable', 'string', 'max:36'],
//            'products.*.discount_payload.scope' => ['nullable', 'in:line,cart,customer,employee,coupon,promotion,manual'],
//            'products.*.discount_payload.original_scope' => ['nullable', 'string', 'max:50'],
//            'products.*.discount_payload.discount_basis' => ['nullable', 'in:percentage,fixed_amount,fixed_price'],
//            'products.*.discount_payload.discount_value' => ['nullable', 'numeric'],
//            'products.*.discount_payload.gross_amount' => ['nullable', 'numeric'],
//            'products.*.discount_payload.discount_amount' => ['nullable', 'numeric'],
//            'products.*.discount_payload.net_amount' => ['nullable', 'numeric'],
//            'products.*.discount_payload.manager_approval_id' => ['nullable', 'string', 'max:36'],
//            'products.*.discount_payload.approved_by' => ['nullable', 'string', 'max:36'],
//            'products.*.discount_payload.reason' => ['nullable', 'string', 'max:1000'],
//
//            'credit_customer_id' => ['nullable', 'string', 'exists:credit_customers,id'],
//            'credit_po_number' => ['nullable', 'string', 'max:255'],
//        ]);
//
//        if ($validator->fails()) {
//            return response()->json([
//                'message' => 'Validation error.',
//                'errors' => $validator->errors(),
//            ], 422);
//        }
//
//        $shift = $this->getOpenShift(
//            companyId: $company->id,
//            branchId: $till->branch_id,
//            tillId: $till->id,
//            cashierId: $user->id
//        );
//
//        if (!$shift) {
//            return response()->json([
//                'message' => 'No open shift found for this cashier and till.',
//            ], 422);
//        }
//
//        $saleCurrency = Currency::where('company_id', $company->id)
//            ->where('code', $request->currency_code)
//            ->where('is_active', true)
//            ->first();
//
//        if (!$saleCurrency) {
//            return response()->json([
//                'message' => 'Selected currency is invalid or inactive.',
//            ], 422);
//        }
//
//        $baseCurrency = Currency::where('company_id', $company->id)
//            ->where('is_base_currency', true)
//            ->where('is_active', true)
//            ->first();
//
//        if (!$baseCurrency) {
//            return response()->json([
//                'message' => 'Base currency is not configured.',
//            ], 422);
//        }
//
//        $paymentType = PaymentType::where('id', $request->payment_type_id)
//            ->first();
//
//        if (!$paymentType) {
//            return response()->json([
//                'message' => 'Invalid payment type.',
//            ], 422);
//        }
//
//        $isCustomerCreditPayment = in_array(strtolower((string)$paymentType->money_code), [
//                'customer_credit',
//                'credit_customer',
//                'credit_account',
//                'account',
//                'CUSTOMER_CREDIT'
//            ], true) || str_contains(strtolower((string)$paymentType->name), 'credit');
//
//        $creditCustomer = null;
//
//        if ($isCustomerCreditPayment) {
//            if (!$request->credit_customer_id) {
//                return response()->json([
//                    'message' => 'Select a credit customer for customer account sale.',
//                ], 422);
//            }
//
//            $creditCustomer = CreditCustomer::query()
//                ->where('company_id', $company->id)
//                ->where('id', $request->credit_customer_id)
//                ->where(function ($query) use ($till) {
//                    $query->whereNull('branch_id')->orWhere('branch_id', $till->branch_id);
//                })
//                ->lockForUpdate()
//                ->first();
//
//            if (!$creditCustomer) {
//                return response()->json([
//                    'message' => 'Credit customer not found for this company or branch.',
//                ], 422);
//            }
//        }
//
//
//        $paymentValidation = $this->validatePaymentCurrency(
//            paymentType: $paymentType,
//            currencyCode: $request->currency_code
//        );
//
//        if ($paymentValidation !== true) {
//            return $paymentValidation;
//        }
//
//        DB::beginTransaction();
//
//        try {
//            $receiptNumber = $this->generateReceiptNumber(
//                companyId: $company->id,
//                branchId: $till->branch_id,
//                tillId: $till->id,
//                tillCode: $till->till_code ?? null
//            );
//
//            $exchangeRate = $this->getExchangeRate(
//                fromCode: $baseCurrency->code,
//                toCode: $saleCurrency->code,
//                companyId: $company->id
//            );
//
//            $shiftConfigurationId = $shift->shift_configuration_id ?? null;
//            $shiftSessionId = $shift->id ?? null;
//
//            $sale = SalesTransaction::create([
//                'id' => (string)Str::uuid(),
//
//                'company_id' => $company->id,
//                'branch_id' => $till->branch_id,
//                'till_id' => $till->id,
//
//                /*
//                 |--------------------------------------------------------------------------
//                 | Shift storage
//                 |--------------------------------------------------------------------------
//                 | shift_id stores the shift configuration ID.
//                 | shift_session_id stores the real cashier opened shift session ID.
//                 | Cashup and shift reports must use shift_session_id.
//                 */
//                'shift_id' => $shiftConfigurationId,
//                'shift_session_id' => $shiftSessionId,
//
//                'cashier_id' => $user->id,
//                'user_id' => $user->id,
//
//                'invoice_number' => $receiptNumber,
//                'transaction_date' => now(),
//
//                'document_type' => 'receipt',
//                'sale_type' => 'sale',
//                'status' => 'completed',
//
//                'currency_code' => $saleCurrency->code,
//                'base_currency_code' => $baseCurrency->code,
//                'exchange_rate' => $exchangeRate,
//
//                'subtotal_amount' => 0,
//                'discount_amount' => 0,
//                'tax_amount' => 0,
//                'total_amount' => 0,
//
//                'base_subtotal_amount' => 0,
//                'base_discount_amount' => 0,
//                'base_tax_amount' => 0,
//                'base_total_amount' => 0,
//
//                'payment_type_id' => $paymentType->id,
//                'payment_method' => $paymentType->name,
//
//                'amount_tendered' => (float)$request->amount_tendered,
//                'change_amount' => 0,
//                'change' => 0,
//
//                'customer_name' => $request->customer_name ?: 'Walk-in Customer',
//                'customer_phone' => $request->customer_phone,
//                'customer_tax_number' => $request->customer_tax_number,
//                'notes' => $request->notes,
//
//                'credit_customer_id' => $creditCustomer?->id,
//                'credit_account_number' => $creditCustomer?->account_number,
//                'is_credit_sale' => $isCustomerCreditPayment,
//                'credit_status' => $isCustomerCreditPayment ? 'pending' : null,
//
//                'metadata' => [
//                    'normal_sale' => true,
//                    'fiscalised' => false,
//                    'till_code' => $till->till_code ?? null,
//                    'till_name' => $till->till_name ?? null,
//                    'cashier_name' => $user->name ?? null,
//                    'shift_session_id' => $shiftSessionId,
//                    'shift_configuration_id' => $shiftConfigurationId,
//                ],
//            ]);
//
//            $totals = $this->createReceiptLines(
//                sale: $sale,
//                products: $request->products,
//                companyId: $company->id,
//                branchId: $till->branch_id,
//                saleCurrencyCode: $saleCurrency->code,
//                baseCurrencyCode: $baseCurrency->code,
//                cashierId: $user->id
//            );
//
////            $amountTendered = (float)$request->amount_tendered;
////            $saleTotal = round($totals['total'], 4);
////
////            if ($amountTendered + 0.0001 < $saleTotal) {
////                throw new \Exception('Amount tendered is less than sale total.');
////            }
//
//            $amountTendered = (float)$request->amount_tendered;
//            $saleTotal = round($totals['total'], 4);
//
//            if (!$isCustomerCreditPayment && $amountTendered + 0.0001 < $saleTotal) {
//                throw new \Exception('Amount tendered is less than sale total.');
//            }
//
//            $change = $isCustomerCreditPayment ? 0 : max($amountTendered - $saleTotal, 0);
//
//
//            $sale->update([
//                'subtotal_amount' => round($totals['subtotal'], 4),
//                'discount_amount' => round($totals['discount'], 4),
//                'tax_amount' => round($totals['tax'], 4),
//                'total_amount' => round($totals['total'], 4),
//
//                'base_subtotal_amount' => round($totals['base_subtotal'], 4),
//                'base_discount_amount' => round($totals['base_discount'], 4),
//                'base_tax_amount' => round($totals['base_tax'], 4),
//                'base_total_amount' => round($totals['base_total'], 4),
//
//                'change_amount' => round($change, 4),
//                'change' => round($change, 4),
//            ]);
//
//            if ($isCustomerCreditPayment) {
//                app(CreditCustomerService::class)->postCreditSale(
//                    sale: $sale,
//                    customer: $creditCustomer,
//                    amount: $saleTotal,
//                    currencyCode: $saleCurrency->code,
//                    baseCurrencyCode: $baseCurrency->code,
//                    exchangeRate: $exchangeRate,
//                    shiftSessionId: $shiftSessionId,
//                    tillId: $till->id,
//                    cashierId: $user->id,
//                    poNumber: $request->credit_po_number,
//                    notes: $request->notes
//                );
//
//                /* This line makes cashup know this amount was CUSTOMER CREDIT.
//                   It is not cash, but it appears in payment summary as Customer Credit. */
//                $this->saveSalePayment(
//                    sale: $sale,
//                    paymentType: $paymentType,
//                    companyId: $company->id,
//                    branchId: $till->branch_id,
//                    tillId: $till->id,
//                    shiftId: $shiftConfigurationId,
//                    shiftSessionId: $shiftSessionId,
//                    currencyCode: $saleCurrency->code,
//                    baseCurrencyCode: $baseCurrency->code,
//                    amountTendered: 0,
//                    saleTotal: $saleTotal,
//                    change: 0
//                );
//            } else {
//                $this->saveSalePayment(
//                    sale: $sale,
//                    paymentType: $paymentType,
//                    companyId: $company->id,
//                    branchId: $till->branch_id,
//                    tillId: $till->id,
//                    shiftId: $shiftConfigurationId,
//                    shiftSessionId: $shiftSessionId,
//                    currencyCode: $saleCurrency->code,
//                    baseCurrencyCode: $baseCurrency->code,
//                    amountTendered: $amountTendered,
//                    saleTotal: $saleTotal,
//                    change: $change
//                );
//            }
//
//            DB::commit();
//
//            /*
//             |--------------------------------------------------------------------------
//             | ZIMRA fiscalisation
//             |--------------------------------------------------------------------------
//             | The local sale is already safely committed. We now submit the fiscal
//             | receipt to FDMS. If fiscalisation is disabled, the sale is marked
//             | as skipped. If it fails, behaviour depends on company ZIMRA settings.
//             */
//            $fiscalisation = $this->fiscaliseSaleAfterCommit($sale);
//
//            if (($fiscalisation['blocked'] ?? false) === true) {
//                return response()->json([
//                    'message' => 'Sale saved, but ZIMRA fiscalisation failed.',
//                    'error' => $fiscalisation['error'] ?? 'Fiscalisation failed.',
//                    'data' => [
//                        'sale' => $fiscalisation['sale'] ?? $sale->fresh(),
//                        'fiscalisation' => $fiscalisation,
//                    ],
//                ], 409);
//            }
//
//            $sale = $fiscalisation['sale'] ?? $sale->fresh();
//
//            return $this->buildSaleCreatedResponse(
//                sale: $sale,
//                receiptNumber: $receiptNumber,
//                saleCurrencyCode: $saleCurrency->code,
//                baseCurrencyCode: $baseCurrency->code,
//                totals: $totals,
//                amountTendered: $amountTendered,
//                change: $change,
//                till: $till,
//                shift: $shift,
//                fiscalisation: $fiscalisation
//            );
//        } catch (\Throwable $e) {
//            if (DB::transactionLevel() > 0) {
//                DB::rollBack();
//            }
//
//            return response()->json([
//                'message' => 'Sale creation failed.',
//                'error' => $e->getMessage(),
//            ], 500);
//        }
//    }
    public function store(Request $request)
    {
        $company = app('company');
        $till = app('till');
        $user = auth()->user();
        /** @var MobileSaleSnapshot|null $mobileSaleSnapshot */
        $mobileSaleSnapshot = $request->attributes->get('mobile_sale_snapshot');
        /** @var SyncNode|null $authenticatedSyncNode */
        $authenticatedSyncNode = $request->attributes->get('sync_node');

        // The allow-listed platform owner may enter a tenant's Front Office
        // explicitly and operate a till for support/emergency cashing.  Their
        // identity is authenticated by the platform token, so do not look up
        // the owner's home-company role against the target company's role
        // assignments.  Ordinary tenant super-admins still use the normal
        // company permission catalogue.
        if (! app(\App\Services\Auth\SuperAdminService::class)->isPlatformAdministrator($user)) {
            $permission = hasPermission($user->role_id, 'create_sale', $company->id);

            if ($permission !== true) {
                return $permission;
            }
        }

        $androidNodes = config('salexa_sync.deployment_mode') === 'cloud'
            ? SyncNode::query()
                ->where('company_id', $company->id)
                ->where('branch_id', $till->branch_id)
                ->where('till_id', $till->id)
                ->where('deployment_mode', 'android')
                ->whereNotNull('activated_at')
                ->get(['status', 'revoked_at'])
            : collect();
        $isAndroidTill = $androidNodes->contains(fn ($node) => $node->status === 'active' && $node->revoked_at === null);
        if ($isAndroidTill && ! $mobileSaleSnapshot) {
            return response()->json([
                'message' => 'Android terminal sales must use the signed mobile sale endpoint.',
                'code' => 'SIGNED_MOBILE_ROUTE_REQUIRED',
            ], 403);
        }
        if ($androidNodes->isNotEmpty() && ! $isAndroidTill) {
            return response()->json([
                'message' => 'This Android till is no longer active. Ask an administrator to review the device lifecycle.',
                'code' => 'ANDROID_NODE_NOT_ACTIVE',
            ], 403);
        }

        $posSaleProductTypes = $this->allowedPosSaleProductTypes(
            companyId: (string) $company->id,
            branchId: (string) $till->branch_id
        );

        $currencyRules = $mobileSaleSnapshot
            ? ['required', 'string', 'max:10']
            : [
                'required',
                Rule::exists('currencies', 'code')->where(function ($query) use ($company, $till) {
                    $query->where('company_id', $company->id)
                        ->where('is_active', true)
                        ->where(function ($branchQuery) use ($till) {
                            $branchQuery->whereNull('branch_id')->orWhere('branch_id', $till->branch_id);
                        });
                }),
            ];
        $paymentTypeRules = $mobileSaleSnapshot
            ? ['required', 'string', 'max:36']
            : [
                'required',
                Rule::exists('payment_types', 'id')->where(function ($query) use ($company, $till) {
                    $query->where('company_id', $company->id)
                        ->where('is_active', true)
                        ->where(function ($branchQuery) use ($till) {
                            $branchQuery->whereNull('branch_id')->orWhere('branch_id', $till->branch_id);
                        });
                }),
            ];

        $validator = Validator::make($request->all(), array_merge([
            'client_sale_id' => ['nullable', 'string', 'max:100'],
            'hospitality_table_order_id' => [$mobileSaleSnapshot ? 'prohibited' : 'nullable', 'uuid'],
            'hospitality_table_order_revision' => [
                $mobileSaleSnapshot ? 'prohibited' : 'required_with:hospitality_table_order_id',
                'nullable',
                'integer',
                'min:1',
            ],
            'configuration_content_hash' => [
                $mobileSaleSnapshot ? 'required' : 'nullable',
                'string',
                'regex:/^[a-fA-F0-9]{64}$/',
            ],
            'fiscal_receipt' => [$mobileSaleSnapshot ? 'nullable' : 'prohibited', 'array'],
            'fiscal_receipt.fiscal_day_no' => ['required_with:fiscal_receipt', 'integer', 'min:1'],
            'fiscal_receipt.previous_receipt_hash' => ['nullable', 'string', 'max:1000'],
            'fiscal_receipt.qr_string' => ['required_with:fiscal_receipt', 'string', 'max:1000'],
            'fiscal_receipt.payload' => ['required_with:fiscal_receipt', 'array'],
            'fiscal_receipt.payload.receipt' => ['required_with:fiscal_receipt', 'array'],
            // Direct-cloud Android sales are queued offline. The date captured
            // when queued is part of the immutable accounting command.
            'business_date' => [$isAndroidTill ? 'required' : 'nullable', 'date_format:Y-m-d'],
            'occurred_at' => [$mobileSaleSnapshot ? 'required' : 'nullable', 'date'],
            'currency_code' => $currencyRules,

            /*
             |--------------------------------------------------------------------------
             | Backward-compatible single payment fields
             |--------------------------------------------------------------------------
             | These remain required so your old frontend still works.
             | New frontend can also send payments[] for split tender.
             */
            'payment_type_id' => $paymentTypeRules,

            'amount_tendered' => ['required', 'numeric', 'min:0'],

            /*
             |--------------------------------------------------------------------------
             | Optional multi-payment fields
             |--------------------------------------------------------------------------
             | Example:
             | payments: [
             |   { payment_type_id: "...cash...", currency_code: "USD", amount: 8 },
             |   { payment_type_id: "...card...", currency_code: "USD", amount: 2 }
             | ]
             */
            'payments' => ['nullable', 'array'],
            'payments.*.payment_type_id' => $mobileSaleSnapshot
                ? ['required_with:payments', 'string', 'max:36']
                : $paymentTypeRules,
            'payments.*.currency_code' => [
                'required_with:payments',
                'string',
                'max:10',
            ],
            'payments.*.amount' => [
                'required_with:payments',
                'numeric',
                'min:0',
            ],

            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:255'],
            'customer_tax_number' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],

            'products' => ['required', 'array', 'min:1'],
            'products.*.product_id' => [
                'required',
                Rule::exists('products', 'id')->where('company_id', $company->id),
            ],
            'products.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'products.*.product_price_id' => ['nullable', 'string'],
            'products.*.discount_amount' => ['nullable', 'numeric', 'min:0'],

            'products.*.price_level_override' => ['nullable', 'array'],
            'products.*.price_level_override.audit_id' => ['nullable', 'string', 'max:36'],
            'products.*.price_level_override.manager_approval_id' => ['nullable', 'string', 'max:36'],
            'products.*.price_level_override.product_price_id' => ['nullable', 'string', 'max:36'],
            'products.*.price_level_override.price_level_id' => ['nullable', 'string', 'max:36'],
            'products.*.price_level_override.price' => ['nullable', 'numeric'],
            'products.*.price_level_override.currency' => ['nullable', 'string', 'max:10'],

            'products.*.discount_payload' => ['nullable', 'array'],
            'products.*.discount_payload.discount_id' => ['nullable', 'string', 'max:36'],
            'products.*.discount_payload.discount_code' => ['nullable', 'string', 'max:100'],
            'products.*.discount_payload.discount_name' => ['nullable', 'string', 'max:255'],
            'products.*.discount_payload.discount_type_id' => ['nullable', 'string', 'max:36'],
            'products.*.discount_payload.scope' => ['nullable', 'in:line,cart,customer,employee,coupon,promotion,manual'],
            'products.*.discount_payload.original_scope' => ['nullable', 'string', 'max:50'],
            'products.*.discount_payload.discount_basis' => ['nullable', 'in:percentage,fixed_amount,fixed_price'],
            'products.*.discount_payload.discount_value' => ['nullable', 'numeric'],
            'products.*.discount_payload.gross_amount' => ['nullable', 'numeric'],
            'products.*.discount_payload.discount_amount' => ['nullable', 'numeric'],
            'products.*.discount_payload.net_amount' => ['nullable', 'numeric'],
            'products.*.discount_payload.manager_approval_id' => ['nullable', 'string', 'max:36'],
            'products.*.discount_payload.approved_by' => ['nullable', 'string', 'max:36'],
            'products.*.discount_payload.reason' => ['nullable', 'string', 'max:1000'],

            'credit_customer_id' => [
                'nullable',
                'string',
                Rule::exists('credit_customers', 'id')->where('company_id', $company->id),
            ],
            'credit_po_number' => ['nullable', 'string', 'max:255'],
            'post_change_to_credit_account' => ['nullable', 'boolean'],
            'change_credit_customer_id' => [
                Rule::requiredIf(fn () => $request->boolean('post_change_to_credit_account')),
                'nullable',
                'string',
                Rule::exists('credit_customers', 'id')->where('company_id', $company->id),
            ],
        ], $this->fastFoodSaleValidationRules()));

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $mobileOccurredAt = $mobileSaleSnapshot
            ? Carbon::parse((string) $request->input('occurred_at'))
                ->setTimezone((string) config('app.timezone', 'UTC'))
            : null;
        $shiftPolicyWarning = null;

        if ($mobileSaleSnapshot) {
            foreach ((array) $request->input('products', []) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $hasBareDiscount = (float) ($item['discount_amount'] ?? 0) > 0;
                $hasServerSensitiveAdjustment = collect([
                    'price_level_override',
                    'discount_payload',
                    'modifier_ids',
                    'modifiers',
                    'prep_message_ids',
                    'prep_messages',
                    'prep_note',
                    'prep_message',
                    'special_instructions',
                ])->contains(function (string $field) use ($item): bool {
                    if (! array_key_exists($field, $item)) {
                        return false;
                    }

                    $value = $item[$field];

                    return is_array($value)
                        ? $value !== []
                        : trim((string) $value) !== '';
                });

                if ($hasBareDiscount || $hasServerSensitiveAdjustment) {
                    return response()->json([
                        'message' => 'Offline Android discounts, price overrides and item customisations require an online server-authorized workflow and cannot be accepted from a queued sale.',
                        'code' => 'MOBILE_SALE_ADJUSTMENT_UNSUPPORTED',
                    ], 422);
                }
            }
        }

        if ($request->filled('client_sale_id')) {
            $existingMobileSale = SalesTransaction::query()
                ->with('till')
                ->where('company_id', $company->id)
                ->where('client_sale_id', $request->client_sale_id)
                ->first();

            if ($existingMobileSale) {
                if ($conflict = $this->existingSaleScopeConflictResponse($existingMobileSale, $company, $till, $user)) {
                    return $conflict;
                }

                return $this->buildDuplicateSaleCreatedResponse($existingMobileSale, $till);
            }
            if ($mobileSaleSnapshot) {
                $staged = MobileSaleReconciliation::query()
                    ->where('company_id', $company->id)
                    ->where('client_sale_id', (string) $request->client_sale_id)
                    ->first();
                if ($staged) {
                    return $this->pendingMobileSaleReconciliationResponse(
                        $request, $authenticatedSyncNode, $user, $mobileSaleSnapshot,
                        Carbon::parse((string) $request->input('occurred_at'))
                            ->setTimezone((string) config('app.timezone', 'UTC')),
                        (string) $staged->reason_code,
                        (array) $staged->context
                    );
                }
            }
        }

        $mobileFiscalService = app(MobileFiscalReceiptService::class);
        $registeredMobileFiscalDevice = $mobileSaleSnapshot && $authenticatedSyncNode instanceof SyncNode
            ? $mobileFiscalService->registrationFor($authenticatedSyncNode)
            : null;
        $mobileFiscalProof = $mobileSaleSnapshot && $request->has('fiscal_receipt');
        if ($registeredMobileFiscalDevice && ! $mobileFiscalProof) {
            return response()->json([
                'code' => 'MOBILE_FISCAL_PROOF_REQUIRED',
                'message' => 'This terminal is registered for local ZIMRA fiscalisation. Upload its signed fiscal receipt with the sale.',
            ], 422);
        }
        if ($mobileFiscalProof && (! $registeredMobileFiscalDevice
            || ! (bool) ZimraCompanySetting::query()->where('company_id', $company->id)->value('enabled'))) {
            // A signed/printed offline receipt may predate an administrator
            // disabling fiscalisation or revoking a device. Without an audited
            // history of those changes we cannot verify the old proof safely,
            // and we must not dead-letter or post it as an ordinary sale.
            return $this->pendingMobileSaleReconciliationResponse(
                $request, $authenticatedSyncNode, $user, $mobileSaleSnapshot,
                Carbon::parse((string) $request->input('occurred_at'))
                    ->setTimezone((string) config('app.timezone', 'UTC')),
                'FISCAL_REGISTRATION_OR_SETTING_CHANGED'
            );
        }

        // Preserve idempotent receipt replay even if a product was deactivated
        // after the original sale. New sales must pass the current till-bound
        // catalogue policy, which is checked again under lock in receipt lines.
        $productPolicyValidator = Validator::make($request->only('products'), [
            'products.*.product_id' => [
                $mobileSaleSnapshot
                    ? Rule::exists('products', 'id')->where('company_id', $company->id)
                    : Rule::exists('products', 'id')->where(function ($query) use ($company, $till, $posSaleProductTypes) {
                        $this->scopePosSaleProducts(
                            query: $query,
                            companyId: (string) $company->id,
                            branchId: (string) $till->branch_id,
                            allowedProductTypes: $posSaleProductTypes
                        );
                    }),
            ],
        ]);

        if ($productPolicyValidator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $productPolicyValidator->errors(),
            ], 422);
        }

        $saleCurrency = $mobileSaleSnapshot
            ? $mobileSaleSnapshot->currency((string) $request->currency_code)
            : Currency::where('company_id', $company->id)
                ->where('code', $request->currency_code)
                ->where('is_active', true)
                ->forBranchOrGlobal($till->branch_id)
                ->first();

        if (!$saleCurrency) {
            return response()->json([
                'message' => 'Selected currency is invalid or inactive.',
            ], 422);
        }

        $baseCurrency = $mobileSaleSnapshot
            ? $mobileSaleSnapshot->baseCurrency()
            : Currency::where('company_id', $company->id)
                ->where('is_base_currency', true)
                ->where('is_active', true)
                ->forBranchOrGlobal($till->branch_id)
                ->first();

        if (!$baseCurrency) {
            return response()->json([
                'message' => 'Base currency is not configured.',
            ], 422);
        }

        $paymentType = $mobileSaleSnapshot
            ? $mobileSaleSnapshot->paymentType((string) $request->payment_type_id)
            : PaymentType::query()
                ->where('id', $request->payment_type_id)
                ->where('company_id', $company->id)
                ->where('is_active', true)
                ->where(function ($query) use ($till) {
                    $query->whereNull('branch_id')->orWhere('branch_id', $till->branch_id);
                })
                ->first();

        if (!$paymentType) {
            return response()->json([
                'message' => 'Invalid payment type.',
            ], 422);
        }

        /*
         |--------------------------------------------------------------------------
         | Build payment rows
         |--------------------------------------------------------------------------
         | If payments[] is not sent, we create one row from the old fields.
         */
        $paymentRows = collect($request->input('payments', []));

        if ($paymentRows->isEmpty()) {
            $paymentRows = collect([
                [
                    'payment_type_id' => $request->payment_type_id,
                    'currency_code' => $request->currency_code,
                    'amount' => (float)$request->amount_tendered,
                ],
            ]);
        } else {
            $paymentRows = $paymentRows->values();
        }

        $multiPaymentValidation = $this->validateSaleMultiPayments(
            paymentRows: $paymentRows,
            saleCurrencyCode: $saleCurrency->code
        );

        if ($multiPaymentValidation !== true) {
            return $multiPaymentValidation;
        }

        $paymentTypeIds = $paymentRows
            ->pluck('payment_type_id')
            ->filter()
            ->unique()
            ->values();

        $paymentTypesById = $mobileSaleSnapshot
            ? $paymentTypeIds->mapWithKeys(fn ($id) => [
                (string) $id => $mobileSaleSnapshot->paymentType((string) $id),
            ])
            : PaymentType::query()
                ->whereIn('id', $paymentTypeIds)
                ->where('company_id', $company->id)
                ->where('is_active', true)
                ->where(function ($query) use ($till) {
                    $query->whereNull('branch_id')->orWhere('branch_id', $till->branch_id);
                })
                ->get()
                ->keyBy('id');

        if ($paymentTypesById->count() !== $paymentTypeIds->count()) {
            return response()->json([
                'message' => 'One or more payment types are invalid.',
            ], 422);
        }

        // payments[] is the accounting source of truth when split-payment data
        // is supplied. Keep the legacy top-level payment_type_id for request
        // compatibility, but never let it misclassify the actual tender rows.
        $paymentType = $paymentTypesById->get($paymentRows->first()['payment_type_id']);

        if ((string) $request->payment_type_id !== (string) $paymentType->id) {
            return response()->json([
                'message' => 'The primary payment type does not match the first payment row.',
                'errors' => [
                    'payment_type_id' => ['Use the same payment type as payments.0.payment_type_id.'],
                ],
            ], 422);
        }

        foreach ($paymentRows as $index => $paymentRow) {
            $rowPaymentType = $paymentTypesById->get($paymentRow['payment_type_id']);

            $paymentValidation = $this->validatePaymentCurrency(
                paymentType: $rowPaymentType,
                currencyCode: $saleCurrency->code
            );

            if ($paymentValidation !== true) {
                return $paymentValidation;
            }
        }

        $amountTendered = round(
            $paymentRows->sum(fn($row) => (float)($row['amount'] ?? 0)),
            4
        );

        $isCustomerCreditPayment = $this->isCustomerCreditPaymentType($paymentType);
        $customerAccountTenderCount = $paymentRows->filter(function ($paymentRow) use ($paymentTypesById) {
            return $this->isCustomerCreditPaymentType(
                $paymentTypesById->get($paymentRow['payment_type_id'])
            );
        })->count();

        if ($customerAccountTenderCount > 0 && ($customerAccountTenderCount !== 1 || $paymentRows->count() !== 1)) {
            return response()->json([
                'message' => 'Customer account tender must be the only payment row on a true credit sale.',
                'error' => 'Remove cash/card/split tenders or use a physical tender sale.',
            ], 422);
        }

        /*
         |--------------------------------------------------------------------------
         | Customer account sale is NOT money received
         |--------------------------------------------------------------------------
         | The customer takes the goods now, so stock and receipt lines are created
         | immediately. But no cash/card/payment value must be counted as received
         | until the customer later pays the account.
         */
        if ($isCustomerCreditPayment) {
            $amountTendered = 0;
            $paymentRows = collect([
                [
                    'payment_type_id' => $request->payment_type_id,
                    'currency_code' => $request->currency_code,
                    'amount' => 0,
                ],
            ]);
        }

        /*
         |--------------------------------------------------------------------------
         | Customer credit limitation
         |--------------------------------------------------------------------------
         | Keep credit sale as single-payment for now because it posts to customer
         | account ledger. Cash + card multi-payment is supported.
         */
        if ($paymentRows->count() > 1) {
            foreach ($paymentRows as $paymentRow) {
                $rowPaymentType = $paymentTypesById->get($paymentRow['payment_type_id']);

                if ($this->isCustomerCreditPaymentType($rowPaymentType)) {
                    return response()->json([
                        'message' => 'Customer credit cannot be mixed with other payment methods.',
                        'error' => 'Use customer credit as a single payment method.',
                    ], 422);
                }
            }
        }

        $postChangeToCreditAccount = $request->boolean('post_change_to_credit_account');
        $changeCreditCustomer = null;

        if ($postChangeToCreditAccount) {
            if ($isCustomerCreditPayment) {
                return response()->json([
                    'message' => 'Change cannot be posted from a credit sale because no money was received.',
                ], 422);
            }

            $changePermission = hasPermission(
                $user->role_id,
                'capture_credit_customer_payment',
                $company->id
            );
            if ($changePermission !== true) {
                return $changePermission;
            }

            $changeCreditCustomer = CreditCustomer::query()
                ->where('company_id', $company->id)
                ->where('id', $request->change_credit_customer_id)
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
                ->first();

            if (!$changeCreditCustomer) {
                return response()->json([
                    'message' => 'The selected customer account is not eligible to receive retained change for this branch.',
                ], 422);
            }
        }

        $creditCustomer = null;

        if ($isCustomerCreditPayment) {
            if (!$request->credit_customer_id) {
                return response()->json([
                    'message' => 'Select a credit customer for customer account sale.',
                ], 422);
            }

            $creditCustomer = CreditCustomer::query()
                ->where('company_id', $company->id)
                ->where('id', $request->credit_customer_id)
                ->where(function ($query) use ($till) {
                    $query->whereNull('branch_id')->orWhere('branch_id', $till->branch_id);
                })
                ->lockForUpdate()
                ->first();

            if (!$creditCustomer) {
                return response()->json([
                    'message' => 'Credit customer not found for this company or branch.',
                ], 422);
            }
        }

        $hospitalityTableOrder = null;

        DB::beginTransaction();

        try {
            /*
             |--------------------------------------------------------------------------
             | Food industry business trading date
             |--------------------------------------------------------------------------
             | Retail remains unchanged. Only BOH + Fast Food mode uses the open
             | trading day as business_date. transaction_date stays as real system
             | timestamp for audit.
             */
            $foodBusinessDatePayload = $mobileSaleSnapshot
                ? $this->historicalMobileSaleDatePayload(
                    $mobileSaleSnapshot, (string) $company->id, (string) $till->branch_id,
                    $mobileOccurredAt, (array) $request->input('products', [])
                )
                : $this->foodIndustrySaleDatePayload(
                    companyId: $company->id,
                    branchId: $till->branch_id
                );

            $authenticatedAndroidNodeActive = ! $isAndroidTill || (
                $authenticatedSyncNode instanceof SyncNode
                && SyncNode::query()
                    ->whereKey($authenticatedSyncNode->id)
                    ->where('company_id', $company->id)
                    ->where('branch_id', $till->branch_id)
                    ->where('till_id', $till->id)
                    ->where('deployment_mode', 'android')
                    ->where('status', 'active')
                    ->whereNotNull('activated_at')
                    ->whereNull('revoked_at')
                    ->lockForUpdate()
                    ->exists()
            );
            if (! $authenticatedAndroidNodeActive) {
                DB::rollBack();

                return response()->json([
                    'message' => 'This Android till is no longer active. Ask an administrator to review the device lifecycle.',
                    'code' => 'ANDROID_NODE_NOT_ACTIVE',
                ], 403);
            }

            if ($mobileSaleSnapshot && isset($foodBusinessDatePayload['reconciliation_reason'])) {
                DB::rollBack();
                return $this->pendingMobileSaleReconciliationResponse(
                    $request, $authenticatedSyncNode, $user, $mobileSaleSnapshot,
                    $mobileOccurredAt, (string) $foodBusinessDatePayload['reconciliation_reason'],
                    $foodBusinessDatePayload['reconciliation_context'] ?? []
                );
            }

            $authoritativeBusinessDate = ($foodBusinessDatePayload['food_industry_business_date_enabled'] ?? false)
                ? ($foodBusinessDatePayload['business_date'] ?? null)
                : $foodBusinessDatePayload['system_date'];
            if (! $mobileSaleSnapshot && $request->filled('business_date')
                && $request->input('business_date') !== $authoritativeBusinessDate) {
                DB::rollBack();

                return response()->json([
                    'message' => 'The queued sale belongs to a different trading date. Keep it on the device for manager reconciliation.',
                    'code' => 'BUSINESS_DATE_MISMATCH',
                    'data' => [
                        'client_business_date' => $request->input('business_date'),
                        'active_business_date' => $authoritativeBusinessDate,
                        'branch_id' => (string) $till->branch_id,
                    ],
                ], 409);
            }

            if (($foodBusinessDatePayload['sale_blocked'] ?? false) === true) {
                DB::rollBack();

                return response()->json([
                    'message' => 'Sale rejected by trading day control.',
                    'code' => 'HOSPITALITY_TRADING_DATE_BLOCKED',
                    'error' => $foodBusinessDatePayload['sale_block_reason']
                        ?? 'The current trading day is not allowed to sell.',
                    'data' => [
                        'business_date' => $foodBusinessDatePayload['business_date'] ?? null,
                        'system_date' => $foodBusinessDatePayload['system_date'] ?? null,
                        'trading_day_id' => $foodBusinessDatePayload['trading_day_id'] ?? null,
                        'trading_day_status' => $foodBusinessDatePayload['trading_day_status_at_sale'] ?? null,
                        'trading_date_is_future' => $foodBusinessDatePayload['trading_date_is_future'] ?? false,
                        'trading_date_is_past' => $foodBusinessDatePayload['trading_date_is_past'] ?? false,
                    ],
                ], 422);
            }

            $shift = $mobileSaleSnapshot
                ? $this->historicalMobileShift(
                    (string) $company->id, (string) $till->branch_id,
                    (string) $till->id, (string) $user->id, $mobileOccurredAt
                )
                : $this->getOpenShift(
                    companyId: $company->id,
                    branchId: $till->branch_id,
                    tillId: $till->id,
                    cashierId: $user->id
                );

            if (! $shift && $mobileSaleSnapshot) {
                DB::rollBack();

                return $this->pendingMobileSaleReconciliationResponse(
                    $request, $authenticatedSyncNode, $user, $mobileSaleSnapshot,
                    $mobileOccurredAt, 'HISTORICAL_SHIFT_NOT_FOUND'
                );
            }

            if (! $shift) {
                // A signed-in cashier never loses a sale to a missing shift
                // (signed in before shifts opened, or after a cash-up): open
                // one now, inside this sale's transaction.
                app(ShiftSessionOpener::class)->openFor(
                    (string) $company->id,
                    (string) $till->branch_id,
                    (string) $till->id,
                    (string) $user->id
                );
                $shift = $this->getOpenShift(
                    companyId: $company->id,
                    branchId: $till->branch_id,
                    tillId: $till->id,
                    cashierId: $user->id
                );
            }

            if ($mobileOccurredAt) {
                $shiftOpenedAt = Carbon::parse($shift->opened_at);
                $latestAllowedOccurrence = now()->addMinutes(self::MOBILE_SALE_MAX_FUTURE_SKEW_MINUTES);
                if (($mobileSaleSnapshot && ! MobileSaleReplayWindow::contains(
                        $mobileOccurredAt, $shift->opened_at, $shift->closed_at
                    ))
                    || (! $mobileSaleSnapshot && $mobileOccurredAt->lessThan($shiftOpenedAt))
                    || $mobileOccurredAt->greaterThan($latestAllowedOccurrence)) {
                    DB::rollBack();

                    if ($mobileSaleSnapshot && $mobileOccurredAt->lessThanOrEqualTo($latestAllowedOccurrence)) {
                        return $this->pendingMobileSaleReconciliationResponse(
                            $request, $authenticatedSyncNode, $user, $mobileSaleSnapshot,
                            $mobileOccurredAt, 'SALE_OUTSIDE_HISTORICAL_SHIFT',
                            ['shift_session_id' => $shift->id]
                        );
                    }

                    return response()->json([
                        'message' => 'The queued sale timestamp is outside the active cashier shift.',
                        'code' => 'MOBILE_SALE_OCCURRED_AT_INVALID',
                        'data' => [
                            'occurred_at' => $mobileOccurredAt->toIso8601String(),
                            'shift_opened_at' => $shiftOpenedAt->toIso8601String(),
                            'latest_allowed_at' => $latestAllowedOccurrence->toIso8601String(),
                            'server_time' => now()->toIso8601String(),
                        ],
                    ], 422);
                }
            }

            if ($mobileSaleSnapshot && DB::table('pos_cashups')
                ->where('company_id', $company->id)
                ->where('branch_id', $till->branch_id)
                ->where('shift_session_id', $shift->id)
                ->whereNull('deleted_at')
                ->exists()) {
                DB::rollBack();
                return $this->pendingMobileSaleReconciliationResponse(
                    $request, $authenticatedSyncNode, $user, $mobileSaleSnapshot,
                    $mobileOccurredAt, 'SHIFT_CASHUP_ALREADY_RECORDED',
                    ['shift_session_id' => $shift->id]
                );
            }

            $shiftCutoff = $this->shiftPolicyService->salesCutoff(
                (string) $company->id,
                (string) $till->branch_id,
                $shift
            );
            $shiftBlockReason = $this->shiftPolicyService->salesBlockReasonForCutoff(
                $shiftCutoff,
                $mobileOccurredAt ?? now()
            );
            if ($shiftBlockReason) {
                if (! $mobileSaleSnapshot) {
                    DB::rollBack();

                    return response()->json([
                        'message' => 'Shift cashup required.',
                        'error' => $shiftBlockReason,
                        'code' => 'SHIFT_SALES_CUTOFF_REACHED',
                    ], 423);
                }

                // A signed Android sale is immutable and may already have been
                // completed and printed offline. Persist it so one policy
                // rejection cannot deadlock the durable outbox. The violation
                // remains explicit in both sale metadata and the response.
                $shiftPolicyWarning = [
                    'code' => 'MOBILE_SALE_ACCEPTED_AFTER_SHIFT_CUTOFF',
                    'message' => $shiftBlockReason,
                    'occurred_at' => $mobileOccurredAt->toIso8601String(),
                    'cutoff_at' => $shiftCutoff['at']->toIso8601String(),
                    'accepted_at' => now()->toIso8601String(),
                    'sync_node_id' => $authenticatedSyncNode?->id,
                ];
            }

            if ($request->filled('hospitality_table_order_id')) {
                $hospitalityTableOrder = $this->hospitalityTables->claimForCheckout(
                    companyId: (string) $company->id,
                    till: $till,
                    actor: $user,
                    orderId: (string) $request->input('hospitality_table_order_id'),
                    expectedRevision: (int) $request->input('hospitality_table_order_revision'),
                    saleProducts: (array) $request->input('products', [])
                );
            }

            $receiptNumber = $this->generateReceiptNumber(
                companyId: $company->id,
                branchId: $till->branch_id,
                tillId: $till->id,
                tillCode: $till->till_code ?? null
            );

            $exchangeRate = $mobileSaleSnapshot
                ? $mobileSaleSnapshot->exchangeRate($baseCurrency->code, $saleCurrency->code)
                : $this->getExchangeRate(
                    fromCode: $baseCurrency->code,
                    toCode: $saleCurrency->code,
                    companyId: $company->id,
                    branchId: $till->branch_id
                );

            $shiftConfigurationId = $shift->shift_configuration_id ?? null;
            $shiftSessionId = $shift->id ?? null;

            $saleCreatePayload = [
                'id' => (string)Str::uuid(),

                'company_id' => $company->id,
                'branch_id' => $till->branch_id,
                'till_id' => $till->id,

                'shift_id' => $shiftConfigurationId,
                'shift_session_id' => $shiftSessionId,

                'cashier_id' => $user->id,
                'user_id' => $user->id,

                'invoice_number' => $receiptNumber,
                'client_sale_id' => $request->input('client_sale_id'),
                'transaction_date' => $mobileOccurredAt ?? now(),

                'document_type' => 'receipt',
                'sale_type' => 'sale',
                'status' => 'completed',

                'currency_code' => $saleCurrency->code,
                'base_currency_code' => $baseCurrency->code,
                'exchange_rate' => $exchangeRate,

                'subtotal_amount' => 0,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'total_amount' => 0,

                'base_subtotal_amount' => 0,
                'base_discount_amount' => 0,
                'base_tax_amount' => 0,
                'base_total_amount' => 0,

                /*
                 |--------------------------------------------------------------------------
                 | Primary payment fields
                 |--------------------------------------------------------------------------
                 | Store the first/selected payment type for backward compatibility.
                 | Detailed split payments are saved into sale payment records below.
                 */
                'payment_type_id' => $paymentType->id,
                'payment_method' => $paymentType->name,

                'amount_tendered' => $amountTendered,
                'change_amount' => 0,
                'change' => 0,

                'customer_name' => $request->customer_name ?: 'Walk-in Customer',
                'customer_phone' => $request->customer_phone,
                'customer_tax_number' => $request->customer_tax_number,
                'notes' => $request->notes,

                'credit_customer_id' => $creditCustomer?->id,
                'credit_account_number' => $creditCustomer?->account_number,
                'is_credit_sale' => $isCustomerCreditPayment,
                'credit_status' => $isCustomerCreditPayment ? 'pending' : null,

                'metadata' => [
                    'normal_sale' => true,
                    'fiscalised' => false,
                    'till_code' => $till->till_code ?? null,
                    'shift_policy_warning' => $shiftPolicyWarning,
                    'till_name' => $till->till_name ?? null,
                    'cashier_name' => $user->name ?? null,
                    'shift_session_id' => $shiftSessionId,
                    'shift_configuration_id' => $shiftConfigurationId,
                    'multi_payment' => $paymentRows->count() > 1,
                    'payment_count' => $paymentRows->count(),
                    'exchange_rate_snapshot' => [
                        'base_currency_code' => $baseCurrency->code,
                        'sale_currency_code' => $saleCurrency->code,
                        'rate' => $exchangeRate,
                        'captured_at' => now()->toIso8601String(),
                    ],
                    'mobile_configuration_snapshot' => $mobileSaleSnapshot ? [
                        'content_hash' => $mobileSaleSnapshot->contentHash(),
                        'generated_at' => $mobileSaleSnapshot->generatedAt(),
                        'verified_server_side' => true,
                    ] : null,
                    'food_industry_business_date_enabled' => $foodBusinessDatePayload['food_industry_business_date_enabled'],
                    'business_date' => $foodBusinessDatePayload['business_date'],
                    'client_business_date' => $request->input('business_date'),
                    'client_occurred_at' => $request->input('occurred_at'),
                    'trading_day_id' => $foodBusinessDatePayload['trading_day_id'],
                    'trading_day_status_at_sale' => $foodBusinessDatePayload['trading_day_status_at_sale'],
                    'system_date_at_sale' => $foodBusinessDatePayload['system_date'] ?? null,
                    'trading_date_is_future' => $foodBusinessDatePayload['trading_date_is_future'] ?? false,
                    'trading_date_is_past' => $foodBusinessDatePayload['trading_date_is_past'] ?? false,
                    'trading_date_matches_system_date' => $foodBusinessDatePayload['trading_date_matches_system_date'] ?? false,
                    'hospitality_table_order_id' => $hospitalityTableOrder?->id,
                    'hospitality_table_id' => $hospitalityTableOrder?->hospitality_table_id,
                    'hospitality_table_name' => $hospitalityTableOrder?->table?->name,
                    'hospitality_service_type' => $hospitalityTableOrder?->service_type,
                ],
            ];

            /*
             |--------------------------------------------------------------------------
             | Store business trading date on the sale row immediately
             |--------------------------------------------------------------------------
             | Do not wait for metadata only. Reports must read sales_transactions.business_date
             | and trading_day_id directly. transaction_date remains the real system timestamp.
             */
            if ($mobileSaleSnapshot || ($foodBusinessDatePayload['food_industry_business_date_enabled'] ?? false) === true) {
                if (Schema::hasColumn('sales_transactions', 'business_date')) {
                    $saleCreatePayload['business_date'] = $foodBusinessDatePayload['business_date'];
                }

                if (Schema::hasColumn('sales_transactions', 'trading_day_id')) {
                    $saleCreatePayload['trading_day_id'] = $foodBusinessDatePayload['trading_day_id'];
                }
            }

            $sale = SalesTransaction::create($saleCreatePayload);

            $sale = $this->assignFastFoodOrderAfterSaleCreate(
                sale: $sale,
                companyId: $company->id,
                branchId: $till->branch_id,
                tillId: $till->id,
                tillCode: $till->till_code ?? null
            );

            $this->applyFoodIndustryBusinessDateToSale($sale, $foodBusinessDatePayload);

            $totals = $this->createReceiptLines(
                sale: $sale,
                products: $request->products,
                companyId: $company->id,
                branchId: $till->branch_id,
                allowedProductTypes: $posSaleProductTypes,
                saleCurrencyCode: $saleCurrency->code,
                baseCurrencyCode: $baseCurrency->code,
                exchangeRate: $exchangeRate,
                cashierId: $user->id,
                mobileSaleSnapshot: $mobileSaleSnapshot
            );

            $saleTotal = round($totals['total'], 4);
            $paymentSettlement = null;

            if ($isCustomerCreditPayment) {
                $change = 0.0;
            } else {
                $paymentSettlement = $this->settleSalePaymentRows(
                    paymentRows: $paymentRows,
                    paymentTypesById: $paymentTypesById,
                    saleTotal: $saleTotal
                );
                $paymentRows = $paymentSettlement['rows'];
                $amountTendered = $paymentSettlement['amount_tendered'];
                $change = $paymentSettlement['change'];
            }

            if ($postChangeToCreditAccount && $change <= 0) {
                throw new \InvalidArgumentException('There is no positive sale change to post to the customer account.');
            }

            $creditedChange = 0.0;
            $changeAccountAmount = null;
            $changeBaseAmount = null;
            $changeAccountCurrencyCode = null;
            $changePaymentType = null;
            $returnedChange = $change;
            $changeAccountResult = null;

            if ($postChangeToCreditAccount) {
                $changeCreditCustomer = CreditCustomer::query()
                    ->where('company_id', $company->id)
                    ->where('id', $changeCreditCustomer->id)
                    ->where('is_active', true)
                    ->where(function ($query) use ($till) {
                        $query->whereNull('branch_id')->orWhere('branch_id', $till->branch_id);
                    })
                    ->lockForUpdate()
                    ->firstOrFail();

                $accountStatus = strtolower(trim((string) $changeCreditCustomer->status));
                if (!in_array($accountStatus, ['approved', 'blocked', 'suspended'], true)) {
                    throw new \InvalidArgumentException('This customer account is not approved to receive retained change.');
                }

                $changeAccountCurrencyCode = strtoupper((string) ($changeCreditCustomer->currency_code ?: $saleCurrency->code));
                $fullAccountAmount = match ($changeAccountCurrencyCode) {
                    strtoupper($saleCurrency->code) => round($change, 4),
                    strtoupper($baseCurrency->code) => $this->convertSaleAmountToBase($change, $exchangeRate),
                    default => round((float) currencyConverter(
                        strtoupper($saleCurrency->code),
                        $changeAccountCurrencyCode,
                        $change,
                        $company->id,
                        $till->branch_id
                    ), 4),
                };

                if ($fullAccountAmount <= 0) {
                    throw new \InvalidArgumentException('Retained change could not be converted into the customer account currency.');
                }

                $creditedChange = round($change, 4);
                $changeAccountAmount = $fullAccountAmount;

                // Restricted accounts may settle debt but may never receive a
                // prepaid balance. Credit only the outstanding portion and return
                // any remainder to the customer in the original sale currency.
                if (in_array($accountStatus, ['blocked', 'suspended'], true)) {
                    $amountDue = round(max(0, (float) $changeCreditCustomer->current_balance), 4);
                    if ($amountDue <= 0) {
                        throw new \InvalidArgumentException('Blocked or suspended accounts may only receive money against an outstanding debt.');
                    }

                    if ($fullAccountAmount > $amountDue) {
                        $accountPerSaleUnit = $fullAccountAmount / max($change, 0.0001);
                        $creditedChange = round(min($change, $amountDue / $accountPerSaleUnit), 4);
                        $changeAccountAmount = $amountDue;
                    }
                }

                if ($creditedChange <= 0 || $changeAccountAmount <= 0) {
                    throw new \InvalidArgumentException('No retained change is eligible to post to this customer account.');
                }

                $returnedChange = round(max(0, $change - $creditedChange), 4);
                $changeBaseAmount = $this->convertSaleAmountToBase($creditedChange, $exchangeRate);
                $changePaymentType = $paymentTypesById->get($paymentSettlement['change_payment_type_id']);
            }

            $sale->update([
                'subtotal_amount' => round($totals['subtotal'], 4),
                'discount_amount' => round($totals['discount'], 4),
                'tax_amount' => round($totals['tax'], 4),
                'total_amount' => round($totals['total'], 4),

                'base_subtotal_amount' => round($totals['base_subtotal'], 4),
                'base_discount_amount' => round($totals['base_discount'], 4),
                'base_tax_amount' => round($totals['base_tax'], 4),
                'base_total_amount' => round($totals['base_total'], 4),

                'amount_tendered' => round($amountTendered, 4),
                'change_amount' => round($returnedChange, 4),
                'change' => round($returnedChange, 4),
            ]);

            if ($isCustomerCreditPayment) {
                app(CreditCustomerService::class)->postCreditSale(
                    sale: $sale,
                    customer: $creditCustomer,
                    amount: $saleTotal,
                    currencyCode: $saleCurrency->code,
                    baseCurrencyCode: $baseCurrency->code,
                    exchangeRate: $exchangeRate,
                    shiftSessionId: $shiftSessionId,
                    tillId: $till->id,
                    cashierId: $user->id,
                    poNumber: $request->credit_po_number,
                    notes: $request->notes
                );

                /*
                 |--------------------------------------------------------------------------
                 | Do NOT save sales_transaction_payments for credit sale here.
                 |--------------------------------------------------------------------------
                 | A credit sale is goods taken on account. It deducts stock and posts
                 | to the customer ledger, but it is not money received. Real money is
                 | inserted into sales_transaction_payments only when the customer pays
                 | through CreditCustomerService::recordPayment().
                 */
            } else {
                /*
                 |--------------------------------------------------------------------------
                 | Save each canonical split payment
                 |--------------------------------------------------------------------------
                 | Example total 10:
                 | Cash 8, Card 2 => two payment records.
                 |
                 | Only a requiresAmountTendered row can carry change. Other
                 | methods are capped to their exact remaining allocation.
                 */
                $remainingReturnedChange = $returnedChange;

                foreach ($paymentRows as $paymentRow) {
                    $rowPaymentType = $paymentTypesById->get($paymentRow['payment_type_id']);
                    $rowAmountTendered = round((float) $paymentRow['amount_tendered'], 4);
                    $rowSaleTotal = round((float) $paymentRow['sale_total'], 4);
                    $rowComputedChange = round((float) $paymentRow['change_amount'], 4);
                    $rowChange = round(min($rowComputedChange, $remainingReturnedChange), 4);

                    $this->saveSalePayment(
                        sale: $sale,
                        paymentType: $rowPaymentType,
                        companyId: $company->id,
                        branchId: $till->branch_id,
                        tillId: $till->id,
                        shiftId: $shiftConfigurationId,
                        shiftSessionId: $shiftSessionId,
                        currencyCode: $saleCurrency->code,
                        baseCurrencyCode: $baseCurrency->code,
                        exchangeRate: $exchangeRate,
                        amountTendered: $rowAmountTendered,
                        saleTotal: $rowSaleTotal,
                        change: $rowChange
                    );

                    $remainingReturnedChange = round(max(0, $remainingReturnedChange - $rowChange), 4);
                }

                if ($postChangeToCreditAccount) {
                    $changePayment = app(CreditCustomerService::class)->recordPayment(array_filter([
                        'company_id' => $company->id,
                        'branch_id' => $till->branch_id,
                        'credit_customer_id' => $changeCreditCustomer->id,
                        'till_id' => $till->id,
                        'shift_id' => $shiftConfigurationId,
                        'shift_session_id' => $shiftSessionId,
                        'cashier_id' => $user->id,
                        'payment_type_id' => $changePaymentType->id,
                        'amount' => round($creditedChange, 4),
                        'currency_code' => strtoupper($saleCurrency->code),
                        'base_currency_code' => strtoupper($baseCurrency->code),
                        'account_amount' => $changeAccountAmount,
                        'base_amount' => $changeBaseAmount,
                        'exchange_rate' => $changeAccountAmount
                            ? round($changeAccountAmount / max($creditedChange, 0.0001), 8)
                            : null,
                        'allow_account_credit' => true,
                        // The sale payment row already carries the actual tender.
                        // This separate account document is retained change, not a
                        // second receipt of physical money.
                        'cashup_amount_tendered' => 0,
                        'reference' => $receiptNumber,
                        'notes' => 'Sale change retained and posted to customer account.',
                        'idempotency_key' => 'sale-change:' . $sale->id,
                        'metadata' => [
                            'source' => 'pos_sale_change_credit',
                            'source_sales_transaction_id' => $sale->id,
                            'source_invoice_number' => $receiptNumber,
                            'source_payment_type_id' => $changePaymentType->id,
                            'source_payment_name' => $changePaymentType->name,
                            'change_currency_code' => strtoupper($saleCurrency->code),
                            'computed_change_amount' => round($change, 4),
                            'change_amount' => round($creditedChange, 4),
                            'returned_cash_change' => round($returnedChange, 4),
                        ],
                    ], static fn ($value) => $value !== null));

                    $changeCreditCustomer = $changeCreditCustomer->fresh();
                    $accountAmount = round((float) data_get($changePayment->metadata, 'account_amount', 0), 4);
                    $changeReceiptPath = "/api/pos/credit-customers/{$changeCreditCustomer->id}/payments/{$changePayment->id}/receipt/pdf";
                    $changeAccountResult = [
                        'payment_id' => $changePayment->id,
                        'payment_number' => $changePayment->payment_number,
                        'reference' => $changePayment->reference,
                        'customer_id' => $changeCreditCustomer->id,
                        'account_number' => $changeCreditCustomer->account_number,
                        'customer_name' => $changeCreditCustomer->name,
                        'computed_change_amount' => round($change, 4),
                        'change_amount' => round($creditedChange, 4),
                        'returned_change_amount' => round($returnedChange, 4),
                        'change_currency_code' => strtoupper($saleCurrency->code),
                        'account_amount' => $accountAmount,
                        'account_currency_code' => $changeAccountCurrencyCode,
                        'applied_to_existing_debt' => round((float) data_get($changePayment->metadata, 'applied_to_existing_debt', 0), 4),
                        'overpayment_credit_created' => round((float) data_get($changePayment->metadata, 'overpayment_credit_created', 0), 4),
                        'credit_balance_after' => round(max(0, -1 * (float) $changeCreditCustomer->current_balance), 4),
                        'balance_after' => round((float) $changeCreditCustomer->current_balance, 4),
                        'receipt_print_url' => $changeReceiptPath . '?paper=80mm&download=0',
                        'receipt_download_url' => $changeReceiptPath . '?paper=80mm&download=1',
                    ];

                    $sale->forceFill([
                        'metadata' => array_merge((array) ($sale->metadata ?? []), [
                            'change_disposition' => 'customer_account',
                            'computed_change_amount' => round($change, 4),
                            'returned_change_amount' => round($returnedChange, 4),
                            'change_credit_payment_id' => $changePayment->id,
                            'change_credit_payment_number' => $changePayment->payment_number,
                            'change_credit_customer_id' => $changeCreditCustomer->id,
                            'change_credit_account_number' => $changeCreditCustomer->account_number,
                            'change_credit_currency_code' => strtoupper($saleCurrency->code),
                            'change_credit_amount' => round($creditedChange, 4),
                            'change_credit_account_amount' => $accountAmount,
                            'change_credit_account_currency_code' => $changeAccountCurrencyCode,
                        ]),
                    ])->save();
                }
            }

            if ($hospitalityTableOrder) {
                $hospitalityTableOrder = $this->hospitalityTables->completeCheckout(
                    $hospitalityTableOrder,
                    $sale->fresh(),
                    $till,
                    $user
                );
            }

            if ($mobileFiscalProof) {
                $mobileFiscalService->attach(
                    $authenticatedSyncNode,
                    $sale->fresh(),
                    (array) $request->input('fiscal_receipt')
                );
            }

            DB::commit();

            // Android has already signed (and may have printed) its immutable
            // receipt offline. Never issue a second Central SubmitReceipt.
            $fiscalisation = $mobileFiscalProof
                ? [
                    'status' => 'device_pending',
                    'blocked' => false,
                    'enabled' => true,
                    'message' => 'The Android-signed fiscal receipt is queued for FDMS acknowledgement.',
                    'sale' => $sale->fresh(),
                ]
                : $this->fiscaliseSaleAfterCommit($sale);

            if (($fiscalisation['blocked'] ?? false) === true) {
                $payload = [
                    'message' => 'Sale saved, but ZIMRA fiscalisation failed.',
                    'error' => $fiscalisation['error'] ?? 'Fiscalisation failed.',
                    'data' => [
                        'sale' => $fiscalisation['sale'] ?? $sale->fresh(),
                        'fiscalisation' => $fiscalisation,
                        'change_posted_to_account' => $changeAccountResult !== null,
                        'credited_change' => $changeAccountResult,
                    ],
                ];

                if ($mobileSaleSnapshot) {
                    $fiscalisationWarning = [
                        'code' => 'SALE_SAVED_FISCALISATION_PENDING',
                        'message' => $fiscalisation['error'] ?? 'Fiscalisation failed and requires retry.',
                    ];
                    $payload['code'] = 'SALE_SAVED_FISCALISATION_PENDING';
                    $payload['warnings'] = array_values(array_filter([
                        $shiftPolicyWarning,
                        $fiscalisationWarning,
                    ]));

                    // The financial sale is already committed. A successful
                    // 202 lets the Android outbox acknowledge it once instead
                    // of retrying the same receipt forever; fiscal recovery is
                    // tracked independently by the persisted fiscal status.
                    return response()->json($payload, 202);
                }

                return response()->json($payload, 409);
            }

            $sale = $fiscalisation['sale'] ?? $sale->fresh();

            return $this->buildSaleCreatedResponse(
                sale: $sale,
                receiptNumber: (string) $sale->invoice_number,
                saleCurrencyCode: $saleCurrency->code,
                baseCurrencyCode: $baseCurrency->code,
                totals: $totals,
                amountTendered: $amountTendered,
                change: $returnedChange,
                till: $till,
                shift: $shift,
                fiscalisation: $fiscalisation,
                changeAccount: $changeAccountResult,
                warning: $shiftPolicyWarning
            );
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            // Concurrent double-clicks can race past the initial lookup. The
            // company/client_sale_id unique key is the final authority; if the
            // other request committed, return that complete original receipt.
            if ($request->filled('client_sale_id')) {
                $existingSale = SalesTransaction::query()
                    ->with('till')
                    ->where('company_id', $company->id)
                    ->where('client_sale_id', $request->client_sale_id)
                    ->first();

                if ($existingSale) {
                    if ($conflict = $this->existingSaleScopeConflictResponse($existingSale, $company, $till, $user)) {
                        return $conflict;
                    }

                    return $this->buildDuplicateSaleCreatedResponse($existingSale, $till);
                }
            }

            $status = match (true) {
                $e instanceof HospitalityTableConflictException => 409,
                $e instanceof HospitalityTableAuthorizationException,
                $e instanceof HospitalityTableScopeException => 403,
                $e instanceof \InvalidArgumentException,
                $e instanceof \DomainException => 422,
                default => 500,
            };

            return response()->json([
                'message' => 'Sale creation failed.',
                'error' => $e->getMessage(),
                'code' => match (true) {
                    $e instanceof HospitalityTableConflictException => 'HOSPITALITY_TABLE_CONFLICT',
                    $e instanceof HospitalityTableScopeException => 'HOSPITALITY_TABLE_SCOPE_DENIED',
                    default => null,
                },
                'data' => $e instanceof HospitalityTableConflictException
                    ? ['current_revision' => $e->currentRevision]
                    : null,
            ], $status);
        }
    }

    /**
     * Resolve POS product eligibility from the authoritative till branch mode.
     * CompanySettingService owns the branch override/fallback rules; the sale
     * endpoint must not infer hospitality mode from client input.
     *
     * @return array<int, string>
     */
    private function allowedPosSaleProductTypes(string $companyId, string $branchId): array
    {
        return (bool) config('servnest.hospitality_only', true)
            || $this->companySettingService->businessMode($companyId, $branchId) === 'hospitality'
            ? ['finished_good']
            : ['simple', 'parent', 'child', 'finished_good'];
    }

    /**
     * Apply the same server-side catalogue boundary to validation and the
     * transaction-time locked product lookup.
     *
     * @param  array<int, string>  $allowedProductTypes
     */
    private function scopePosSaleProducts(
        $query,
        string $companyId,
        string $branchId,
        array $allowedProductTypes
    ) {
        $query = $query
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereIn(DB::raw('LOWER(product_type)'), $allowedProductTypes)
            ->where(function ($branchQuery) use ($branchId) {
                $branchQuery->where('is_global', true)
                    ->orWhereNull('branch_id')
                    ->orWhere('branch_id', $branchId);
            });

        if ((bool) config('servnest.hospitality_only', true)) {
            return $this->hospitalityMenuPrices->scopeSaleableProducts(
                $query,
                $companyId,
                $branchId,
                (string) config('servnest.catalogue.default_channel', 'POS')
            );
        }

        return $query;
    }

    /**
     * Convert client payment amounts into the canonical sale allocation.
     *
     * A single card/mobile-money style method does not ask the cashier for a
     * tender amount, so its client value is only a compatibility field and the
     * server replaces it with the exact sale total. In a split payment, those
     * methods are explicit allocations and may not be the row that creates
     * change. Only methods configured with requiresAmountTendered may carry an
     * over-tender and change.
     *
     * @return array{rows: \Illuminate\Support\Collection, amount_tendered: float, change: float, change_payment_type_id: ?string}
     */
    private function settleSalePaymentRows($paymentRows, $paymentTypesById, float $saleTotal): array
    {
        $rows = collect($paymentRows)->values();
        $remainingTotal = round($saleTotal, 4);
        $singlePayment = $rows->count() === 1;
        $settledRows = collect();

        foreach ($rows as $index => $paymentRow) {
            $paymentType = $paymentTypesById->get($paymentRow['payment_type_id'] ?? null);
            if (!$paymentType) {
                throw new \InvalidArgumentException('One or more payment types are invalid.');
            }

            $requestedAmount = round((float) ($paymentRow['amount'] ?? 0), 4);
            $requiresAmountTendered = (bool) $paymentType->requiresAmountTendered;

            if ($singlePayment && !$requiresAmountTendered) {
                $amountTendered = $remainingTotal;
                $rowSaleTotal = $remainingTotal;
                $rowChange = 0.0;
            } else {
                if ($requiresAmountTendered
                    && $requestedAmount > $remainingTotal + 0.0001
                    && $rows->slice($index + 1)->contains(
                        fn (array $laterRow) => (float) ($laterRow['amount'] ?? 0) > 0.0001
                    )) {
                    throw new \InvalidArgumentException(
                        'A payment row that creates change must be the final positive split payment.'
                    );
                }

                $rowSaleTotal = round(min($requestedAmount, max($remainingTotal, 0)), 4);
                $amountTendered = $requiresAmountTendered
                    ? $requestedAmount
                    : $rowSaleTotal;
                $rowChange = $requiresAmountTendered
                    ? round(max($requestedAmount - $rowSaleTotal, 0), 4)
                    : 0.0;
            }

            $remainingTotal = round(max($remainingTotal - $rowSaleTotal, 0), 4);
            $settledRows->push(array_merge($paymentRow, [
                'amount' => $amountTendered,
                'amount_tendered' => $amountTendered,
                'sale_total' => $rowSaleTotal,
                'change_amount' => $rowChange,
            ]));
        }

        if ($remainingTotal > 0.0001) {
            throw new \InvalidArgumentException('Amount tendered is less than sale total.');
        }

        $changeRow = $settledRows
            ->filter(fn (array $row) => (float) $row['change_amount'] > 0.0001)
            ->last();

        return [
            'rows' => $settledRows,
            'amount_tendered' => round($settledRows->sum('amount_tendered'), 4),
            'change' => round($settledRows->sum('change_amount'), 4),
            'change_payment_type_id' => $changeRow['payment_type_id'] ?? null,
        ];
    }

    private function validateSaleMultiPayments($paymentRows, string $saleCurrencyCode)
    {
        if ($paymentRows->isEmpty()) {
            return response()->json([
                'message' => 'At least one payment method is required.',
            ], 422);
        }

        $saleCurrency = strtoupper((string)$saleCurrencyCode);

        foreach ($paymentRows as $index => $paymentRow) {
            $lineNumber = $index + 1;

            $paymentCurrency = strtoupper((string)($paymentRow['currency_code'] ?? ''));

            if ($paymentCurrency === '') {
                return response()->json([
                    'message' => "Payment line {$lineNumber} currency is required.",
                ], 422);
            }

            if ($paymentCurrency !== $saleCurrency) {
                return response()->json([
                    'message' => 'Multi-payment currency mismatch.',
                    'error' => "Payment line {$lineNumber} uses {$paymentCurrency}, but sale currency is {$saleCurrency}. All split payments must use the same currency.",
                ], 422);
            }

            $amount = (float)($paymentRow['amount'] ?? 0);

            if ($amount < 0) {
                return response()->json([
                    'message' => "Payment line {$lineNumber} amount must not be negative.",
                ], 422);
            }

            if (empty($paymentRow['payment_type_id'])) {
                return response()->json([
                    'message' => "Payment line {$lineNumber} payment type is required.",
                ], 422);
            }
        }

        return true;
    }

    private function isCustomerCreditPaymentType($paymentType): bool
    {
        $moneyCode = strtolower((string)($paymentType->money_code ?? ''));
        $name = strtolower((string)($paymentType->name ?? ''));

        $isAccountCode = in_array($moneyCode, [
                'customer_credit',
                'credit_customer',
                'credit_account',
                'account',
            ], true);

        // Do not classify ordinary tenders such as "Credit Card" as a customer
        // account sale merely because their display name contains "credit".
        $isExplicitAccountName = in_array(trim($name), [
            'customer credit',
            'customer account',
            'credit account',
            'on account',
            'account sale',
        ], true) || (
            str_contains($name, 'customer')
            && (str_contains($name, 'credit') || str_contains($name, 'account'))
        );

        return $isAccountCode || $isExplicitAccountName;
    }

    private function createReceiptLines(
        SalesTransaction $sale,
        array            $products,
        string           $companyId,
        string           $branchId,
        array            $allowedProductTypes,
        string           $saleCurrencyCode,
        string           $baseCurrencyCode,
        float            $exchangeRate,
        string           $cashierId,
        ?MobileSaleSnapshot $mobileSaleSnapshot = null
    ): array
    {
        $subtotal = 0;
        $discount = 0;
        $tax = 0;
        $total = 0;

        $baseSubtotal = 0;
        $baseDiscount = 0;
        $baseTax = 0;
        $baseTotal = 0;

        $lineNo = 1;

        foreach ($products as $item) {
            $productQuery = $mobileSaleSnapshot
                ? Product::withTrashed()->where('company_id', $companyId)
                : Product::query();
            if ($mobileSaleSnapshot) {
                // The acknowledged, branch-scoped server snapshot authorizes
                // this historical product; current catalogue edits do not
                // invalidate an already signed/printed offline receipt.
                $mobileSaleSnapshot->productFiscalFields((string) $item['product_id']);
            } else {
                $this->scopePosSaleProducts(
                    query: $productQuery,
                    companyId: $companyId,
                    branchId: $branchId,
                    allowedProductTypes: $allowedProductTypes
                );
            }

            $product = $productQuery
                ->where('id', $item['product_id'])
                ->with([
                    'uom',
                    'tax', // legacy single tax, safe to keep
                    'taxes' => function ($query) use ($branchId) {
                        $query->where(function ($sub) use ($branchId) {
                            $sub->whereNull('branch_id')
                                ->orWhere('branch_id', $branchId);
                        })
                            ->where('is_active', true)
                            ->where(function ($sub) {
                                $sub->whereNull('starts_at')
                                    ->orWhere('starts_at', '<=', now());
                            })
                            ->where(function ($sub) {
                                $sub->whereNull('ends_at')
                                    ->orWhere('ends_at', '>=', now());
                            })
                            ->with('tax')
                            ->orderByRaw('CASE WHEN branch_id IS NULL THEN 1 ELSE 0 END');
                    },
                ])
                ->lockForUpdate()
                ->first();

            if (!$product) {
                throw new \InvalidArgumentException(
                    'A selected product is inactive or is not available for POS sale from this till.'
                );
            }

            if ($mobileSaleSnapshot) {
                // Replace today's tax relationship with the server-held tax
                // profile, name and HS code this terminal used while offline.
                $product->setRelation('taxes', $mobileSaleSnapshot->productTaxes((string) $product->id));
                foreach ($mobileSaleSnapshot->productFiscalFields((string) $product->id) as $field => $value) {
                    $product->setAttribute($field, $value);
                }
            }

            $quantity = (float)$item['quantity'];

            $fastFoodCustomisation = $this->resolveFastFoodSaleLineCustomisationOrEmpty(
                item: $item,
                product: $product,
                companyId: $companyId,
                branchId: $branchId
            );

            $price = $this->resolveProductPrice(
                product: $product,
                productPriceId: $item['product_price_id'] ?? null,
                companyId: $companyId,
                branchId: $branchId,
                mobileSaleSnapshot: $mobileSaleSnapshot
            );

            $priceLevelOverride = $this->validatePriceLevelOverrideForSaleLine(
                line: $item,
                selectedPrice: $price,
                product: $product,
                companyId: $companyId,
                branchId: $branchId,
                cashierId: $cashierId
            );

            $priceCurrency = $price->currency ?? $baseCurrencyCode;

            if (strtoupper($priceCurrency) === strtoupper($saleCurrencyCode)) {
                $unitPrice = (float)$price->price;
                $baseUnitPrice = $this->convertSaleAmountToBase(
                    amount: $unitPrice,
                    exchangeRate: $exchangeRate
                );
            } else {
                $baseUnitPrice = $mobileSaleSnapshot
                    ? $mobileSaleSnapshot->convert(
                        (float) $price->price,
                        $priceCurrency,
                        $baseCurrencyCode
                    )
                    : (float)currencyConverter(
                        $priceCurrency,
                        $baseCurrencyCode,
                        (float)$price->price,
                        $companyId,
                        $branchId
                    );
                $unitPrice = $this->convertBaseAmountToSale(
                    amount: $baseUnitPrice,
                    exchangeRate: $exchangeRate
                );
            }

            /*
             |--------------------------------------------------------------------------
             | Fast food modifier price uplift
             |--------------------------------------------------------------------------
             | Modifiers are not separate selling products, but they can carry prices.
             | The selected modifier prices increase the parent menu item unit price.
             | Example: Burger 5.00 + Extra Cheese 0.50 x 2 = unit price 6.00.
             */
            $baseMenuUnitPrice = $unitPrice;
            $baseMenuBaseUnitPrice = $baseUnitPrice;
            $modifierUnitPrice = $this->fastFoodModifierUnitPriceForSale(
                customisation: $fastFoodCustomisation,
                saleCurrencyCode: $saleCurrencyCode,
                companyId: $companyId,
                branchId: $branchId
            );
            $modifierBaseUnitPrice = $this->convertSaleAmountToBase(
                amount: $modifierUnitPrice,
                exchangeRate: $exchangeRate
            );

            $unitPrice += $modifierUnitPrice;
            $baseUnitPrice += $modifierBaseUnitPrice;

            $discountPayload = $item['discount_payload'] ?? null;
            $discountApproval = null;

            $lineGrossAmount = round($unitPrice * $quantity, 4);
            $lineDiscount = (float)($item['discount_amount'] ?? 0);

            if ($discountPayload && (float)($discountPayload['discount_amount'] ?? 0) > 0) {
                /** @var DiscountEngineService $discountEngine */
                $discountEngine = app(DiscountEngineService::class);

                $discountApproval = $discountEngine->validateDiscountApproval(
                    line: $item,
                    companyId: $companyId,
                    branchId: $branchId,
                    cashierId: $cashierId
                );

                $lineDiscount = min((float)$discountPayload['discount_amount'], $lineGrossAmount);
            } else {
                $lineDiscount = min($lineDiscount, $lineGrossAmount);
            }

            $lineCalc = $this->calculateLine(
                product: $product,
                unitPrice: $unitPrice,
                quantity: $quantity,
                discountAmount: $lineDiscount
            );

            $baseLineDiscount = $this->convertSaleAmountToBase(
                amount: $lineDiscount,
                exchangeRate: $exchangeRate
            );

            $baseLineCalc = $this->calculateLine(
                product: $product,
                unitPrice: $baseUnitPrice,
                quantity: $quantity,
                discountAmount: $baseLineDiscount
            );

            $receiptLine = ReceiptLine::create([
                'id' => (string)Str::uuid(),
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'sales_transaction_id' => $sale->id,
                'product_id' => $product->id,
                'product_price_id' => $price->pricing_source === 'menu_item_channel'
                    ? null
                    : $price->id,
                'price_level_id' => $price->price_level_id,
                'line_no' => $lineNo++,
                'line_name' => $product->name,
                'product_code' => $product->code,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'hs_code' => $product->hs_code ?? null,
                'line_quantity' => $quantity,
                'line_price' => round($unitPrice, 4),
                'line_subtotal' => round($lineCalc['subtotal'], 4),
                'line_discount' => round($lineDiscount, 4),
                'line_tax' => round($lineCalc['tax'], 4),
                'line_total' => round($lineCalc['total'], 4),
                'base_line_price' => round($baseUnitPrice, 4),
                'base_line_subtotal' => round($baseLineCalc['subtotal'], 4),
                'base_line_discount' => round($baseLineDiscount, 4),
                'base_line_tax' => round($baseLineCalc['tax'], 4),
                'base_line_total' => round($baseLineCalc['total'], 4),
                'currency_code' => $saleCurrencyCode,
                'base_currency_code' => $baseCurrencyCode,
                'metadata' => [
                    'price_currency' => $priceCurrency,
                    'pricing_source' => $price->pricing_source ?: 'product_price',
                    'menu_item_channel_id' => $price->menu_item_channel_id,
                    'sales_channel' => $price->channel_code,
                    'exchange_rate_snapshot' => $exchangeRate,
                    'uom' => $product->uom?->name ?? null,
                    'uom_code' => $product->uom?->code ?? null,
                    'product_type' => $product->product_type ?? null,
                    'parent_product_id' => $product->parent_product_id ?? null,
                    'price_level_override' => $priceLevelOverride,
                    'discount_payload' => $discountPayload,
                    'discount_approval' => $discountApproval,
                    'fast_food' => $fastFoodCustomisation,
                    'base_menu_unit_price' => round($baseMenuUnitPrice, 4),
                    'modifier_unit_price' => round($modifierUnitPrice, 4),
                    'prep_messages' => $fastFoodCustomisation['prep_messages'] ?? [],
                    'prep_note' => $fastFoodCustomisation['prep_note'] ?? null,
                    'modifiers' => $fastFoodCustomisation['modifiers'] ?? [],
                    'kitchen' => [
                        'print_required' => (bool)($fastFoodCustomisation['enabled'] ?? false),
                        'prep_messages' => $fastFoodCustomisation['prep_messages'] ?? [],
                        'prep_note' => $fastFoodCustomisation['prep_note'] ?? null,
                        'modifiers' => $fastFoodCustomisation['modifiers'] ?? [],
                    ],
                    'tax_summary' => [
                        'gross' => round($lineCalc['gross'], 4),
                        'subtotal_excluding_tax' => round($lineCalc['subtotal'], 4),
                        'inclusive_tax' => round($lineCalc['inclusive_tax'], 4),
                        'exclusive_tax' => round($lineCalc['exclusive_tax'], 4),
                        'tax' => round($lineCalc['tax'], 4),
                        'total' => round($lineCalc['total'], 4),
                    ],
                ],
            ]);

            $this->applyFastFoodLineCustomisationToReceiptLine(
                receiptLine: $receiptLine,
                sale: $sale,
                customisation: $fastFoodCustomisation
            );

            if ($discountPayload && (float)($discountPayload['discount_amount'] ?? 0) > 0) {
                /** @var DiscountEngineService $discountEngine */
                $discountEngine = app(DiscountEngineService::class);

                $originalScope = $discountPayload['original_scope'] ?? $discountPayload['scope'] ?? 'line';
                $saleScope = ($discountPayload['scope'] ?? 'line') === 'cart' ? 'cart' : 'line';

                $discountEngine->recordSaleDiscount([
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'sales_transaction_id' => $sale->id,
                    'receipt_line_id' => $receiptLine->id,
                    'discount_id' => $discountPayload['discount_id'] ?? null,
                    'discount_type_id' => $discountPayload['discount_type_id'] ?? null,
                    'discount_code' => $discountPayload['discount_code'] ?? null,
                    'discount_name' => $discountPayload['discount_name'] ?? null,
                    'scope' => $saleScope,
                    'original_scope' => $originalScope,
                    'discount_basis' => $discountPayload['discount_basis'] ?? 'percentage',
                    'discount_value' => $discountPayload['discount_value'] ?? 0,
                    'gross_amount' => $lineGrossAmount,
                    'discount_amount' => $lineDiscount,
                    'net_amount' => max(0, $lineGrossAmount - $lineDiscount),
                    'currency' => $saleCurrencyCode,
                    'cashier_id' => $cashierId,
                    'approved_by' => $discountApproval['approved_by'] ?? null,
                    'manager_approval_id' => $discountPayload['manager_approval_id'] ?? null,
                    'reason' => $discountPayload['reason'] ?? null,
                    'metadata' => [
                        'approval' => $discountApproval,
                        'payload' => $discountPayload,
                        'original_scope' => $originalScope,
                        'line_no' => $receiptLine->line_no,
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                    ],
                ]);
            }

            foreach ($lineCalc['taxes'] as $taxRow) {
                ReceiptLineTaxe::create([
                    'id' => (string)Str::uuid(),
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'sales_transaction_id' => $sale->id,
                    'receipt_line_id' => $receiptLine->id,
                    'tax_id' => $taxRow['tax_id'],
                    'tax_code' => $taxRow['tax_code'],
                    'tax_percent' => $taxRow['tax_percent'],
                    'is_inclusive' => $taxRow['is_inclusive'],
                    'obliged_to_zimra' => $taxRow['obliged_to_zimra'],
                    'tax_amount' => round($taxRow['tax_amount'], 4),
                    'sales_amount_with_tax' => round($taxRow['sales_amount_with_tax'], 4),
                    'currency_code' => $saleCurrencyCode,
                    'metadata' => [
                        'fiscal_tax_id' => $taxRow['fiscal_tax_id'],
                        'tax_name' => $taxRow['tax_name'],
                        'tax_type' => $taxRow['tax_type'],
                        'taxable_amount' => round($taxRow['taxable_amount'], 4),
                        'inclusive_tax' => $taxRow['is_inclusive'],
                    ],
                ]);
            }

//            if ((bool)$product->stock_tracking_enabled) {
//                $this->deductStock(
//                    product: $product,
//                    branchId: $branchId,
//                    quantity: $quantity,
//                    saleId: $sale->id
//                );
//            }

            $this->deductSaleStockAccordingToMode(
                product: $product,
                branchId: $branchId,
                quantity: $quantity,
                saleId: $sale->id,
                receiptLineId: $receiptLine->id,
                shiftSessionId: $sale->shift_session_id,
                companyId: $companyId,
                recipeAdjustments: $this->fastFoodModifierRecipeAdjustmentsForLine(
                    menuProduct: $product,
                    soldQuantity: $quantity,
                    customisation: $fastFoodCustomisation
                )
            );

            $this->deductFastFoodModifierRecipesForLine(
                menuProduct: $product,
                branchId: $branchId,
                soldQuantity: $quantity,
                saleId: $sale->id,
                receiptLineId: $receiptLine->id,
                shiftSessionId: $sale->shift_session_id,
                customisation: $fastFoodCustomisation
            );


            $subtotal += $lineCalc['subtotal'];
            $discount += $lineDiscount;
            $tax += $lineCalc['tax'];
            $total += $lineCalc['total'];

            $baseSubtotal += $baseLineCalc['subtotal'];
            $baseDiscount += $baseLineDiscount;
            $baseTax += $baseLineCalc['tax'];
            $baseTotal += $baseLineCalc['total'];
        }

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax' => $tax,
            'total' => $total,
            'base_subtotal' => $baseSubtotal,
            'base_discount' => $baseDiscount,
            'base_tax' => $baseTax,
            'base_total' => $baseTotal,
        ];
    }

    private function calculateLine(
        Product $product,
        float   $unitPrice,
        float   $quantity,
        float   $discountAmount = 0
    ): array
    {
        $grossBeforeDiscount = max($unitPrice * $quantity, 0);
        $discountAmount = min(max($discountAmount, 0), $grossBeforeDiscount);
        $gross = max($grossBeforeDiscount - $discountAmount, 0);

        $productTaxes = $product->taxes ?? collect();

        $inclusivePercentRate = 0.0;
        $inclusiveFixedTotal = 0.0;

        foreach ($productTaxes as $productTax) {
            $tax = $productTax->tax;

            if (!$tax || !(bool)($tax->is_active ?? true)) {
                continue;
            }

            $taxType = $tax->type ?? 'percentage';
            $taxRate = (float)($tax->rate ?? $tax->tax_rate ?? 0);

            if ((bool)$productTax->is_inclusive) {
                if ($taxType === 'fixed') {
                    $inclusiveFixedTotal += $taxRate * $quantity;
                } else {
                    $inclusivePercentRate += $taxRate / 100;
                }
            }
        }

        /*
         * Inclusive logic:
         * If a product is inclusive, its selling price already contains those taxes.
         * Example: price 115 with 15% inclusive tax -> base = 100, tax = 15, total = 115.
         * Fixed inclusive tax is also already inside the price, so remove it before
         * extracting percentage-inclusive tax.
         */
        $amountAfterInclusiveFixed = max($gross - $inclusiveFixedTotal, 0);

        $taxableBase = $inclusivePercentRate > 0
            ? $amountAfterInclusiveFixed / (1 + $inclusivePercentRate)
            : $amountAfterInclusiveFixed;

        $taxRows = [];
        $inclusiveTaxTotal = 0.0;
        $exclusiveTaxTotal = 0.0;

        foreach ($productTaxes as $productTax) {
            $tax = $productTax->tax;

            if (!$tax || !(bool)($tax->is_active ?? true)) {
                continue;
            }

            $taxType = $tax->type ?? 'percentage';
            $taxRate = (float)($tax->rate ?? $tax->tax_rate ?? 0);
            $isInclusive = (bool)$productTax->is_inclusive;

            if ($taxType === 'fixed') {
                $taxAmount = $taxRate * $quantity;
                $taxPercent = 0;
            } else {
                $taxAmount = $taxableBase * ($taxRate / 100);
                $taxPercent = $taxRate;
            }

            if ($isInclusive) {
                $inclusiveTaxTotal += $taxAmount;
                $salesAmountWithTax = $taxableBase + $inclusiveTaxTotal;
            } else {
                $exclusiveTaxTotal += $taxAmount;
                $salesAmountWithTax = $taxableBase + $inclusiveTaxTotal + $taxAmount;
            }

            $taxRows[] = [
                'tax_id' => $tax->id,
                'tax_name' => $tax->name ?? null,
                'tax_code' => $tax->tax_code ?? null,
                'tax_type' => $taxType,
                'tax_percent' => $taxPercent,
                'taxable_amount' => $taxableBase,
                'tax_amount' => $taxAmount,
                'sales_amount_with_tax' => $salesAmountWithTax,
                'is_inclusive' => $isInclusive,
                'obliged_to_zimra' => (bool)($tax->obliged_to_zimra ?? false),
                'fiscal_tax_id' => $tax->tax_id ?? null,
            ];
        }

        $totalTax = $inclusiveTaxTotal + $exclusiveTaxTotal;
        $lineTotal = $taxableBase + $inclusiveTaxTotal + $exclusiveTaxTotal;

        return [
            'gross' => $gross,
            'subtotal' => $taxableBase,
            'inclusive_tax' => $inclusiveTaxTotal,
            'exclusive_tax' => $exclusiveTaxTotal,
            'tax' => $totalTax,
            'total' => $lineTotal,
            'taxes' => $taxRows,
        ];
    }


    private function resolveProductPrice(
        Product $product,
        ?string $productPriceId,
        string  $companyId,
        string  $branchId,
        ?MobileSaleSnapshot $mobileSaleSnapshot = null
    )
    {
        if ($mobileSaleSnapshot) {
            return $mobileSaleSnapshot->productPrice((string) $product->id, $productPriceId);
        }

        if ((bool) config('servnest.hospitality_only', true)) {
            return $this->hospitalityMenuPrices->resolve(
                companyId: $companyId,
                branchId: $branchId,
                productId: (string) $product->id,
                selectedPriceId: $productPriceId,
                channel: (string) config('servnest.catalogue.default_channel', 'POS')
            );
        }

        $query = ProductPrice::where('company_id', $companyId)
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->where(function ($sub) use ($branchId) {
                $sub->whereNull('branch_id')->orWhere('branch_id', $branchId);
            })
            ->where(function ($sub) {
                $sub->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($sub) {
                $sub->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            });

        if ($productPriceId) {
            $price = (clone $query)
                ->where('id', $productPriceId)
                ->first();

            if (!$price) {
                throw new \InvalidArgumentException('Selected price is not valid for this product and branch.');
            }

            return $price;
        }

        $price = $query
            ->with('priceLevel')
            ->orderByRaw(
                "CASE
                    WHEN EXISTS (
                        SELECT 1 FROM price_levels
                        WHERE price_levels.id = product_prices.price_level_id
                        AND LOWER(price_levels.name) = 'standard'
                    ) THEN 0 ELSE 1
                END"
            )
            ->orderByRaw('CASE WHEN branch_id = ? THEN 0 ELSE 1 END', [$branchId])
            ->orderByDesc('is_default')
            ->orderBy('price')
            ->first();

        if (!$price) {
            throw new \InvalidArgumentException('No active selling price found for this product and branch.');
        }

        return $price;
    }

    private function validatePriceLevelOverrideForSaleLine(
        array        $line,
        ProductPrice $selectedPrice,
        Product      $product,
        string       $companyId,
        ?string      $branchId,
        string       $cashierId
    ): ?array
    {
        $override = $line['price_level_override'] ?? null;

        /*
         * No override payload means normal sale using the selected product_price_id.
         * If you want to block non-standard prices without override, enforce it here.
         */
        if (!$override) {
            return null;
        }

        if (!$this->priceLevelOverrideSettingEnabled($companyId, $branchId, 'price_level_override_enabled', false)) {
            throw new \Exception('Price level override is disabled in General Settings.');
        }

        $auditId = $override['audit_id'] ?? null;
        $managerApprovalId = $override['manager_approval_id'] ?? null;
        $overrideProductPriceId = $override['product_price_id'] ?? null;
        $overridePriceLevelId = $override['price_level_id'] ?? null;

        if (!$auditId || !$overrideProductPriceId || !$overridePriceLevelId) {
            throw new \Exception('Invalid price level override payload.');
        }

        if ((string)$selectedPrice->id !== (string)$overrideProductPriceId) {
            throw new \Exception('Selected product price does not match the approved price level override.');
        }

        if ((string)$selectedPrice->price_level_id !== (string)$overridePriceLevelId) {
            throw new \Exception('Selected price level does not match the approved price level override.');
        }

        if ((string)$selectedPrice->product_id !== (string)$product->id) {
            throw new \Exception('Selected override price does not belong to this product.');
        }

        $audit = DB::table('audit_trails')
            ->where('id', $auditId)
            ->where('company_id', $companyId)
            ->where('module', 'sales')
            ->where('action', 'price_level_override_applied')
            ->where('status', 'success')
            ->first();

        if (!$audit) {
            throw new \Exception('Price level override audit was not found or not successful.');
        }

        if ($branchId && $audit->branch_id && (string)$audit->branch_id !== (string)$branchId) {
            throw new \Exception('Price level override audit belongs to another branch.');
        }

        $auditMetadata = $this->jsonToArray($audit->metadata ?? null);
        $auditNewValues = $this->jsonToArray($audit->new_values ?? null);

        if (($auditMetadata['product_id'] ?? null) && (string)$auditMetadata['product_id'] !== (string)$product->id) {
            throw new \Exception('Price level override audit belongs to another product.');
        }

        if (($auditNewValues['price_level_id'] ?? null) && (string)$auditNewValues['price_level_id'] !== (string)$selectedPrice->price_level_id) {
            throw new \Exception('Price level override audit belongs to another price level.');
        }

        if (($audit->auditable_id ?? null) && (string)$audit->auditable_id !== (string)$selectedPrice->id) {
            throw new \Exception('Price level override audit belongs to another product price.');
        }

        $requiresManager = $this->priceLevelOverrideSettingEnabled(
            companyId: $companyId,
            branchId: $branchId,
            settingKey: 'price_level_override_requires_manager_approval',
            default: true
        );

        $cashierCanOverride = $this->priceLevelOverrideSettingEnabled(
            companyId: $companyId,
            branchId: $branchId,
            settingKey: 'cashier_can_price_level_override_without_manager',
            default: false
        );

        /*
         * Determine which role must be eligible for the selected price level.
         * - If manager approval is required: approving manager role is used.
         * - If cashier override is allowed: cashier role is used.
         */
        $approvalRoleId = null;
        $approvalUserId = null;
        $approvalUserName = null;

        if ($requiresManager && !$cashierCanOverride) {
            if (!$managerApprovalId) {
                throw new \Exception('Manager approval is required for price level override.');
            }

            $managerApproval = DB::table('audit_trails')
                ->where('id', $managerApprovalId)
                ->where('company_id', $companyId)
                ->where('module', 'manager-approval')
                ->where('action', 'manager_approval_price_level_override')
                ->where('status', 'success')
                ->first();

            /*
             * Backward compatibility:
             * Some older manager approval controllers may save the action as
             * manager_approval_price_override.
             */
            if (!$managerApproval) {
                $managerApproval = DB::table('audit_trails')
                    ->where('id', $managerApprovalId)
                    ->where('company_id', $companyId)
                    ->where('module', 'manager-approval')
                    ->where('action', 'manager_approval_price_override')
                    ->where('status', 'success')
                    ->first();
            }

            if (!$managerApproval) {
                throw new \Exception('Invalid manager approval for price level override.');
            }

            if ($branchId && $managerApproval->branch_id && (string)$managerApproval->branch_id !== (string)$branchId) {
                throw new \Exception('Manager approval belongs to another branch.');
            }

            $managerApprovalMetadata = $this->jsonToArray($managerApproval->metadata ?? null);
            $managerApprovalNewValues = $this->jsonToArray($managerApproval->new_values ?? null);

            $requiredPermission = $managerApprovalMetadata['required_permission']
                ?? $managerApprovalNewValues['required_permission']
                ?? null;

            if ($requiredPermission !== 'approve_price_override') {
                throw new \Exception('Manager approval does not have approve_price_override permission.');
            }

            $manager = PosUser::query()
                ->where('company_id', $companyId)
                ->where('id', $managerApproval->user_id)
                ->where('is_active', true)
                ->where('can_login_pos', true)
                ->whereNull('deleted_at')
                ->first();

            if (!$manager) {
                throw new \Exception('Approving manager is no longer active.');
            }

            $approvalRoleId = $manager->role_id;
            $approvalUserId = $manager->id;
            $approvalUserName = $manager->name ?? $manager->username;
        } else {
            $cashier = PosUser::query()
                ->where('company_id', $companyId)
                ->where('id', $cashierId)
                ->first();

            if (!$cashier) {
                throw new \Exception('Cashier not found for price level override validation.');
            }

            $permission = hasPermission($cashier->role_id, 'price_level_override', $companyId);

            if ($permission !== true) {
                throw new \Exception('Cashier role is not allowed to apply price level override.');
            }

            $approvalRoleId = $cashier->role_id;
            $approvalUserId = $cashier->id;
            $approvalUserName = $cashier->name ?? $cashier->username;
        }

        $roleCanUsePriceLevel = DB::table('role_price_levels')
            ->where('company_id', $companyId)
            ->where('role_id', $approvalRoleId)
            ->where('price_level_id', $selectedPrice->price_level_id)
            ->exists();

        if (!$roleCanUsePriceLevel) {
            throw new \Exception('Approving role is not eligible for the selected price level.');
        }

        return [
            'enabled' => true,
            'audit_id' => $auditId,
            'manager_approval_id' => $managerApprovalId,
            'approved_by_user_id' => $approvalUserId,
            'approved_by_name' => $approvalUserName,
            'approved_by_role_id' => $approvalRoleId,
            'product_price_id' => $selectedPrice->id,
            'price_level_id' => $selectedPrice->price_level_id,
            'price' => (float)$selectedPrice->price,
            'currency' => $selectedPrice->currency,
        ];
    }

    private function priceLevelOverrideSettingEnabled(
        string  $companyId,
        ?string $branchId,
        string  $settingKey,
        bool    $default = false
    ): bool
    {
        $setting = GeneralSetting::query()
            ->where('company_id', $companyId)
            ->where('setting_key', $settingKey)
            ->where('is_active', true)
            ->where(function ($query) use ($branchId) {
                $query->whereNull('branch_id');

                if ($branchId) {
                    $query->orWhere('branch_id', $branchId);
                }
            })
            ->orderByRaw('CASE WHEN branch_id IS NULL THEN 1 ELSE 0 END')
            ->first();

        return $setting
            ? filter_var($setting->setting_value, FILTER_VALIDATE_BOOLEAN)
            : $default;
    }

    private function jsonToArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return json_decode(json_encode($value), true) ?: [];
        }

        if (is_string($value) && $value !== '') {
            return json_decode($value, true) ?: [];
        }

        return [];
    }


    private function fiscaliseSaleAfterCommit(SalesTransaction $sale): array
    {
        $setting = ZimraCompanySetting::where('company_id', $sale->company_id)->first();

        if (!$setting || !(bool)$setting->enabled) {
            $this->updateSaleFiscalState(
                sale: $sale,
                values: [
                    'is_fiscalised' => false,
                    'fiscal_status' => 'skipped',
                    'fiscal_error' => null,
                ],
                metadata: [
                    'fiscalised' => false,
                    'fiscalisation_status' => 'skipped',
                    'fiscalisation_message' => 'ZIMRA fiscalisation is disabled for this company.',
                ]
            );

            return [
                'status' => 'skipped',
                'blocked' => false,
                'enabled' => false,
                'message' => 'ZIMRA fiscalisation is disabled for this company.',
                'sale' => $sale->fresh(),
            ];
        }

        try {
            /*
             * ZimraReceiptService must:
             * - find active fiscal device for this branch
             * - ensure there is an open fiscal day
             * - build the receipt payload from receiptLines.taxes
             * - submit to FDMS
             * - update sales_transactions fiscal fields
             * - create/update zimra_receipts
             */
            $fiscalisedSale = $this->zimraReceiptService->submitSale($sale->id);

            $sale = $fiscalisedSale instanceof SalesTransaction
                ? $fiscalisedSale
                : $sale->fresh();

            $sale = $this->hydrateSaleFiscalFieldsFromZimraReceipt($sale);

            $this->mergeSaleMetadata($sale, [
                'fiscalised' => true,
                'fiscalisation_status' => 'success',
                'fiscalisation_message' => 'Receipt submitted to ZIMRA successfully.',
                'qr_code_data' => $sale->qr_code_data ?? null,
                'qr_url' => $sale->qr_url ?? null,
                'receipt_global_no' => $sale->receipt_global_no ?? null,
            ]);

            return [
                'status' => 'success',
                'blocked' => false,
                'enabled' => true,
                'message' => 'Receipt submitted to ZIMRA successfully.',
                'fdms_receipt_id' => $sale->fdms_receipt_id ?? null,
                'receipt_global_no' => $sale->receipt_global_no ?? null,
                'receipt_counter' => $sale->receipt_counter ?? null,
                'qr_url' => $sale->qr_url ?? null,
                'qr_code_data' => $sale->qr_code_data ?? null,
                'zimra_signature_hash' => $sale->zimra_signature_hash ?? null,
                'sale' => $sale->fresh(),
            ];
        } catch (\Throwable $e) {
            $this->updateSaleFiscalState(
                sale: $sale,
                values: [
                    'is_fiscalised' => false,
                    'fiscal_status' => 'failed',
                    'fiscal_error' => $e->getMessage(),
                ],
                metadata: [
                    'fiscalised' => false,
                    'fiscalisation_status' => 'failed',
                    'fiscalisation_error' => $e->getMessage(),
                ]
            );

            return [
                'status' => 'failed',
                'blocked' => (bool)$setting->block_sale_if_fiscalisation_fails,
                'enabled' => true,
                'error' => $e->getMessage(),
                'sale' => $sale->fresh(),
            ];
        }
    }


    private function fiscaliseCancellationAfterCommit(
        SalesTransaction $cancelTransaction,
        SalesTransaction $originalSale,
        ?string          $reason = null
    ): array
    {
        $setting = ZimraCompanySetting::where('company_id', $cancelTransaction->company_id)->first();

        if (!$setting || !(bool)$setting->enabled) {
            $this->updateSaleFiscalState(
                sale: $cancelTransaction,
                values: [
                    'is_fiscalised' => false,
                    'fiscal_status' => 'skipped',
                    'fiscal_error' => null,
                ],
                metadata: [
                    'fiscalised' => false,
                    'fiscalisation_status' => 'skipped',
                    'fiscalisation_message' => 'ZIMRA fiscalisation is disabled for this company.',
                    'fdms_receipt_type' => 'CreditNote',
                    'original_sale_id' => $originalSale->id,
                    'original_invoice_number' => $originalSale->invoice_number,
                ]
            );

            return [
                'status' => 'skipped',
                'blocked' => false,
                'enabled' => false,
                'receipt_type' => 'CreditNote',
                'message' => 'ZIMRA fiscalisation is disabled for this company.',
                'sale' => $cancelTransaction->fresh(),
            ];
        }

        try {
            /*
             |--------------------------------------------------------------------------
             | CreditNote submission
             |--------------------------------------------------------------------------
             | ZimraReceiptService::submitCreditNote() must:
             | - find the original submitted zimra_receipts row
             | - add receiptNotes
             | - add creditDebitNote reference
             | - send negative receiptTotal and negative paymentAmount
             | - send only taxes used on the original fiscal invoice
             | - update fiscal counters after FDMS accepts the CreditNote
             */
            if (!method_exists($this->zimraReceiptService, 'submitCreditNote')) {
                throw new \Exception('ZimraReceiptService::submitCreditNote() is missing. Replace ZimraReceiptService with the FDMS CreditNote version.');
            }

            $fiscalisedSale = $this->zimraReceiptService->submitCreditNote(
                cancelSaleId: $cancelTransaction->id,
                originalSaleId: $originalSale->id,
                reason: $reason ?: ('Cancellation of ' . $originalSale->invoice_number)
            );

            $sale = $fiscalisedSale instanceof SalesTransaction
                ? $fiscalisedSale
                : $cancelTransaction->fresh();

            $sale = $this->hydrateSaleFiscalFieldsFromZimraReceipt($sale);

            $this->mergeSaleMetadata($sale, [
                'fiscalised' => true,
                'fiscalisation_status' => 'success',
                'fiscalisation_message' => 'CreditNote submitted to ZIMRA successfully.',
                'fdms_receipt_type' => 'CreditNote',
                'original_sale_id' => $originalSale->id,
                'original_invoice_number' => $originalSale->invoice_number,
                'qr_code_data' => $sale->qr_code_data ?? null,
                'qr_url' => $sale->qr_url ?? null,
                'receipt_global_no' => $sale->receipt_global_no ?? null,
            ]);

            return [
                'status' => 'success',
                'blocked' => false,
                'enabled' => true,
                'receipt_type' => 'CreditNote',
                'message' => 'CreditNote submitted to ZIMRA successfully.',
                'fdms_receipt_id' => $sale->fdms_receipt_id ?? null,
                'receipt_global_no' => $sale->receipt_global_no ?? null,
                'receipt_counter' => $sale->receipt_counter ?? null,
                'qr_url' => $sale->qr_url ?? null,
                'qr_code_data' => $sale->qr_code_data ?? null,
                'zimra_signature_hash' => $sale->zimra_signature_hash ?? null,
                'sale' => $sale->fresh(),
            ];
        } catch (\Throwable $e) {
            $this->updateSaleFiscalState(
                sale: $cancelTransaction,
                values: [
                    'is_fiscalised' => false,
                    'fiscal_status' => 'failed',
                    'fiscal_error' => $e->getMessage(),
                ],
                metadata: [
                    'fiscalised' => false,
                    'fiscalisation_status' => 'failed',
                    'fiscalisation_error' => $e->getMessage(),
                    'fdms_receipt_type' => 'CreditNote',
                    'original_sale_id' => $originalSale->id,
                    'original_invoice_number' => $originalSale->invoice_number,
                ]
            );

            return [
                'status' => 'failed',
                'blocked' => (bool)$setting->block_sale_if_fiscalisation_fails,
                'enabled' => true,
                'receipt_type' => 'CreditNote',
                'error' => $e->getMessage(),
                'sale' => $cancelTransaction->fresh(),
            ];
        }
    }

    private function updateSaleFiscalState(
        SalesTransaction $sale,
        array            $values = [],
        array            $metadata = []
    ): void
    {
        $updates = [];

        foreach ($values as $column => $value) {
            if (Schema::hasColumn('sales_transactions', $column)) {
                $updates[$column] = $value;
            }
        }

        if (!empty($updates)) {
            $sale->update($updates);
        }

        if (!empty($metadata)) {
            $this->mergeSaleMetadata($sale->fresh(), $metadata);
        }
    }

    private function mergeSaleMetadata(SalesTransaction $sale, array $metadata): void
    {
        if (!Schema::hasColumn('sales_transactions', 'metadata')) {
            return;
        }

        $current = $sale->metadata;

        if (!is_array($current)) {
            $current = json_decode($current ?? '[]', true) ?: [];
        }

        $sale->metadata = array_merge($current, $metadata);
        $sale->save();
    }


    private function hydrateSaleFiscalFieldsFromZimraReceipt(SalesTransaction $sale): SalesTransaction
    {
        /*
         |--------------------------------------------------------------------------
         | QR / fiscal field repair
         |--------------------------------------------------------------------------
         | ZIMRAReceiptService creates the local signed FDMS receipt before the HTTP
         | SubmitReceipt response. That record already has qr_string, qr_url,
         | receipt_global_no, receipt_counter and receipt_hash. If the sale row was
         | reloaded before those values reached sales_transactions, the receipt
         | renderer can say "Invoice is not yet received". This method repairs the
         | sale row from zimra_receipts before preparing the PDF.
         */
        $zimraReceipt = ZimraReceipt::where('sales_transaction_id', $sale->id)
            ->orderByDesc('created_at')
            ->first();

        if (!$zimraReceipt) {
            return $sale->fresh() ?: $sale;
        }

        $updates = [];

        $map = [
            'fiscal_device_id' => $zimraReceipt->fiscal_device_id ?? null,
            'fiscal_day_id' => $zimraReceipt->fiscal_day_id ?? null,
            'receipt_counter' => $zimraReceipt->receipt_counter ?? null,
            'receipt_global_no' => $zimraReceipt->receipt_global_no ?? null,
            'fdms_receipt_id' => $zimraReceipt->fdms_receipt_id ?? null,
            'qr_url' => $zimraReceipt->qr_url ?? null,
            'qr_code_data' => $zimraReceipt->qr_string ?: ($zimraReceipt->qr_code_data ?? null),
            'zimra_signature_hash' => $zimraReceipt->receipt_hash ?? null,
            'fiscal_response' => $zimraReceipt->response ?? null,
            'fiscalised_at' => $zimraReceipt->submitted_at ?? null,
        ];

        foreach ($map as $column => $value) {
            if ($value !== null && Schema::hasColumn('sales_transactions', $column)) {
                $updates[$column] = $value;
            }
        }

        if (Schema::hasColumn('sales_transactions', 'is_fiscalised')) {
            $updates['is_fiscalised'] = $zimraReceipt->status === 'submitted';
        }

        if (Schema::hasColumn('sales_transactions', 'zimra_submitted')) {
            $updates['zimra_submitted'] = $zimraReceipt->status === 'submitted';
        }

        if (Schema::hasColumn('sales_transactions', 'zimra_submitted_at') && $zimraReceipt->submitted_at) {
            $updates['zimra_submitted_at'] = $zimraReceipt->submitted_at;
        }

        if (Schema::hasColumn('sales_transactions', 'fiscal_status')) {
            $updates['fiscal_status'] = match ($zimraReceipt->status) {
                'submitted' => 'success',
                'failed' => 'failed',
                default => 'pending',
            };
        }

        if (Schema::hasColumn('sales_transactions', 'fiscal_error')) {
            $updates['fiscal_error'] = $zimraReceipt->error;
        }

        if (!empty($updates)) {
            $sale->update($updates);
        }

        $freshSale = $sale->fresh();

        if ($freshSale) {
            $this->mergeSaleMetadata($freshSale, [
                'fiscalisation_status' => $freshSale->fiscal_status ?? null,
                'fdms_receipt_id' => $freshSale->fdms_receipt_id ?? null,
                'receipt_global_no' => $freshSale->receipt_global_no ?? null,
                'receipt_counter' => $freshSale->receipt_counter ?? null,
                'qr_url' => $freshSale->qr_url ?? null,
                'qr_code_data' => $freshSale->qr_code_data ?? null,
            ]);

            return $freshSale->fresh() ?: $freshSale;
        }

        return $sale;
    }

    private function prepareReceiptAfterFiscalisation(SalesTransaction $sale): array
    {
        /*
         * Always hydrate from zimra_receipts first, then prepare the PDF.
         * This fixes the "Invoice is not yet received" case where the local
         * ZIMRA receipt already has QR data but the sales_transactions row does not.
         */
        $sale = $this->hydrateSaleFiscalFieldsFromZimraReceipt($sale);

        $saleForReceipt = SalesTransaction::with([
            'company',
            'branch',
            'till',
            'receiptLines.taxes.tax',
        ])->where('id', $sale->id)->firstOrFail();

        try {
            return $this->receiptService->prepareReceipt($saleForReceipt);
        } catch (\Throwable $e) {
            /*
             * One more repair attempt in case fiscalisation completed between the
             * first hydrate and the receipt renderer.
             */
            $saleForReceipt = $this->hydrateSaleFiscalFieldsFromZimraReceipt($saleForReceipt);

            $saleForReceipt = SalesTransaction::with([
                'company',
                'branch',
                'till',
                'receiptLines.taxes.tax',
            ])->where('id', $saleForReceipt->id)->firstOrFail();

            return $this->receiptService->prepareReceipt($saleForReceipt);
        }
    }


    private function buildSaleCreatedResponse(
        SalesTransaction $sale,
        string           $receiptNumber,
        string           $saleCurrencyCode,
        string           $baseCurrencyCode,
        array            $totals,
        float            $amountTendered,
        float            $change,
                         $till,
                         $shift,
        ?array           $fiscalisation = null,
        ?array           $changeAccount = null,
        bool             $duplicate = false,
        ?array           $warning = null
    )
    {
        $saleForReceipt = SalesTransaction::with([
            'company',
            'branch',
            'till',
            'receiptLines.taxes.tax',
        ])
            ->where('id', $sale->id)
            ->firstOrFail();

        $saleForReceipt = $this->hydrateSaleFiscalFieldsFromZimraReceipt($saleForReceipt);

        try {
            $receipt = $this->prepareReceiptAfterFiscalisation($saleForReceipt);
        } catch (\Throwable $receiptError) {
            // The financial sale is already committed at this boundary. A PDF
            // filesystem/rendering problem must not turn a successful,
            // idempotent Android upload into an endless retry. Explicit
            // receipt/reprint endpoints still surface their rendering errors;
            // only the committed sale response degrades to a null PDF URL.
            Log::error('Receipt PDF generation failed after the sale was persisted.', [
                'sale_id' => (string) $saleForReceipt->id,
                'company_id' => (string) $saleForReceipt->company_id,
                'branch_id' => (string) $saleForReceipt->branch_id,
                'invoice_number' => (string) $saleForReceipt->invoice_number,
                'exception' => $receiptError,
            ]);

            try {
                $receiptSettings = $this->receiptService->resolveReceiptSettings(
                    $saleForReceipt->company_id,
                    $saleForReceipt->branch_id
                );
            } catch (\Throwable) {
                $receiptSettings = [
                    'paper_size' => '80mm',
                    'receipt_size' => '80mm',
                    'auto_print' => false,
                    'receipt_auto_print' => false,
                ];
            }

            $receipt = [
                'receipt_url' => null,
                'receipt_generated' => false,
                'receipt_settings' => $receiptSettings,
                'auto_print' => false,
                'printer' => null,
            ];
        }

        return response()->json([
            'message' => $duplicate
                ? 'Sale was already recorded; the original receipt was returned.'
                : 'Sale created successfully.',
            'duplicate' => $duplicate,
            'warnings' => $warning ? [$warning] : [],
            'data' => [
                'sale' => $saleForReceipt,

                'receipt_number' => $receiptNumber,
                'receipt_url' => $receipt['receipt_url'],
                'receipt_generated' => (bool) ($receipt['receipt_generated'] ?? false),

                'currency_code' => $saleCurrencyCode,
                'base_currency_code' => $baseCurrencyCode,

                'subtotal' => round($totals['subtotal'], 4),
                'discount' => round($totals['discount'], 4),
                'tax' => round($totals['tax'], 4),
                'total' => round($totals['total'], 4),

                'amount_tendered' => round($amountTendered, 4),
                'change' => round($change, 4),
                'change_posted_to_account' => $changeAccount !== null,
                'change_credit_amount' => $changeAccount['change_amount'] ?? 0,
                'change_credit_currency_code' => $changeAccount['change_currency_code'] ?? null,
                'change_credit_customer_id' => $changeAccount['customer_id'] ?? null,
                'change_credit_account_number' => $changeAccount['account_number'] ?? null,
                'change_credit_payment_number' => $changeAccount['payment_number'] ?? null,
                'change_credit_reference' => $changeAccount['reference'] ?? null,
                'change_credit_payment' => $changeAccount ? [
                    'id' => $changeAccount['payment_id'],
                    'payment_number' => $changeAccount['payment_number'],
                    'reference' => $changeAccount['reference'],
                    'print_url' => $changeAccount['receipt_print_url'] ?? null,
                    'download_url' => $changeAccount['receipt_download_url'] ?? null,
                ] : null,
                'change_credit_customer' => $changeAccount ? [
                    'id' => $changeAccount['customer_id'],
                    'account_number' => $changeAccount['account_number'],
                    'name' => $changeAccount['customer_name'],
                    'currency_code' => $changeAccount['account_currency_code'],
                    'current_balance' => $changeAccount['balance_after'],
                    'credit_balance' => $changeAccount['credit_balance_after'],
                ] : null,
                'credited_change' => $changeAccount,

                'receipt_settings' => $receipt['receipt_settings'],

                'order_number' => $this->fastFoodSaleResponseExtras($saleForReceipt)['order_number'] ?? null,
                'fast_food' => $this->fastFoodSaleResponseExtras($saleForReceipt),
                'kitchen_print' => $this->fastFoodSaleResponseExtras($saleForReceipt)['kitchen_print'] ?? null,

                'fiscalisation' => $fiscalisation ?: [
                    'status' => $saleForReceipt->fiscal_status ?? data_get($saleForReceipt->metadata, 'fiscalisation_status'),
                    'enabled' => null,
                    'blocked' => false,
                    'message' => data_get($saleForReceipt->metadata, 'fiscalisation_message'),
                    'error' => $saleForReceipt->fiscal_error ?? data_get($saleForReceipt->metadata, 'fiscalisation_error'),
                    'is_fiscalised' => (bool)($saleForReceipt->is_fiscalised ?? false),
                    'fdms_receipt_id' => $saleForReceipt->fdms_receipt_id ?? null,
                    'receipt_global_no' => $saleForReceipt->receipt_global_no ?? null,
                    'receipt_counter' => $saleForReceipt->receipt_counter ?? null,
                    'qr_url' => $saleForReceipt->qr_url ?? null,
                    'qr_code_data' => $saleForReceipt->qr_code_data ?? null,
                    'zimra_signature_hash' => $saleForReceipt->zimra_signature_hash ?? null,
                ],

                'print' => [
                    'auto_print' => $receipt['auto_print'],
                    'printer' => $receipt['printer'],
                ],

                'till' => [
                    'id' => $till->id,
                    'till_code' => $till->till_code ?? null,
                    'till_name' => $till->till_name ?? null,
                ],

                'shift' => [
                    'id' => $shift->shift_configuration_id ?? null,
                    'session_id' => $shift->id ?? null,
                    'shift_name' => $shift->shift_name ?? null,
                    'opened_at' => $shift->opened_at ?? null,
                ],
            ],
        ], $duplicate ? 200 : 201);
    }

    private function existingSaleScopeConflictResponse(SalesTransaction $sale, $company, $till, $user)
    {
        foreach ([
            'company_id' => $company->id,
            'branch_id' => $till->branch_id,
            'till_id' => $till->id,
            'cashier_id' => $user->id,
            'user_id' => $user->id,
        ] as $field => $expectedId) {
            if (!$expectedId || !$sale->{$field} || (string) $sale->{$field} !== (string) $expectedId) {
                return response()->json([
                    'message' => 'Client sale ID is already in use.',
                    'code' => 'CLIENT_SALE_ID_SCOPE_CONFLICT',
                ], 409);
            }
        }

        return null;
    }

    private function buildDuplicateSaleCreatedResponse(SalesTransaction $sale, $fallbackTill)
    {
        $metadata = (array) ($sale->metadata ?? []);
        $shift = Schema::hasTable('pos_user_shift_sessions') && $sale->shift_session_id
            ? DB::table('pos_user_shift_sessions')->where('id', $sale->shift_session_id)->first()
            : null;
        $shift ??= (object) [
            'id' => $sale->shift_session_id,
            'shift_configuration_id' => $sale->shift_id,
        ];

        $responseTill = $sale->till ?: (object) [
            'id' => $sale->till_id ?: $fallbackTill?->id,
            'till_code' => data_get($metadata, 'till_code', $fallbackTill?->till_code),
            'till_name' => data_get($metadata, 'till_name', $fallbackTill?->till_name),
        ];

        $changeAccount = null;
        if (data_get($metadata, 'change_disposition') === 'customer_account') {
            $payment = CreditCustomerPayment::withTrashed()
                ->where('company_id', $sale->company_id)
                ->where('id', data_get($metadata, 'change_credit_payment_id'))
                ->first();
            $customer = CreditCustomer::query()
                ->where('company_id', $sale->company_id)
                ->where('id', data_get($metadata, 'change_credit_customer_id'))
                ->first();
            $changeCustomerId = $customer?->id ?: data_get($metadata, 'change_credit_customer_id');
            $changePaymentId = $payment?->id ?: data_get($metadata, 'change_credit_payment_id');
            $changeReceiptPath = "/api/pos/credit-customers/{$changeCustomerId}/payments/{$changePaymentId}/receipt/pdf";

            $changeAccount = [
                'payment_id' => $changePaymentId,
                'payment_number' => $payment?->payment_number ?: data_get($metadata, 'change_credit_payment_number'),
                'reference' => $payment?->reference ?: $sale->invoice_number,
                'customer_id' => $changeCustomerId,
                'account_number' => $customer?->account_number ?: data_get($metadata, 'change_credit_account_number'),
                'customer_name' => $customer?->name,
                'computed_change_amount' => round((float) data_get($metadata, 'computed_change_amount', 0), 4),
                'change_amount' => round((float) data_get($metadata, 'change_credit_amount', 0), 4),
                'returned_change_amount' => round((float) data_get($metadata, 'returned_change_amount', $sale->change_amount), 4),
                'change_currency_code' => data_get($metadata, 'change_credit_currency_code', $sale->currency_code),
                'account_amount' => round((float) data_get($metadata, 'change_credit_account_amount', data_get($payment?->metadata, 'account_amount', 0)), 4),
                'account_currency_code' => data_get($metadata, 'change_credit_account_currency_code', $customer?->currency_code),
                'applied_to_existing_debt' => round((float) data_get($payment?->metadata, 'applied_to_existing_debt', 0), 4),
                'overpayment_credit_created' => round((float) data_get($payment?->metadata, 'overpayment_credit_created', 0), 4),
                'credit_balance_after' => round((float) data_get($payment?->metadata, 'customer_credit_after_payment', max(0, -1 * (float) ($customer?->current_balance ?? 0))), 4),
                'balance_after' => round((float) data_get($payment?->metadata, 'balance_after_payment', $customer?->current_balance ?? 0), 4),
                'receipt_print_url' => $changeReceiptPath . '?paper=80mm&download=0',
                'receipt_download_url' => $changeReceiptPath . '?paper=80mm&download=1',
            ];
        }

        return $this->buildSaleCreatedResponse(
            sale: $sale,
            receiptNumber: $sale->invoice_number,
            saleCurrencyCode: (string) ($sale->currency_code ?: 'USD'),
            baseCurrencyCode: (string) ($sale->base_currency_code ?: $sale->currency_code ?: 'USD'),
            totals: [
                'subtotal' => (float) $sale->subtotal_amount,
                'discount' => (float) $sale->discount_amount,
                'tax' => (float) $sale->tax_amount,
                'total' => (float) $sale->total_amount,
            ],
            amountTendered: (float) $sale->amount_tendered,
            change: (float) ($sale->change_amount ?? $sale->change ?? 0),
            till: $responseTill,
            shift: $shift,
            changeAccount: $changeAccount,
            duplicate: true,
            warning: is_array(data_get($metadata, 'shift_policy_warning'))
                ? data_get($metadata, 'shift_policy_warning')
                : null
        );
    }


//    private function deductStock(
//        Product $product,
//        string  $branchId,
//        float   $quantity,
//        string  $saleId
//    ): void
//    {
//        $stock = ProductStock::where('company_id', $product->company_id)
//            ->where('branch_id', $branchId)
//            ->where('product_id', $product->id)
//            ->lockForUpdate()
//            ->first();
//
//        if (!$stock) {
//            $stock = ProductStock::create([
//                'id' => (string)Str::uuid(),
//                'company_id' => $product->company_id,
//                'branch_id' => $branchId,
//                'product_id' => $product->id,
//                'quantity_on_hand' => 0,
//                'reserved_quantity' => 0,
//                'available_quantity' => 0,
//            ]);
//
//            $stock = ProductStock::where('id', $stock->id)
//                ->lockForUpdate()
//                ->first();
//        }
//
//        if ($this->shouldAutoBreakBeforeSale($product, $stock, $quantity)) {
//            $this->autoBreakChildForSale(
//                child: $product,
//                branchId: $branchId,
//                requiredSaleQuantity: $quantity,
//                currentChildAvailable: (float)$stock->available_quantity,
//                saleId: $saleId
//            );
//
//            $stock = ProductStock::where('company_id', $product->company_id)
//                ->where('branch_id', $branchId)
//                ->where('product_id', $product->id)
//                ->lockForUpdate()
//                ->first();
//        }
//
//        if (!$product->allow_negative_stock && (float)$stock->available_quantity < $quantity) {
//            throw new \Exception("Insufficient stock for {$product->name}.");
//        }
//
//        $stock->quantity_on_hand = (float)$stock->quantity_on_hand - $quantity;
//        $stock->available_quantity = (float)$stock->available_quantity - $quantity;
//        $stock->save();
//
//        DB::table('stock_movements')->insert([
//            'id' => (string)Str::uuid(),
//            'company_id' => $product->company_id,
//            'branch_id' => $branchId,
//            'product_id' => $product->id,
//            'movement_type' => 'sale',
//            'quantity_in' => 0,
//            'quantity_out' => $quantity,
//            'unit_cost' => 0,
//            'total_cost' => 0,
//            'reference_type' => 'sale',
//            'reference_id' => $saleId,
//            'notes' => 'Sale stock deduction',
//            'created_by' => auth()->id(),
//            'created_at' => now(),
//            'updated_at' => now(),
//        ]);
//    }
//    private function deductStock(
//        Product $product,
//        string  $branchId,
//        float   $quantity,
//        string  $saleId
//    ): void {
//        $stock = ProductStock::where('company_id', $product->company_id)
//            ->where('branch_id', $branchId)
//            ->where('product_id', $product->id)
//            ->lockForUpdate()
//            ->first();
//
//        if (!$stock) {
//            $stock = ProductStock::create([
//                'id' => (string) Str::uuid(),
//                'company_id' => $product->company_id,
//                'branch_id' => $branchId,
//                'product_id' => $product->id,
//                'quantity_on_hand' => 0,
//                'reserved_quantity' => 0,
//                'available_quantity' => 0,
//            ]);
//
//            $stock = ProductStock::where('id', $stock->id)
//                ->lockForUpdate()
//                ->first();
//        }
//
//        /*
//         |--------------------------------------------------------------------------
//         | Auto break parent product before sale
//         |--------------------------------------------------------------------------
//         | This keeps your existing parent/child stock behaviour. If the child stock
//         | is not enough, Salexa can automatically break parent stock into child units
//         | before final sale deduction.
//         */
//        if ($this->shouldAutoBreakBeforeSale($product, $stock, $quantity)) {
//            $this->autoBreakChildForSale(
//                child: $product,
//                branchId: $branchId,
//                requiredSaleQuantity: $quantity,
//                currentChildAvailable: (float) $stock->available_quantity,
//                saleId: $saleId
//            );
//
//            $stock = ProductStock::where('company_id', $product->company_id)
//                ->where('branch_id', $branchId)
//                ->where('product_id', $product->id)
//                ->lockForUpdate()
//                ->first();
//
//            if (!$stock) {
//                throw new \Exception("Stock record could not be resolved after auto-break for {$product->name}.");
//            }
//        }
//
//        if (!$product->allow_negative_stock && (float) $stock->available_quantity < $quantity) {
//            throw new \Exception("Insufficient stock for {$product->name}.");
//        }
//
//        /*
//         |--------------------------------------------------------------------------
//         | Resolve product cost for COGS
//         |--------------------------------------------------------------------------
//         | Previously this was saved as zero:
//         | unit_cost = 0
//         | total_cost = 0
//         |
//         | That makes sales revenue visible but hides real gross profit.
//         | Now the sale movement carries the current weighted average cost.
//         */
//        $cost = ProductCost::where('company_id', $product->company_id)
//            ->where('branch_id', $branchId)
//            ->where('product_id', $product->id)
//            ->lockForUpdate()
//            ->first();
//
//        $unitCost = (float) (
//        $cost?->average_cost
//            ?: $cost?->last_cost
//            ?: 0
//        );
//
//        $currency = $cost?->currency ?? null;
//        $totalCost = round($unitCost * $quantity, 4);
//
//        $previousQuantityOnHand = (float) $stock->quantity_on_hand;
//        $previousAvailableQuantity = (float) $stock->available_quantity;
//
//        $stock->quantity_on_hand = $previousQuantityOnHand - $quantity;
//        $stock->available_quantity = $previousAvailableQuantity - $quantity;
//        $stock->save();
//
//        /*
//         |--------------------------------------------------------------------------
//         | Optional: keep ProductCost quantity/value aligned with stock leaving
//         |--------------------------------------------------------------------------
//         | If your ProductCost.total_quantity and total_value are used as inventory
//         | valuation totals, reduce them when selling stock.
//         |
//         | This is usually correct for weighted average inventory:
//         | sale reduces quantity and value at current average cost.
//         */
//        if ($cost) {
//            $newCostQuantity = max(0, (float) $cost->total_quantity - $quantity);
//            $newCostValue = max(0, (float) $cost->total_value - $totalCost);
//
//            $cost->total_quantity = $newCostQuantity;
//            $cost->total_value = $newCostValue;
//
//            /*
//             * Keep average cost stable when remaining quantity exists.
//             * If stock is exhausted, keep last_cost and reset average_cost to 0.
//             */
//            $cost->average_cost = $newCostQuantity > 0
//                ? $newCostValue / $newCostQuantity
//                : 0;
//
//            $cost->save();
//        }
//
//        DB::table('stock_movements')->insert([
//            'id' => (string) Str::uuid(),
//
//            'company_id' => $product->company_id,
//            'branch_id' => $branchId,
//
//            /*
//             * These columns exist in some of your other stock movement inserts.
//             * If your stock_movements table does not have them, remove them here.
//             */
//            'till_id' => app('till')?->id ?? null,
//            'shift_session_id' => null,
//
//            'product_id' => $product->id,
//
//            'movement_type' => 'sale',
//
//            'quantity_in' => 0,
//            'quantity_out' => $quantity,
//            'balance_after' => $stock->quantity_on_hand,
//
//            'unit_cost' => round($unitCost, 6),
//            'total_cost' => $totalCost,
//
//            'currency' => $currency,
//            'currency_code' => $currency,
//
//            'reference_type' => 'sale',
//            'reference_id' => $saleId,
//
//            'notes' => 'Sale stock deduction with COGS.',
//
//            'metadata' => json_encode([
//                'source' => 'sales',
//                'sale_id' => $saleId,
//                'product_id' => $product->id,
//                'product_name' => $product->name,
//                'product_code' => $product->code,
//                'sku' => $product->sku,
//                'barcode' => $product->barcode,
//
//                'previous_quantity_on_hand' => $previousQuantityOnHand,
//                'previous_available_quantity' => $previousAvailableQuantity,
//
//                'quantity_sold' => $quantity,
//                'balance_after' => $stock->quantity_on_hand,
//                'available_after' => $stock->available_quantity,
//
//                'unit_cost' => round($unitCost, 6),
//                'total_cost' => $totalCost,
//                'currency' => $currency,
//
//                'costing_method' => 'weighted_average',
//                'product_cost_id' => $cost?->id,
//            ]),
//
//            'created_by' => auth()->id(),
//            'created_at' => now(),
//            'updated_at' => now(),
//        ]);
//    }

    private function deductStock(
        Product $product,
        string  $branchId,
        float   $quantity,
        string  $saleId,
        ?string $shiftSessionId = null,
        ?string $receiptLineId = null
    ): void
    {
        $till = null;

        try {
            $till = app('till');
        } catch (\Throwable $e) {
            $till = null;
        }

        $stock = ProductStock::where('company_id', $product->company_id)
            ->where('branch_id', $branchId)
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->first();

        if (!$stock) {
            $stock = ProductStock::create([
                'id' => (string)Str::uuid(),
                'company_id' => $product->company_id,
                'branch_id' => $branchId,
                'product_id' => $product->id,
                'quantity_on_hand' => 0,
                'reserved_quantity' => 0,
                'available_quantity' => 0,
            ]);

            $stock = ProductStock::where('id', $stock->id)
                ->lockForUpdate()
                ->first();
        }

        if (!$stock) {
            throw new \Exception("Stock record could not be created for {$product->name}.");
        }

        /*
         |--------------------------------------------------------------------------
         | Auto break child product before sale
         |--------------------------------------------------------------------------
         | If this is a child product and stock is not enough, Salexa tries to break
         | parent stock into child stock before deducting the sold quantity.
         */
        if ($this->shouldAutoBreakBeforeSale($product, $stock, $quantity)) {
            $this->autoBreakChildForSale(
                child: $product,
                branchId: $branchId,
                requiredSaleQuantity: $quantity,
                currentChildAvailable: (float)$stock->available_quantity,
                saleId: $saleId
            );

            $stock = ProductStock::where('company_id', $product->company_id)
                ->where('branch_id', $branchId)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->first();

            if (!$stock) {
                throw new \Exception("Stock record could not be resolved after auto-break for {$product->name}.");
            }
        }

        if (!$product->allow_negative_stock && (float)$stock->available_quantity < $quantity) {
            throw new \Exception("Insufficient stock for {$product->name}.");
        }

        /*
         |--------------------------------------------------------------------------
         | Resolve weighted average cost for COGS
         |--------------------------------------------------------------------------
         */
        $cost = ProductCost::where('company_id', $product->company_id)
            ->where('branch_id', $branchId)
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->first();

        $unitCost = (float)(
        $cost?->average_cost
            ?: $cost?->last_cost
            ?: 0
        );

        $currency = $cost?->currency ?? null;
        $totalCost = round($unitCost * $quantity, 4);

        $previousQuantityOnHand = (float)$stock->quantity_on_hand;
        $previousAvailableQuantity = (float)$stock->available_quantity;
        $previousReservedQuantity = (float)$stock->reserved_quantity;

        $oldCostQuantity = $cost ? (float)$cost->total_quantity : null;
        $oldCostValue = $cost ? (float)$cost->total_value : null;
        $oldAverageCost = $cost ? (float)$cost->average_cost : null;
        $oldLastCost = $cost ? (float)$cost->last_cost : null;

        /*
         |--------------------------------------------------------------------------
         | Deduct physical stock
         |--------------------------------------------------------------------------
         */
        $stock->quantity_on_hand = $previousQuantityOnHand - $quantity;
        $stock->available_quantity = $previousAvailableQuantity - $quantity;
        $stock->save();

        $allocatedByBatch = app(InventoryAllocationService::class)->allocateSale([
            'company_id' => $product->company_id,
            'branch_id' => $branchId,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'sale_id' => $saleId,
            'receipt_line_id' => $receiptLineId,
            'till_id' => $till?->id,
            'shift_session_id' => $shiftSessionId,
            'balance_after' => (float) $stock->quantity_on_hand,
            'unit_cost' => $unitCost,
            'currency' => $currency,
            'user_id' => auth()->id(),
        ]);

        /*
         |--------------------------------------------------------------------------
         | Reduce inventory valuation
         |--------------------------------------------------------------------------
         | This keeps ProductCost.total_quantity and ProductCost.total_value aligned
         | with stock leaving through sales.
         */
        $newCostQuantity = null;
        $newCostValue = null;
        $newAverageCost = null;

        if ($cost) {
            $newCostQuantity = max(0, $oldCostQuantity - $quantity);
            $newCostValue = max(0, $oldCostValue - $totalCost);
            $newAverageCost = $newCostQuantity > 0
                ? $newCostValue / $newCostQuantity
                : 0;

            $cost->total_quantity = $newCostQuantity;
            $cost->total_value = $newCostValue;
            $cost->average_cost = $newAverageCost;
            $cost->last_cost = $oldLastCost ?? $unitCost;
            $cost->save();
        }

        /*
         |--------------------------------------------------------------------------
         | Record stock movement with COGS
         |--------------------------------------------------------------------------
         */
        if (!$allocatedByBatch) DB::table('stock_movements')->insert([
            'id' => (string)Str::uuid(),

            'company_id' => $product->company_id,
            'branch_id' => $branchId,
            'till_id' => $till?->id,
            'shift_session_id' => $shiftSessionId,

            'product_id' => $product->id,
            'movement_type' => 'sale',

            'quantity_in' => 0,
            'quantity_out' => round($quantity, 4),
            'balance_after' => round((float)$stock->quantity_on_hand, 4),

            'unit_cost' => round($unitCost, 4),
            'total_cost' => round($totalCost, 4),

            'currency' => $currency,
            'currency_code' => $currency,

            'reference_type' => 'sale',
            'reference_id' => $saleId,

            'notes' => 'Sale stock deduction with COGS.',

            'metadata' => json_encode([
                'source' => 'sales',
                'movement_reason' => 'sale_stock_deduction',
                'costing_method' => 'weighted_average',

                'sale_id' => $saleId,
                'receipt_line_id' => $receiptLineId,
                'till_id' => $till?->id,
                'shift_session_id' => $shiftSessionId,

                'product' => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'code' => $product->code,
                    'sku' => $product->sku,
                    'barcode' => $product->barcode,
                    'product_type' => $product->product_type,
                    'parent_product_id' => $product->parent_product_id,
                ],

                'before' => [
                    'quantity_on_hand' => round($previousQuantityOnHand, 4),
                    'available_quantity' => round($previousAvailableQuantity, 4),
                    'reserved_quantity' => round($previousReservedQuantity, 4),

                    'cost_total_quantity' => $oldCostQuantity !== null ? round($oldCostQuantity, 4) : null,
                    'cost_total_value' => $oldCostValue !== null ? round($oldCostValue, 4) : null,
                    'average_cost' => $oldAverageCost !== null ? round($oldAverageCost, 4) : null,
                    'last_cost' => $oldLastCost !== null ? round($oldLastCost, 4) : null,
                ],

                'movement' => [
                    'quantity_out' => round($quantity, 4),
                    'unit_cost' => round($unitCost, 4),
                    'total_cost' => round($totalCost, 4),
                    'currency' => $currency,
                ],

                'after' => [
                    'quantity_on_hand' => round((float)$stock->quantity_on_hand, 4),
                    'available_quantity' => round((float)$stock->available_quantity, 4),
                    'reserved_quantity' => round((float)$stock->reserved_quantity, 4),

                    'cost_total_quantity' => $newCostQuantity !== null ? round($newCostQuantity, 4) : null,
                    'cost_total_value' => $newCostValue !== null ? round($newCostValue, 4) : null,
                    'average_cost' => $newAverageCost !== null ? round($newAverageCost, 4) : null,
                ],

                'product_cost_id' => $cost?->id,
            ]),

            'created_by' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function shouldAutoBreakBeforeSale(
        Product      $product,
        ProductStock $childStock,
        float        $saleQuantity
    ): bool
    {
        if ($product->product_type !== 'child') {
            return false;
        }

        if (!$product->parent_product_id) {
            return false;
        }

        $projectedAvailable = (float)$childStock->available_quantity - $saleQuantity;

        if ($projectedAvailable > 0) {
            return false;
        }

        return ProductBreakRule::where('company_id', $product->company_id)
            ->where('child_product_id', $product->id)
            ->where('is_auto_break', true)
            ->where('is_active', true)
            ->exists();
    }

    private function autoBreakChildForSale(
        Product $child,
        string  $branchId,
        float   $requiredSaleQuantity,
        float   $currentChildAvailable,
        string  $saleId
    ): void
    {
        $rule = ProductBreakRule::where('company_id', $child->company_id)
            ->where('child_product_id', $child->id)
            ->where('is_auto_break', true)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        if (!$rule) {
            return;
        }

        $parent = Product::where('company_id', $child->company_id)
            ->where('id', $rule->parent_product_id)
            ->lockForUpdate()
            ->first();

        if (!$parent) {
            throw new \Exception("Parent product not found for {$child->name}.");
        }

        $unitsPerParent = (float)$rule->units_per_parent;
        $parentQuantityPerBreak = (float)$rule->parent_quantity;

        if ($unitsPerParent <= 0 || $parentQuantityPerBreak <= 0) {
            throw new \Exception("Invalid break rule configured for {$child->name}.");
        }

        $shortage = max($requiredSaleQuantity - $currentChildAvailable, 0);

        if ($shortage <= 0) {
            $breakTimes = 1;
        } else {
            $breakTimes = (int)ceil($shortage / $unitsPerParent);
        }

        $parentQtyNeeded = $parentQuantityPerBreak * $breakTimes;
        $childQtyCreated = $unitsPerParent * $breakTimes;

        $parentStock = ProductStock::where('company_id', $parent->company_id)
            ->where('branch_id', $branchId)
            ->where('product_id', $parent->id)
            ->lockForUpdate()
            ->first();

        if (!$parentStock) {
            throw new \Exception("No parent stock found for {$parent->name}.");
        }

        if (!$parent->allow_negative_stock && (float)$parentStock->available_quantity < $parentQtyNeeded) {
            throw new \Exception(
                "Insufficient parent stock for auto-break. Parent: {$parent->name}, required: {$parentQtyNeeded}."
            );
        }

        $childStock = ProductStock::where('company_id', $child->company_id)
            ->where('branch_id', $branchId)
            ->where('product_id', $child->id)
            ->lockForUpdate()
            ->first();

        if (!$childStock) {
            $childStock = ProductStock::create([
                'id' => (string)Str::uuid(),
                'company_id' => $child->company_id,
                'branch_id' => $branchId,
                'product_id' => $child->id,
                'quantity_on_hand' => 0,
                'reserved_quantity' => 0,
                'available_quantity' => 0,
            ]);

            $childStock = ProductStock::where('id', $childStock->id)
                ->lockForUpdate()
                ->first();
        }

        $parentCost = ProductCost::where('company_id', $parent->company_id)
            ->where('branch_id', $branchId)
            ->where('product_id', $parent->id)
            ->lockForUpdate()
            ->first();

        $parentUnitCost = (float)($parentCost?->average_cost ?? 0);
        $totalCostMoved = $parentUnitCost * $parentQtyNeeded;
        $childUnitCost = $childQtyCreated > 0
            ? $totalCostMoved / $childQtyCreated
            : 0;

        $parentStock->quantity_on_hand = (float)$parentStock->quantity_on_hand - $parentQtyNeeded;
        $parentStock->available_quantity = (float)$parentStock->available_quantity - $parentQtyNeeded;
        $parentStock->save();

        $childStock->quantity_on_hand = (float)$childStock->quantity_on_hand + $childQtyCreated;
        $childStock->available_quantity = (float)$childStock->available_quantity + $childQtyCreated;
        $childStock->save();

        $this->addAverageCostForAutoBreak(
            product: $child,
            branchId: $branchId,
            quantity: $childQtyCreated,
            unitCost: $childUnitCost
        );

        DB::table('stock_movements')->insert([
            [
                'id' => (string)Str::uuid(),
                'company_id' => $parent->company_id,
                'branch_id' => $branchId,
                'product_id' => $parent->id,
                'movement_type' => 'break_parent',
                'quantity_in' => 0,
                'quantity_out' => $parentQtyNeeded,
                'unit_cost' => $parentUnitCost,
                'total_cost' => $totalCostMoved,
                'reference_type' => 'sale_auto_break',
                'reference_id' => $saleId,
                'notes' => "Auto break during sale for child product: {$child->name}",
                'created_by' => auth()->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string)Str::uuid(),
                'company_id' => $child->company_id,
                'branch_id' => $branchId,
                'product_id' => $child->id,
                'movement_type' => 'create_child',
                'quantity_in' => $childQtyCreated,
                'quantity_out' => 0,
                'unit_cost' => $childUnitCost,
                'total_cost' => $totalCostMoved,
                'reference_type' => 'sale_auto_break',
                'reference_id' => $saleId,
                'notes' => "Auto-created child stock from parent during sale: {$parent->name}",
                'created_by' => auth()->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    private function addAverageCostForAutoBreak(
        Product $product,
        string  $branchId,
        float   $quantity,
        float   $unitCost
    ): void
    {
        $cost = ProductCost::firstOrCreate(
            [
                'company_id' => $product->company_id,
                'branch_id' => $branchId,
                'product_id' => $product->id,
            ],
            [
                'average_cost' => 0,
                'last_cost' => 0,
                'total_quantity' => 0,
                'total_value' => 0,
            ]
        );

        $oldQuantity = (float)$cost->total_quantity;
        $oldValue = (float)$cost->total_value;

        $newValue = $quantity * $unitCost;
        $newTotalQuantity = $oldQuantity + $quantity;
        $newTotalValue = $oldValue + $newValue;

        $cost->average_cost = $newTotalQuantity > 0
            ? $newTotalValue / $newTotalQuantity
            : 0;

        $cost->last_cost = $unitCost;
        $cost->total_quantity = $newTotalQuantity;
        $cost->total_value = $newTotalValue;
        $cost->save();
    }

    private function saveSalePayment(
        SalesTransaction $sale,
        PaymentType      $paymentType,
        string           $companyId,
        string           $branchId,
        ?string          $tillId,
        ?string          $shiftId,
        ?string          $shiftSessionId,
        string           $currencyCode,
        string           $baseCurrencyCode,
        float            $exchangeRate,
        float            $amountTendered,
        float            $saleTotal,
        float            $change
    ): void
    {
        $baseAmount = $this->convertSaleAmountToBase(
            amount: $saleTotal,
            exchangeRate: $exchangeRate
        );

        DB::table('sales_transaction_payments')->insert([
            'id' => (string)Str::uuid(),

            'company_id' => $companyId,
            'branch_id' => $branchId,
            'till_id' => $tillId,
            'shift_id' => $shiftId,
            'shift_session_id' => $shiftSessionId,

            'sales_transaction_id' => $sale->id,
            'payment_type_id' => $paymentType->id,

            'payment_name' => $paymentType->name,
            'money_code' => $paymentType->money_code,

            'currency_code' => $currencyCode,
            'base_currency_code' => $baseCurrencyCode,
            'exchange_rate' => $exchangeRate,

            'amount' => round($saleTotal, 4),
            'base_amount' => round($baseAmount, 4),

            'amount_tendered' => round($amountTendered, 4),
            'change_amount' => round($change, 4),

            'reference' => null,
            'approval_code' => null,
            'card_last_four' => null,

            'metadata' => json_encode([
                'normal_sale' => true,
                'shift_session_id' => $shiftSessionId,
                'shift_configuration_id' => $shiftId,
                'exchange_rate_snapshot' => true,
            ]),

            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function validatePaymentCurrency(PaymentType $paymentType, string $currencyCode)
    {
        if ((bool)$paymentType->supportsMultiCurrency) {
            return true;
        }

        if (!$paymentType->default_currency_code) {
            return response()->json([
                'message' => 'This payment type does not support multi-currency and has no default currency configured.',
            ], 422);
        }

        if ($paymentType->default_currency_code !== $currencyCode) {
            return response()->json([
                'message' => "{$paymentType->name} only supports {$paymentType->default_currency_code}.",
            ], 422);
        }

        return true;
    }

    private function generateReceiptNumber(
        string  $companyId,
        string  $branchId,
        ?string $tillId,
        ?string $tillCode = null
    ): string
    {
        return DB::transaction(function () use ($companyId, $branchId, $tillId, $tillCode) {
            $today = Carbon::today()->toDateString();
            $datePart = Carbon::today()->format('ymd');

            $prefix = 'SAL';

            if ($tillCode) {
                $cleanTillCode = strtoupper(preg_replace('/[^A-Z0-9]/', '', $tillCode));
                $prefix = 'SAL-' . $cleanTillCode;
            }

            $sequence = ReceiptNumberSequence::where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('till_id', $tillId)
                ->where('sequence_type', 'sale_receipt')
                ->whereDate('sequence_date', $today)
                ->lockForUpdate()
                ->first();

            if (!$sequence) {
                $sequence = ReceiptNumberSequence::create([
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'till_id' => $tillId,
                    'sequence_type' => 'sale_receipt',
                    'prefix' => $prefix,
                    'sequence_date' => $today,
                    'last_number' => 0,
                ]);

                $sequence = ReceiptNumberSequence::where('id', $sequence->id)
                    ->lockForUpdate()
                    ->first();
            }

            $nextNumber = ((int)$sequence->last_number) + 1;

            do {
                $receiptNumber = $prefix . '-' . $datePart . '-' . str_pad($nextNumber, 6, '0', STR_PAD_LEFT);

                $exists = SalesTransaction::where('invoice_number', $receiptNumber)->exists();

                if (!$exists) {
                    break;
                }

                $nextNumber++;
            } while (true);

            $sequence->update([
                'prefix' => $prefix,
                'last_number' => $nextNumber,
            ]);

            return $receiptNumber;
        });
    }

    private function getExchangeRate(
        string $fromCode,
        string $toCode,
        string $companyId,
        string $branchId
    ): float
    {
        if ($fromCode === $toCode) {
            return 1;
        }

        $rate = (float)currencyConverter($fromCode, $toCode, 1, $companyId, $branchId);

        if (!is_finite($rate) || $rate <= 0) {
            throw new \RuntimeException("Invalid exchange rate from {$fromCode} to {$toCode}.");
        }

        return $rate;
    }

    /**
     * Convert with the immutable base -> sale rate captured on the sale header.
     */
    private function convertBaseAmountToSale(float $amount, float $exchangeRate): float
    {
        if (!is_finite($exchangeRate) || $exchangeRate <= 0) {
            throw new \RuntimeException('The sale exchange-rate snapshot is invalid.');
        }

        return round($amount * $exchangeRate, 4);
    }

    /**
     * Reverse the immutable base -> sale rate captured on the sale header.
     */
    private function convertSaleAmountToBase(float $amount, float $exchangeRate): float
    {
        if (!is_finite($exchangeRate) || $exchangeRate <= 0) {
            throw new \RuntimeException('The sale exchange-rate snapshot is invalid.');
        }

        return round($amount / $exchangeRate, 4);
    }

    private function getOpenShift(
        string $companyId,
        string $branchId,
        string $tillId,
        string $cashierId
    )
    {
        return DB::table('pos_user_shift_sessions')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('till_id', $tillId)
            ->where('pos_user_id', $cashierId)
            ->where('status', 'open')
            ->whereNull('closed_at')
            ->first();
    }

    /** Historical Android receipts must be bound to their original shift, not today's open shift. */
    private function historicalMobileShift(
        string $companyId,
        string $branchId,
        string $tillId,
        string $cashierId,
        Carbon $occurredAt
    ) {
        $matches = DB::table('pos_user_shift_sessions')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('till_id', $tillId)
            ->where('pos_user_id', $cashierId)
            ->where('opened_at', '<=', $occurredAt->toDateTimeString())
            ->where(function ($query) use ($occurredAt): void {
                $query->whereNull('closed_at')
                    ->orWhere('closed_at', '>=', $occurredAt->toDateTimeString());
            })
            ->lockForUpdate()->limit(2)->get();

        // Overlapping historical sessions are ambiguous and must be reviewed.
        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** @return array<string, mixed> */
    private function historicalMobileSaleDatePayload(
        MobileSaleSnapshot $snapshot,
        string $companyId,
        string $branchId,
        Carbon $occurredAt,
        array $products
    ): array {
        $originalDate = (string) $snapshot->businessDate();
        $originalSystemDate = $occurredAt->toDateString();
        $currentMode = $this->companySettingService->businessMode($companyId, $branchId);
        if ($currentMode !== $snapshot->businessMode()) {
            return ['reconciliation_reason' => 'BUSINESS_MODE_CHANGED_SINCE_SALE'];
        }
        if ($occurredAt->greaterThan(now()->addMinutes(self::MOBILE_SALE_MAX_FUTURE_SKEW_MINUTES))
            || $occurredAt->lessThan(Carbon::parse($snapshot->generatedAt())->subMinutes(5))) {
            return ['reconciliation_reason' => 'SALE_OUTSIDE_ACKNOWLEDGED_CONFIGURATION_WINDOW'];
        }
        if ($snapshot->businessMode() !== 'hospitality') {
            if ($originalDate !== $originalSystemDate) {
                return ['reconciliation_reason' => 'RETAIL_BUSINESS_DATE_OUTSIDE_SALE_DATE'];
            }
            return array_replace($this->emptyFoodIndustrySaleDatePayload(), [
                'business_date' => $originalDate,
                'system_date' => $originalSystemDate,
                'transaction_date' => $occurredAt,
                'trading_date_matches_system_date' => true,
            ]);
        }

        foreach ($products as $item) {
            if (! $this->historicalMenuRecipeUnchanged($snapshot, $companyId, (string) ($item['product_id'] ?? ''))) {
                return ['reconciliation_reason' => 'MENU_RECIPE_CHANGED_SINCE_SALE'];
            }
        }

        $day = TradingDay::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereDate('trading_date', $originalDate)
            ->lockForUpdate()->first();
        if (! $day || ! $day->opened_at) {
            return ['reconciliation_reason' => 'HISTORICAL_TRADING_DAY_NOT_FOUND'];
        }
        if (! MobileSaleReplayWindow::contains(
            $occurredAt, $day->opened_at, $day->closed_at, $day->day_end_started_at
        )) {
            return [
                'reconciliation_reason' => 'SALE_OUTSIDE_HISTORICAL_TRADING_DAY',
                'reconciliation_context' => ['trading_day_id' => $day->id],
            ];
        }
        if ($day->status !== 'open' || $day->closed_at) {
            return [
                'reconciliation_reason' => 'TRADING_DAY_ALREADY_FINALIZED',
                'reconciliation_context' => ['trading_day_id' => $day->id],
            ];
        }

        return [
            'food_industry_business_date_enabled' => true,
            'sale_blocked' => false,
            'sale_block_reason' => null,
            'trading_day_id' => $day->id,
            'business_date' => $originalDate,
            'system_date' => $originalSystemDate,
            'transaction_date' => $occurredAt,
            'trading_day_status_at_sale' => 'open',
            'trading_date_is_future' => $originalDate > $originalSystemDate,
            'trading_date_is_past' => $originalDate < $originalSystemDate,
            'trading_date_matches_system_date' => $originalDate === $originalSystemDate,
        ];
    }

    private function historicalMenuRecipeUnchanged(
        MobileSaleSnapshot $snapshot,
        string $companyId,
        string $productId
    ): bool {
        $currentRecipes = FoodRecipe::query()
            ->where('company_id', $companyId)
            ->where('menu_product_id', $productId)
            ->where('recipe_type', 'menu')
            ->where('is_active', true)
            ->with('ingredients')
            ->get();
        $currentIds = $currentRecipes->pluck('id')->map(fn ($id): string => (string) $id)->all();
        sort($currentIds, SORT_STRING);
        if ($currentIds !== $snapshot->activeMenuRecipeIds($productId)) {
            return false;
        }
        $snapshotTime = Carbon::parse($snapshot->generatedAt());
        foreach ($currentRecipes as $recipe) {
            if (($recipe->updated_at && $recipe->updated_at->greaterThan($snapshotTime))
                || $recipe->ingredients->contains(fn ($ingredient): bool =>
                    $ingredient->updated_at && $ingredient->updated_at->greaterThan($snapshotTime))) {
                return false;
            }
        }
        return true;
    }

    private function pendingMobileSaleReconciliationResponse(
        Request $request,
        ?SyncNode $node,
        $cashier,
        MobileSaleSnapshot $snapshot,
        Carbon $occurredAt,
        string $reason,
        array $context = []
    ) {
        if (! $node || ! $node->till_id || ! $request->filled('client_sale_id')) {
            return response()->json(['code' => 'MOBILE_SALE_RECONCILIATION_SCOPE_INVALID',
                'message' => 'The queued sale cannot be staged without its paired node, till and client sale ID.'], 403);
        }
        try {
            $record = app(MobileSaleReconciliationService::class)->stage(
                $node, (string) $cashier->id, (string) $request->input('client_sale_id'),
                (string) $snapshot->businessDate(), $occurredAt, $snapshot,
                $request->all(), $reason, $context
            );
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['code' => 'MOBILE_SALE_RECONCILIATION_CONFLICT',
                'message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'code' => 'MOBILE_SALE_PENDING_RECONCILIATION',
            'message' => 'The original offline receipt is preserved for manager reconciliation; it was not posted into closed books.',
            'data' => [
                'status' => 'pending_reconciliation',
                'reconciliation_id' => $record->id,
                'reason_code' => $record->reason_code,
                'sale_posted' => false,
                'fiscal_proof_verified' => false,
            ],
        ], 409);
    }


    public function findReceiptForAction(Request $request)
    {
        $company = app('company');
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'view_sale', $company->id);

        if ($permission !== true) {
            return $permission;
        }

        $validator = Validator::make($request->all(), [
            'invoice_number' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $branchIds = $this->accessibleReceiptBranchIds($request, $company->id);

        $sale = SalesTransaction::with([
            'company',
            'branch',
            'till',
            'receiptLines.taxes.tax',
            'receiptLines.product',
        ])
            ->where('company_id', $company->id)
            ->where('invoice_number', trim($request->invoice_number))
            ->whereIn('branch_id', $branchIds)
            ->first();

        if (!$sale) {
            return response()->json([
                'message' => 'Receipt not found.',
            ], 404);
        }

        return response()->json([
            'message' => 'Receipt fetched successfully.',
            'data' => [
                'sale' => $sale,
                'can_cancel' => $sale->status === 'completed',
                'can_reprint' => in_array($sale->status, ['completed', 'cancelled', 'voided'], true),
            ],
        ]);
    }

    public function reprintReceipt(Request $request)
    {
        $company = app('company');
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'reprint_receipt', $company->id);

        if ($permission !== true) {
            return $permission;
        }

        $validator = Validator::make($request->all(), [
            'invoice_number' => ['required_without:sale_id', 'nullable', 'string', 'max:255'],
            'sale_id' => ['required_without:invoice_number', 'nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $branchIds = $this->accessibleReceiptBranchIds($request, $company->id);

        $sale = $this->receiptQueryForAction($company->id)
            ->whereIn('branch_id', $branchIds)
            ->when($request->sale_id, fn($q) => $q->where('id', $request->sale_id))
            ->when($request->invoice_number, fn($q) => $q->where('invoice_number', trim($request->invoice_number)))
            ->first();

        if (!$sale) {
            return response()->json([
                'message' => 'Receipt not found.',
            ], 404);
        }

        $receipt = $this->prepareReceiptAfterFiscalisation($sale);

        return response()->json([
            'message' => 'Receipt prepared for reprint successfully.',
            'data' => [
                'sale' => $sale,
                'receipt_url' => $receipt['receipt_url'],
                'receipt_settings' => $receipt['receipt_settings'],
                'print' => [
                    'auto_print' => true,
                    'printer' => $receipt['printer'],
                ],
            ],
        ]);
    }

    public function cancelReceipt(Request $request)
    {
        $company = app('company');
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'cancel_receipt', $company->id);

        if ($permission !== true) {
            return $permission;
        }

        $validator = Validator::make($request->all(), [
            'invoice_number' => ['required_without:sale_id', 'nullable', 'string', 'max:255'],
            'sale_id' => ['required_without:invoice_number', 'nullable', 'string'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'manager_pin' => ['nullable', 'string', 'max:255'],
            'reprint_cancelled_receipt' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $branchIds = $this->accessibleReceiptBranchIds($request, $company->id);

        DB::beginTransaction();

        try {
            $sale = $this->receiptQueryForAction($company->id)
                ->whereIn('branch_id', $branchIds)
                ->where('status', 'completed')
                ->when($request->sale_id, fn($q) => $q->where('id', $request->sale_id))
                ->when($request->invoice_number, fn($q) => $q->where('invoice_number', trim($request->invoice_number)))
                ->lockForUpdate()
                ->first();

            if (!$sale) {
                DB::rollBack();

                return response()->json([
                    'message' => 'Completed receipt not found or already cancelled.',
                ], 404);
            }

            $existingCancel = SalesTransaction::where('company_id', $company->id)
                ->where('metadata->cancelled_original_sale_id', $sale->id)
                ->where('sale_type', 'sale_cancel')
                ->first();

            if ($existingCancel) {
                DB::rollBack();

                return response()->json([
                    'message' => 'This receipt has already been cancelled.',
                    'data' => [
                        'cancel_transaction' => $existingCancel,
                    ],
                ], 422);
            }

            $originalTill = $sale->till;

            $cancelNumber = $this->generateCancellationReceiptNumber(
                companyId: $company->id,
                branchId: $sale->branch_id,
                tillId: $sale->till_id,
                tillCode: $originalTill?->till_code
            );

            $cancelBusinessDatePayload = $this->foodIndustrySaleDatePayload(
                companyId: $company->id,
                branchId: $sale->branch_id
            );

            if (($cancelBusinessDatePayload['sale_blocked'] ?? false) === true) {
                DB::rollBack();

                return response()->json([
                    'message' => 'Cancellation rejected by trading day control.',
                    'code' => 'HOSPITALITY_TRADING_DATE_BLOCKED',
                    'error' => $cancelBusinessDatePayload['sale_block_reason']
                        ?? 'The current Hospitality trading day is not allowed to post transactions.',
                    'data' => [
                        'business_date' => $cancelBusinessDatePayload['business_date'] ?? null,
                        'system_date' => $cancelBusinessDatePayload['system_date'] ?? null,
                        'trading_day_id' => $cancelBusinessDatePayload['trading_day_id'] ?? null,
                        'trading_day_status' => $cancelBusinessDatePayload['trading_day_status_at_sale'] ?? null,
                        'trading_date_is_future' => $cancelBusinessDatePayload['trading_date_is_future'] ?? false,
                        'trading_date_is_past' => $cancelBusinessDatePayload['trading_date_is_past'] ?? false,
                    ],
                ], 422);
            }

            // On a paired edge, upload the original sale before any reversal
            // side effects so the cloud never receives a void without its sale.
            app(SyncSaleBundleService::class)->queueSaleBeforeCancellation(
                (string) $sale->company_id,
                (string) $sale->branch_id,
                (string) $sale->id
            );

            $cancelTransaction = SalesTransaction::create([
                'id' => (string)Str::uuid(),

                'company_id' => $sale->company_id,
                'branch_id' => $sale->branch_id,
                'till_id' => $sale->till_id,

                'shift_id' => $sale->shift_id,
                'shift_session_id' => $sale->shift_session_id,

                'cashier_id' => $user->id,
                'user_id' => $user->id,

                'invoice_number' => $cancelNumber,
                'transaction_date' => now(),

                'document_type' => 'cancel_receipt',
                'sale_type' => 'sale_cancel',
                'status' => 'completed',

                'currency_code' => $sale->currency_code,
                'base_currency_code' => $sale->base_currency_code,
                'exchange_rate' => $sale->exchange_rate,

                'subtotal_amount' => -1 * (float)$sale->subtotal_amount,
                'discount_amount' => -1 * (float)$sale->discount_amount,
                'tax_amount' => -1 * (float)$sale->tax_amount,
                'total_amount' => -1 * (float)$sale->total_amount,

                'base_subtotal_amount' => -1 * (float)$sale->base_subtotal_amount,
                'base_discount_amount' => -1 * (float)$sale->base_discount_amount,
                'base_tax_amount' => -1 * (float)$sale->base_tax_amount,
                'base_total_amount' => -1 * (float)$sale->base_total_amount,

                'payment_type_id' => $sale->payment_type_id,
                'payment_method' => $sale->payment_method,

                'amount_tendered' => 0,
                'change_amount' => 0,
                'change' => 0,

                'customer_name' => $sale->customer_name,
                'customer_phone' => $sale->customer_phone,
                'customer_tax_number' => $sale->customer_tax_number,
                'notes' => 'Cancellation of ' . $sale->invoice_number . '. Reason: ' . $request->reason,

                'metadata' => [
                    'cancelled_original_sale_id' => $sale->id,
                    'cancelled_original_invoice_number' => $sale->invoice_number,
                    'cancelled_by' => $user->id,
                    'cancelled_by_name' => $user->name ?? $user->username ?? null,
                    'cancel_reason' => $request->reason,
                    'cancelled_at' => now()->toDateTimeString(),
                    'till_code' => $originalTill?->till_code,
                    'till_name' => $originalTill?->till_name,
                    'food_industry_business_date_enabled' => $cancelBusinessDatePayload['food_industry_business_date_enabled'],
                    'business_date' => $cancelBusinessDatePayload['business_date'],
                    'trading_day_id' => $cancelBusinessDatePayload['trading_day_id'],
                    'trading_day_status_at_sale' => $cancelBusinessDatePayload['trading_day_status_at_sale'],
                ],
            ]);

            $this->applyFoodIndustryBusinessDateToSale($cancelTransaction, $cancelBusinessDatePayload);

            $this->createCancellationReceiptLines(
                originalSale: $sale,
                cancelTransaction: $cancelTransaction
            );

            $this->restoreCancelledSaleStock(
                sale: $sale,
                cancelTransaction: $cancelTransaction,
                userId: $user->id,
                reason: $request->reason
            );

            $this->reverseSalePayments(
                originalSale: $sale,
                cancelTransaction: $cancelTransaction,
                userId: $user->id
            );

            if ((bool)($sale->is_credit_sale ?? false) || $sale->credit_customer_id || $sale->credit_account_number) {
                app(CreditCustomerService::class)->reverseCreditSaleForCancellation(
                    originalSale: $sale,
                    cancelTransaction: $cancelTransaction,
                    reason: $request->reason,
                    userId: $user->id
                );
            }

            $metadata = $sale->metadata ?: [];
            if (!is_array($metadata)) {
                $metadata = json_decode($metadata, true) ?: [];
            }

            $metadata['cancelled'] = true;
            $metadata['cancel_transaction_id'] = $cancelTransaction->id;
            $metadata['cancel_invoice_number'] = $cancelTransaction->invoice_number;
            $metadata['cancelled_by'] = $user->id;
            $metadata['cancelled_by_name'] = $user->name ?? $user->username ?? null;
            $metadata['cancel_reason'] = $request->reason;
            $metadata['cancelled_at'] = now()->toDateTimeString();

            $sale->status = 'cancelled';
            $sale->metadata = $metadata;
            $sale->save();

            DB::commit();

            /*
             |--------------------------------------------------------------------------
             | ZIMRA fiscalisation for cancellation / CreditNote
             |--------------------------------------------------------------------------
             | The cancellation is already committed locally. For FDMS this must be
             | submitted as a CreditNote and must reference the original submitted
             | fiscal receipt using receiptID or deviceID + receiptGlobalNo + fiscalDayNo.
             */
            $fiscalisation = $this->fiscaliseCancellationAfterCommit(
                cancelTransaction: $cancelTransaction,
                originalSale: $sale->fresh(),
                reason: $request->reason
            );

            if (($fiscalisation['blocked'] ?? false) === true) {
                return response()->json([
                    'message' => 'Receipt cancelled locally, but ZIMRA fiscalisation failed.',
                    'error' => $fiscalisation['error'] ?? 'Fiscalisation failed.',
                    'data' => [
                        'original_sale' => $sale->fresh(),
                        'cancel_transaction' => $fiscalisation['sale'] ?? $cancelTransaction->fresh(),
                        'fiscalisation' => $fiscalisation,
                    ],
                ], 409);
            }

            $cancelForReceipt = SalesTransaction::with([
                'company',
                'branch',
                'till',
                'receiptLines.taxes.tax',
            ])->where('id', ($fiscalisation['sale']->id ?? $cancelTransaction->id))->firstOrFail();

            $cancelForReceipt = $this->hydrateSaleFiscalFieldsFromZimraReceipt($cancelForReceipt);

            $receipt = $this->prepareReceiptAfterFiscalisation($cancelForReceipt);

            return response()->json([
                'message' => 'Receipt cancelled successfully.',
                'data' => [
                    'original_sale' => $sale->fresh(),
                    'cancel_transaction' => $cancelForReceipt,
                    'receipt_url' => $receipt['receipt_url'],
                    'receipt_settings' => $receipt['receipt_settings'],
                    'print' => [
                        'auto_print' => (bool)$request->boolean('reprint_cancelled_receipt', true),
                        'printer' => $receipt['printer'],
                    ],

                    'fiscalisation' => $fiscalisation,
                ],
            ]);
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'message' => 'Receipt cancellation failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function cancelledReceipts(Request $request)
    {
        $company = app('company');
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'view_cancelled_receipts', $company->id);

        if ($permission !== true) {
            return $permission;
        }

        $dateFrom = $request->date_from ?: now()->toDateString();
        $dateTo = $request->date_to ?: now()->toDateString();
        $branchIds = $this->accessibleReceiptBranchIds($request, $company->id);

        $query = SalesTransaction::query()
            ->with(['branch', 'till'])
            ->where('company_id', $company->id)
            ->whereIn('branch_id', $branchIds)
            ->where(function ($q) {
                $q->where('sale_type', 'sale_cancel')
                    ->orWhere('document_type', 'cancel_receipt')
                    ->orWhere('status', 'cancelled');
            })
            ->whereDate('transaction_date', '>=', $dateFrom)
            ->whereDate('transaction_date', '<=', $dateTo)
            ->when($request->till_id, fn($q) => $q->where('till_id', $request->till_id))
            ->when($request->q, function ($q) use ($request) {
                $term = trim($request->q);

                $q->where(function ($sub) use ($term) {
                    $sub->where('invoice_number', 'like', "%{$term}%")
                        ->orWhere('customer_name', 'like', "%{$term}%")
                        ->orWhere('metadata->cancelled_original_invoice_number', 'like', "%{$term}%")
                        ->orWhere('metadata->cancel_reason', 'like', "%{$term}%");
                });
            })
            ->orderByDesc('transaction_date');

        return response()->json([
            'message' => 'Cancelled receipts fetched successfully.',
            'data' => $query->paginate($request->per_page ?? 25),
        ]);
    }

    private function receiptQueryForAction(string $companyId, ?string $branchId = null)
    {
        return SalesTransaction::with([
            'company',
            'branch',
            'till',
            'receiptLines.taxes.tax',
            'receiptLines.product',
        ])
            ->where('company_id', $companyId)
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId));
    }

    private function createCancellationReceiptLines(
        SalesTransaction $originalSale,
        SalesTransaction $cancelTransaction
    ): void
    {
        $lines = ReceiptLine::where('sales_transaction_id', $originalSale->id)
            ->orderBy('line_no')
            ->get();

        foreach ($lines as $line) {
            $cancelLine = ReceiptLine::create([
                'id' => (string)Str::uuid(),

                'company_id' => $line->company_id,
                'branch_id' => $line->branch_id,
                'sales_transaction_id' => $cancelTransaction->id,

                'product_id' => $line->product_id,
                'product_price_id' => $line->product_price_id,
                'price_level_id' => $line->price_level_id,

                'line_no' => $line->line_no,
                'line_name' => 'CANCEL - ' . $line->line_name,
                'product_code' => $line->product_code,
                'sku' => $line->sku,
                'barcode' => $line->barcode,
                'hs_code' => $line->hs_code,

                'line_quantity' => -1 * (float)$line->line_quantity,
                'line_price' => (float)$line->line_price,
                'line_subtotal' => -1 * (float)$line->line_subtotal,
                'line_discount' => -1 * (float)$line->line_discount,
                'line_tax' => -1 * (float)$line->line_tax,
                'line_total' => -1 * (float)$line->line_total,

                'base_line_price' => (float)$line->base_line_price,
                'base_line_subtotal' => -1 * (float)$line->base_line_subtotal,
                'base_line_discount' => -1 * (float)$line->base_line_discount,
                'base_line_tax' => -1 * (float)$line->base_line_tax,
                'base_line_total' => -1 * (float)$line->base_line_total,

                'currency_code' => $line->currency_code,
                'base_currency_code' => $line->base_currency_code,

                'metadata' => array_merge(
                    is_array($line->metadata) ? $line->metadata : (json_decode($line->metadata ?? '[]', true) ?: []),
                    [
                        'cancel_line' => true,
                        'original_receipt_line_id' => $line->id,
                        'original_sale_id' => $originalSale->id,
                    ]
                ),
            ]);

            $taxes = ReceiptLineTaxe::where('receipt_line_id', $line->id)->get();

            foreach ($taxes as $tax) {
                ReceiptLineTaxe::create([
                    'id' => (string)Str::uuid(),

                    'company_id' => $tax->company_id,
                    'branch_id' => $tax->branch_id,
                    'sales_transaction_id' => $cancelTransaction->id,
                    'receipt_line_id' => $cancelLine->id,

                    'tax_id' => $tax->tax_id,
                    'tax_code' => $tax->tax_code,
                    'tax_percent' => $tax->tax_percent,
                    'is_inclusive' => $tax->is_inclusive,
                    'obliged_to_zimra' => $tax->obliged_to_zimra,

                    'tax_amount' => -1 * (float)$tax->tax_amount,
                    'sales_amount_with_tax' => -1 * (float)$tax->sales_amount_with_tax,

                    'currency_code' => $tax->currency_code,

                    'metadata' => array_merge(
                        is_array($tax->metadata) ? $tax->metadata : (json_decode($tax->metadata ?? '[]', true) ?: []),
                        [
                            'cancel_tax_line' => true,
                            'original_receipt_line_tax_id' => $tax->id,
                        ]
                    ),
                ]);
            }
        }
    }

//    private function restoreCancelledSaleStock(
//        SalesTransaction $sale,
//        SalesTransaction $cancelTransaction,
//        string           $userId,
//        string           $reason
//    ): void
//    {
//        $lines = ReceiptLine::where('sales_transaction_id', $sale->id)->get();
//
//        foreach ($lines as $line) {
//            $product = Product::where('company_id', $sale->company_id)
//                ->where('id', $line->product_id)
//                ->first();
//
//            if (!$product || !(bool)$product->stock_tracking_enabled) {
//                continue;
//            }
//
//            $quantity = abs((float)$line->line_quantity);
//
//            $stock = ProductStock::firstOrCreate(
//                [
//                    'company_id' => $sale->company_id,
//                    'branch_id' => $sale->branch_id,
//                    'product_id' => $product->id,
//                ],
//                [
//                    'id' => (string)Str::uuid(),
//                    'quantity_on_hand' => 0,
//                    'reserved_quantity' => 0,
//                    'available_quantity' => 0,
//                ]
//            );
//
//            $stock = ProductStock::where('id', $stock->id)->lockForUpdate()->first();
//
//            $stock->quantity_on_hand = (float)$stock->quantity_on_hand + $quantity;
//            $stock->available_quantity = (float)$stock->available_quantity + $quantity;
//            $stock->save();
//
//            $cost = ProductCost::where('company_id', $sale->company_id)
//                ->where('branch_id', $sale->branch_id)
//                ->where('product_id', $product->id)
//                ->first();
//
//            $unitCost = (float)($cost?->average_cost ?: $cost?->last_cost ?: 0);
//
//            DB::table('stock_movements')->insert([
//                'id' => (string)Str::uuid(),
//                'company_id' => $sale->company_id,
//                'branch_id' => $sale->branch_id,
//                'till_id' => $sale->till_id,
//                'shift_session_id' => $sale->shift_session_id,
//                'product_id' => $product->id,
//                'movement_type' => 'sale_cancel',
//                'quantity_in' => $quantity,
//                'quantity_out' => 0,
//                'balance_after' => $stock->quantity_on_hand,
//                'unit_cost' => $unitCost,
//                'total_cost' => $unitCost * $quantity,
//                'currency' => $cost?->currency,
//                'currency_code' => $cost?->currency,
//                'reference_type' => 'sale_cancel',
//                'reference_id' => $cancelTransaction->id,
//                'notes' => 'Receipt cancellation stock restore. Original invoice: ' . $sale->invoice_number . '. Reason: ' . $reason,
//                'metadata' => json_encode([
//                    'original_sale_id' => $sale->id,
//                    'original_invoice_number' => $sale->invoice_number,
//                    'cancel_invoice_number' => $cancelTransaction->invoice_number,
//                ]),
//                'created_by' => $userId,
//                'created_at' => now(),
//                'updated_at' => now(),
//            ]);
//        }
//    }
    private function restoreCancelledSaleStock(
        SalesTransaction $sale,
        SalesTransaction $cancelTransaction,
        string           $userId,
        string           $reason
    ): void
    {
        $lines = ReceiptLine::where('sales_transaction_id', $sale->id)->get();

        foreach ($lines as $line) {
            $product = Product::where('company_id', $sale->company_id)
                ->where('id', $line->product_id)
                ->first();

            if (!$product) {
                continue;
            }

            $quantity = abs((float)$line->line_quantity);

            if ($this->shouldUseRecipeFrontStockForSale($sale->company_id, $sale->branch_id, $product)) {
                $restoredByRecipe = $this->restoreMenuRecipeIngredientsToFrontStock(
                    menuProduct: $product,
                    branchId: $sale->branch_id,
                    soldQuantity: $quantity,
                    originalSaleId: $sale->id,
                    cancelTransactionId: $cancelTransaction->id,
                    receiptLineId: $line->id,
                    shiftSessionId: $sale->shift_session_id,
                    userId: $userId,
                    reason: $reason
                );

                if ($restoredByRecipe) {
                    continue;
                }
            }

            if (!(bool)$product->stock_tracking_enabled) {
                continue;
            }

            $stock = ProductStock::firstOrCreate(
                [
                    'company_id' => $sale->company_id,
                    'branch_id' => $sale->branch_id,
                    'product_id' => $product->id,
                ],
                [
                    'id' => (string)Str::uuid(),
                    'quantity_on_hand' => 0,
                    'reserved_quantity' => 0,
                    'available_quantity' => 0,
                ]
            );

            $stock = ProductStock::where('id', $stock->id)->lockForUpdate()->first();

            $stock->quantity_on_hand = (float)$stock->quantity_on_hand + $quantity;
            $stock->available_quantity = (float)$stock->available_quantity + $quantity;
            $stock->save();

            $restoredByBatch = app(InventoryAllocationService::class)->reverseSale([
                'company_id' => $sale->company_id,
                'branch_id' => $sale->branch_id,
                'product_id' => $product->id,
                'original_sale_id' => $sale->id,
                'cancel_sale_id' => $cancelTransaction->id,
                'receipt_line_id' => $line->id,
                'till_id' => $sale->till_id,
                'shift_session_id' => $sale->shift_session_id,
                'balance_after' => (float) $stock->quantity_on_hand,
                'user_id' => $userId,
            ]);

            $cost = ProductCost::where('company_id', $sale->company_id)
                ->where('branch_id', $sale->branch_id)
                ->where('product_id', $product->id)
                ->first();

            $unitCost = (float)($cost?->average_cost ?: $cost?->last_cost ?: 0);

            if (!$restoredByBatch) DB::table('stock_movements')->insert([
                'id' => (string)Str::uuid(),
                'company_id' => $sale->company_id,
                'branch_id' => $sale->branch_id,
                'till_id' => $sale->till_id,
                'shift_session_id' => $sale->shift_session_id,
                'product_id' => $product->id,
                'movement_type' => 'sale_cancel',
                'quantity_in' => $quantity,
                'quantity_out' => 0,
                'balance_after' => $stock->quantity_on_hand,
                'unit_cost' => $unitCost,
                'total_cost' => $unitCost * $quantity,
                'currency' => $cost?->currency,
                'currency_code' => $cost?->currency,
                'reference_type' => 'sale_cancel',
                'reference_id' => $cancelTransaction->id,
                'notes' => 'Receipt cancellation stock restore. Original invoice: ' . $sale->invoice_number . '. Reason: ' . $reason,
                'metadata' => json_encode([
                    'original_sale_id' => $sale->id,
                    'original_invoice_number' => $sale->invoice_number,
                    'cancel_invoice_number' => $cancelTransaction->invoice_number,
                ]),
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function reverseSalePayments(
        SalesTransaction $originalSale,
        SalesTransaction $cancelTransaction,
        string           $userId
    ): void
    {
        if (!Schema::hasTable('sales_transaction_payments')) {
            return;
        }

        $payments = DB::table('sales_transaction_payments')
            ->where('sales_transaction_id', $originalSale->id)
            ->get();

        foreach ($payments as $payment) {
            DB::table('sales_transaction_payments')->insert([
                'id' => (string)Str::uuid(),

                'company_id' => $payment->company_id,
                'branch_id' => $payment->branch_id,
                'till_id' => $payment->till_id,
                'shift_id' => $payment->shift_id,
                'shift_session_id' => $payment->shift_session_id,

                'sales_transaction_id' => $cancelTransaction->id,
                'payment_type_id' => $payment->payment_type_id,

                'payment_name' => $payment->payment_name,
                'money_code' => $payment->money_code,

                'currency_code' => $payment->currency_code,
                'base_currency_code' => $payment->base_currency_code,
                'exchange_rate' => $payment->exchange_rate,

                'amount' => -1 * (float)$payment->amount,
                'base_amount' => -1 * (float)$payment->base_amount,

                'amount_tendered' => 0,
                'change_amount' => 0,

                'reference' => 'CANCEL-' . $originalSale->invoice_number,
                'approval_code' => null,
                'card_last_four' => $payment->card_last_four ?? null,

                'metadata' => json_encode([
                    'payment_reversal' => true,
                    'original_sale_id' => $originalSale->id,
                    'original_payment_id' => $payment->id,
                    'cancel_transaction_id' => $cancelTransaction->id,
                    'created_by' => $userId,
                ]),

                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function generateCancellationReceiptNumber(
        string  $companyId,
        string  $branchId,
        ?string $tillId,
        ?string $tillCode = null
    ): string
    {
        return DB::transaction(function () use ($companyId, $branchId, $tillId, $tillCode) {
            $today = Carbon::today()->toDateString();
            $datePart = Carbon::today()->format('ymd');

            $prefix = 'CAN';

            if ($tillCode) {
                $cleanTillCode = strtoupper(preg_replace('/[^A-Z0-9]/', '', $tillCode));
                $prefix = 'CAN-' . $cleanTillCode;
            }

            $sequence = ReceiptNumberSequence::where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('till_id', $tillId)
                ->where('sequence_type', 'cancel_receipt')
                ->whereDate('sequence_date', $today)
                ->lockForUpdate()
                ->first();

            if (!$sequence) {
                $sequence = ReceiptNumberSequence::create([
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'till_id' => $tillId,
                    'sequence_type' => 'cancel_receipt',
                    'prefix' => $prefix,
                    'sequence_date' => $today,
                    'last_number' => 0,
                ]);

                $sequence = ReceiptNumberSequence::where('id', $sequence->id)
                    ->lockForUpdate()
                    ->first();
            }

            $nextNumber = ((int)$sequence->last_number) + 1;

            do {
                $receiptNumber = $prefix . '-' . $datePart . '-' . str_pad($nextNumber, 6, '0', STR_PAD_LEFT);

                $exists = SalesTransaction::where('invoice_number', $receiptNumber)->exists();

                if (!$exists) {
                    break;
                }

                $nextNumber++;
            } while (true);

            $sequence->update([
                'prefix' => $prefix,
                'last_number' => $nextNumber,
            ]);

            return $receiptNumber;
        });
    }


    public function receiptRegister(Request $request)
    {
        $company = app('company');
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'view_sale', $company->id);

        if ($permission !== true) {
            return $permission;
        }

        $dateFrom = $request->date_from ?: now()->toDateString();
        $dateTo = $request->date_to ?: $dateFrom;
        $receiptScope = $this->receiptAccessScope($request, $company->id);

        $query = SalesTransaction::query()
            ->with([
                'branch',
                'till',
            ])
            ->where('company_id', $company->id)
            ->whereIn('branch_id', $receiptScope['branch_ids'])
            ->when($receiptScope['till_id'], fn($q, $tillId) => $q->where('till_id', $tillId))
            ->whereDate('transaction_date', '>=', $dateFrom)
            ->whereDate('transaction_date', '<=', $dateTo)
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->currency_code, fn($q) => $q->where('currency_code', $request->currency_code))
            ->when(! $receiptScope['till_id'] && $request->till_id, fn($q) => $q->where('till_id', $request->till_id))
            ->when($request->q, function ($q) use ($request) {
                $term = trim($request->q);

                $q->where(function ($sub) use ($term) {
                    $sub->where('invoice_number', 'like', "%{$term}%")
                        ->orWhere('customer_name', 'like', "%{$term}%")
                        ->orWhere('customer_phone', 'like', "%{$term}%")
                        ->orWhere('payment_method', 'like', "%{$term}%");
                });
            })
            ->orderByDesc('transaction_date');

        return response()->json([
            'message' => 'Receipt register fetched successfully.',
            'data' => $query->paginate($request->per_page ?? 25),
        ]);
    }

    public function receiptRegisterShow(Request $request, string $saleId)
    {
        $company = app('company');
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'view_sale', $company->id);

        if ($permission !== true) {
            return $permission;
        }

        $receiptScope = $this->receiptAccessScope($request, $company->id);

        $sale = SalesTransaction::with([
            'company',
            'branch',
            'till',
            'receiptLines.taxes.tax',
            'receiptLines.product',
        ])
            ->where('company_id', $company->id)
            ->whereIn('branch_id', $receiptScope['branch_ids'])
            ->when($receiptScope['till_id'], fn($q, $tillId) => $q->where('till_id', $tillId))
            ->where('id', $saleId)
            ->first();

        if (!$sale) {
            return response()->json([
                'message' => 'Receipt not found.',
            ], 404);
        }

        return response()->json([
            'message' => 'Receipt fetched successfully.',
            'data' => [
                'sale' => $sale,
            ],
        ]);
    }

    public function receiptPdfForSharing(Request $request, string $saleId)
    {
        $company = app('company');
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'view_sale', $company->id);

        if ($permission !== true) {
            return $permission;
        }

        $receiptScope = $this->receiptAccessScope($request, $company->id);

        $sale = SalesTransaction::with([
            'company',
            'branch',
            'till',
            'receiptLines.taxes.tax',
            'receiptLines.product',
        ])
            ->where('company_id', $company->id)
            ->whereIn('branch_id', $receiptScope['branch_ids'])
            ->when($receiptScope['till_id'], fn($q, $tillId) => $q->where('till_id', $tillId))
            ->where('id', $saleId)
            ->first();

        if (!$sale) {
            return response()->json([
                'message' => 'Receipt not found.',
            ], 404);
        }

        $receipt = $this->prepareReceiptAfterFiscalisation($sale);

        return response()->json([
            'message' => 'Receipt PDF prepared successfully.',
            'data' => [
                'sale' => $sale,
                'receipt_url' => $receipt['receipt_url'],
                'receipt_settings' => $receipt['receipt_settings'],
                'print' => [
                    'auto_print' => false,
                    'printer' => $receipt['printer'] ?? null,
                ],
            ],
        ]);
    }


    public function receiptPdfFileForSharing(Request $request, string $saleId)
    {
        $company = app('company');
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'view_sale', $company->id);

        if ($permission !== true) {
            return $permission;
        }

        $receiptScope = $this->receiptAccessScope($request, $company->id);

        $sale = SalesTransaction::with([
            'company',
            'branch',
            'till',
            'receiptLines.taxes.tax',
            'receiptLines.product',
        ])
            ->where('company_id', $company->id)
            ->whereIn('branch_id', $receiptScope['branch_ids'])
            ->when($receiptScope['till_id'], fn($q, $tillId) => $q->where('till_id', $tillId))
            ->where('id', $saleId)
            ->first();

        if (!$sale) {
            return response()->json([
                'message' => 'Receipt not found.',
            ], 404);
        }

        $receipt = $this->prepareReceiptAfterFiscalisation($sale);
        $filePath = $this->resolveReceiptPdfPath($receipt['receipt_url'] ?? null, $sale);

        if (!$filePath || !is_file($filePath)) {
            return response()->json([
                'message' => 'Receipt PDF file was generated but could not be found on the server.',
                'receipt_url' => $receipt['receipt_url'] ?? null,
                'checked_paths' => $this->receiptPdfCandidatePaths($receipt['receipt_url'] ?? null, $sale),
            ], 404);
        }

        $fileName = ($sale->invoice_number ?: 'receipt') . '.pdf';

        return response()->file($filePath, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $fileName . '"',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }

    /**
     * Resolve the receipt rows visible to the current workspace.
     *
     * Front Office is always clamped to its authenticated terminal. Back Office
     * remains scoped to the user's allocated branches and may optionally filter
     * those rows by till.
     *
     * @return array{branch_ids: array<int, string>, till_id: ?string}
     */
    private function receiptAccessScope(Request $request, string $companyId): array
    {
        $user = $request->user();
        abort_if(!$user, 401, 'Unauthenticated.');

        if ($this->usesFrontOfficeWorkspace($request)) {
            $till = $request->attributes->get('resolved_till');

            abort_unless($till, 403, 'A registered till is required to view Front Office receipts.');
            abort_unless(
                (string) data_get($till, 'company_id') === (string) $companyId,
                403,
                'The registered till does not belong to this company.'
            );

            $branchId = (string) data_get($till, 'branch_id');
            $tillId = (string) data_get($till, 'id');

            abort_if($branchId === '' || $tillId === '', 403, 'The registered till scope is incomplete.');

            $requestedBranchId = $request->input('branch_id') ?: $request->header('X-Branch-Id');
            abort_if(
                $requestedBranchId && (string) $requestedBranchId !== $branchId,
                403,
                'Front Office receipts are limited to the registered till branch.'
            );

            $requestedTillId = $request->input('till_id') ?: $request->header('X-Till-Id');
            abort_if(
                $requestedTillId && (string) $requestedTillId !== $tillId,
                403,
                'Front Office receipts are limited to the registered till.'
            );

            return [
                'branch_ids' => [$branchId],
                'till_id' => $tillId,
            ];
        }

        $normalise = static fn ($value) => str_replace([' ', '-'], '_', strtolower(trim((string) $value)));
        $roleName = $normalise(data_get($user, 'role.name'));
        $userType = $normalise(data_get($user, 'user_type'));
        $isSuperAdmin = in_array($roleName, ['super_admin', 'super_administrator'], true)
            || in_array($userType, ['super_admin', 'super_administrator'], true);

        if ($isSuperAdmin) {
            $branchIds = DB::table('company_branches')
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->pluck('id');
        } else {
            $branchIds = collect();

            if (method_exists($user, 'branches')) {
                $branchIds = $user->branches()
                    ->where('company_branches.company_id', $companyId)
                    ->pluck('company_branches.id');
            }

            if (data_get($user, 'branch_id')) {
                $branchIds->push((string) data_get($user, 'branch_id'));
            }
        }

        $branchIds = $branchIds
            ->map(fn ($id) => (string) $id)
            ->filter()
            ->unique()
            ->values();

        abort_if($branchIds->isEmpty(), 403, 'No branches are allocated to this user.');

        $requestedBranchId = $request->input('branch_id') ?: $request->header('X-Branch-Id');

        if ($requestedBranchId) {
            $requestedBranchId = (string) $requestedBranchId;
            abort_unless(
                $branchIds->contains($requestedBranchId),
                403,
                'You are not allocated to the selected branch.'
            );

            return [
                'branch_ids' => [$requestedBranchId],
                'till_id' => null,
            ];
        }

        return [
            'branch_ids' => $branchIds->all(),
            'till_id' => null,
        ];
    }

    private function usesFrontOfficeWorkspace(Request $request): bool
    {
        $user = $request->user();
        $token = $user && method_exists($user, 'currentAccessToken')
            ? $user->currentAccessToken()
            : null;

        return $token
            && method_exists($token, 'can')
            && $token->can('workspace:front-office');
    }

    private function accessibleReceiptBranchIds(Request $request, string $companyId): array
    {
        return $this->receiptAccessScope($request, $companyId)['branch_ids'];
    }

    private function resolveReceiptPdfPath(?string $receiptUrl, SalesTransaction $sale): ?string
    {
        foreach ($this->receiptPdfCandidatePaths($receiptUrl, $sale) as $path) {
            if ($path && is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function receiptPdfCandidatePaths(?string $receiptUrl, SalesTransaction $sale): array
    {
        $paths = [];

        $invoice = $sale->invoice_number ?: 'receipt';
        $companyId = $sale->company_id;
        $branchId = $sale->branch_id;

        $relativeFromConvention = "receipts/{$companyId}/{$branchId}/{$invoice}.pdf";

        /*
         |--------------------------------------------------------------------------
         | These candidates cover the receipt paths normally used by the receipt
         | service: base path, public path, and storage public path.
         |--------------------------------------------------------------------------
         */
        $paths[] = base_path("assets/{$relativeFromConvention}");
        $paths[] = public_path("assets/{$relativeFromConvention}");
        $paths[] = storage_path("app/public/assets/{$relativeFromConvention}");
        $paths[] = storage_path("app/assets/{$relativeFromConvention}");

        if ($receiptUrl) {
            $urlPath = parse_url($receiptUrl, PHP_URL_PATH) ?: '';

            /*
             |--------------------------------------------------------------------------
             | Example URL:
             | /salexaapi/api/assets/receipts/company/branch/invoice.pdf
             |--------------------------------------------------------------------------
             */
            $assetMarker = '/api/assets/';
            $assetPos = strpos($urlPath, $assetMarker);

            if ($assetPos !== false) {
                $relativeAfterApiAssets = substr($urlPath, $assetPos + strlen($assetMarker));

                $paths[] = base_path("assets/{$relativeAfterApiAssets}");
                $paths[] = public_path("assets/{$relativeAfterApiAssets}");
                $paths[] = storage_path("app/public/assets/{$relativeAfterApiAssets}");
                $paths[] = storage_path("app/assets/{$relativeAfterApiAssets}");
            }

            $receiptsMarker = '/receipts/';
            $receiptsPos = strpos($urlPath, $receiptsMarker);

            if ($receiptsPos !== false) {
                $relativeAfterReceipts = substr($urlPath, $receiptsPos + 1);

                $paths[] = base_path("assets/{$relativeAfterReceipts}");
                $paths[] = public_path("assets/{$relativeAfterReceipts}");
                $paths[] = storage_path("app/public/assets/{$relativeAfterReceipts}");
                $paths[] = storage_path("app/assets/{$relativeAfterReceipts}");
            }
        }

        return array_values(array_unique(array_filter($paths)));
    }

    private function priceOverrideRequiresApproval(string $companyId, ?string $branchId): bool
    {
        $setting = \Modules\Authentication\Models\GeneralSetting::query()
            ->where('company_id', $companyId)
            ->where('setting_key', 'price_override_requires_manager_approval')
            ->where('is_active', true)
            ->where(function ($query) use ($branchId) {
                $query->whereNull('branch_id');

                if ($branchId) {
                    $query->orWhere('branch_id', $branchId);
                }
            })
            ->orderByRaw('CASE WHEN branch_id IS NULL THEN 1 ELSE 0 END')
            ->first();

        return $setting ? filter_var($setting->setting_value, FILTER_VALIDATE_BOOLEAN) : false;
    }

    private function validatePriceOverrideApproval(array $line, string $companyId, ?string $branchId): void
    {
        if (!$this->priceOverrideRequiresApproval($companyId, $branchId)) {
            return;
        }

        $systemPrice = (float)($line['system_price'] ?? $line['original_price'] ?? $line['selling_price'] ?? 0);
        $sellingPrice = (float)($line['price'] ?? $line['selling_price'] ?? 0);

        if (round($systemPrice, 4) === round($sellingPrice, 4)) {
            return;
        }

        $approvalId = $line['manager_approval']['approval_id'] ?? $line['price_override_approval_id'] ?? null;

        if (!$approvalId) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'price_override' => ['Manager approval is required for price override.'],
            ]);
        }

        $approval = \Illuminate\Support\Facades\DB::table('audit_trails')
            ->where('id', $approvalId)
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('module', 'manager-approval')
            ->where('action', 'manager_approval_price_override')
            ->where('status', 'success')
            ->first();

        if (!$approval) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'price_override' => ['Invalid or missing manager approval for price override.'],
            ]);
        }
    }

    /*
    Usage inside sale line loop:

    foreach ($request->items as $line) {
        $this->validatePriceOverrideApproval($line, $company->id, $branchId);
    }
    */


    public function convertCurrency(Request $request)
    {
        $company = app('company');

        $validator = Validator::make($request->all(), [
            'from' => [
                'required',
                'string',
                Rule::exists('currencies', 'code')
                    ->where('company_id', $company->id)
                    ->where('is_active', true),
            ],
            'to' => [
                'required',
                'string',
                Rule::exists('currencies', 'code')
                    ->where('company_id', $company->id)
                    ->where('is_active', true),
            ],
            'amount' => ['required', 'numeric', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $from = strtoupper(trim($request->from));
            $to = strtoupper(trim($request->to));
            $amount = (float)$request->amount;

            $convertedAmount = currencyConverter(
                $from,
                $to,
                $amount,
                $company->id
            );

            return response()->json([
                'success' => true,
                'message' => 'Currency converted successfully.',
                'data' => [
                    'from' => $from,
                    'to' => $to,
                    'amount' => round($amount, 4),
                    'converted_amount' => round($convertedAmount, 4),
                ],
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Currency conversion failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * @param array<string, float> $recipeAdjustments ingredient product id => negative
     *     quantity removed by the line's exclusion/substitution modifiers
     */
    private function deductSaleStockAccordingToMode(
        Product $product,
        string  $branchId,
        float   $quantity,
        string  $saleId,
        ?string $receiptLineId,
        ?string $shiftSessionId,
        string  $companyId,
        array   $recipeAdjustments = []
    ): void
    {
        if ($this->shouldUseRecipeFrontStockForSale($companyId, $branchId, $product)) {
            $deductedByRecipe = $this->deductMenuRecipeIngredientsFromFrontStock(
                menuProduct: $product,
                branchId: $branchId,
                soldQuantity: $quantity,
                saleId: $saleId,
                receiptLineId: $receiptLineId,
                shiftSessionId: $shiftSessionId,
                recipeAdjustments: $recipeAdjustments
            );

            if ($deductedByRecipe) {
                return;
            }
        }

        if ((bool)$product->stock_tracking_enabled) {
            $this->deductStock(
                product: $product,
                branchId: $branchId,
                quantity: $quantity,
                saleId: $saleId,
                shiftSessionId: $shiftSessionId,
                receiptLineId: $receiptLineId
            );
        }
    }

    private function shouldUseRecipeFrontStockForSale(
        string  $companyId,
        ?string $branchId,
        Product $product
    ): bool
    {
        if (!$this->companySettingService->isHospitalityMode($companyId, $branchId)) {
            return false;
        }

//        return in_array((string)$product->product_type, ['finished_good', 'service'], true);
        return (string)$product->product_type === 'finished_good';
    }

    /**
     * @param array<string, float> $recipeAdjustments ingredient product id => negative
     *     quantity; reduces this recipe's consumption of that ingredient, never below zero
     */
    private function deductMenuRecipeIngredientsFromFrontStock(
        Product $menuProduct,
        string  $branchId,
        float   $soldQuantity,
        string  $saleId,
        ?string $receiptLineId = null,
        ?string $shiftSessionId = null,
        array   $recipeAdjustments = []
    ): bool
    {
        $recipe = FoodRecipe::query()
            ->where('company_id', $menuProduct->company_id)
            ->where('menu_product_id', $menuProduct->id)
            ->where('recipe_type', 'menu')
            ->where('is_active', true)
            ->with(['ingredients.ingredientProduct'])
            ->orderByDesc('version_no')
            ->orderByDesc('created_at')
            ->first();

        if (!$recipe) {
            return false;
        }

        $lines = $recipe->ingredients ?? collect();

        if ($lines->isEmpty()) {
            return false;
        }

        $yieldQuantity = max((float)($recipe->yield_quantity ?? 1), 0.0001);
        $allowNegativeFrontStock = (bool) $menuProduct->allow_negative_stock;
        $till = null;

        try {
            $till = app('till');
        } catch (\Throwable $e) {
            $till = null;
        }

        foreach ($lines as $line) {
            $ingredient = $line->ingredientProduct ?? null;

            if (!$ingredient) {
                throw new \Exception("Recipe ingredient product not found for menu item {$menuProduct->name}.");
            }

            $recipeNetQty = (float)($line->quantity ?? 0);
            $wastagePercent = (float)($line->wastage_percent ?? 0);
            $grossQtyPerYield = $recipeNetQty + ($recipeNetQty * $wastagePercent / 100);
            $requiredQty = ($grossQtyPerYield / $yieldQuantity) * $soldQuantity;
            $baseRequiredQty = $requiredQty;
            $modifierAdjustment = 0.0;

            $pendingAdjustment = (float)($recipeAdjustments[(string)$ingredient->id] ?? 0);
            if ($pendingAdjustment < 0 && $requiredQty > 0) {
                $modifierAdjustment = max($pendingAdjustment, -$requiredQty);
                $requiredQty += $modifierAdjustment;
                $recipeAdjustments[(string)$ingredient->id] = $pendingAdjustment - $modifierAdjustment;
            }

            if ($requiredQty <= 0) {
                continue;
            }

            $stock = ProductStock::where('company_id', $menuProduct->company_id)
                ->where('branch_id', $branchId)
                ->where('product_id', $ingredient->id)
                ->where('stock_area', 'front')
                ->lockForUpdate()
                ->first();

            if (!$stock) {
                $stock = ProductStock::create([
                    'id' => (string)Str::uuid(),
                    'company_id' => $menuProduct->company_id,
                    'branch_id' => $branchId,
                    'product_id' => $ingredient->id,
                    'stock_area' => 'front',
                    'quantity_on_hand' => 0,
                    'reserved_quantity' => 0,
                    'available_quantity' => 0,
                ]);

                $stock = ProductStock::where('id', $stock->id)
                    ->lockForUpdate()
                    ->first();
            }

            if (!$stock) {
                throw new \Exception("FRONT stock record could not be created for ingredient {$ingredient->name}.");
            }

            if (!$allowNegativeFrontStock && (float)$stock->available_quantity < $requiredQty) {
                throw new \Exception("Insufficient FRONT stock for ingredient {$ingredient->name}. Required: {$requiredQty}, Available: {$stock->available_quantity}. Enable 'Allow Recipe to Use Negative Front Stock' on menu item {$menuProduct->name} only when deferred BOH issue is authorised.");
            }

            $cost = ProductCost::where('company_id', $menuProduct->company_id)
                ->where('branch_id', $branchId)
                ->where('product_id', $ingredient->id)
                ->lockForUpdate()
                ->first();

            $unitCost = (float)(
            $cost?->average_cost
                ?: $cost?->last_cost
                ?: 0
            );

            $currency = $cost?->currency_code ?? $cost?->currency ?? null;
            $totalCost = round($unitCost * $requiredQty, 4);

            if ($cost) {
                $oldCostQuantity = (float)$cost->total_quantity;
                $oldCostValue = (float)$cost->total_value;

                $newCostQuantity = max(0, $oldCostQuantity - $requiredQty);
                $newCostValue = max(0, $oldCostValue - $totalCost);

                $cost->total_quantity = $newCostQuantity;
                $cost->total_value = $newCostValue;
                $cost->average_cost = $newCostQuantity > 0
                    ? $newCostValue / $newCostQuantity
                    : 0;

                $cost->save();
            }

            $previousQuantityOnHand = (float)$stock->quantity_on_hand;
            $previousAvailableQuantity = (float)$stock->available_quantity;
            $previousReservedQuantity = (float)$stock->reserved_quantity;
            $deferredFrontStockIssue = $allowNegativeFrontStock
                && $previousAvailableQuantity < $requiredQty;
            $negativeQuantityCreated = $deferredFrontStockIssue
                ? max(0, $requiredQty - max(0, $previousAvailableQuantity))
                : 0;

            $stock->quantity_on_hand = $previousQuantityOnHand - $requiredQty;
            $stock->available_quantity = $previousAvailableQuantity - $requiredQty;
            $stock->save();

            $allocatedByBatch = $deferredFrontStockIssue ? false : app(InventoryAllocationService::class)->allocateSale([
                'company_id' => $menuProduct->company_id, 'branch_id' => $branchId, 'product_id' => $ingredient->id,
                'quantity' => $requiredQty, 'sale_id' => $saleId, 'receipt_line_id' => $receiptLineId,
                'till_id' => $till?->id, 'shift_session_id' => $shiftSessionId, 'balance_after' => (float) $stock->quantity_on_hand,
                'unit_cost' => $unitCost, 'currency' => $currency, 'user_id' => auth()->id(),
                'stock_area' => 'front', 'reference_type' => 'sale_recipe',
                'movement_notes' => "Recipe ingredient consumed from FRONT stock for menu item: {$menuProduct->name}",
                'movement_metadata' => [
                    'source' => 'sales',
                    'movement_reason' => 'sale_recipe_front_stock_consumption',
                    'sale_id' => $saleId,
                    'receipt_line_id' => $receiptLineId,
                    'recipe_id' => $recipe->id,
                    'recipe_version_no' => $recipe->version_no,
                    'recipe_name' => $recipe->name ?? null,
                    'menu_product_id' => $menuProduct->id,
                    'menu_product_name' => $menuProduct->name,
                    'menu_allows_negative_front_stock' => $allowNegativeFrontStock,
                    'deferred_front_stock_issue' => $deferredFrontStockIssue,
                    'negative_quantity_created' => round($negativeQuantityCreated, 4),
                    'ingredient_product_id' => $ingredient->id,
                    'ingredient_product_name' => $ingredient->name,
                    'recipe_line_id' => $line->id ?? null,
                    'required_quantity' => round($requiredQty, 4),
                    'base_required_quantity' => round($baseRequiredQty, 4),
                    'modifier_recipe_adjustment' => round($modifierAdjustment, 4),
                ],
            ]);

            if (!$allocatedByBatch) DB::table('stock_movements')->insert([
                'id' => (string)Str::uuid(),
                'company_id' => $menuProduct->company_id,
                'branch_id' => $branchId,
                'till_id' => $till?->id,
                'shift_session_id' => $shiftSessionId,
                'product_id' => $ingredient->id,
                'stock_area' => 'front',
                'movement_type' => 'sale',
                'quantity_in' => 0,
                'quantity_out' => round($requiredQty, 4),
                'balance_after' => round((float)$stock->quantity_on_hand, 4),
                'unit_cost' => round($unitCost, 4),
                'total_cost' => round($totalCost, 4),
                'currency' => $currency,
                'currency_code' => $currency,
                'reference_type' => 'sale_recipe',
                'reference_id' => $saleId,
                'notes' => "Recipe ingredient consumed from FRONT stock for menu item: {$menuProduct->name}",
                'metadata' => json_encode([
                    'source' => 'sales',
                    'movement_reason' => 'sale_recipe_front_stock_consumption',
                    'sale_id' => $saleId,
                    'receipt_line_id' => $receiptLineId,
                    'recipe_id' => $recipe->id,
                    'recipe_version_no' => $recipe->version_no,
                    'recipe_name' => $recipe->name ?? null,
                    'menu_allows_negative_front_stock' => $allowNegativeFrontStock,
                    'deferred_front_stock_issue' => $deferredFrontStockIssue,
                    'negative_quantity_created' => round($negativeQuantityCreated, 4),
                    'menu_product' => [
                        'id' => $menuProduct->id,
                        'name' => $menuProduct->name,
                        'code' => $menuProduct->code,
                        'sku' => $menuProduct->sku,
                        'allows_negative_front_stock' => $allowNegativeFrontStock,
                        'deferred_front_stock_issue' => $deferredFrontStockIssue,
                    ],
                    'ingredient_product' => [
                        'id' => $ingredient->id,
                        'name' => $ingredient->name,
                        'code' => $ingredient->code,
                        'sku' => $ingredient->sku,
                    ],
                    'recipe_line' => [
                        'id' => $line->id ?? null,
                        'net_quantity' => round($recipeNetQty, 4),
                        'wastage_percent' => round($wastagePercent, 4),
                        'gross_quantity_per_yield' => round($grossQtyPerYield, 4),
                        'yield_quantity' => round($yieldQuantity, 4),
                        'sold_quantity' => round($soldQuantity, 4),
                        'required_quantity' => round($requiredQty, 4),
                        'base_required_quantity' => round($baseRequiredQty, 4),
                        'modifier_recipe_adjustment' => round($modifierAdjustment, 4),
                    ],
                    'before' => [
                        'quantity_on_hand' => round($previousQuantityOnHand, 4),
                        'available_quantity' => round($previousAvailableQuantity, 4),
                        'reserved_quantity' => round($previousReservedQuantity, 4),
                    ],
                    'after' => [
                        'quantity_on_hand' => round((float)$stock->quantity_on_hand, 4),
                        'available_quantity' => round((float)$stock->available_quantity, 4),
                        'reserved_quantity' => round((float)$stock->reserved_quantity, 4),
                    ],
                    'cost' => [
                        'unit_cost' => round($unitCost, 4),
                        'total_cost' => round($totalCost, 4),
                        'currency' => $currency,
                        'product_cost_id' => $cost?->id,
                    ],
                ]),
                'created_by' => auth()->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return true;
    }

    private function restoreMenuRecipeIngredientsToFrontStock(
        Product $menuProduct,
        string  $branchId,
        float   $soldQuantity,
        string  $originalSaleId,
        string  $cancelTransactionId,
        ?string $receiptLineId = null,
        ?string $shiftSessionId = null,
        ?string $userId = null,
        ?string $reason = null
    ): bool
    {
        if ($this->restoreRecordedRecipeConsumptionToFrontStock(
            menuProduct: $menuProduct,
            branchId: $branchId,
            originalSaleId: $originalSaleId,
            cancelTransactionId: $cancelTransactionId,
            receiptLineId: $receiptLineId,
            shiftSessionId: $shiftSessionId,
            userId: $userId,
            reason: $reason
        )) {
            return true;
        }

        $recipe = FoodRecipe::query()
            ->where('company_id', $menuProduct->company_id)
            ->where('menu_product_id', $menuProduct->id)
            ->where('recipe_type', 'menu')
            ->where('is_active', true)
            ->with(['ingredients.ingredientProduct'])
            ->orderByDesc('version_no')
            ->orderByDesc('created_at')
            ->first();

        if (!$recipe || ($recipe->ingredients ?? collect())->isEmpty()) {
            return false;
        }

        $yieldQuantity = max((float)($recipe->yield_quantity ?? 1), 0.0001);
        $till = null;

        try {
            $till = app('till');
        } catch (\Throwable $e) {
            $till = null;
        }

        foreach ($recipe->ingredients as $line) {
            $ingredient = $line->ingredientProduct ?? null;

            if (!$ingredient) {
                continue;
            }

            $recipeNetQty = (float)($line->quantity ?? 0);
            $wastagePercent = (float)($line->wastage_percent ?? 0);
            $grossQtyPerYield = $recipeNetQty + ($recipeNetQty * $wastagePercent / 100);
            $restoreQty = ($grossQtyPerYield / $yieldQuantity) * $soldQuantity;

            if ($restoreQty <= 0) {
                continue;
            }

            $stock = ProductStock::firstOrCreate(
                [
                    'company_id' => $menuProduct->company_id,
                    'branch_id' => $branchId,
                    'product_id' => $ingredient->id,
                    'stock_area' => 'front',
                ],
                [
                    'id' => (string)Str::uuid(),
                    'quantity_on_hand' => 0,
                    'reserved_quantity' => 0,
                    'available_quantity' => 0,
                ]
            );

            $stock = ProductStock::where('id', $stock->id)->lockForUpdate()->first();

            $previousQuantityOnHand = (float)$stock->quantity_on_hand;
            $previousAvailableQuantity = (float)$stock->available_quantity;

            $stock->quantity_on_hand = $previousQuantityOnHand + $restoreQty;
            $stock->available_quantity = $previousAvailableQuantity + $restoreQty;
            $stock->save();

            $restoredByBatch = app(InventoryAllocationService::class)->reverseSale([
                'company_id' => $menuProduct->company_id, 'branch_id' => $branchId, 'product_id' => $ingredient->id,
                'original_sale_id' => $originalSaleId, 'cancel_sale_id' => $cancelTransactionId, 'sale_id' => $cancelTransactionId,
                'receipt_line_id' => $receiptLineId, 'till_id' => $till?->id, 'shift_session_id' => $shiftSessionId,
                'balance_after' => (float) $stock->quantity_on_hand, 'user_id' => $userId,
                'stock_area' => 'front',
                'original_reference_types' => ['sale_recipe', 'sale'],
                'reversal_reference_type' => 'sale_cancel_recipe',
                'movement_notes' => "Cancellation restore of recipe ingredient to FRONT stock for menu item: {$menuProduct->name}",
                'movement_metadata' => [
                    'source' => 'sales',
                    'movement_reason' => 'sale_cancel_recipe_front_stock_restore',
                    'cancel_transaction_id' => $cancelTransactionId,
                    'receipt_line_id' => $receiptLineId,
                    'recipe_id' => $recipe->id,
                    'recipe_version_no' => $recipe->version_no,
                    'recipe_name' => $recipe->name ?? null,
                    'reason' => $reason,
                    'menu_product_id' => $menuProduct->id,
                    'menu_product_name' => $menuProduct->name,
                    'ingredient_product_id' => $ingredient->id,
                    'ingredient_product_name' => $ingredient->name,
                ],
            ]);

            $cost = ProductCost::where('company_id', $menuProduct->company_id)
                ->where('branch_id', $branchId)
                ->where('product_id', $ingredient->id)
                ->first();

            $unitCost = (float)(
            $cost?->average_cost
                ?: $cost?->last_cost
                ?: 0
            );
            $totalCost = round($unitCost * $restoreQty, 4);

            if ($cost) {
                $oldCostQuantity = (float)$cost->total_quantity;
                $oldCostValue = (float)$cost->total_value;

                $newCostQuantity = $oldCostQuantity + $restoreQty;
                $newCostValue = $oldCostValue + $totalCost;

                $cost->total_quantity = $newCostQuantity;
                $cost->total_value = $newCostValue;
                $cost->average_cost = $newCostQuantity > 0
                    ? $newCostValue / $newCostQuantity
                    : 0;

                $cost->save();
            }

            if (!$restoredByBatch) DB::table('stock_movements')->insert([
                'id' => (string)Str::uuid(),
                'company_id' => $menuProduct->company_id,
                'branch_id' => $branchId,
                'till_id' => $till?->id,
                'shift_session_id' => $shiftSessionId,
                'product_id' => $ingredient->id,
                'stock_area' => 'front',
                'movement_type' => 'sale_cancel',
                'quantity_in' => round($restoreQty, 4),
                'quantity_out' => 0,
                'balance_after' => round((float)$stock->quantity_on_hand, 4),
                'unit_cost' => round($unitCost, 4),
//                'total_cost' => round($unitCost * $restoreQty, 4),
                'total_cost' => $totalCost,
                'currency' => $cost?->currency_code ?? $cost?->currency ?? null,
                'currency_code' => $cost?->currency_code ?? $cost?->currency ?? null,
                'reference_type' => 'sale_cancel_recipe',
                'reference_id' => $cancelTransactionId,
                'notes' => "Cancellation restore of recipe ingredient to FRONT stock for menu item: {$menuProduct->name}",
                'metadata' => json_encode([
                    'source' => 'sales',
                    'movement_reason' => 'sale_cancel_recipe_front_stock_restore',
                    'original_sale_id' => $originalSaleId,
                    'cancel_transaction_id' => $cancelTransactionId,
                    'receipt_line_id' => $receiptLineId,
                    'recipe_id' => $recipe->id,
                    'recipe_version_no' => $recipe->version_no,
                    'recipe_name' => $recipe->name ?? null,
                    'reason' => $reason,
                    'menu_product_id' => $menuProduct->id,
                    'menu_product_name' => $menuProduct->name,
                    'ingredient_product_id' => $ingredient->id,
                    'ingredient_product_name' => $ingredient->name,
                    'sold_quantity' => round($soldQuantity, 4),
                    'restore_quantity' => round($restoreQty, 4),
                    'before' => [
                        'quantity_on_hand' => round($previousQuantityOnHand, 4),
                        'available_quantity' => round($previousAvailableQuantity, 4),
                    ],
                    'after' => [
                        'quantity_on_hand' => round((float)$stock->quantity_on_hand, 4),
                        'available_quantity' => round((float)$stock->available_quantity, 4),
                    ],
                ]),
                'created_by' => $userId ?: auth()->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return true;
    }

    /**
     * Reverse the exact ingredient quantities and costs captured when the sale
     * posted. Rebuilding from the currently-active recipe is only a legacy
     * fallback because a recipe may have changed after the original sale.
     */
    private function restoreRecordedRecipeConsumptionToFrontStock(
        Product $menuProduct,
        string $branchId,
        string $originalSaleId,
        string $cancelTransactionId,
        ?string $receiptLineId,
        ?string $shiftSessionId,
        ?string $userId,
        ?string $reason
    ): bool {
        if (!$receiptLineId) {
            return false;
        }

        $movements = DB::table('stock_movements')
            ->where('company_id', $menuProduct->company_id)
            ->where('branch_id', $branchId)
            ->where('reference_id', $originalSaleId)
            ->where('metadata->receipt_line_id', $receiptLineId)
            ->whereRaw('COALESCE(quantity_out, 0) > 0')
            ->where(function ($query) use ($menuProduct) {
                $query->whereIn('reference_type', ['sale_recipe', 'sale_modifier_recipe'])
                    ->orWhere(function ($legacyBatchQuery) use ($menuProduct) {
                        $legacyBatchQuery->where('reference_type', 'sale')
                            ->where('product_id', '<>', $menuProduct->id)
                            ->whereNotNull('batch_id');
                    });
            })
            ->orderBy('created_at')
            ->lockForUpdate()
            ->get();

        if ($movements->isEmpty()) {
            return false;
        }

        foreach ($movements->groupBy('product_id') as $ingredientId => $ingredientMovements) {
            $ingredient = Product::query()
                ->where('company_id', $menuProduct->company_id)
                ->where('id', $ingredientId)
                ->first();

            if (!$ingredient) {
                throw new \RuntimeException('A recipe ingredient used by the original sale no longer exists.');
            }

            $restoreQuantity = round((float) $ingredientMovements->sum('quantity_out'), 4);
            $restoreValue = round((float) $ingredientMovements->sum(function ($movement) {
                $recordedTotal = (float) ($movement->total_cost ?? 0);

                return $recordedTotal != 0.0
                    ? $recordedTotal
                    : (float) ($movement->quantity_out ?? 0) * (float) ($movement->unit_cost ?? 0);
            }), 6);

            if ($restoreQuantity <= 0) {
                continue;
            }

            $stock = ProductStock::firstOrCreate(
                [
                    'company_id' => $menuProduct->company_id,
                    'branch_id' => $branchId,
                    'product_id' => $ingredientId,
                    'stock_area' => 'front',
                ],
                [
                    'id' => (string) Str::uuid(),
                    'quantity_on_hand' => 0,
                    'reserved_quantity' => 0,
                    'available_quantity' => 0,
                ]
            );
            $stock = ProductStock::where('id', $stock->id)->lockForUpdate()->firstOrFail();
            $beforeQuantity = (float) $stock->quantity_on_hand;
            $beforeAvailable = (float) $stock->available_quantity;
            $stock->quantity_on_hand = $beforeQuantity + $restoreQuantity;
            $stock->available_quantity = $beforeAvailable + $restoreQuantity;
            $stock->save();

            $batchMovements = $ingredientMovements->whereNotNull('batch_id');

            if ($batchMovements->isNotEmpty()) {
                $restored = app(InventoryAllocationService::class)->reverseSale([
                    'company_id' => $menuProduct->company_id,
                    'branch_id' => $branchId,
                    'product_id' => $ingredientId,
                    'original_sale_id' => $originalSaleId,
                    'cancel_sale_id' => $cancelTransactionId,
                    'sale_id' => $cancelTransactionId,
                    'receipt_line_id' => $receiptLineId,
                    'shift_session_id' => $shiftSessionId,
                    'balance_after' => (float) $stock->quantity_on_hand,
                    'user_id' => $userId,
                    'stock_area' => 'front',
                    'original_reference_types' => ['sale_recipe', 'sale_modifier_recipe', 'sale'],
                    'reversal_reference_type' => 'sale_cancel_recipe',
                    'movement_notes' => "Exact recipe stock restoration for cancelled menu item: {$menuProduct->name}",
                    'movement_metadata' => [
                        'source' => 'sales',
                        'movement_reason' => 'sale_cancel_exact_recipe_consumption_restore',
                        'cancel_transaction_id' => $cancelTransactionId,
                        'receipt_line_id' => $receiptLineId,
                        'menu_product_id' => $menuProduct->id,
                        'reason' => $reason,
                    ],
                ]);

                if (!$restored) {
                    throw new \RuntimeException('The original recipe batch allocation could not be restored safely.');
                }
            }

            $plainMovements = $ingredientMovements->whereNull('batch_id');
            $plainQuantity = round((float) $plainMovements->sum('quantity_out'), 4);
            $plainValue = round((float) $plainMovements->sum(function ($movement) {
                $recordedTotal = (float) ($movement->total_cost ?? 0);

                return $recordedTotal != 0.0
                    ? $recordedTotal
                    : (float) ($movement->quantity_out ?? 0) * (float) ($movement->unit_cost ?? 0);
            }), 6);

            if ($plainQuantity > 0) {
                $firstMovement = $plainMovements->first();
                $currency = $firstMovement->currency_code ?? $firstMovement->currency ?? null;

                DB::table('stock_movements')->insert([
                    'id' => (string) Str::uuid(),
                    'company_id' => $menuProduct->company_id,
                    'branch_id' => $branchId,
                    'till_id' => $firstMovement->till_id ?? null,
                    'shift_session_id' => $shiftSessionId,
                    'product_id' => $ingredientId,
                    'stock_area' => 'front',
                    'movement_type' => 'sale_cancel',
                    'quantity_in' => $plainQuantity,
                    'quantity_out' => 0,
                    'balance_after' => round((float) $stock->quantity_on_hand, 4),
                    'unit_cost' => round($plainValue / $plainQuantity, 6),
                    'total_cost' => $plainValue,
                    'currency' => $currency,
                    'currency_code' => $currency,
                    'reference_type' => 'sale_cancel_recipe',
                    'reference_id' => $cancelTransactionId,
                    'notes' => "Exact recipe stock restoration for cancelled menu item: {$menuProduct->name}",
                    'metadata' => json_encode([
                        'source' => 'sales',
                        'movement_reason' => 'sale_cancel_exact_recipe_consumption_restore',
                        'original_sale_id' => $originalSaleId,
                        'cancel_transaction_id' => $cancelTransactionId,
                        'receipt_line_id' => $receiptLineId,
                        'menu_product_id' => $menuProduct->id,
                        'ingredient_product_id' => $ingredientId,
                        'original_movement_ids' => $plainMovements->pluck('id')->values()->all(),
                        'reason' => $reason,
                        'before' => [
                            'quantity_on_hand' => round($beforeQuantity, 4),
                            'available_quantity' => round($beforeAvailable, 4),
                        ],
                        'after' => [
                            'quantity_on_hand' => round((float) $stock->quantity_on_hand, 4),
                            'available_quantity' => round((float) $stock->available_quantity, 4),
                        ],
                    ]),
                    'created_by' => $userId ?: auth()->id(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $cost = ProductCost::query()
                ->where('company_id', $menuProduct->company_id)
                ->where('branch_id', $branchId)
                ->where('product_id', $ingredientId)
                ->lockForUpdate()
                ->first();

            if ($cost) {
                $cost->total_quantity = (float) $cost->total_quantity + $restoreQuantity;
                $cost->total_value = (float) $cost->total_value + $restoreValue;
                $cost->average_cost = (float) $cost->total_quantity > 0
                    ? (float) $cost->total_value / (float) $cost->total_quantity
                    : 0;
                $cost->save();
            }
        }

        return true;
    }


}
