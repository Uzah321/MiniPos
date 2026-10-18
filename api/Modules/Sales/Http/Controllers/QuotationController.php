<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\CompanyBranchContextService;
use App\Services\Sales\QuotationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Sales\Models\Quotation;
use Modules\Sales\Models\SalesTransaction;

use function App\Helpers\hasPermission;

class QuotationController extends Controller
{
    public function __construct(private readonly QuotationService $quotationService)
    {
    }

    public function index(Request $request)
    {
        $company = app('company');
        $permission = hasPermission(auth()->user()->role_id, 'view_quotations', $company->id);
        if ($permission !== true) return $permission;

        $rows = Quotation::query()
            ->withCount('items')
            ->where('company_id', $company->id)
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = '%' . $request->q . '%';
                $query->where(function ($query) use ($q) {
                    $query->where('quote_code', 'like', $q)
                        ->orWhere('customer_name', 'like', $q)
                        ->orWhere('customer_phone', 'like', $q)
                        ->orWhere('customer_email', 'like', $q);
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->status))
            ->when($request->filled('date_from'), fn ($query) => $query->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($query) => $query->whereDate('created_at', '<=', $request->date_to))
            ->orderByDesc('created_at')
            ->paginate((int) $request->input('per_page', 50));

        return response()->json(['message' => 'Quotations fetched successfully.', 'data' => $rows]);
    }

    public function frontIndex(Request $request)
    {
        $company = app('company');
        $till = $this->frontOfficeTill($request, (string) $company->id);
        $permission = hasPermission(auth()->user()->role_id, 'view_quotations', $company->id);
        if ($permission !== true) return $permission;

        $rows = Quotation::query()
            ->withCount('items')
            ->where('company_id', $company->id)
            ->where('branch_id', (string) $till->branch_id)
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = '%' . $request->q . '%';
                $query->where(function ($query) use ($q) {
                    $query->where('quote_code', 'like', $q)
                        ->orWhere('customer_name', 'like', $q)
                        ->orWhere('customer_phone', 'like', $q)
                        ->orWhere('customer_email', 'like', $q);
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->status))
            ->when($request->filled('date_from'), fn ($query) => $query->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($query) => $query->whereDate('created_at', '<=', $request->date_to))
            ->orderByDesc('created_at')
            ->paginate((int) $request->input('per_page', 50));

        return response()->json(['message' => 'Quotations fetched successfully.', 'data' => $rows]);
    }

    public function show(string $id)
    {
        $company = app('company');
        $permission = hasPermission(auth()->user()->role_id, 'view_quotations', $company->id);
        if ($permission !== true) return $permission;

        $quotation = Quotation::with(['items'])
            ->where('company_id', $company->id)
            ->where(function ($query) use ($id) {
                $query->where('id', $id)->orWhere('quote_code', $id);
            })
            ->firstOrFail();

        return response()->json([
            'message' => 'Quotation fetched successfully.',
            'data' => array_merge($quotation->toArray(), [
                'sale_payload' => $this->quotationService->formatForSale($quotation),
            ]),
        ]);
    }

