<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\CompanyBranchContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductTaxe;
use Modules\Product\Models\Taxe;
use function App\Helpers\hasPermission;

class ProductTaxController extends Controller
{
    public function __construct(
        private readonly CompanyBranchContextService $branchContext
    ) {}

    public function index(Request $request, string $productId)
    {
        $company = app('company');
        $branchId = $this->branchContext->resolve($request, (string) $company->id);

        $product = Product::where('company_id', $company->id)
            ->where('id', $productId)
            ->first();

        if (!$product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $rows = ProductTaxe::where('company_id', $company->id)
            ->where('product_id', $product->id)
            ->where(function ($query) use ($branchId) {
                $query->whereNull('branch_id');

                if ($branchId) {
                    $query->orWhere('branch_id', $branchId);
                }
            })
            ->with('tax')
            ->orderByRaw('CASE WHEN branch_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'message' => 'Product taxes fetched successfully.',
            'data' => $rows,
        ]);
    }

    public function sync(Request $request, string $productId)
    {
        $company = app('company');
        $user = auth()->user();

        $result = hasPermission($user->role_id, 'tax_management', $company->id);
        if ($result !== true) return $result;

        $product = Product::where('company_id', $company->id)
            ->where('id', $productId)
            ->first();

        if (!$product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $validator = $this->syncValidator($request, (string) $company->id);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();
            $this->replaceProductTaxes($company->id, $product, collect($request->taxes ?? []));
            DB::commit();

            return response()->json([
                'message' => 'Product taxes updated successfully.',
                'data' => ProductTaxe::where('company_id', $company->id)
                    ->where('product_id', $product->id)
                    ->with('tax')
                    ->get(),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Product taxes update failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function syncMany(Request $request)
    {
        $company = app('company');
        $user = auth()->user();

        $result = hasPermission($user->role_id, 'tax_management', $company->id);
        if ($result !== true) return $result;

        $validator = Validator::make($request->all(), [
            'product_ids' => 'required|array|min:1',
            'product_ids.*' => 'required|uuid',
            'taxes' => 'nullable|array',
            'taxes.*.tax_id' => [
                'required',
                Rule::exists('taxes', 'id')->where('company_id', $company->id),
            ],
            'taxes.*.branch_id' => [
                'nullable',
                Rule::exists('company_branches', 'id')->where('company_id', $company->id),
            ],
            'taxes.*.is_inclusive' => 'required|boolean',
            'taxes.*.is_active' => 'nullable|boolean',
            'taxes.*.starts_at' => 'nullable|date',
            'taxes.*.ends_at' => 'nullable|date|after_or_equal:taxes.*.starts_at',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $productIds = array_values(array_unique($request->product_ids));

        $products = Product::where('company_id', $company->id)
            ->whereIn('id', $productIds)
            ->get();

        if ($products->count() !== count($productIds)) {
            return response()->json([
                'message' => 'One or more selected products were not found.',
            ], 404);
        }

        try {
            DB::beginTransaction();

            $taxRows = collect($request->taxes ?? []);

            foreach ($products as $product) {
                $this->replaceProductTaxes($company->id, $product, $taxRows);
            }

            DB::commit();

            return response()->json([
                'message' => 'Product taxes updated successfully.',
                'data' => [
                    'products_updated' => $products->count(),
                    'taxes_assigned' => $taxRows->count(),
                ],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Bulk product tax update failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(string $productId, string $productTaxId)
    {
        $company = app('company');
        $user = auth()->user();

        $result = hasPermission($user->role_id, 'tax_management', $company->id);
        if ($result !== true) return $result;

        $row = ProductTaxe::where('company_id', $company->id)
            ->where('product_id', $productId)
            ->where('id', $productTaxId)
            ->first();

        if (!$row) {
            return response()->json(['message' => 'Product tax assignment not found.'], 404);
        }

        $row->delete();

        return response()->json(['message' => 'Product tax removed successfully.']);
    }

    public function calculatePreview(Request $request, string $productId)
    {
        $company = app('company');
        $branchId = $this->branchContext->resolve($request, (string) $company->id);

        $product = Product::where('company_id', $company->id)
            ->where('id', $productId)
            ->first();

        if (!$product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'price' => 'required|numeric|min:0',
            'quantity' => 'nullable|numeric|min:0.0001',
            'discount_amount' => 'nullable|numeric|min:0',
            'taxes' => 'nullable|array',
            'taxes.*.tax_id' => [
                'required',
                Rule::exists('taxes', 'id')->where('company_id', $company->id),
            ],
            'taxes.*.branch_id' => [
                'nullable',
                Rule::exists('company_branches', 'id')->where('company_id', $company->id),
            ],
            'taxes.*.is_inclusive' => 'required|boolean',
            'taxes.*.is_active' => 'nullable|boolean',
            'taxes.*.starts_at' => 'nullable|date',
            'taxes.*.ends_at' => 'nullable|date|after_or_equal:taxes.*.starts_at',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $result = $this->calculateProductTax(
            $product->id,
            (float) $request->price,
            (float) ($request->quantity ?? 1),
            (float) ($request->discount_amount ?? 0),
            $company->id,
            $branchId,
            $request->has('taxes') ? collect($request->taxes ?? []) : null
        );

        return response()->json([
            'message' => 'Tax preview calculated successfully.',
            'data' => $result,
        ]);
    }

    public function calculateProductTax(
        string $productId,
        float $unitPrice,
        float $quantity,
        float $discountAmount,
        string $companyId,
        ?string $branchId = null,
        ?\Illuminate\Support\Collection $taxTemplate = null
    ): array {
        $gross = max(($unitPrice * $quantity) - $discountAmount, 0);

        if ($taxTemplate !== null) {
            $assignments = $taxTemplate
                ->filter(fn ($row) => ($row['is_active'] ?? true) !== false)
                ->map(function ($row) use ($companyId, $productId) {
                    $tax = Taxe::where('company_id', $companyId)
                        ->where('id', $row['tax_id'])
                        ->first();

                    return (object) [
                        'id' => null,
                        'product_id' => $productId,
                        'tax_id' => $row['tax_id'],
                        'is_inclusive' => (bool) $row['is_inclusive'],
                        'is_active' => $row['is_active'] ?? true,
                        'tax' => $tax,
                    ];
                })
                ->filter(fn ($assignment) => $assignment->tax && $assignment->tax->is_active)
                ->values();
        } else {
            $assignments = ProductTaxe::where('company_id', $companyId)
                ->where('product_id', $productId)
                ->where('is_active', true)
                ->where(function ($query) use ($branchId) {
                    $query->whereNull('branch_id');

                    if ($branchId) {
                        $query->orWhere('branch_id', $branchId);
                    }
                })
                ->where(function ($query) {
                    $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
                })
                ->where(function ($query) {
                    $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
                })
                ->with('tax')
                ->get();
        }

        $inclusiveRate = 0.0;

        foreach ($assignments as $assignment) {
            $tax = $assignment->tax;

            if (!$tax || !$tax->is_active || $tax->type !== 'percentage') {
                continue;
            }

            if ($assignment->is_inclusive) {
                $inclusiveRate += ((float) $tax->rate) / 100;
            }
        }

        $taxableBase = $inclusiveRate > 0
            ? $gross / (1 + $inclusiveRate)
            : $gross;

        $rows = [];
        $inclusiveTaxTotal = 0.0;
        $exclusiveTaxTotal = 0.0;

        foreach ($assignments as $assignment) {
            $tax = $assignment->tax;

            if (!$tax || !$tax->is_active) {
                continue;
            }

            if ($tax->type === 'fixed') {
                $taxAmount = ((float) $tax->rate) * $quantity;
            } else {
                $taxAmount = $taxableBase * (((float) $tax->rate) / 100);
            }

            if ($assignment->is_inclusive) {
                $inclusiveTaxTotal += $taxAmount;
                $salesAmountWithTax = $gross;
            } else {
                $exclusiveTaxTotal += $taxAmount;
                $salesAmountWithTax = $taxableBase + $taxAmount;
            }

            $rows[] = [
                'product_tax_id' => $assignment->id,
                'tax_id' => $tax->id,
                'fdms_tax_id' => $tax->tax_id,
                'tax_name' => $tax->name,
                'tax_code' => $tax->tax_code,
                'tax_type' => $tax->type,
                'tax_percent' => $tax->type === 'percentage' ? (float) $tax->rate : 0,
                'rate' => (float) $tax->rate,
                'is_inclusive' => (bool) $assignment->is_inclusive,
                'obliged_to_zimra' => (bool) $tax->obliged_to_zimra,
                'taxable_amount' => round($taxableBase, 4),
                'tax_amount' => round($taxAmount, 4),
                'sales_amount_with_tax' => round($salesAmountWithTax, 4),
            ];
        }

        return [
            'gross' => round($gross, 4),
            'taxable_amount' => round($taxableBase, 4),
            'inclusive_tax_total' => round($inclusiveTaxTotal, 4),
            'exclusive_tax_total' => round($exclusiveTaxTotal, 4),
            'tax_total' => round($inclusiveTaxTotal + $exclusiveTaxTotal, 4),
            'net_total' => round($taxableBase + $inclusiveTaxTotal + $exclusiveTaxTotal, 4),
            'taxes' => $rows,
            'zimra_taxes' => collect($rows)->where('obliged_to_zimra', true)->values()->all(),
        ];
    }

    private function syncValidator(Request $request, string $companyId)
    {
        return Validator::make($request->all(), [
            'taxes' => 'nullable|array',
            'taxes.*.tax_id' => [
                'required',
                Rule::exists('taxes', 'id')->where('company_id', $companyId),
            ],
            'taxes.*.branch_id' => [
                'nullable',
                Rule::exists('company_branches', 'id')->where('company_id', $companyId),
            ],
            'taxes.*.is_inclusive' => 'required|boolean',
            'taxes.*.is_active' => 'nullable|boolean',
            'taxes.*.starts_at' => 'nullable|date',
            'taxes.*.ends_at' => 'nullable|date|after_or_equal:taxes.*.starts_at',
        ]);
    }

    private function replaceProductTaxes(string $companyId, Product $product, \Illuminate\Support\Collection $incoming): void
    {
        $cleanRows = $incoming->map(function ($row) {
            return [
                'tax_id' => $row['tax_id'],
                'branch_id' => $row['branch_id'] ?? null,
                'is_inclusive' => (bool) $row['is_inclusive'],
                'is_active' => array_key_exists('is_active', $row) ? (bool) $row['is_active'] : true,
                'starts_at' => $row['starts_at'] ?? null,
                'ends_at' => $row['ends_at'] ?? null,
            ];
        });

        $duplicates = $cleanRows
            ->groupBy(fn ($row) => ($row['branch_id'] ?? 'global') . '|' . $row['tax_id'])
            ->filter(fn ($group) => $group->count() > 1);

        if ($duplicates->isNotEmpty()) {
            throw new \Exception('Duplicate tax assignment detected for the same product and branch.');
        }

        ProductTaxe::where('company_id', $companyId)
            ->where('product_id', $product->id)
            ->delete();

        foreach ($cleanRows as $row) {
            $tax = Taxe::where('company_id', $companyId)
                ->where('id', $row['tax_id'])
                ->where('is_active', true)
                ->first();

            if (!$tax) {
                throw new \Exception('One of the selected taxes is invalid or inactive.');
            }

            ProductTaxe::create([
                'company_id' => $companyId,
                'branch_id' => $row['branch_id'],
                'product_id' => $product->id,
                'tax_id' => $tax->id,
                'is_inclusive' => $row['is_inclusive'],
                'is_active' => $row['is_active'],
                'starts_at' => $row['starts_at'],
                'ends_at' => $row['ends_at'],
            ]);
        }

        $product->taxable = $cleanRows->where('is_active', true)->count() > 0;
        $product->save();
    }
}