    public function frontShow(Request $request, string $id)
    {
        $company = app('company');
        $till = $this->frontOfficeTill($request, (string) $company->id);
        $permission = hasPermission(auth()->user()->role_id, 'view_quotations', $company->id);
        if ($permission !== true) return $permission;

        $quotation = Quotation::with(['items'])
            ->where('company_id', $company->id)
            ->where('branch_id', (string) $till->branch_id)
            ->where(function ($query) use ($id) {
                $query->where('id', $id)->orWhere('quote_code', $id);
            })
            ->firstOrFail();

        return response()->json([
            'message' => 'Quotation fetched successfully.',
            'data' => array_merge($quotation->toArray(), [
                'sale_payload' => $this->quotationService->formatForSale($quotation),
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $company = app('company');
        $permission = hasPermission(auth()->user()->role_id, 'create_quotations', $company->id);
        if ($permission !== true) return $permission;

        $data = $this->validatePayload($request);
        $data['company_id'] = $company->id;
        $data['branch_id'] = app(CompanyBranchContextService::class)->resolve(
            $request,
            (string) $company->id,
            true
        );
        $data['pos_user_id'] = auth()->id();
        $data['created_by'] = auth()->id();
        $branchCode = $this->quotationService->companyBranchCode($data['branch_id']);
        $data['quote_code'] = $data['quote_code'] ?? $this->quotationService->quoteCode($company->id, $branchCode);

        $quotation = $this->quotationService->createOrUpdate($data);
        $this->quotationService->audit('quotation_created', $quotation, [
            'company_id' => $company->id,
            'branch_id' => $data['branch_id'],
            'new_values' => $quotation->toArray(),
        ]);

        return response()->json([
            'message' => 'Quotation created successfully.',
            'data' => array_merge($quotation->toArray(), [
                'sale_payload' => $this->quotationService->formatForSale($quotation),
            ]),
        ], 201);
    }

    public function frontStore(Request $request)
    {
        $company = app('company');
        $till = $this->frontOfficeTill($request, (string) $company->id);
        $request->merge(['branch_id' => (string) $till->branch_id]);

        return $this->store($request);
    }

    public function update(Request $request, string $id)
    {
        $company = app('company');
        $permission = hasPermission(auth()->user()->role_id, 'update_quotations', $company->id);
        if ($permission !== true) return $permission;

        $quotation = Quotation::where('company_id', $company->id)->findOrFail($id);
        if (in_array($quotation->status, ['converted', 'cancelled'], true)) {
            return response()->json(['message' => 'Converted or cancelled quotations cannot be edited.'], 422);
        }

        $old = $quotation->load('items')->toArray();
        $data = $this->validatePayload($request);
        $data['company_id'] = $company->id;
        $data['branch_id'] = $request->filled('branch_id')
            ? app(CompanyBranchContextService::class)->resolve(
                $request,
                (string) $company->id,
                true
            )
            : $quotation->branch_id;
        $data['quote_code'] = $quotation->quote_code;

        $quotation = $this->quotationService->createOrUpdate($data, $quotation);
        $this->quotationService->audit('quotation_updated', $quotation, [
            'company_id' => $company->id,
            'branch_id' => $quotation->branch_id,
            'old_values' => $old,
            'new_values' => $quotation->toArray(),
        ]);

        return response()->json([
            'message' => 'Quotation updated successfully.',
            'data' => array_merge($quotation->toArray(), [
                'sale_payload' => $this->quotationService->formatForSale($quotation),
            ]),
        ]);
    }

    public function frontUpdate(Request $request, string $id)
    {
        $company = app('company');
        $till = $this->frontOfficeTill($request, (string) $company->id);
        $permission = hasPermission(auth()->user()->role_id, 'update_quotations', $company->id);
        if ($permission !== true) return $permission;

        $quotation = Quotation::where('company_id', $company->id)
            ->where('branch_id', (string) $till->branch_id)
            ->findOrFail($id);
        if (in_array($quotation->status, ['converted', 'cancelled'], true)) {
            return response()->json(['message' => 'Converted or cancelled quotations cannot be edited.'], 422);
        }

        $old = $quotation->load('items')->toArray();
        $request->merge(['branch_id' => (string) $till->branch_id]);
        $data = $this->validatePayload($request);
        $data['company_id'] = $company->id;
        $data['branch_id'] = (string) $till->branch_id;
        $data['quote_code'] = $quotation->quote_code;

        $quotation = $this->quotationService->createOrUpdate($data, $quotation);
        $this->quotationService->audit('quotation_updated', $quotation, [
            'company_id' => $company->id,
            'branch_id' => $quotation->branch_id,
            'old_values' => $old,
            'new_values' => $quotation->toArray(),
        ]);

        return response()->json([
            'message' => 'Quotation updated successfully.',
            'data' => array_merge($quotation->toArray(), [
                'sale_payload' => $this->quotationService->formatForSale($quotation),
            ]),
        ]);
    }

    public function issue(string $id)
    {
        $company = app('company');
        $permission = hasPermission(auth()->user()->role_id, 'issue_quotations', $company->id);
        if ($permission !== true) return $permission;

        $quotation = Quotation::where('company_id', $company->id)->findOrFail($id);
        $old = $quotation->toArray();
        $quotation->status = 'issued';
        $quotation->save();
        $this->quotationService->audit('quotation_issued', $quotation, [
            'company_id' => $company->id,
            'branch_id' => $quotation->branch_id,
            'old_values' => $old,
            'new_values' => $quotation->fresh()->toArray(),
        ]);

        $quotation = $quotation->fresh(['items']);
        return response()->json([
            'message' => 'Quotation issued successfully.',
            'data' => array_merge($quotation->toArray(), [
                'sale_payload' => $this->quotationService->formatForSale($quotation),
            ]),
        ]);
    }

    public function frontIssue(Request $request, string $id)
    {
        $company = app('company');
        $till = $this->frontOfficeTill($request, (string) $company->id);
        $permission = hasPermission(auth()->user()->role_id, 'issue_quotations', $company->id);
        if ($permission !== true) return $permission;

        $quotation = Quotation::where('company_id', $company->id)
            ->where('branch_id', (string) $till->branch_id)
            ->findOrFail($id);
        $old = $quotation->toArray();
        $quotation->status = 'issued';
        $quotation->save();
        $this->quotationService->audit('quotation_issued', $quotation, [
            'company_id' => $company->id,
            'branch_id' => $quotation->branch_id,
            'old_values' => $old,
            'new_values' => $quotation->fresh()->toArray(),
        ]);

        $quotation = $quotation->fresh(['items']);
        return response()->json([
            'message' => 'Quotation issued successfully.',
            'data' => array_merge($quotation->toArray(), [
                'sale_payload' => $this->quotationService->formatForSale($quotation),
            ]),
        ]);
    }

    public function cancel(string $id)
    {
        $company = app('company');
        $permission = hasPermission(auth()->user()->role_id, 'cancel_quotations', $company->id);
        if ($permission !== true) return $permission;

        $quotation = Quotation::where('company_id', $company->id)->findOrFail($id);
        $old = $quotation->toArray();
        $quotation->status = 'cancelled';
        $quotation->save();
        $this->quotationService->audit('quotation_cancelled', $quotation, [
            'company_id' => $company->id,
            'branch_id' => $quotation->branch_id,
            'old_values' => $old,
            'new_values' => $quotation->fresh()->toArray(),
        ]);

        $quotation = $quotation->fresh(['items']);
        return response()->json([
            'message' => 'Quotation cancelled successfully.',
            'data' => array_merge($quotation->toArray(), [
                'sale_payload' => $this->quotationService->formatForSale($quotation),
            ]),
        ]);
    }

    public function frontCancel(Request $request, string $id)
    {
        $company = app('company');
        $till = $this->frontOfficeTill($request, (string) $company->id);
        $permission = hasPermission(auth()->user()->role_id, 'cancel_quotations', $company->id);
        if ($permission !== true) return $permission;

        $quotation = Quotation::where('company_id', $company->id)
            ->where('branch_id', (string) $till->branch_id)
            ->findOrFail($id);
        $old = $quotation->toArray();
        $quotation->status = 'cancelled';
        $quotation->save();
        $this->quotationService->audit('quotation_cancelled', $quotation, [
            'company_id' => $company->id,
            'branch_id' => $quotation->branch_id,
            'old_values' => $old,
            'new_values' => $quotation->fresh()->toArray(),
        ]);

        $quotation = $quotation->fresh(['items']);
        return response()->json([
            'message' => 'Quotation cancelled successfully.',
            'data' => array_merge($quotation->toArray(), [
                'sale_payload' => $this->quotationService->formatForSale($quotation),
            ]),
        ]);
    }

    public function lookup(Request $request, string $quoteCode)
    {
        $company = app('company');
        $till = $this->frontOfficeTill($request, (string) $company->id);
        $permission = hasPermission(auth()->user()->role_id, 'redeem_quotations', $company->id);
        if ($permission !== true) return $permission;

        $quotation = Quotation::with(['items'])
            ->where('company_id', $company->id)
            ->where('branch_id', (string) $till->branch_id)
            ->where('quote_code', $quoteCode)
            ->firstOrFail();

        if ($quotation->status === 'converted') {
            return response()->json(['message' => 'This quotation has already been converted to a sale.'], 422);
        }
        if ($quotation->status === 'cancelled') {
            return response()->json(['message' => 'This quotation has been cancelled.'], 422);
        }
        if ($quotation->valid_until && now()->greaterThan($quotation->valid_until)) {
            $quotation->update(['status' => 'expired']);
            return response()->json(['message' => 'This quotation has expired.'], 422);
        }

        return response()->json([
            'message' => 'Quotation found successfully.',
            'data' => array_merge($quotation->toArray(), [
                'sale_payload' => $this->quotationService->formatForSale($quotation),
            ]),
        ]);
    }

    public function convert(Request $request, string $quoteCode)
    {
        $company = app('company');
        $till = $this->frontOfficeTill($request, (string) $company->id);
        $permission = hasPermission(auth()->user()->role_id, 'redeem_quotations', $company->id);
        if ($permission !== true) return $permission;

        $validator = Validator::make($request->all(), [
            'sales_transaction_id' => ['required', 'string', 'max:36'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error.', 'errors' => $validator->errors()], 422);
        }

        $quotation = Quotation::where('company_id', $company->id)
            ->where('branch_id', (string) $till->branch_id)
            ->where('quote_code', $quoteCode)
            ->firstOrFail();

        $sale = SalesTransaction::query()
            ->where('company_id', $company->id)
            ->where('branch_id', (string) $till->branch_id)
            ->where('till_id', (string) $till->id)
            ->where('id', (string) $request->sales_transaction_id)
            ->first();

        if (! $sale) {
            return response()->json([
                'message' => 'The converted sale does not belong to this registered till.',
                'code' => 'QUOTATION_SALE_SCOPE_MISMATCH',
            ], 422);
        }

        if ($quotation->status === 'converted') {
            return response()->json(['message' => 'This quotation has already been converted.'], 422);
        }

        $quotation->status = 'converted';
        $quotation->converted_at = now();
        $quotation->converted_sale_id = $request->sales_transaction_id;
        $quotation->save();

        $this->quotationService->audit('quotation_converted', $quotation, [
            'company_id' => $company->id,
            'branch_id' => $quotation->branch_id,
            'metadata' => ['sales_transaction_id' => $request->sales_transaction_id],
        ]);

        $quotation = $quotation->fresh(['items']);
        return response()->json([
            'message' => 'Quotation marked as converted.',
            'data' => array_merge($quotation->toArray(), [
                'sale_payload' => $this->quotationService->formatForSale($quotation),
            ]),
        ]);
    }

    private function frontOfficeTill(Request $request, string $companyId): object
    {
        $till = $request->attributes->get('resolved_till')
            ?? (app()->bound('till') ? app('till') : null);

        abort_unless($till, 403, 'A registered till is required for Front Office quotations.');
        abort_unless(
            (string) data_get($till, 'company_id') === $companyId,
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
            'Front Office quotations are limited to the registered till branch.'
        );

        return $till;
    }

    private function validatePayload(Request $request): array
    {
        return Validator::make($request->all(), [
            'branch_id' => ['nullable', 'string', 'max:36'],
            'customer_id' => ['nullable', 'string', 'max:36'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:80'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'currency' => ['required', 'string', 'max:20'],
            'valid_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,issued'],
            'metadata' => ['nullable', 'array'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'string', 'max:36'],
            'items.*.product_price_id' => ['nullable', 'string', 'max:36'],
            'items.*.price_level_id' => ['nullable', 'string', 'max:36'],
            'items.*.category_id' => ['nullable', 'string', 'max:36'],
            'items.*.product_name' => ['required', 'string', 'max:255'],
            'items.*.product_code' => ['nullable', 'string', 'max:255'],
            'items.*.sku' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'items.*.base_unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.has_price_override' => ['nullable', 'boolean'],
            'items.*.price_override_level_id' => ['nullable', 'string', 'max:36'],
            'items.*.price_override_payload' => ['nullable', 'array'],
            'items.*.price_level_override' => ['nullable', 'array'],
            'items.*.discount_payload' => ['nullable', 'array'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.taxable' => ['nullable', 'boolean'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax_total' => ['nullable', 'numeric', 'min:0'],
            'items.*.inclusive_tax_total' => ['nullable', 'numeric', 'min:0'],
            'items.*.exclusive_tax_total' => ['nullable', 'numeric', 'min:0'],
            'items.*.subtotal_excl_tax' => ['nullable', 'numeric', 'min:0'],
            'items.*.line_total' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax_details' => ['nullable', 'array'],
            'items.*.tax_details.*.tax_id' => ['nullable', 'string', 'max:36'],
            'items.*.tax_details.*.tax_name' => ['nullable', 'string', 'max:255'],
            'items.*.tax_details.*.tax_code' => ['nullable', 'string', 'max:255'],
            'items.*.tax_details.*.fdms_tax_id' => ['nullable'],
            'items.*.tax_details.*.fiscal_tax_id' => ['nullable'],
            'items.*.tax_details.*.tax_type' => ['nullable', 'string', 'max:50'],
            'items.*.tax_details.*.tax_percent' => ['nullable', 'numeric'],
            'items.*.tax_details.*.tax_rate' => ['nullable', 'numeric'],
            'items.*.tax_details.*.taxable_amount' => ['nullable', 'numeric'],
            'items.*.tax_details.*.tax_amount' => ['nullable', 'numeric'],
            'items.*.tax_details.*.sales_amount_with_tax' => ['nullable', 'numeric'],
            'items.*.tax_details.*.is_inclusive' => ['nullable', 'boolean'],
            'items.*.tax_details.*.obliged_to_zimra' => ['nullable', 'boolean'],
            'items.*.metadata' => ['nullable', 'array'],
        ])->validate();
    }
}
