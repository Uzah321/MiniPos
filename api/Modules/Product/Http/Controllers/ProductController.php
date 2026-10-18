<?php
////
////namespace Modules\Product\Http\Controllers;
////
////use App\Http\Controllers\Controller;
////use App\Services\ProductIdentityGenerator;
////use Illuminate\Http\Request;
////use Illuminate\Support\Facades\DB;
////use Illuminate\Support\Facades\Validator;
////use Illuminate\Support\Str;
////use Illuminate\Validation\Rule;
////use Modules\Company\Models\AuditTrail;
////use Modules\Currency\Models\Currency;
////use App\Services\CompanySettingService;
////use Modules\Product\Models\PriceLevel;
////use Modules\Product\Models\Product;
////use Modules\Product\Models\ProductBarcode;
////use Modules\Product\Models\ProductBreakRule;
////use Modules\Product\Models\ProductCost;
////use Modules\Product\Models\ProductPrice;
////use Modules\Product\Models\ProductStock;
////use Modules\Product\Models\ProductSupplier;
////use Modules\Product\Models\StockMovement;
////use Modules\Product\Models\Uom;
////use Illuminate\Support\Facades\File;
////use function App\Helpers\hasPermission;
////
////class ProductController extends Controller
////{
////
////    public function index(Request $request)
////    {
////        $company = app('company');
////        $branchId = auth()->user()->branch_id;
////
////        $query = Product::where('company_id', $company->id)
////            ->with([
////                'category',
////                'supplier',
////                'parent',
////                'children',
////                'subCategory',
////                'uom',
////
////                // Optional legacy single tax from products.tax_id
////                'tax',
////
////                // New real many-tax relationships
////                'taxes.tax',
////                'productTaxes.tax',
////
////                'prices' => function ($query) use ($branchId) {
////                    $query->where(function ($sub) use ($branchId) {
////                        $sub->where('branch_id', $branchId)
////                            ->orWhereNull('branch_id');
////                    })
////                        ->where('is_active', true)
////                        ->with('priceLevel')
////                        ->orderByRaw(
////                            'CASE WHEN branch_id = ? THEN 0 ELSE 1 END',
////                            [$branchId]
////                        )
////                        ->orderByDesc('is_default');
////                },
////
////                'stocks' => function ($query) use ($branchId) {
////                    $query->where('branch_id', $branchId);
////                },
////
////                'costs' => function ($query) use ($branchId) {
////                    $query->where('branch_id', $branchId);
////                },
////
////                'barcodes',
////                'discounts',
////                'childBreakRule.parentProduct',
////            ])
////            ->latest();
////
////        if ($request->filled('q')) {
////            $q = $request->q;
////
////            $query->where(function ($sub) use ($q) {
////                $sub->where('name', 'like', "%{$q}%")
////                    ->orWhere('code', 'like', "%{$q}%")
////                    ->orWhere('sku', 'like', "%{$q}%")
////                    ->orWhere('barcode', 'like', "%{$q}%");
////            });
////        }
////
////        if ($request->filled('category_id')) {
////            $query->where('category_id', $request->category_id);
////        }
////
////        if ($request->filled('supplier_id')) {
////            $query->where('supplier_id', $request->supplier_id);
////        }
////        if ($request->filled('sub_category_id')) {
////            $query->where('sub_category_id', $request->sub_category_id);
////        }
////
////        if ($request->filled('product_type') || $request->filled('product_types')) {
////            $productTypes = $request->input(
////                'product_types',
////                $request->input('product_type')
////            );
////
////            if (is_string($productTypes)) {
////                $productTypes = explode(',', $productTypes);
////            }
////
////            $productTypes = collect($productTypes)
////                ->map(fn ($type) => trim($type))
////                ->filter()
////                ->values()
////                ->all();
////
////            if (count($productTypes) > 1) {
////                $query->whereIn('product_type', $productTypes);
////            } elseif (count($productTypes) === 1) {
////                $query->where('product_type', $productTypes[0]);
////            }
////        }
////
////        if ($request->filled('is_active')) {
////            $query->where(
////                'is_active',
////                filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)
////            );
////        }
////
////        return response()->json([
////            'message' => 'Products fetched successfully.',
////            'data' => $query->paginate($request->per_page ?? 15),
////        ]);
////    }
////    public function store(Request $request, ProductIdentityGenerator $identityGenerator)
////    {
////        $company = app('company');
////
////        $result = hasPermission(auth()->user()->role_id, 'product_management', $company->id);
////        if ($result !== true) return $result;
////
////        $validator = Validator::make($request->all(), [
////            'category_id' => 'nullable|exists:product_categories,id',
////            'supplier_id' => 'nullable|exists:suppliers,id',
////            'parent_product_id' => 'nullable|exists:products,id',
////            'base_uom_id' => 'required|exists:uoms,id',
////            'sub_category_id' => [
////                'nullable',
////                Rule::exists('product_sub_categories', 'id')->where('company_id', $company->id),
////            ],
////            'tax_id' => 'nullable|exists:taxes,id',
////
////            'name' => [
////                'required',
////                'string',
////                'max:255',
////                Rule::unique('products', 'name')->where('company_id', $company->id),
////            ],
////            'code' => [
////                'nullable',
////                'string',
////                'max:100',
////                Rule::unique('products', 'code')->where('company_id', $company->id),
////            ],
////            'sku' => [
////                'nullable',
////                'string',
////                'max:100',
////                Rule::unique('products', 'sku')->where('company_id', $company->id),
////            ],
////            'barcode' => [
////                'nullable',
////                'string',
////                'max:100',
////                Rule::unique('products', 'barcode')->where('company_id', $company->id),
////            ],
////
////            'description' => 'nullable|string',
////            'product_type' => 'required|in:simple,parent,child,service,raw_material,finished_good,semi_finished,packaging,consumable',
////
////            'stock_tracking_enabled' => 'nullable|boolean',
////            'allow_negative_stock' => 'nullable|boolean',
////            'allow_discount' => 'nullable|boolean',
////            'taxable' => 'nullable|boolean',
////            'is_active' => 'nullable|boolean',
////
////            'reorder_level' => 'nullable|numeric|min:0',
////            'minimum_stock' => 'nullable|numeric|min:0',
////            'maximum_stock' => 'nullable|numeric|min:0',
////
////            'prices' => 'nullable|array',
////            'prices.*.branch_id' => 'nullable|exists:company_branches,id',
////            'prices.*.price_level_id' => 'required|exists:price_levels,id',
////            'prices.*.price' => 'required|numeric|min:0',
////            'prices.*.currency' => 'nullable|string|max:10',
////            'prices.*.is_default' => 'nullable|boolean',
////
////            'barcodes' => 'nullable|array',
////            'barcodes.*.barcode' => 'required|string|max:100',
////            'barcodes.*.type' => 'nullable|in:primary,alternative,supplier',
////
////            'suppliers' => 'nullable|array',
////            'suppliers.*.supplier_id' => 'required|exists:suppliers,id',
////            'suppliers.*.supplier_code' => 'nullable|string|max:100',
////            'suppliers.*.last_purchase_price' => 'nullable|numeric|min:0',
////            'suppliers.*.is_default' => 'nullable|boolean',
////
////            'discount_ids' => 'nullable|array',
////            'discount_ids.*' => 'exists:discounts,id',
////
////            'break_rule' => 'nullable|array',
////            'break_rule.parent_product_id' => 'required_with:break_rule|exists:products,id',
////            'break_rule.parent_quantity' => 'required_with:break_rule|numeric|min:0.0001',
////            'break_rule.units_per_parent' => 'required_with:break_rule|numeric|min:0.0001',
////            'break_rule.is_auto_break' => 'nullable|boolean',
////
////            'opening_stocks' => 'nullable|array',
////            'opening_stocks.*.branch_id' => 'required|exists:company_branches,id',
////            'opening_stocks.*.quantity' => 'required|numeric|min:0',
////            'opening_stocks.*.unit_cost' => 'nullable|numeric|min:0',
////            'opening_stocks.*.currency' => 'required',
////        ]);
////
////        if ($validator->fails()) {
////            return response()->json([
////                'message' => 'Validation error.',
////                'errors' => $validator->errors(),
////            ], 422);
////        }
////
////        DB::beginTransaction();
////
////        try {
////            $productData = $identityGenerator->generateAllForProductCreate(
////                $company->id,
////                auth()->user()->branch_id,
////                [
////                    'company_id' => $company->id,
////                    'category_id' => $request->category_id,
////                    'supplier_id' => $request->supplier_id,
////                    'sub_category_id' => $request->sub_category_id,
////                    'parent_product_id' => $request->parent_product_id,
////                    'base_uom_id' => $request->base_uom_id,
////                    'tax_id' => $request->tax_id,
////
////                    'name' => $request->name,
////                    'code' => $request->code,
////                    'sku' => $request->sku,
////                    'barcode' => $request->barcode,
////                    'description' => $request->description,
////                    'product_type' => $request->product_type,
////
////                    'stock_tracking_enabled' => $request->stock_tracking_enabled ?? true,
////                    'allow_negative_stock' => $request->allow_negative_stock ?? false,
////                    'allow_discount' => $request->allow_discount ?? true,
////                    'taxable' => $request->taxable ?? false,
////                    'is_active' => $request->is_active ?? true,
////
////                    'reorder_level' => $request->reorder_level ?? 0,
////                    'minimum_stock' => $request->minimum_stock ?? 0,
////                    'maximum_stock' => $request->maximum_stock ?? 0,
////                ]
////            );
////
////            $identityGenerator->assertProductIdentityIsComplete($productData);
////
////            $product = Product::create($productData);
////
////            $this->syncPrices($product, $request->prices ?? []);
////            $this->syncBarcodes($product, $request->barcodes ?? []);
////            $this->syncSuppliers($product, $request->suppliers ?? []);
////            $this->syncDiscounts($product, $request->discount_ids ?? []);
////
////            if ($request->filled('break_rule')) {
////                $this->saveBreakRule($product, $request->break_rule);
////            }
////
////            if ($request->filled('opening_stocks')) {
////                foreach ($request->opening_stocks as $stockData) {
////                    $this->receiveProductStock(
////                        $product,
////                        $stockData['branch_id'],
////                        $stockData['quantity'],
////                        $stockData['unit_cost'] ?? 0,
////                        $stockData['currency'] ?? null,
////                        'opening',
////                        null,
////                        'Opening stock'
////                    );
////                }
////            }
////
////            DB::commit();
////
////            return response()->json([
////                'message' => 'Product created successfully.',
////                'data' => $this->loadProduct($product->id),
////            ], 201);
////        } catch (\Exception $e) {
////            DB::rollBack();
////
////            return response()->json([
////                'message' => 'Something went wrong while creating product.',
////                'error' => $e->getMessage(),
////            ], 500);
////        }
////    }
////
////    public function show(string $id)
////    {
////        $product = $this->loadProduct($id);
////
////        if (!$product) {
////            return response()->json(['message' => 'Product not found.'], 404);
////        }
////
////        return response()->json([
////            'message' => 'Product fetched successfully.',
////            'data' => $product,
////        ]);
////    }
////
////    public function update(Request $request, string $id)
////    {
////        $company = app('company');
////        $result = hasPermission(auth()->user()->role_id, 'product_management', $company->id);
////        if ($result !== true) return $result;
////
////        $product = Product::where('company_id', $company->id)
////            ->where('id', $id)
////            ->first();
////
////        if (!$product) {
////            return response()->json(['message' => 'Product not found.'], 404);
////        }
////
////        $validator = Validator::make($request->all(), [
////            'category_id' => 'nullable|exists:product_categories,id',
////            'supplier_id' => 'nullable|exists:suppliers,id',
////            'parent_product_id' => 'nullable|exists:products,id',
////            'base_uom_id' => 'required|exists:uoms,id',
////            'tax_id' => 'nullable|exists:taxes,id',
////            'sub_category_id' => [
////                'nullable',
////                Rule::exists('product_sub_categories', 'id')->where('company_id', $company->id),
////            ],
////
////            'name' => [
////                'required',
////                'string',
////                'max:255',
////                Rule::unique('products', 'name')->where('company_id', $company->id)->ignore($product->id),
////            ],
////            'code' => [
////                'required',
////                'string',
////                'max:100',
////                Rule::unique('products', 'code')->where('company_id', $company->id)->ignore($product->id),
////            ],
////            'sku' => [
////                'required',
////                'string',
////                'max:100',
////                Rule::unique('products', 'sku')->where('company_id', $company->id)->ignore($product->id),
////            ],
////            'barcode' => [
////                'nullable',
////                'string',
////                'max:100',
////                Rule::unique('products', 'barcode')->where('company_id', $company->id)->ignore($product->id),
////            ],
////
////            'description' => 'nullable|string',
////            'product_type' => 'required|in:simple,parent,child,service,raw_material,finished_good,semi_finished,packaging,consumable',
////
////
////
////            'stock_tracking_enabled' => 'nullable|boolean',
////            'allow_negative_stock' => 'nullable|boolean',
////            'allow_discount' => 'nullable|boolean',
////            'taxable' => 'nullable|boolean',
////            'is_active' => 'nullable|boolean',
////
////            'reorder_level' => 'nullable|numeric|min:0',
////            'minimum_stock' => 'nullable|numeric|min:0',
////            'maximum_stock' => 'nullable|numeric|min:0',
////        ]);
////
////        if ($validator->fails()) {
////            return response()->json([
////                'message' => 'Validation error.',
////                'errors' => $validator->errors(),
////            ], 422);
////        }
////
////        $product->update($request->only([
////            'category_id',
////            'supplier_id',
////            'sub_category_id',
////            'parent_product_id',
////            'base_uom_id',
////            'tax_id',
////            'name',
////            'code',
////            'sku',
////            'barcode',
////            'description',
////            'product_type',
////            'stock_tracking_enabled',
////            'allow_negative_stock',
////            'allow_discount',
////            'taxable',
////            'is_active',
////            'reorder_level',
////            'minimum_stock',
////            'maximum_stock',
////        ]));
////
////        return response()->json([
////            'message' => 'Product updated successfully.',
////            'data' => $this->loadProduct($product->id),
////        ]);
////    }
////
////    public function destroy(string $id)
////    {
////        $company = app('company');
////        $result = hasPermission(auth()->user()->role_id, 'product_management', $company->id);
////        if ($result !== true) return $result;
////
////        $product = Product::where('company_id', $company->id)
////            ->where('id', $id)
////            ->first();
////
////        if (!$product) {
////            return response()->json(['message' => 'Product not found.'], 404);
////        }
////
////        $product->delete();
////
////        return response()->json([
////            'message' => 'Product deleted successfully.',
////        ]);
////    }
////
//////    public function addPrice(Request $request, string $id)
//////    {
//////        $company = app('company');
//////        $user = auth()->user();
//////        $result = hasPermission(auth()->user()->role_id, 'add_price', $company->id);
//////        if ($result !== true) return $result;
//////
//////        $product = Product::where('company_id', $company->id)
//////            ->where('id', $id)
//////            ->first();
//////
//////        if (!$product) {
//////            return response()->json([
//////                'message' => 'Product not found.',
//////            ], 404);
//////        }
//////
//////        $validator = Validator::make($request->all(), [
//////            'price_level_id' => 'required|exists:price_levels,id',
//////            'branch_id' => 'nullable|exists:company_branches,id',
//////            'price' => 'required|numeric|min:0',
//////            'currency' => 'required|string|size:3',
//////            'is_default' => 'nullable|boolean',
//////            'is_active' => 'nullable|boolean',
//////            'starts_at' => 'nullable|date',
//////            'ends_at' => 'nullable|date|after_or_equal:starts_at',
//////        ]);
//////
//////        if ($validator->fails()) {
//////            return response()->json([
//////                'message' => 'Validation error.',
//////                'errors' => $validator->errors(),
//////            ], 422);
//////        }
//////
//////        DB::beginTransaction();
//////
//////        try {
//////            $branchId = $request->branch_id ?: ($user->branch_id ?? null);
//////
//////            $currencyCode = $this->validateSelectedBaseCurrency(
//////                $company->id,
//////                $branchId,
//////                $request->currency
//////            );
//////
//////            if ($request->boolean('is_default')) {
//////                ProductPrice::where('company_id', $company->id)
//////                    ->where('product_id', $product->id)
//////                    ->where('price_level_id', $request->price_level_id)
//////                    ->where('branch_id', $branchId)
//////                    ->update([
//////                        'is_default' => false,
//////                    ]);
//////            }
//////
//////            $price = ProductPrice::create([
//////                'company_id' => $company->id,
//////                'branch_id' => $branchId,
//////                'product_id' => $product->id,
//////                'price_level_id' => $request->price_level_id,
//////                'price' => (float) $request->price,
//////                'currency' => $currencyCode,
//////                'is_default' => $request->boolean('is_default', false),
//////                'is_active' => $request->boolean('is_active', true),
//////                'starts_at' => $request->starts_at,
//////                'ends_at' => $request->ends_at,
//////            ]);
//////
//////            DB::commit();
//////
//////            return response()->json([
//////                'message' => 'Product price added successfully.',
//////                'currency' => $currencyCode,
//////                'data' => $price,
//////            ], 201);
//////        } catch (\Exception $e) {
//////            DB::rollBack();
//////
//////            return response()->json([
//////                'message' => 'Something went wrong while adding product price.',
//////                'error' => $e->getMessage(),
//////            ], 500);
//////        }
//////    }
////    public function addPrice(Request $request, string $id)
////    {
////        $company = app('company');
////        $user = auth()->user();
////
////        $result = hasPermission($user->role_id, 'add_price', $company->id);
////        if ($result !== true) return $result;
////
////        $product = Product::where('company_id', $company->id)
////            ->where('id', $id)
////            ->first();
////
////        if (!$product) {
////            return response()->json([
////                'message' => 'Product not found.',
////            ], 404);
////        }
////
////        $validator = Validator::make($request->all(), [
////            'price_level_id' => 'required|exists:price_levels,id',
////            'branch_id' => 'nullable|exists:company_branches,id',
////            'price' => 'required|numeric|min:0',
////            'currency' => 'required|string|size:3',
////            'is_default' => 'nullable|boolean',
////            'is_active' => 'nullable|boolean',
////            'starts_at' => 'nullable|date',
////            'ends_at' => 'nullable|date|after_or_equal:starts_at',
////        ]);
////
////        if ($validator->fails()) {
////            return response()->json([
////                'message' => 'Validation error.',
////                'errors' => $validator->errors(),
////            ], 422);
////        }
////
////        DB::beginTransaction();
////
////        try {
////            $branchId = $request->branch_id ?: ($user->branch_id ?? null);
////
////            $currencyCode = $this->validateSelectedBaseCurrency(
////                $company->id,
////                $branchId,
////                $request->currency
////            );
////
////            if ($request->boolean('is_default')) {
////                $defaultQuery = ProductPrice::withoutGlobalScopes()
////                    ->where('company_id', $company->id)
////                    ->where('product_id', $product->id)
////                    ->where('price_level_id', $request->price_level_id);
////
////                if ($branchId) {
////                    $defaultQuery->where('branch_id', $branchId);
////                } else {
////                    $defaultQuery->whereNull('branch_id');
////                }
////
////                $defaultQuery->update([
////                    'is_default' => false,
////                ]);
////            }
////
////            $priceQuery = ProductPrice::withoutGlobalScopes()
////                ->where('company_id', $company->id)
////                ->where('product_id', $product->id)
////                ->where('price_level_id', $request->price_level_id);
////
////            if ($branchId) {
////                $priceQuery->where('branch_id', $branchId);
////            } else {
////                $priceQuery->whereNull('branch_id');
////            }
////
////            $price = $priceQuery->first();
////
////            $payload = [
////                'company_id' => $company->id,
////                'branch_id' => $branchId,
////                'product_id' => $product->id,
////                'price_level_id' => $request->price_level_id,
////                'price' => (float) $request->price,
////                'currency' => $currencyCode,
////                'is_default' => $request->boolean('is_default', false),
////                'is_active' => $request->boolean('is_active', true),
////                'starts_at' => $request->starts_at,
////                'ends_at' => $request->ends_at,
////            ];
////
////            if ($price) {
////                $price->fill($payload);
////
////                if (array_key_exists('deleted_at', $price->getAttributes())) {
////                    $price->deleted_at = null;
////                }
////
////                $price->save();
////                $statusCode = 200;
////                $message = 'Product price updated successfully.';
////            } else {
////                $price = ProductPrice::create($payload);
////                $statusCode = 201;
////                $message = 'Product price added successfully.';
////            }
////
////            DB::commit();
////
////            return response()->json([
////                'message' => $message,
////                'currency' => $currencyCode,
////                'data' => $price,
////            ], $statusCode);
////        } catch (\Exception $e) {
////            DB::rollBack();
////
////            return response()->json([
////                'message' => 'Something went wrong while saving product price.',
////                'error' => $e->getMessage(),
////            ], 500);
////        }
////    }
////    public function deletePrice(string $productId, string $priceId)
////    {
////        $company = app('company');
////        $result = hasPermission(auth()->user()->role_id, 'delete_price', $company->id);
////        if ($result !== true) return $result;
////
////        $price = ProductPrice::where('company_id', $company->id)
////            ->where('product_id', $productId)
////            ->where('id', $priceId)
////            ->first();
////
////        if (!$price) {
////            return response()->json(['message' => 'Product price not found.'], 404);
////        }
////
////        $price->delete();
////
////        return response()->json([
////            'message' => 'Product price deleted successfully.',
////        ]);
////    }
////
////
////    public function receiveStock(Request $request, string $id, CompanySettingService $settings)
////    {
////        $company = app('company');
////        $user = auth()->user();
////
////        $result = hasPermission($user->role_id, 'receive_stock', $company->id);
////        if ($result !== true) return $result;
////
////        $product = Product::where('company_id', $company->id)
////            ->where('id', $id)
////            ->first();
////
////        if (!$product) {
////            return response()->json([
////                'message' => 'Product not found.',
////            ], 404);
////        }
////
////        $validator = Validator::make($request->all(), [
////            'branch_id' => 'required|exists:company_branches,id',
////            'quantity' => 'required|numeric|min:0.0001',
////            'unit_cost' => 'required|numeric|min:0',
////            'currency' => 'required|string|size:3',
////            'notes' => 'nullable|string',
////        ]);
////
////        if ($validator->fails()) {
////            return response()->json([
////                'message' => 'Validation error.',
////                'errors' => $validator->errors(),
////            ], 422);
////        }
////
////        $branchId = $request->branch_id ?: ($user->branch_id ?? null);
////
////        $stockArea = (
////            $settings->isFastFoodMode($company->id) &&
////            $settings->isBohEnabled($company->id) &&
////            $settings->get($company->id, 'receive_stock_to_boh_first', true)
////        ) ? 'back' : 'front';
////
////        DB::beginTransaction();
////
////        try {
////            $currencyCode = $this->validateSelectedBaseCurrency(
////                $company->id,
////                $branchId,
////                $request->currency
////            );
////
////            $quantity = (float) $request->quantity;
////            $unitCost = (float) $request->unit_cost;
////            $totalCost = $quantity * $unitCost;
////
////            $stock = $this->getOrCreateStock($product, $branchId, $stockArea);
////
////            $stock->quantity_on_hand += $quantity;
////            $stock->available_quantity += $quantity;
////            $stock->save();
////
////            $cost = ProductCost::firstOrCreate(
////                [
////                    'company_id' => $product->company_id,
////                    'branch_id' => $branchId,
////                    'product_id' => $product->id,
////                ],
////                [
////                    'average_cost' => 0,
////                    'last_cost' => 0,
////                    'total_quantity' => 0,
////                    'total_value' => 0,
////                    'currency' => $currencyCode,
////                ]
////            );
////
////            $previousQuantity = (float) $cost->total_quantity;
////            $previousValue = (float) $cost->total_value;
////
////            $newTotalQuantity = $previousQuantity + $quantity;
////            $newTotalValue = $previousValue + $totalCost;
////
////            $cost->last_cost = $unitCost;
////            $cost->total_quantity = $newTotalQuantity;
////            $cost->total_value = $newTotalValue;
////            $cost->average_cost = $newTotalQuantity > 0
////                ? $newTotalValue / $newTotalQuantity
////                : 0;
////            $cost->currency = $currencyCode;
////            $cost->save();
////
////            StockMovement::create([
////                'company_id' => $product->company_id,
////                'branch_id' => $branchId,
////                'product_id' => $product->id,
////                'movement_type' => $stockArea === 'back'
////                    ? 'boh_purchase_receive'
////                    : 'purchase',
////                'stock_area' => $stockArea,
////                'quantity_in' => $quantity,
////                'quantity_out' => 0,
////                'unit_cost' => $unitCost,
////                'total_cost' => $totalCost,
////                'currency' => $currencyCode,
////                'notes' => $request->notes,
////                'created_by' => auth()->id(),
////            ]);
////
////            DB::commit();
////
////            return response()->json([
////                'message' => $stockArea === 'back'
////                    ? 'Stock received into BOH/back stock successfully.'
////                    : 'Stock received successfully.',
////                'currency' => $currencyCode,
////                'stock_area' => $stockArea,
////                'data' => $this->loadProduct($product->id),
////            ], 200);
////        } catch (\Exception $e) {
////            DB::rollBack();
////
////            return response()->json([
////                'message' => 'Something went wrong while receiving stock.',
////                'error' => $e->getMessage(),
////            ], 500);
////        }
////    }
////
////    public function adjustStock(Request $request, string $id)
////    {
////        $company = app('company');
////
////        $result = hasPermission(auth()->user()->role_id, 'adjust_stock', $company->id);
////        if ($result !== true) return $result;
////
////        $product = Product::where('company_id', $company->id)
////            ->where('id', $id)
////            ->first();
////
////        if (!$product) {
////            return response()->json(['message' => 'Product not found.'], 404);
////        }
////
////        $validator = Validator::make($request->all(), [
////            'branch_id' => 'required|exists:company_branches,id',
////            'adjustment_type' => 'required|in:increase,decrease',
////            'stock_area' => 'nullable|in:front,back',
////            'quantity' => 'required|numeric|min:0.0001',
////            'notes' => 'nullable|string',
////        ]);
////
////        if ($validator->fails()) {
////            return response()->json([
////                'message' => 'Validation error.',
////                'errors' => $validator->errors(),
////            ], 422);
////        }
////
////        DB::beginTransaction();
////
////        try {
////            $stockArea = $request->input('stock_area', 'front');
////            $stock = $this->getOrCreateStock($product, $request->branch_id, $stockArea);
////
////            if ($request->adjustment_type === 'increase') {
////                $stock->quantity_on_hand += $request->quantity;
////                $stock->available_quantity += $request->quantity;
////
////                $quantityIn = $request->quantity;
////                $quantityOut = 0;
////            } else {
////                if (!$product->allow_negative_stock && $stock->available_quantity < $request->quantity) {
////                    DB::rollBack();
////
////                    return response()->json([
////                        'message' => 'Insufficient stock.',
////                    ], 422);
////                }
////
////                $stock->quantity_on_hand -= $request->quantity;
////                $stock->available_quantity -= $request->quantity;
////
////                $quantityIn = 0;
////                $quantityOut = $request->quantity;
////            }
////
////            $stock->save();
////
////            StockMovement::create([
////                'company_id' => $product->company_id,
////                'branch_id' => $request->branch_id,
////                'product_id' => $product->id,
////                'movement_type' => 'adjustment',
////                'stock_area' => $stockArea,
////                'quantity_in' => $quantityIn,
////                'quantity_out' => $quantityOut,
////                'unit_cost' => 0,
////                'total_cost' => 0,
////                'notes' => $request->notes,
////                'created_by' => auth()->id(),
////            ]);
////
////            DB::commit();
////
////            return response()->json([
////                'message' => 'Stock adjusted successfully.',
////                'data' => $this->loadProduct($product->id),
////            ]);
////        } catch (\Exception $e) {
////            DB::rollBack();
////
////            return response()->json([
////                'message' => 'Something went wrong while adjusting stock.',
////                'error' => $e->getMessage(),
////            ], 500);
////        }
////    }
////
////    public function adjustStockBulk(Request $request)
////    {
////        $company = app('company');
////        $user = auth()->user();
////        $result = hasPermission(auth()->user()->role_id, 'adjust_stock', $company->id);
////        if ($result !== true) return $result;
////
////        $validator = Validator::make($request->all(), [
////            'branch_id' => 'required|exists:company_branches,id',
////            'stock_area' => 'nullable|in:front,back',
////            'items' => 'required|array|min:1',
////            'items.*.product_id' => 'required|exists:products,id',
////            'items.*.quantity' => 'required|numeric|min:0',
////            'items.*.notes' => 'nullable|string',
////        ]);
////
////        if ($validator->fails()) {
////            return response()->json([
////                'message' => 'Validation error.',
////                'errors' => $validator->errors(),
////            ], 422);
////        }
////
////        DB::beginTransaction();
////
////        try {
////            $adjustedProducts = [];
////            $skippedProducts = [];
////            $movementIds = [];
////            $stockArea = $request->input('stock_area', 'front');
////
////            foreach ($request->items as $item) {
////                $product = Product::where('company_id', $company->id)
////                    ->where('id', $item['product_id'])
////                    ->first();
////
////                if (!$product) {
////                    $skippedProducts[] = [
////                        'product_id' => $item['product_id'],
////                        'message' => 'Product not found for this company.',
////                    ];
////                    continue;
////                }
////
////                $stock = $this->getOrCreateStock($product, $request->branch_id, $stockArea);
////
////                $previousQuantity = (float) $stock->quantity_on_hand;
////                $previousAvailable = (float) $stock->available_quantity;
////                $reservedQuantity = (float) $stock->reserved_quantity;
////
////                $newQuantity = (float) $item['quantity'];
////                $difference = $newQuantity - $previousQuantity;
////
////                if ($difference == 0) {
////                    $skippedProducts[] = [
////                        'product_id' => $product->id,
////                        'product_name' => $product->name,
////                        'sku' => $product->sku,
////                        'previous_quantity' => $previousQuantity,
////                        'new_quantity' => $newQuantity,
////                        'message' => 'No quantity change detected.',
////                    ];
////                    continue;
////                }
////
////                if ($difference > 0) {
////                    $adjustmentType = 'increase';
////                    $quantityIn = abs($difference);
////                    $quantityOut = 0;
////                } else {
////                    $adjustmentType = 'decrease';
////                    $quantityIn = 0;
////                    $quantityOut = abs($difference);
////
////                    if (!$product->allow_negative_stock && $newQuantity < 0) {
////                        DB::rollBack();
////
////                        return response()->json([
////                            'message' => "Negative stock is not allowed for {$product->name}.",
////                        ], 422);
////                    }
////                }
////
////                $stock->quantity_on_hand = $newQuantity;
////
////                if ($product->allow_negative_stock) {
////                    $stock->available_quantity = $newQuantity - $reservedQuantity;
////                } else {
////                    $stock->available_quantity = max(0, $newQuantity - $reservedQuantity);
////                }
////
////                $stock->save();
////
////                $movement = StockMovement::create([
////                    'company_id' => $product->company_id,
////                    'branch_id' => $request->branch_id,
////                    'product_id' => $product->id,
////                    'movement_type' => 'adjustment',
////                    'stock_area' => $stockArea,
////                    'quantity_in' => $quantityIn,
////                    'quantity_out' => $quantityOut,
////                    'unit_cost' => 0,
////                    'total_cost' => 0,
////                    'notes' => $item['notes'] ?? null,
////                    'created_by' => $user?->id,
////                ]);
////
////                $movementIds[] = $movement->id;
////
////                $adjustedProducts[] = [
////                    'product_id' => $product->id,
////                    'product_name' => $product->name,
////                    'sku' => $product->sku,
////                    'code' => $product->code,
////                    'barcode' => $product->barcode,
////                    'previous_quantity' => $previousQuantity,
////                    'new_quantity' => $newQuantity,
////                    'difference' => $difference,
////                    'adjustment_type' => $adjustmentType,
////                    'quantity_in' => $quantityIn,
////                    'quantity_out' => $quantityOut,
////                    'previous_available_quantity' => $previousAvailable,
////                    'available_quantity' => $stock->available_quantity,
////                    'reserved_quantity' => $stock->reserved_quantity,
////                    'notes' => $item['notes'] ?? null,
////                    'stock_area' => $stockArea,
////                    'movement_id' => $movement->id,
////                ];
////            }
////
////            $this->writeAuditTrail($request, [
////                'company_id' => $company->id,
////                'branch_id' => $request->branch_id,
////                'action' => 'stock_adjusted_bulk',
////                'module' => 'inventory',
////                'description' => 'Bulk stock adjustment completed.',
////                'auditable_type' => StockMovement::class,
////                'auditable_id' => $movementIds[0] ?? null,
////                'old_values' => null,
////                'new_values' => [
////                    'adjusted_count' => count($adjustedProducts),
////                    'skipped_count' => count($skippedProducts),
////                ],
////                'changed_fields' => ['quantity_on_hand', 'available_quantity'],
////                'metadata' => [
////                    'movement_ids' => $movementIds,
////                    'adjusted' => $adjustedProducts,
////                    'skipped' => $skippedProducts,
////                ],
////            ]);
////
////            DB::commit();
////
////            return response()->json([
////                'message' => 'Bulk stock adjustment completed successfully.',
////                'data' => [
////                    'adjusted' => $adjustedProducts,
////                    'skipped' => $skippedProducts,
////                ],
////            ], 200);
////        } catch (\Exception $e) {
////            DB::rollBack();
////
////            $this->writeAuditTrail($request, [
////                'company_id' => $company->id,
////                'branch_id' => $request->branch_id,
////                'action' => 'stock_adjust_bulk_failed',
////                'module' => 'inventory',
////                'description' => 'Bulk stock adjustment failed.',
////                'auditable_type' => StockMovement::class,
////                'auditable_id' => null,
////                'old_values' => null,
////                'new_values' => [
////                    'items' => $request->items,
////                ],
////                'changed_fields' => ['quantity_on_hand'],
////                'status' => 'failed',
////                'error_message' => $e->getMessage(),
////                'metadata' => [
////                    'branch_id' => $request->branch_id,
////                ],
////            ]);
////
////            return response()->json([
////                'message' => 'Something went wrong while adjusting stock.',
////                'error' => $e->getMessage(),
////            ], 500);
////        }
////    }
////
////    public function stockAdjustmentHistory(Request $request)
////    {
////        $company = app('company');
////        $user = auth()->user();
////
////        $query = StockMovement::where('company_id', $company->id)
////            ->where('movement_type', 'adjustment')
////            ->with([
////                'product.category',
////                'product.uom',
////                'branch',
////                'createdBy',
////            ])
////            ->orderByDesc('created_at');
////
////        if ($request->filled('branch_id')) {
////            $query->where('branch_id', $request->branch_id);
////        } else if (!empty($user?->branch_id)) {
////            $query->where('branch_id', $user->branch_id);
////        }
////
////        if ($request->filled('product_id')) {
////            $query->where('product_id', $request->product_id);
////        }
////
////        if ($request->filled('stock_area')) {
////            $query->where('stock_area', $request->stock_area);
////        }
////
////        if ($request->filled('q')) {
////            $q = $request->q;
////
////            $query->whereHas('product', function ($sub) use ($q) {
////                $sub->where('name', 'like', "%{$q}%")
////                    ->orWhere('sku', 'like', "%{$q}%")
////                    ->orWhere('code', 'like', "%{$q}%")
////                    ->orWhere('barcode', 'like', "%{$q}%");
////            });
////        }
////
////        if ($request->filled('adjustment_type')) {
////            if ($request->adjustment_type === 'increase') {
////                $query->where('quantity_in', '>', 0);
////            }
////
////            if ($request->adjustment_type === 'decrease') {
////                $query->where('quantity_out', '>', 0);
////            }
////        }
////
////        if ($request->filled('date_from')) {
////            $query->whereDate('created_at', '>=', $request->date_from);
////        }
////
////        if ($request->filled('date_to')) {
////            $query->whereDate('created_at', '<=', $request->date_to);
////        }
////
////        $perPage = (int) $request->get('per_page', 50);
////
////        $movements = $query->paginate($perPage);
////
////        return response()->json([
////            'message' => 'Stock adjustment history fetched successfully.',
////            'data' => $movements,
////        ], 200);
////    }
////
////    public function breakProduct(Request $request, string $childProductId)
////    {
////        $company = app('company');
////
////        $child = Product::where('company_id', $company->id)
////            ->where('id', $childProductId)
////            ->first();
////
////        if (!$child) {
////            return response()->json([
////                'message' => 'Child product not found.',
////            ], 404);
////        }
////
////        $validator = Validator::make($request->all(), [
////            'branch_id' => 'required|exists:company_branches,id',
////            'break_times' => 'nullable|integer|min:1',
////        ]);
////
////        if ($validator->fails()) {
////            return response()->json([
////                'message' => 'Validation error.',
////                'errors' => $validator->errors(),
////            ], 422);
////        }
////
////        DB::beginTransaction();
////
////        try {
////            $this->breakChildProduct(
////                $child,
////                $request->branch_id,
////                $request->break_times ?? 1
////            );
////
////            DB::commit();
////
////            return response()->json([
////                'message' => 'Product broken successfully.',
////                'data' => $this->loadProduct($child->id),
////            ], 200);
////        } catch (\Exception $e) {
////            DB::rollBack();
////
////            return response()->json([
////                'message' => $e->getMessage(),
////            ], 422);
////        }
////    }
////
////
////    public function frontStock(Request $request)
////    {
////        return $this->stockByArea($request, 'front');
////    }
////
////    public function backStock(Request $request, CompanySettingService $settings)
////    {
////        $company = app('company');
////
////        if (!$settings->isFastFoodMode($company->id) || !$settings->isBohEnabled($company->id)) {
////            return response()->json([
////                'message' => 'BOH is not enabled for this company.',
////            ], 422);
////        }
////
////        return $this->stockByArea($request, 'back');
////    }
////
////    private function stockByArea(Request $request, string $stockArea)
////    {
////        $company = app('company');
////
////        $result = hasPermission(auth()->user()->role_id, 'view_stock_reports', $company->id);
////        if ($result !== true) return $result;
////
////        $perPage = (int) $request->get('per_page', 50);
////        $perPage = $perPage > 0 && $perPage <= 200 ? $perPage : 50;
////
////        $query = ProductStock::query()
////            ->where('company_id', $company->id)
////            ->where('stock_area', $stockArea)
////            ->with([
////                'branch',
////                'product.category',
////                'product.supplier',
////                'product.uom',
////                'product.costs',
////            ]);
////
////        if ($request->filled('branch_id')) {
////            $query->where('branch_id', $request->branch_id);
////        }
////
////        if ($request->filled('product_id')) {
////            $query->where('product_id', $request->product_id);
////        }
////
////        if ($request->filled('product_type')) {
////            $query->whereHas('product', function ($productQuery) use ($request) {
////                $productQuery->where('product_type', $request->product_type);
////            });
////        }
////
////        if ($request->filled('category_id')) {
////            $query->whereHas('product', function ($productQuery) use ($request) {
////                $productQuery->where('category_id', $request->category_id);
////            });
////        }
////
////        if ($request->filled('q')) {
////            $search = trim($request->q);
////
////            $query->whereHas('product', function ($productQuery) use ($search) {
////                $productQuery->where('name', 'like', "%{$search}%")
////                    ->orWhere('code', 'like', "%{$search}%")
////                    ->orWhere('sku', 'like', "%{$search}%")
////                    ->orWhere('barcode', 'like', "%{$search}%");
////            });
////        }
////
////        $rows = $query
////            ->orderByDesc('updated_at')
////            ->paginate($perPage);
////
////        $rows->getCollection()->transform(function ($stock) {
////            $cost = optional($stock->product)->costs
////                ? $stock->product->costs->firstWhere('branch_id', $stock->branch_id)
////                : null;
////
////            if (!$cost && optional($stock->product)->costs) {
////                $cost = $stock->product->costs->first();
////            }
////
////            $stock->setAttribute('cost', $cost);
////            $stock->setAttribute('average_cost', $cost?->average_cost);
////            $stock->setAttribute('last_cost', $cost?->last_cost);
////            $stock->setAttribute('total_value', $cost?->total_value);
////            $stock->setAttribute('currency', $cost?->currency);
////
////            return $stock;
////        });
////
////        return response()->json([
////            'message' => strtoupper($stockArea) . ' stock fetched successfully.',
////            'stock_area' => $stockArea,
////            'data' => $rows,
////        ]);
////    }
////
////
////    public function issueToFront(Request $request, CompanySettingService $settings)
////    {
////        $company = app('company');
////
////        if (!$settings->isFastFoodMode($company->id) || !$settings->isBohEnabled($company->id)) {
////            return response()->json([
////                'message' => 'BOH is not enabled for this company.',
////            ], 422);
////        }
////
////        $result = hasPermission(auth()->user()->role_id, 'boh_issue_stock', $company->id);
////        if ($result !== true) return $result;
////
////        $validator = Validator::make($request->all(), [
////            'branch_id' => 'required|exists:company_branches,id',
////            'items' => 'required|array|min:1',
////            'items.*.product_id' => 'required|exists:products,id',
////            'items.*.quantity' => 'required|numeric|min:0.0001',
////            'notes' => 'nullable|string',
////        ]);
////
////        if ($validator->fails()) {
////            return response()->json([
////                'message' => 'Validation error.',
////                'errors' => $validator->errors(),
////            ], 422);
////        }
////
////        DB::beginTransaction();
////
////        try {
////            $issued = [];
////
////            foreach ($request->items as $item) {
////                $product = Product::where('company_id', $company->id)
////                    ->where('id', $item['product_id'])
////                    ->first();
////
////                if (!$product) {
////                    throw new \Exception('Product not found.');
////                }
////
////                $quantity = (float) $item['quantity'];
////
////                $backStock = $this->getOrCreateStock($product, $request->branch_id, 'back');
////                $frontStock = $this->getOrCreateStock($product, $request->branch_id, 'front');
////
////                if ((float) $backStock->available_quantity < $quantity) {
////                    throw new \Exception("Insufficient BOH stock for {$product->name}.");
////                }
////
////                $backStock->quantity_on_hand -= $quantity;
////                $backStock->available_quantity -= $quantity;
////                $backStock->save();
////
////                $frontStock->quantity_on_hand += $quantity;
////                $frontStock->available_quantity += $quantity;
////                $frontStock->save();
////
////                StockMovement::create([
////                    'company_id' => $company->id,
////                    'branch_id' => $request->branch_id,
////                    'product_id' => $product->id,
////                    'movement_type' => 'boh_issue_to_front',
////                    'stock_area' => 'front',
////                    'from_stock_area' => 'back',
////                    'to_stock_area' => 'front',
////                    'quantity_in' => $quantity,
////                    'quantity_out' => $quantity,
////                    'unit_cost' => 0,
////                    'total_cost' => 0,
////                    'notes' => $request->notes,
////                    'created_by' => auth()->id(),
////                ]);
////
////                $issued[] = [
////                    'product_id' => $product->id,
////                    'product_name' => $product->name,
////                    'sku' => $product->sku,
////                    'code' => $product->code,
////                    'quantity' => $quantity,
////                    'from_stock_area' => 'back',
////                    'to_stock_area' => 'front',
////                ];
////            }
////
////            DB::commit();
////
////            return response()->json([
////                'message' => 'Stock issued to front successfully.',
////                'data' => $issued,
////            ], 200);
////        } catch (\Exception $e) {
////            DB::rollBack();
////
////            return response()->json([
////                'message' => $e->getMessage(),
////            ], 422);
////        }
////    }
////
////    private function loadProduct(string $id)
////    {
////        $company = app('company');
////
////        return Product::where('company_id', $company->id)
////            ->where('id', $id)
////            ->with([
////                'category',
////                'supplier',
////                'parent',
////                'subCategory',
////                'children',
////                'uom',
////                'tax',
////                'prices.priceLevel',
////                'stocks',
////                'costs',
////                'barcodes',
////                'suppliers.supplier',
////                'discounts',
////                'childBreakRule.parentProduct',
////                'parentBreakRules.childProduct',
////            ])
////            ->first();
////    }
////    private function breakChildProduct(Product $child, string $branchId, int $breakTimes = 1): void
////    {
////        $rule = ProductBreakRule::where('company_id', $child->company_id)
////            ->where('child_product_id', $child->id)
////            ->where('is_active', true)
////            ->first();
////
////        if (!$rule) {
////            throw new \Exception('No break rule configured for this child product.');
////        }
////
////        $parent = Product::where('company_id', $child->company_id)
////            ->where('id', $rule->parent_product_id)
////            ->first();
////
////        if (!$parent) {
////            throw new \Exception('Parent product not found.');
////        }
////
////        $parentQtyNeeded = $rule->parent_quantity * $breakTimes;
////        $childQtyCreated = $rule->units_per_parent * $breakTimes;
////
////        $parentStock = $this->getOrCreateStock($parent, $branchId);
////        $childStock = $this->getOrCreateStock($child, $branchId);
////
////        if ($parentStock->available_quantity < $parentQtyNeeded) {
////            throw new \Exception('Insufficient parent stock.');
////        }
////
////        $parentStock->quantity_on_hand -= $parentQtyNeeded;
////        $parentStock->available_quantity -= $parentQtyNeeded;
////        $parentStock->save();
////
////        $childStock->quantity_on_hand += $childQtyCreated;
////        $childStock->available_quantity += $childQtyCreated;
////        $childStock->save();
////
////        $parentCost = ProductCost::where('company_id', $parent->company_id)
////            ->where('branch_id', $branchId)
////            ->where('product_id', $parent->id)
////            ->first();
////
////        $parentUnitCost = $parentCost?->average_cost ?? 0;
////        $totalCostMoved = $parentUnitCost * $parentQtyNeeded;
////        $childUnitCost = $childQtyCreated > 0
////            ? $totalCostMoved / $childQtyCreated
////            : 0;
////
////        $this->addAverageCost($child, $branchId, $childQtyCreated, $childUnitCost);
////
////        StockMovement::create([
////            'company_id' => $parent->company_id,
////            'branch_id' => $branchId,
////            'product_id' => $parent->id,
////            'movement_type' => 'break_parent',
////            'quantity_in' => 0,
////            'quantity_out' => $parentQtyNeeded,
////            'unit_cost' => $parentUnitCost,
////            'total_cost' => $totalCostMoved,
////            'notes' => 'Parent product broken into child units',
////            'created_by' => auth()->id(),
////        ]);
////
////        StockMovement::create([
////            'company_id' => $child->company_id,
////            'branch_id' => $branchId,
////            'product_id' => $child->id,
////            'movement_type' => 'create_child',
////            'quantity_in' => $childQtyCreated,
////            'quantity_out' => 0,
////            'unit_cost' => $childUnitCost,
////            'total_cost' => $totalCostMoved,
////            'notes' => 'Child units created from parent product',
////            'created_by' => auth()->id(),
////        ]);
////    }
////    private function addAverageCost(Product $product, string $branchId, float $quantity, float $unitCost): void
////    {
////        $cost = ProductCost::firstOrCreate(
////            [
////                'company_id' => $product->company_id,
////                'branch_id' => $branchId,
////                'product_id' => $product->id,
////            ],
////            [
////                'average_cost' => 0,
////                'last_cost' => 0,
////                'total_quantity' => 0,
////                'total_value' => 0,
////            ]
////        );
////
////        $newValue = $quantity * $unitCost;
////        $newTotalQuantity = $cost->total_quantity + $quantity;
////        $newTotalValue = $cost->total_value + $newValue;
////
////        $cost->average_cost = $newTotalQuantity > 0
////            ? $newTotalValue / $newTotalQuantity
////            : 0;
////
////        $cost->last_cost = $unitCost;
////        $cost->total_quantity = $newTotalQuantity;
////        $cost->total_value = $newTotalValue;
////        $cost->save();
////    }
////    private function syncPrices(Product $product, array $prices): void
////    {
////        foreach ($prices as $price) {
////            ProductPrice::create([
////                'company_id' => $product->company_id,
////                'product_id' => $product->id,
////                'branch_id' => $price['branch_id'] ?? null,
////                'price_level_id' => $price['price_level_id'],
////                'price' => $price['price'],
////                'currency' => $price['currency'] ?? 'USD',
////                'is_default' => $price['is_default'] ?? false,
////                'is_active' => true,
////                'starts_at' => $price['starts_at'] ?? null,
////                'ends_at' => $price['ends_at'] ?? null,
////            ]);
////        }
////    }
////
////    private function syncBarcodes(Product $product, array $barcodes): void
////    {
////        foreach ($barcodes as $barcode) {
////            ProductBarcode::create([
////                'company_id' => $product->company_id,
////                'product_id' => $product->id,
////                'barcode' => $barcode['barcode'],
////                'type' => $barcode['type'] ?? 'alternative',
////                'is_active' => true,
////            ]);
////        }
////    }
////
////    private function syncSuppliers(Product $product, array $suppliers): void
////    {
////        foreach ($suppliers as $supplier) {
////            ProductSupplier::create([
////                'company_id' => $product->company_id,
////                'product_id' => $product->id,
////                'supplier_id' => $supplier['supplier_id'],
////                'supplier_code' => $supplier['supplier_code'] ?? null,
////                'last_purchase_price' => $supplier['last_purchase_price'] ?? 0,
////                'is_default' => $supplier['is_default'] ?? false,
////            ]);
////        }
////    }
////
////    private function syncDiscounts(Product $product, array $discountIds): void
////    {
////        $syncData = [];
////
////        foreach ($discountIds as $discountId) {
////            $syncData[$discountId] = [
////                'company_id' => $product->company_id,
////            ];
////        }
////
////        $product->discounts()->sync($syncData);
////    }
////
////    private function saveBreakRule(Product $product, array $rule): void
////    {
////        ProductBreakRule::updateOrCreate(
////            [
////                'company_id' => $product->company_id,
////                'child_product_id' => $product->id,
////            ],
////            [
////                'parent_product_id' => $rule['parent_product_id'],
////                'parent_quantity' => $rule['parent_quantity'] ?? 1,
////                'units_per_parent' => $rule['units_per_parent'],
////                'is_auto_break' => $rule['is_auto_break'] ?? true,
////                'is_active' => true,
////            ]
////        );
////    }
////
////    private function getSafeSessionId(Request $request): ?string
////    {
////        try {
////            return $request->hasSession() ? $request->session()->getId() : null;
////        } catch (\Throwable $e) {
////            return null;
////        }
////    }
////
////    private function writeAuditTrail(Request $request, array $data): void
////    {
////        try {
////            $user = auth()->user();
////
////            AuditTrail::create([
////                'company_id' => $data['company_id'] ?? null,
////                'branch_id' => $data['branch_id'] ?? null,
////                'user_id' => $user?->id,
////                'user_name' => $user?->name ?? $user?->username ?? null,
////                'user_email' => $user?->email ?? null,
////                'user_role' => $user?->role?->name ?? $user?->role_id ?? null,
////
////                'action' => $data['action'],
////                'module' => $data['module'] ?? 'inventory',
////                'description' => $data['description'] ?? null,
////
////                'auditable_type' => $data['auditable_type'] ?? null,
////                'auditable_id' => $data['auditable_id'] ?? null,
////
////                'old_values' => $data['old_values'] ?? null,
////                'new_values' => $data['new_values'] ?? null,
////                'changed_fields' => $data['changed_fields'] ?? null,
////
////                'ip_address' => $request->ip(),
////                'user_agent' => $request->userAgent(),
////                'device_name' => $request->header('X-Device-Name'),
////                'terminal_name' => $request->header('X-Terminal-Name'),
////                'session_id' => $this->getSafeSessionId($request),
////
////                'status' => $data['status'] ?? 'success',
////                'error_message' => $data['error_message'] ?? null,
////                'metadata' => $data['metadata'] ?? null,
////            ]);
////        } catch (\Throwable $e) {
////            logger()->error('Failed to write stock adjustment audit trail', [
////                'error' => $e->getMessage(),
////            ]);
////        }
////    }
////
////    private function getOrCreateStock(Product $product, string $branchId, string $stockArea = 'front'): ProductStock
////    {
////        return ProductStock::firstOrCreate(
////            [
////                'company_id' => $product->company_id,
////                'branch_id' => $branchId,
////                'product_id' => $product->id,
////                'stock_area' => $stockArea,
////            ],
////            [
////                'quantity_on_hand' => 0,
////                'reserved_quantity' => 0,
////                'available_quantity' => 0,
////            ]
////        );
////    }
////
////    private function receiveProductStock(
////        Product $product,
////        string $branchId,
////        float $quantity,
////        float $unitCost,
////        ?string $currency,
////        string $movementType,
////        ?string $referenceId = null,
////        ?string $notes = null,
////        string $stockArea = 'front'
////    ): void {
////        $currencyCode = strtoupper($currency ?: 'USD');
////
////        $stock = $this->getOrCreateStock($product, $branchId, $stockArea);
////
////        $stock->quantity_on_hand = (float) $stock->quantity_on_hand + $quantity;
////        $stock->available_quantity = (float) $stock->available_quantity + $quantity;
////        $stock->save();
////
////        $cost = ProductCost::firstOrCreate(
////            [
////                'company_id' => $product->company_id,
////                'branch_id' => $branchId,
////                'product_id' => $product->id,
////            ],
////            [
////                'average_cost' => 0,
////                'last_cost' => 0,
////                'total_quantity' => 0,
////                'total_value' => 0,
////                'currency' => $currencyCode,
////            ]
////        );
////
////        $oldTotalValue = (float) $cost->total_value;
////        $oldTotalQuantity = (float) $cost->total_quantity;
////
////        $newPurchaseValue = $quantity * $unitCost;
////        $newTotalQuantity = $oldTotalQuantity + $quantity;
////        $newTotalValue = $oldTotalValue + $newPurchaseValue;
////
////        $cost->average_cost = $newTotalQuantity > 0
////            ? $newTotalValue / $newTotalQuantity
////            : 0;
////
////        $cost->last_cost = $unitCost;
////        $cost->total_quantity = $newTotalQuantity;
////        $cost->total_value = $newTotalValue;
////        $cost->currency = $currencyCode;
////        $cost->save();
////
////        StockMovement::create([
////            'company_id' => $product->company_id,
////            'branch_id' => $branchId,
////            'product_id' => $product->id,
////            'movement_type' => $movementType,
////            'stock_area' => $stockArea,
////            'quantity_in' => $quantity,
////            'quantity_out' => 0,
////            'unit_cost' => $unitCost,
////            'total_cost' => $quantity * $unitCost,
////            'currency' => $currencyCode,
////            'reference_type' => 'product',
////            'reference_id' => $referenceId ?: $product->id,
////            'notes' => $notes,
////            'created_by' => auth()->id(),
////        ]);
////    }
////
////
////    private function autoBreakChildProduct(Product $child, string $branchId, int $breakTimes = 1): void
////    {
////        $rule = ProductBreakRule::where('company_id', $child->company_id)
////            ->where('child_product_id', $child->id)
////            ->where('is_active', true)
////            ->first();
////
////        if (!$rule) {
////            throw new \Exception('No break rule configured for this child product.');
////        }
////
////        $parent = Product::find($rule->parent_product_id);
////
////        if (!$parent) {
////            throw new \Exception('Parent product not found.');
////        }
////
////        $parentQtyNeeded = $rule->parent_quantity * $breakTimes;
////        $childQtyCreated = $rule->child_quantity * $breakTimes;
////
////        $parentStock = $this->getOrCreateStock($parent, $branchId);
////        $childStock = $this->getOrCreateStock($child, $branchId);
////
////        if ($parentStock->available_quantity < $parentQtyNeeded) {
////            throw new \Exception('Insufficient parent product stock.');
////        }
////
////        $parentStock->quantity_on_hand -= $parentQtyNeeded;
////        $parentStock->available_quantity -= $parentQtyNeeded;
////        $parentStock->save();
////
////        $childStock->quantity_on_hand += $childQtyCreated;
////        $childStock->available_quantity += $childQtyCreated;
////        $childStock->save();
////
////        $parentCost = ProductCost::where('company_id', $parent->company_id)
////            ->where('branch_id', $branchId)
////            ->where('product_id', $parent->id)
////            ->first();
////
////        $unitParentCost = $parentCost?->average_cost ?? 0;
////        $totalParentCost = $unitParentCost * $parentQtyNeeded;
////        $childUnitCost = $childQtyCreated > 0
////            ? $totalParentCost / $childQtyCreated
////            : 0;
////
////        $this->updateChildAverageCost($child, $branchId, $childQtyCreated, $childUnitCost);
////
////        StockMovement::create([
////            'company_id' => $parent->company_id,
////            'branch_id' => $branchId,
////            'product_id' => $parent->id,
////            'movement_type' => 'break_parent',
////            'quantity_in' => 0,
////            'quantity_out' => $parentQtyNeeded,
////            'unit_cost' => $unitParentCost,
////            'total_cost' => $totalParentCost,
////            'notes' => 'Auto/manual product break parent deduction',
////            'created_by' => auth()->id(),
////        ]);
////
////        StockMovement::create([
////            'company_id' => $child->company_id,
////            'branch_id' => $branchId,
////            'product_id' => $child->id,
////            'movement_type' => 'create_child',
////            'quantity_in' => $childQtyCreated,
////            'quantity_out' => 0,
////            'unit_cost' => $childUnitCost,
////            'total_cost' => $totalParentCost,
////            'notes' => 'Auto/manual product break child creation',
////            'created_by' => auth()->id(),
////        ]);
////    }
////
////    private function updateChildAverageCost(
////        Product $child,
////        string $branchId,
////        float $quantity,
////        float $unitCost
////    ): void {
////        $cost = ProductCost::firstOrCreate(
////            [
////                'company_id' => $child->company_id,
////                'branch_id' => $branchId,
////                'product_id' => $child->id,
////            ],
////            [
////                'average_cost' => 0,
////                'last_cost' => 0,
////                'total_quantity' => 0,
////                'total_value' => 0,
////            ]
////        );
////
////        $newValue = $quantity * $unitCost;
////        $newTotalQuantity = $cost->total_quantity + $quantity;
////        $newTotalValue = $cost->total_value + $newValue;
////
////        $cost->average_cost = $newTotalQuantity > 0
////            ? $newTotalValue / $newTotalQuantity
////            : 0;
////
////        $cost->last_cost = $unitCost;
////        $cost->total_quantity = $newTotalQuantity;
////        $cost->total_value = $newTotalValue;
////        $cost->save();
////    }
////
////    public function generateIdentity(Request $request, ProductIdentityGenerator $generator)
////    {
////        $company = app('company');
////
////        $validator = Validator::make($request->all(), [
////            'branch_id' => 'nullable|exists:company_branches,id',
////            'type' => 'required|in:product_code,sku',
////        ]);
////
////        if ($validator->fails()) {
////            return response()->json([
////                'message' => 'Validation error.',
////                'errors' => $validator->errors(),
////            ], 422);
////        }
////
////        $branchId = $request->branch_id ?? auth()->user()->branch_id;
////
////        $value = match ($request->type) {
////            'product_code' => $generator->generateProductCode($company->id, $branchId),
////            'sku' => $generator->generateSku($company->id, $branchId),
////        };
////
////        return response()->json([
////            'message' => 'Generated successfully.',
////            'data' => [
////                'type' => $request->type,
////                'value' => $value,
////            ],
////        ]);
////    }
////
////    public function getUoms(Request $request)
////    {
////        $company = app('company');
////
////        $query = Uom::where('company_id', $company->id)
////            ->where('is_active', true)
////            ->orderBy('name');
////
////        if ($request->filled('q')) {
////            $q = $request->q;
////
////            $query->where(function ($sub) use ($q) {
////                $sub->where('name', 'like', "%{$q}%")
////                    ->orWhere('code', 'like', "%{$q}%")
////                    ->orWhere('quantity_type', 'like', "%{$q}%");
////            });
////        }
////
////        return response()->json([
////            'message' => 'UOMs fetched successfully.',
////            'data' => $query->get(),
////        ], 200);
////    }
////
////    public function getPriceLevels(Request $request)
////    {
////        $company = app('company');
////
////        $query = PriceLevel::where('company_id', $company->id)
////            ->where('is_active', true)
////            ->orderByDesc('is_default')
////            ->orderBy('name');
////
////        if ($request->filled('q')) {
////            $q = $request->q;
////
////            $query->where(function ($sub) use ($q) {
////                $sub->where('name', 'like', "%{$q}%")
////                    ->orWhere('code', 'like', "%{$q}%")
////                    ->orWhere('description', 'like', "%{$q}%");
////            });
////        }
////
////        return response()->json([
////            'message' => 'Price levels fetched successfully.',
////            'data' => $query->get(),
////        ], 200);
////    }
////
////
////    private function validateSelectedBaseCurrency(
////        string $companyId,
////        ?string $branchId,
////        ?string $currencyCode
////    ): string {
////        if (!$currencyCode) {
////            throw new \Exception('Currency is required.');
////        }
////
////        $currencyCode = strtoupper(trim($currencyCode));
////
////        $currency = Currency::where('company_id', $companyId)
////            ->where('code', $currencyCode)
////            ->where('is_active', true)
////            ->where('is_base_currency', true)
////            ->where(function ($query) use ($branchId) {
////                $query->whereNull('branch_id');
////
////                if ($branchId) {
////                    $query->orWhere('branch_id', $branchId);
////                }
////            })
////            ->first();
////
////        if (!$currency) {
////            throw new \Exception(
////                'Selected currency must exist and must be the active base currency.'
////            );
////        }
////
////        return $currencyCode;
////    }
////
//////    public function uploadImage(Request $request, string $id)
//////    {
//////        $company = app('company');
//////
//////        $result = hasPermission(auth()->user()->role_id, 'product_management', $company->id);
//////        if ($result !== true) return $result;
//////
//////        $product = Product::where('company_id', $company->id)
//////            ->where('id', $id)
//////            ->first();
//////
//////        if (!$product) {
//////            return response()->json([
//////                'message' => 'Product not found.',
//////            ], 404);
//////        }
//////
//////        $validator = Validator::make($request->all(), [
//////            'image' => 'required|image|mimes:jpg,jpeg,png,webp|max:4096',
//////        ]);
//////
//////        if ($validator->fails()) {
//////            return response()->json([
//////                'message' => 'Validation error.',
//////                'errors' => $validator->errors(),
//////            ], 422);
//////        }
//////
//////        DB::beginTransaction();
//////
//////        try {
//////            $relativeDirectory = 'menu item images/' . $company->id;
//////            $absoluteDirectory = base_path('assets/' . $relativeDirectory);
//////
//////            if (!File::exists($absoluteDirectory)) {
//////                File::makeDirectory($absoluteDirectory, 0755, true);
//////            }
//////
//////            if (!empty($product->image_path)) {
//////                $oldRelativePath = ltrim(str_replace('assets/', '', $product->image_path), '/');
//////                $oldAbsolutePath = base_path('assets/' . $oldRelativePath);
//////
//////                if (File::exists($oldAbsolutePath) && File::isFile($oldAbsolutePath)) {
//////                    File::delete($oldAbsolutePath);
//////                }
//////            }
//////
//////            $file = $request->file('image');
//////            $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
//////
//////            $safeName = Str::slug($product->name ?: 'menu-item');
//////            $filename = $safeName . '-' . $product->id . '-' . time() . '.' . $extension;
//////
//////            $file->move($absoluteDirectory, $filename);
//////
//////            $relativePath = $relativeDirectory . '/' . $filename;
//////
//////            $product->image_path = 'assets/' . $relativePath;
//////            $product->image_url = url('api/assets/' . str_replace(' ', '%20', $relativePath));
//////            $product->save();
//////
//////            DB::commit();
//////
//////            return response()->json([
//////                'message' => 'Menu item image uploaded successfully.',
//////                'data' => $this->loadProduct($product->id),
//////                'image_path' => $product->image_path,
//////                'image_url' => $product->image_url,
//////            ], 200);
//////        } catch (\Exception $e) {
//////            DB::rollBack();
//////
//////            return response()->json([
//////                'message' => 'Something went wrong while uploading menu item image.',
//////                'error' => $e->getMessage(),
//////            ], 500);
//////        }
//////    }
//////
//////    public function deleteImage(string $id)
//////    {
//////        $company = app('company');
//////
//////        $result = hasPermission(auth()->user()->role_id, 'product_management', $company->id);
//////        if ($result !== true) return $result;
//////
//////        $product = Product::where('company_id', $company->id)
//////            ->where('id', $id)
//////            ->first();
//////
//////        if (!$product) {
//////            return response()->json([
//////                'message' => 'Product not found.',
//////            ], 404);
//////        }
//////
//////        DB::beginTransaction();
//////
//////        try {
//////            if (!empty($product->image_path)) {
//////                $oldRelativePath = ltrim(str_replace('assets/', '', $product->image_path), '/');
//////                $oldAbsolutePath = base_path('assets/' . $oldRelativePath);
//////
//////                if (File::exists($oldAbsolutePath) && File::isFile($oldAbsolutePath)) {
//////                    File::delete($oldAbsolutePath);
//////                }
//////            }
//////
//////            $product->image_path = null;
//////            $product->image_url = null;
//////            $product->save();
//////
//////            DB::commit();
//////
//////            return response()->json([
//////                'message' => 'Menu item image removed successfully.',
//////                'data' => $this->loadProduct($product->id),
//////            ], 200);
//////        } catch (\Exception $e) {
//////            DB::rollBack();
//////
//////            return response()->json([
//////                'message' => 'Something went wrong while removing menu item image.',
//////                'error' => $e->getMessage(),
//////            ], 500);
//////        }
//////    }
////
////
////
////    public function uploadImage(Request $request, string $id)
////    {
////        $company = app('company');
////
////        $result = hasPermission(auth()->user()->role_id, 'product_management', $company->id);
////        if ($result !== true) {
////            return $result;
////        }
////
////        $product = Product::where('company_id', $company->id)
////            ->where('id', $id)
////            ->first();
////
////        if (!$product) {
////            return response()->json([
////                'message' => 'Product not found.',
////            ], 404);
////        }
////
////        $validator = Validator::make($request->all(), [
////            'image' => 'required|image|mimes:jpg,jpeg,png,webp|max:4096',
////        ]);
////
////        if ($validator->fails()) {
////            return response()->json([
////                'message' => 'Validation error.',
////                'errors' => $validator->errors(),
////            ], 422);
////        }
////
////        DB::beginTransaction();
////
////        try {
////            $file = $request->file('image');
////
////            if (!$file || !$file->isValid()) {
////                return response()->json([
////                    'message' => 'Invalid uploaded image.',
////                    'error' => $file ? $file->getErrorMessage() : 'No file received.',
////                ], 422);
////            }
////
////            /*
////             |--------------------------------------------------------------------------
////             | Public assets folder
////             |--------------------------------------------------------------------------
////             | Localhost/XAMPP:
////             | base_path() = C:/xampp/htdocs/salexaapi/api
////             | assets root = C:/xampp/htdocs/salexaapi/assets
////             |
////             | Nginx:
////             | base_path() = C:/salexaapi/api
////             | assets root = C:/salexaapi/assets
////             |--------------------------------------------------------------------------
////             */
////            $relativeDirectory = 'menu-item-images/' . $company->id;
////            $absoluteDirectory = $this->publicAssetsPath($relativeDirectory);
////
////            if (!File::exists($absoluteDirectory)) {
////                File::makeDirectory($absoluteDirectory, 0775, true);
////            }
////
////            /*
////             * Delete old image.
////             */
////            if (!empty($product->image_path)) {
////                $this->deleteStoredAsset($product->image_path);
////            }
////
////            $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
////
////            $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
////            if (!in_array($extension, $allowedExtensions, true)) {
////                $extension = 'jpg';
////            }
////
////            $safeName = Str::slug($product->name ?: 'menu-item');
////            $filename = $safeName . '-' . $product->id . '-' . time() . '.' . $extension;
////
////            $file->move($absoluteDirectory, $filename);
////
////            $fullPath = $absoluteDirectory . DIRECTORY_SEPARATOR . $filename;
////
////            @chmod($absoluteDirectory, 0775);
////            @chmod($fullPath, 0664);
////
////            if (!File::exists($fullPath) || !File::isFile($fullPath)) {
////                throw new \Exception('Image file was not saved correctly.');
////            }
////
////            if (@getimagesize($fullPath) === false) {
////                File::delete($fullPath);
////
////                return response()->json([
////                    'message' => 'Uploaded file was saved but is not a valid image.',
////                ], 422);
////            }
////
////            $relativePath = $relativeDirectory . '/' . $filename;
////
////            $product->image_path = 'assets/' . $relativePath;
////            $product->image_url = $this->publicAssetUrl($relativePath);
////
////            $product->save();
////
////            DB::commit();
////
////            return response()->json([
////                'message' => 'Menu item image uploaded successfully.',
////                'data' => $this->loadProduct($product->id),
////                'image_path' => $product->image_path,
////                'image_url' => $product->image_url,
////            ], 200);
////        } catch (\Exception $e) {
////            DB::rollBack();
////
////            return response()->json([
////                'message' => 'Something went wrong while uploading menu item image.',
////                'error' => $e->getMessage(),
////            ], 500);
////        }
////    }
////
////    public function deleteImage(string $id)
////    {
////        $company = app('company');
////
////        $result = hasPermission(auth()->user()->role_id, 'product_management', $company->id);
////        if ($result !== true) {
////            return $result;
////        }
////
////        $product = Product::where('company_id', $company->id)
////            ->where('id', $id)
////            ->first();
////
////        if (!$product) {
////            return response()->json([
////                'message' => 'Product not found.',
////            ], 404);
////        }
////
////        DB::beginTransaction();
////
////        try {
////            if (!empty($product->image_path)) {
////                $this->deleteStoredAsset($product->image_path);
////            }
////
////            $product->image_path = null;
////            $product->image_url = null;
////            $product->save();
////
////            DB::commit();
////
////            return response()->json([
////                'message' => 'Menu item image removed successfully.',
////                'data' => $this->loadProduct($product->id),
////            ], 200);
////        } catch (\Exception $e) {
////            DB::rollBack();
////
////            return response()->json([
////                'message' => 'Something went wrong while removing menu item image.',
////                'error' => $e->getMessage(),
////            ], 500);
////        }
////    }
////
////    /**
////     * Encode each part of a URL path safely.
////     *
////     * Example:
////     * menu-item-images/company id/file name.png
////     * becomes:
////     * menu-item-images/company%20id/file%20name.png
////     */
////    private function publicAssetsRoot(): string
////    {
////        /*
////         * Laravel app folder:
////         * C:/xampp/htdocs/salexaapi/api
////         *
////         * Public project folder:
////         * C:/xampp/htdocs/salexaapi
////         *
////         * So assets must be saved one level above /api.
////         */
////        return dirname(base_path()) . DIRECTORY_SEPARATOR . 'assets';
////    }
////
////    private function publicAssetsPath(string $relativeDirectory): string
////    {
////        $relativeDirectory = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativeDirectory);
////
////        return $this->publicAssetsRoot() . DIRECTORY_SEPARATOR . $relativeDirectory;
////    }
////
////    private function publicAssetUrl(string $relativePath): string
////    {
////        $relativePath = $this->encodeUrlPath($relativePath);
////
////        $host = rtrim(request()->getSchemeAndHttpHost(), '/');
////
////        /*
////         * Localhost/XAMPP API base URL is usually:
////         * http://localhost/salexaapi/api
////         *
////         * But public assets are:
////         * http://localhost/salexaapi/assets
////         *
////         * So remove trailing /api from the base path.
////         */
////        $basePath = rtrim(request()->getBaseUrl(), '/');
////
////        if (Str::endsWith($basePath, '/api')) {
////            $basePath = Str::beforeLast($basePath, '/api');
////        }
////
////        return $host . $basePath . '/assets/' . $relativePath;
////    }
////
////    private function deleteStoredAsset(?string $path): void
////    {
////        if (empty($path)) {
////            return;
////        }
////
////        $relativePath = ltrim(str_replace('\\', '/', $path), '/');
////
////        if (Str::startsWith($relativePath, 'assets/')) {
////            $relativePath = Str::after($relativePath, 'assets/');
////        }
////
////        $absolutePath = $this->publicAssetsPath(dirname($relativePath))
////            . DIRECTORY_SEPARATOR
////            . basename($relativePath);
////
////        if (File::exists($absolutePath) && File::isFile($absolutePath)) {
////            File::delete($absolutePath);
////        }
////    }
////
////    private function encodeUrlPath(string $path): string
////    {
////        $path = str_replace('\\', '/', $path);
////
////        return collect(explode('/', $path))
////            ->map(fn ($part) => rawurlencode($part))
////            ->implode('/');
////    }
////}
////
//
//
//namespace Modules\Product\Http\Controllers;
//
//use App\Http\Controllers\Controller;
//use App\Services\ProductIdentityGenerator;
//use Illuminate\Http\Request;
//use Illuminate\Support\Facades\DB;
//use Illuminate\Support\Facades\Validator;
//use Illuminate\Support\Facades\Schema;
//use Illuminate\Support\Str;
//use Illuminate\Validation\Rule;
//use Modules\Company\Models\AuditTrail;
//use Modules\Currency\Models\Currency;
//use App\Services\CompanySettingService;
//use Modules\Product\Models\PriceLevel;
//use Modules\Product\Models\Product;
//use Modules\Product\Models\ProductBarcode;
//use Modules\Product\Models\ProductBreakRule;
//use Modules\Product\Models\ProductCost;
//use Modules\Product\Models\ProductPrice;
//use Modules\Product\Models\ProductStock;
//use Modules\Product\Models\ProductSupplier;
//use Modules\Product\Models\StockMovement;
//use Modules\Product\Models\Uom;
//use Illuminate\Support\Facades\File;
//use function App\Helpers\hasPermission;
//
//class ProductController extends Controller
//{
//
//    public function index(Request $request)
//    {
//        $company = app('company');
//        $branchId = auth()->user()->branch_id;
//
//        $query = Product::where('company_id', $company->id)
//            ->with([
//                'category',
//                'supplier',
//                'parent',
//                'children',
//                'subCategory',
//                'uom',
//
//                // Optional legacy single tax from products.tax_id
//                'tax',
//
//                // New real many-tax relationships
//                'taxes.tax',
//                'productTaxes.tax',
//
//                'prices' => function ($query) use ($branchId) {
//                    $query->where(function ($sub) use ($branchId) {
//                        $sub->where('branch_id', $branchId)
//                            ->orWhereNull('branch_id');
//                    })
//                        ->where('is_active', true)
//                        ->with('priceLevel')
//                        ->orderByRaw(
//                            'CASE WHEN branch_id = ? THEN 0 ELSE 1 END',
//                            [$branchId]
//                        )
//                        ->orderByDesc('is_default');
//                },
//
//                'stocks' => function ($query) use ($branchId) {
//                    $query->where('branch_id', $branchId);
//                },
//
//                'costs' => function ($query) use ($branchId) {
//                    $query->where('branch_id', $branchId);
//                },
//
//                'barcodes',
//                'discounts',
//                'childBreakRule.parentProduct',
//            ])
//            ->latest();
//
//        if ($request->filled('q')) {
//            $q = $request->q;
//
//            $query->where(function ($sub) use ($q) {
//                $sub->where('name', 'like', "%{$q}%")
//                    ->orWhere('code', 'like', "%{$q}%")
//                    ->orWhere('sku', 'like', "%{$q}%")
//                    ->orWhere('barcode', 'like', "%{$q}%");
//            });
//        }
//
//        if ($request->filled('category_id')) {
//            $query->where('category_id', $request->category_id);
//        }
//
//        if ($request->filled('supplier_id')) {
//            $query->where('supplier_id', $request->supplier_id);
//        }
//        if ($request->filled('sub_category_id')) {
//            $query->where('sub_category_id', $request->sub_category_id);
//        }
//
//        if ($request->filled('product_type') || $request->filled('product_types')) {
//            $productTypes = $request->input(
//                'product_types',
//                $request->input('product_type')
//            );
//
//            if (is_string($productTypes)) {
//                $productTypes = explode(',', $productTypes);
//            }
//
//            $productTypes = collect($productTypes)
//                ->map(fn($type) => trim($type))
//                ->filter()
//                ->values()
//                ->all();
//
//            if (count($productTypes) > 1) {
//                $query->whereIn('product_type', $productTypes);
//            } elseif (count($productTypes) === 1) {
//                $query->where('product_type', $productTypes[0]);
//            }
//        }
//
//        if ($request->filled('is_active')) {
//            $query->where(
//                'is_active',
//                filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)
//            );
//        }
//
//        return response()->json([
//            'message' => 'Products fetched successfully.',
//            'data' => $query->paginate($request->per_page ?? 15),
//        ]);
//    }
//
//    public function store(Request $request, ProductIdentityGenerator $identityGenerator)
//    {
//        $company = app('company');
//
//        $result = hasPermission(auth()->user()->role_id, 'product_management', $company->id);
//        if ($result !== true) return $result;
//
//        $validator = Validator::make($request->all(), [
//            'category_id' => 'nullable|exists:product_categories,id',
//            'supplier_id' => 'nullable|exists:suppliers,id',
//            'parent_product_id' => 'nullable|exists:products,id',
//            'base_uom_id' => 'required|exists:uoms,id',
//            'sub_category_id' => [
//                'nullable',
//                Rule::exists('product_sub_categories', 'id')->where('company_id', $company->id),
//            ],
//            'tax_id' => 'nullable|exists:taxes,id',
//
//            'name' => [
//                'required',
//                'string',
//                'max:255',
//                Rule::unique('products', 'name')->where('company_id', $company->id),
//            ],
//            'code' => [
//                'nullable',
//                'string',
//                'max:100',
//                Rule::unique('products', 'code')->where('company_id', $company->id),
//            ],
//            'sku' => [
//                'nullable',
//                'string',
//                'max:100',
//                Rule::unique('products', 'sku')->where('company_id', $company->id),
//            ],
//            'barcode' => [
//                'nullable',
//                'string',
//                'max:100',
//                Rule::unique('products', 'barcode')->where('company_id', $company->id),
//            ],
//
//            'description' => 'nullable|string',
//            'product_type' => 'required|in:simple,parent,child,service,raw_material,finished_good,semi_finished,packaging,consumable',
//
//            'stock_tracking_enabled' => 'nullable|boolean',
//            'allow_negative_stock' => 'nullable|boolean',
//            'allow_discount' => 'nullable|boolean',
//            'taxable' => 'nullable|boolean',
//            'is_active' => 'nullable|boolean',
//
//            'reorder_level' => 'nullable|numeric|min:0',
//            'minimum_stock' => 'nullable|numeric|min:0',
//            'maximum_stock' => 'nullable|numeric|min:0',
//
//            'prices' => 'nullable|array',
//            'prices.*.branch_id' => 'nullable|exists:company_branches,id',
//            'prices.*.price_level_id' => 'required|exists:price_levels,id',
//            'prices.*.price' => 'required|numeric|min:0',
//            'prices.*.currency' => 'nullable|string|max:10',
//            'prices.*.is_default' => 'nullable|boolean',
//
//            'barcodes' => 'nullable|array',
//            'barcodes.*.barcode' => 'required|string|max:100',
//            'barcodes.*.type' => 'nullable|in:primary,alternative,supplier',
//
//            'suppliers' => 'nullable|array',
//            'suppliers.*.supplier_id' => 'required|exists:suppliers,id',
//            'suppliers.*.supplier_code' => 'nullable|string|max:100',
//            'suppliers.*.last_purchase_price' => 'nullable|numeric|min:0',
//            'suppliers.*.is_default' => 'nullable|boolean',
//
//            'discount_ids' => 'nullable|array',
//            'discount_ids.*' => 'exists:discounts,id',
//
//            'break_rule' => 'nullable|array',
//            'break_rule.parent_product_id' => 'required_with:break_rule|exists:products,id',
//            'break_rule.parent_quantity' => 'required_with:break_rule|numeric|min:0.0001',
//            'break_rule.units_per_parent' => 'required_with:break_rule|numeric|min:0.0001',
//            'break_rule.is_auto_break' => 'nullable|boolean',
//
//            'opening_stocks' => 'nullable|array',
//            'opening_stocks.*.branch_id' => 'required|exists:company_branches,id',
//            'opening_stocks.*.quantity' => 'required|numeric|min:0',
//            'opening_stocks.*.unit_cost' => 'nullable|numeric|min:0',
//            'opening_stocks.*.currency' => 'required',
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
//            $productData = $identityGenerator->generateAllForProductCreate(
//                $company->id,
//                auth()->user()->branch_id,
//                [
//                    'company_id' => $company->id,
//                    'category_id' => $request->category_id,
//                    'supplier_id' => $request->supplier_id,
//                    'sub_category_id' => $request->sub_category_id,
//                    'parent_product_id' => $request->parent_product_id,
//                    'base_uom_id' => $request->base_uom_id,
//                    'tax_id' => $request->tax_id,
//
//                    'name' => $request->name,
//                    'code' => $request->code,
//                    'sku' => $request->sku,
//                    'barcode' => $request->barcode,
//                    'description' => $request->description,
//                    'product_type' => $request->product_type,
//
//                    'stock_tracking_enabled' => $request->stock_tracking_enabled ?? true,
//                    'allow_negative_stock' => $request->allow_negative_stock ?? false,
//                    'allow_discount' => $request->allow_discount ?? true,
//                    'taxable' => $request->taxable ?? false,
//                    'is_active' => $request->is_active ?? true,
//
//                    'reorder_level' => $request->reorder_level ?? 0,
//                    'minimum_stock' => $request->minimum_stock ?? 0,
//                    'maximum_stock' => $request->maximum_stock ?? 0,
//                ]
//            );
//
//            $identityGenerator->assertProductIdentityIsComplete($productData);
//
//            $product = Product::create($productData);
//
//            $this->syncPrices($product, $request->prices ?? []);
//            $this->syncBarcodes($product, $request->barcodes ?? []);
//            $this->syncSuppliers($product, $request->suppliers ?? []);
//            $this->syncDiscounts($product, $request->discount_ids ?? []);
//
//            if ($request->filled('break_rule')) {
//                $this->saveBreakRule($product, $request->break_rule);
//            }
//
//            if ($request->filled('opening_stocks')) {
//                foreach ($request->opening_stocks as $stockData) {
//                    $this->receiveProductStock(
//                        $product,
//                        $stockData['branch_id'],
//                        $stockData['quantity'],
//                        $stockData['unit_cost'] ?? 0,
//                        $stockData['currency'] ?? null,
//                        'opening',
//                        null,
//                        'Opening stock'
//                    );
//                }
//            }
//
//            DB::commit();
//
//            return response()->json([
//                'message' => 'Product created successfully.',
//                'data' => $this->loadProduct($product->id),
//            ], 201);
//        } catch (\Exception $e) {
//            DB::rollBack();
//
//            return response()->json([
//                'message' => 'Something went wrong while creating product.',
//                'error' => $e->getMessage(),
//            ], 500);
//        }
//    }
//
//    public function show(string $id)
//    {
//        $product = $this->loadProduct($id);
//
//        if (!$product) {
//            return response()->json(['message' => 'Product not found.'], 404);
//        }
//
//        return response()->json([
//            'message' => 'Product fetched successfully.',
//            'data' => $product,
//        ]);
//    }
//
//    public function update(Request $request, string $id)
//    {
//        $company = app('company');
//        $result = hasPermission(auth()->user()->role_id, 'product_management', $company->id);
//        if ($result !== true) return $result;
//
//        $product = Product::where('company_id', $company->id)
//            ->where('id', $id)
//            ->first();
//
//        if (!$product) {
//            return response()->json(['message' => 'Product not found.'], 404);
//        }
//
//        $validator = Validator::make($request->all(), [
//            'category_id' => 'nullable|exists:product_categories,id',
//            'supplier_id' => 'nullable|exists:suppliers,id',
//            'parent_product_id' => 'nullable|exists:products,id',
//            'base_uom_id' => 'required|exists:uoms,id',
//            'tax_id' => 'nullable|exists:taxes,id',
//            'sub_category_id' => [
//                'nullable',
//                Rule::exists('product_sub_categories', 'id')->where('company_id', $company->id),
//            ],
//
//            'name' => [
//                'required',
//                'string',
//                'max:255',
//                Rule::unique('products', 'name')->where('company_id', $company->id)->ignore($product->id),
//            ],
//            'code' => [
//                'required',
//                'string',
//                'max:100',
//                Rule::unique('products', 'code')->where('company_id', $company->id)->ignore($product->id),
//            ],
//            'sku' => [
//                'required',
//                'string',
//                'max:100',
//                Rule::unique('products', 'sku')->where('company_id', $company->id)->ignore($product->id),
//            ],
//            'barcode' => [
//                'nullable',
//                'string',
//                'max:100',
//                Rule::unique('products', 'barcode')->where('company_id', $company->id)->ignore($product->id),
//            ],
//
//            'description' => 'nullable|string',
//            'product_type' => 'required|in:simple,parent,child,service,raw_material,finished_good,semi_finished,packaging,consumable',
//
//
//            'stock_tracking_enabled' => 'nullable|boolean',
//            'allow_negative_stock' => 'nullable|boolean',
//            'allow_discount' => 'nullable|boolean',
//            'taxable' => 'nullable|boolean',
//            'is_active' => 'nullable|boolean',
//
//            'reorder_level' => 'nullable|numeric|min:0',
//            'minimum_stock' => 'nullable|numeric|min:0',
//            'maximum_stock' => 'nullable|numeric|min:0',
//        ]);
//
//        if ($validator->fails()) {
//            return response()->json([
//                'message' => 'Validation error.',
//                'errors' => $validator->errors(),
//            ], 422);
//        }
//
//        $product->update($request->only([
//            'category_id',
//            'supplier_id',
//            'sub_category_id',
//            'parent_product_id',
//            'base_uom_id',
//            'tax_id',
//            'name',
//            'code',
//            'sku',
//            'barcode',
//            'description',
//            'product_type',
//            'stock_tracking_enabled',
//            'allow_negative_stock',
//            'allow_discount',
//            'taxable',
//            'is_active',
//            'reorder_level',
//            'minimum_stock',
//            'maximum_stock',
//        ]));
//
//        return response()->json([
//            'message' => 'Product updated successfully.',
//            'data' => $this->loadProduct($product->id),
//        ]);
//    }
//
//    public function destroy(string $id)
//    {
//        $company = app('company');
//        $result = hasPermission(auth()->user()->role_id, 'product_management', $company->id);
//        if ($result !== true) return $result;
//
//        $product = Product::where('company_id', $company->id)
//            ->where('id', $id)
//            ->first();
//
//        if (!$product) {
//            return response()->json(['message' => 'Product not found.'], 404);
//        }
//
//        $product->delete();
//
//        return response()->json([
//            'message' => 'Product deleted successfully.',
//        ]);
//    }
//
////    public function addPrice(Request $request, string $id)
////    {
////        $company = app('company');
////        $user = auth()->user();
////        $result = hasPermission(auth()->user()->role_id, 'add_price', $company->id);
////        if ($result !== true) return $result;
////
////        $product = Product::where('company_id', $company->id)
////            ->where('id', $id)
////            ->first();
////
////        if (!$product) {
////            return response()->json([
////                'message' => 'Product not found.',
////            ], 404);
////        }
////
////        $validator = Validator::make($request->all(), [
////            'price_level_id' => 'required|exists:price_levels,id',
////            'branch_id' => 'nullable|exists:company_branches,id',
////            'price' => 'required|numeric|min:0',
////            'currency' => 'required|string|size:3',
////            'is_default' => 'nullable|boolean',
////            'is_active' => 'nullable|boolean',
////            'starts_at' => 'nullable|date',
////            'ends_at' => 'nullable|date|after_or_equal:starts_at',
////        ]);
////
////        if ($validator->fails()) {
////            return response()->json([
////                'message' => 'Validation error.',
////                'errors' => $validator->errors(),
////            ], 422);
////        }
////
////        DB::beginTransaction();
////
////        try {
////            $branchId = $request->branch_id ?: ($user->branch_id ?? null);
////
////            $currencyCode = $this->validateSelectedBaseCurrency(
////                $company->id,
////                $branchId,
////                $request->currency
////            );
////
////            if ($request->boolean('is_default')) {
////                ProductPrice::where('company_id', $company->id)
////                    ->where('product_id', $product->id)
////                    ->where('price_level_id', $request->price_level_id)
////                    ->where('branch_id', $branchId)
////                    ->update([
////                        'is_default' => false,
////                    ]);
////            }
////
////            $price = ProductPrice::create([
////                'company_id' => $company->id,
////                'branch_id' => $branchId,
////                'product_id' => $product->id,
////                'price_level_id' => $request->price_level_id,
////                'price' => (float) $request->price,
////                'currency' => $currencyCode,
////                'is_default' => $request->boolean('is_default', false),
////                'is_active' => $request->boolean('is_active', true),
////                'starts_at' => $request->starts_at,
////                'ends_at' => $request->ends_at,
////            ]);
////
////            DB::commit();
////
////            return response()->json([
////                'message' => 'Product price added successfully.',
////                'currency' => $currencyCode,
////                'data' => $price,
////            ], 201);
////        } catch (\Exception $e) {
////            DB::rollBack();
////
////            return response()->json([
////                'message' => 'Something went wrong while adding product price.',
////                'error' => $e->getMessage(),
////            ], 500);
////        }
////    }
//    public function addPrice(Request $request, string $id)
//    {
//        $company = app('company');
//        $user = auth()->user();
//
//        $result = hasPermission($user->role_id, 'add_price', $company->id);
//        if ($result !== true) return $result;
//
//        $product = Product::where('company_id', $company->id)
//            ->where('id', $id)
//            ->first();
//
//        if (!$product) {
//            return response()->json([
//                'message' => 'Product not found.',
//            ], 404);
//        }
//
//        $validator = Validator::make($request->all(), [
//            'price_level_id' => 'required|exists:price_levels,id',
//            'branch_id' => 'nullable|exists:company_branches,id',
//            'price' => 'required|numeric|min:0',
//            'currency' => 'required|string|size:3',
//            'is_default' => 'nullable|boolean',
//            'is_active' => 'nullable|boolean',
//            'starts_at' => 'nullable|date',
//            'ends_at' => 'nullable|date|after_or_equal:starts_at',
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
//            $branchId = $request->branch_id ?: ($user->branch_id ?? null);
//
//            $currencyCode = $this->validateSelectedBaseCurrency(
//                $company->id,
//                $branchId,
//                $request->currency
//            );
//
//            if ($request->boolean('is_default')) {
//                $defaultQuery = ProductPrice::withoutGlobalScopes()
//                    ->where('company_id', $company->id)
//                    ->where('product_id', $product->id)
//                    ->where('price_level_id', $request->price_level_id);
//
//                if ($branchId) {
//                    $defaultQuery->where('branch_id', $branchId);
//                } else {
//                    $defaultQuery->whereNull('branch_id');
//                }
//
//                $defaultQuery->update([
//                    'is_default' => false,
//                ]);
//            }
//
//            $priceQuery = ProductPrice::withoutGlobalScopes()
//                ->where('company_id', $company->id)
//                ->where('product_id', $product->id)
//                ->where('price_level_id', $request->price_level_id);
//
//            if ($branchId) {
//                $priceQuery->where('branch_id', $branchId);
//            } else {
//                $priceQuery->whereNull('branch_id');
//            }
//
//            $price = $priceQuery->first();
//
//            $payload = [
//                'company_id' => $company->id,
//                'branch_id' => $branchId,
//                'product_id' => $product->id,
//                'price_level_id' => $request->price_level_id,
//                'price' => (float)$request->price,
//                'currency' => $currencyCode,
//                'is_default' => $request->boolean('is_default', false),
//                'is_active' => $request->boolean('is_active', true),
//                'starts_at' => $request->starts_at,
//                'ends_at' => $request->ends_at,
//            ];
//
//            if ($price) {
//                $price->fill($payload);
//
//                if (array_key_exists('deleted_at', $price->getAttributes())) {
//                    $price->deleted_at = null;
//                }
//
//                $price->save();
//                $statusCode = 200;
//                $message = 'Product price updated successfully.';
//            } else {
//                $price = ProductPrice::create($payload);
//                $statusCode = 201;
//                $message = 'Product price added successfully.';
//            }
//
//            DB::commit();
//
//            return response()->json([
//                'message' => $message,
//                'currency' => $currencyCode,
//                'data' => $price,
//            ], $statusCode);
//        } catch (\Exception $e) {
//            DB::rollBack();
//
//            return response()->json([
//                'message' => 'Something went wrong while saving product price.',
//                'error' => $e->getMessage(),
//            ], 500);
//        }
//    }
//
//    public function deletePrice(string $productId, string $priceId)
//    {
//        $company = app('company');
//        $result = hasPermission(auth()->user()->role_id, 'delete_price', $company->id);
//        if ($result !== true) return $result;
//
//        $price = ProductPrice::where('company_id', $company->id)
//            ->where('product_id', $productId)
//            ->where('id', $priceId)
//            ->first();
//
//        if (!$price) {
//            return response()->json(['message' => 'Product price not found.'], 404);
//        }
//
//        $price->delete();
//
//        return response()->json([
//            'message' => 'Product price deleted successfully.',
//        ]);
//    }
//
//
//    public function receiveStock(Request $request, string $id, CompanySettingService $settings)
//    {
//        $company = app('company');
//        $user = auth()->user();
//
//        $result = hasPermission($user->role_id, 'receive_stock', $company->id);
//        if ($result !== true) return $result;
//
//        $product = Product::where('company_id', $company->id)
//            ->where('id', $id)
//            ->first();
//
//        if (!$product) {
//            return response()->json([
//                'message' => 'Product not found.',
//            ], 404);
//        }
//
//        $validator = Validator::make($request->all(), [
//            'branch_id' => 'required|exists:company_branches,id',
//            'quantity' => 'required|numeric|min:0.0001',
//            'unit_cost' => 'required|numeric|min:0',
//            'currency' => 'required|string|size:3',
//            'notes' => 'nullable|string',
//        ]);
//
//        if ($validator->fails()) {
//            return response()->json([
//                'message' => 'Validation error.',
//                'errors' => $validator->errors(),
//            ], 422);
//        }
//
//        $branchId = $request->branch_id ?: ($user->branch_id ?? null);
//
//        $stockArea = (
//            $settings->isFastFoodMode($company->id) &&
//            $settings->isBohEnabled($company->id) &&
//            $settings->get($company->id, 'receive_stock_to_boh_first', true)
//        ) ? 'back' : 'front';
//
//        DB::beginTransaction();
//
//        try {
//            $currencyCode = $this->validateSelectedBaseCurrency(
//                $company->id,
//                $branchId,
//                $request->currency
//            );
//
//            $quantity = (float)$request->quantity;
//            $unitCost = (float)$request->unit_cost;
//            $totalCost = $quantity * $unitCost;
//
//            $stock = $this->getOrCreateStock($product, $branchId, $stockArea);
//
//            $stock->quantity_on_hand += $quantity;
//            $stock->available_quantity += $quantity;
//            $stock->save();
//
//            $cost = ProductCost::firstOrCreate(
//                [
//                    'company_id' => $product->company_id,
//                    'branch_id' => $branchId,
//                    'product_id' => $product->id,
//                ],
//                [
//                    'average_cost' => 0,
//                    'last_cost' => 0,
//                    'total_quantity' => 0,
//                    'total_value' => 0,
//                    'currency' => $currencyCode,
//                ]
//            );
//
//            $previousQuantity = (float)$cost->total_quantity;
//            $previousValue = (float)$cost->total_value;
//
//            $newTotalQuantity = $previousQuantity + $quantity;
//            $newTotalValue = $previousValue + $totalCost;
//
//            $cost->last_cost = $unitCost;
//            $cost->total_quantity = $newTotalQuantity;
//            $cost->total_value = $newTotalValue;
//            $cost->average_cost = $newTotalQuantity > 0
//                ? $newTotalValue / $newTotalQuantity
//                : 0;
//            $cost->currency = $currencyCode;
//            $cost->save();
//
//            StockMovement::create([
//                'company_id' => $product->company_id,
//                'branch_id' => $branchId,
//                'product_id' => $product->id,
//                'movement_type' => $stockArea === 'back'
//                    ? 'boh_purchase_receive'
//                    : 'purchase',
//                'stock_area' => $stockArea,
//                'quantity_in' => $quantity,
//                'quantity_out' => 0,
//                'unit_cost' => $unitCost,
//                'total_cost' => $totalCost,
//                'currency' => $currencyCode,
//                'notes' => $request->notes,
//                'created_by' => auth()->id(),
//            ]);
//
//            DB::commit();
//
//            return response()->json([
//                'message' => $stockArea === 'back'
//                    ? 'Stock received into BOH/back stock successfully.'
//                    : 'Stock received successfully.',
//                'currency' => $currencyCode,
//                'stock_area' => $stockArea,
//                'data' => $this->loadProduct($product->id),
//            ], 200);
//        } catch (\Exception $e) {
//            DB::rollBack();
//
//            return response()->json([
//                'message' => 'Something went wrong while receiving stock.',
//                'error' => $e->getMessage(),
//            ], 500);
//        }
//    }
//
//    public function adjustStock(Request $request, string $id)
//    {
//        $company = app('company');
//
//        $result = hasPermission(auth()->user()->role_id, 'adjust_stock', $company->id);
//        if ($result !== true) return $result;
//
//        $product = Product::where('company_id', $company->id)
//            ->where('id', $id)
//            ->first();
//
//        if (!$product) {
//            return response()->json(['message' => 'Product not found.'], 404);
//        }
//
//        $validator = Validator::make($request->all(), [
//            'branch_id' => 'required|exists:company_branches,id',
//            'adjustment_type' => 'required|in:increase,decrease',
//            'stock_area' => 'nullable|in:front,back',
//            'quantity' => 'required|numeric|min:0.0001',
//            'notes' => 'nullable|string',
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
//            $stockArea = $request->input('stock_area', 'front');
//            $stock = $this->getOrCreateStock($product, $request->branch_id, $stockArea);
//
//            if ($request->adjustment_type === 'increase') {
//                $stock->quantity_on_hand += $request->quantity;
//                $stock->available_quantity += $request->quantity;
//
//                $quantityIn = $request->quantity;
//                $quantityOut = 0;
//            } else {
//                if (!$product->allow_negative_stock && $stock->available_quantity < $request->quantity) {
//                    DB::rollBack();
//
//                    return response()->json([
//                        'message' => 'Insufficient stock.',
//                    ], 422);
//                }
//
//                $stock->quantity_on_hand -= $request->quantity;
//                $stock->available_quantity -= $request->quantity;
//
//                $quantityIn = 0;
//                $quantityOut = $request->quantity;
//            }
//
//            $stock->save();
//
//            StockMovement::create([
//                'company_id' => $product->company_id,
//                'branch_id' => $request->branch_id,
//                'product_id' => $product->id,
//                'movement_type' => 'adjustment',
//                'stock_area' => $stockArea,
//                'quantity_in' => $quantityIn,
//                'quantity_out' => $quantityOut,
//                'unit_cost' => 0,
//                'total_cost' => 0,
//                'notes' => $request->notes,
//                'created_by' => auth()->id(),
//            ]);
//
//            DB::commit();
//
//            return response()->json([
//                'message' => 'Stock adjusted successfully.',
//                'data' => $this->loadProduct($product->id),
//            ]);
//        } catch (\Exception $e) {
//            DB::rollBack();
//
//            return response()->json([
//                'message' => 'Something went wrong while adjusting stock.',
//                'error' => $e->getMessage(),
//            ], 500);
//        }
//    }
//
//    public function adjustStockBulk(Request $request)
//    {
//        $company = app('company');
//        $user = auth()->user();
//        $result = hasPermission(auth()->user()->role_id, 'adjust_stock', $company->id);
//        if ($result !== true) return $result;
//
//        $validator = Validator::make($request->all(), [
//            'branch_id' => 'required|exists:company_branches,id',
//            'stock_area' => 'nullable|in:front,back',
//            'items' => 'required|array|min:1',
//            'items.*.product_id' => 'required|exists:products,id',
//            'items.*.quantity' => 'required|numeric|min:0',
//            'items.*.notes' => 'nullable|string',
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
//            $adjustedProducts = [];
//            $skippedProducts = [];
//            $movementIds = [];
//            $stockArea = $request->input('stock_area', 'front');
//
//            foreach ($request->items as $item) {
//                $product = Product::where('company_id', $company->id)
//                    ->where('id', $item['product_id'])
//                    ->first();
//
//                if (!$product) {
//                    $skippedProducts[] = [
//                        'product_id' => $item['product_id'],
//                        'message' => 'Product not found for this company.',
//                    ];
//                    continue;
//                }
//
//                $stock = $this->getOrCreateStock($product, $request->branch_id, $stockArea);
//
//                $previousQuantity = (float)$stock->quantity_on_hand;
//                $previousAvailable = (float)$stock->available_quantity;
//                $reservedQuantity = (float)$stock->reserved_quantity;
//
//                $newQuantity = (float)$item['quantity'];
//                $difference = $newQuantity - $previousQuantity;
//
//                if ($difference == 0) {
//                    $skippedProducts[] = [
//                        'product_id' => $product->id,
//                        'product_name' => $product->name,
//                        'sku' => $product->sku,
//                        'previous_quantity' => $previousQuantity,
//                        'new_quantity' => $newQuantity,
//                        'message' => 'No quantity change detected.',
//                    ];
//                    continue;
//                }
//
//                if ($difference > 0) {
//                    $adjustmentType = 'increase';
//                    $quantityIn = abs($difference);
//                    $quantityOut = 0;
//                } else {
//                    $adjustmentType = 'decrease';
//                    $quantityIn = 0;
//                    $quantityOut = abs($difference);
//
//                    if (!$product->allow_negative_stock && $newQuantity < 0) {
//                        DB::rollBack();
//
//                        return response()->json([
//                            'message' => "Negative stock is not allowed for {$product->name}.",
//                        ], 422);
//                    }
//                }
//
//                $stock->quantity_on_hand = $newQuantity;
//
//                if ($product->allow_negative_stock) {
//                    $stock->available_quantity = $newQuantity - $reservedQuantity;
//                } else {
//                    $stock->available_quantity = max(0, $newQuantity - $reservedQuantity);
//                }
//
//                $stock->save();
//
//                $movement = StockMovement::create([
//                    'company_id' => $product->company_id,
//                    'branch_id' => $request->branch_id,
//                    'product_id' => $product->id,
//                    'movement_type' => 'adjustment',
//                    'stock_area' => $stockArea,
//                    'quantity_in' => $quantityIn,
//                    'quantity_out' => $quantityOut,
//                    'unit_cost' => 0,
//                    'total_cost' => 0,
//                    'notes' => $item['notes'] ?? null,
//                    'created_by' => $user?->id,
//                ]);
//
//                $movementIds[] = $movement->id;
//
//                $adjustedProducts[] = [
//                    'product_id' => $product->id,
//                    'product_name' => $product->name,
//                    'sku' => $product->sku,
//                    'code' => $product->code,
//                    'barcode' => $product->barcode,
//                    'previous_quantity' => $previousQuantity,
//                    'new_quantity' => $newQuantity,
//                    'difference' => $difference,
//                    'adjustment_type' => $adjustmentType,
//                    'quantity_in' => $quantityIn,
//                    'quantity_out' => $quantityOut,
//                    'previous_available_quantity' => $previousAvailable,
//                    'available_quantity' => $stock->available_quantity,
//                    'reserved_quantity' => $stock->reserved_quantity,
//                    'notes' => $item['notes'] ?? null,
//                    'stock_area' => $stockArea,
//                    'movement_id' => $movement->id,
//                ];
//            }
//
//            $this->writeAuditTrail($request, [
//                'company_id' => $company->id,
//                'branch_id' => $request->branch_id,
//                'action' => 'stock_adjusted_bulk',
//                'module' => 'inventory',
//                'description' => 'Bulk stock adjustment completed.',
//                'auditable_type' => StockMovement::class,
//                'auditable_id' => $movementIds[0] ?? null,
//                'old_values' => null,
//                'new_values' => [
//                    'adjusted_count' => count($adjustedProducts),
//                    'skipped_count' => count($skippedProducts),
//                ],
//                'changed_fields' => ['quantity_on_hand', 'available_quantity'],
//                'metadata' => [
//                    'movement_ids' => $movementIds,
//                    'adjusted' => $adjustedProducts,
//                    'skipped' => $skippedProducts,
//                ],
//            ]);
//
//            DB::commit();
//
//            return response()->json([
//                'message' => 'Bulk stock adjustment completed successfully.',
//                'data' => [
//                    'adjusted' => $adjustedProducts,
//                    'skipped' => $skippedProducts,
//                ],
//            ], 200);
//        } catch (\Exception $e) {
//            DB::rollBack();
//
//            $this->writeAuditTrail($request, [
//                'company_id' => $company->id,
//                'branch_id' => $request->branch_id,
//                'action' => 'stock_adjust_bulk_failed',
//                'module' => 'inventory',
//                'description' => 'Bulk stock adjustment failed.',
//                'auditable_type' => StockMovement::class,
//                'auditable_id' => null,
//                'old_values' => null,
//                'new_values' => [
//                    'items' => $request->items,
//                ],
//                'changed_fields' => ['quantity_on_hand'],
//                'status' => 'failed',
//                'error_message' => $e->getMessage(),
//                'metadata' => [
//                    'branch_id' => $request->branch_id,
//                ],
//            ]);
//
//            return response()->json([
//                'message' => 'Something went wrong while adjusting stock.',
//                'error' => $e->getMessage(),
//            ], 500);
//        }
//    }
//
//    public function stockAdjustmentHistory(Request $request)
//    {
//        $company = app('company');
//        $user = auth()->user();
//
//        $query = StockMovement::where('company_id', $company->id)
//            ->where('movement_type', 'adjustment')
//            ->with([
//                'product.category',
//                'product.uom',
//                'branch',
//                'createdBy',
//            ])
//            ->orderByDesc('created_at');
//
//        if ($request->filled('branch_id')) {
//            $query->where('branch_id', $request->branch_id);
//        } else if (!empty($user?->branch_id)) {
//            $query->where('branch_id', $user->branch_id);
//        }
//
//        if ($request->filled('product_id')) {
//            $query->where('product_id', $request->product_id);
//        }
//
//        if ($request->filled('stock_area')) {
//            $query->where('stock_area', $request->stock_area);
//        }
//
//        if ($request->filled('q')) {
//            $q = $request->q;
//
//            $query->whereHas('product', function ($sub) use ($q) {
//                $sub->where('name', 'like', "%{$q}%")
//                    ->orWhere('sku', 'like', "%{$q}%")
//                    ->orWhere('code', 'like', "%{$q}%")
//                    ->orWhere('barcode', 'like', "%{$q}%");
//            });
//        }
//
//        if ($request->filled('adjustment_type')) {
//            if ($request->adjustment_type === 'increase') {
//                $query->where('quantity_in', '>', 0);
//            }
//
//            if ($request->adjustment_type === 'decrease') {
//                $query->where('quantity_out', '>', 0);
//            }
//        }
//
//        if ($request->filled('date_from')) {
//            $query->whereDate('created_at', '>=', $request->date_from);
//        }
//
//        if ($request->filled('date_to')) {
//            $query->whereDate('created_at', '<=', $request->date_to);
//        }
//
//        $perPage = (int)$request->get('per_page', 50);
//
//        $movements = $query->paginate($perPage);
//
//        return response()->json([
//            'message' => 'Stock adjustment history fetched successfully.',
//            'data' => $movements,
//        ], 200);
//    }
//
//    public function breakProduct(Request $request, string $childProductId)
//    {
//        $company = app('company');
//
//        $child = Product::where('company_id', $company->id)
//            ->where('id', $childProductId)
//            ->first();
//
//        if (!$child) {
//            return response()->json([
//                'message' => 'Child product not found.',
//            ], 404);
//        }
//
//        $validator = Validator::make($request->all(), [
//            'branch_id' => 'required|exists:company_branches,id',
//            'break_times' => 'nullable|integer|min:1',
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
//            $this->breakChildProduct(
//                $child,
//                $request->branch_id,
//                $request->break_times ?? 1
//            );
//
//            DB::commit();
//
//            return response()->json([
//                'message' => 'Product broken successfully.',
//                'data' => $this->loadProduct($child->id),
//            ], 200);
//        } catch (\Exception $e) {
//            DB::rollBack();
//
//            return response()->json([
//                'message' => $e->getMessage(),
//            ], 422);
//        }
//    }
//
//
//    public function frontStock(Request $request)
//    {
//        return $this->stockByArea($request, 'front');
//    }
//
//    public function backStock(Request $request, CompanySettingService $settings)
//    {
//        $company = app('company');
//
//        if (!$settings->isFastFoodMode($company->id) || !$settings->isBohEnabled($company->id)) {
//            return response()->json([
//                'message' => 'BOH is not enabled for this company.',
//            ], 422);
//        }
//
//        return $this->stockByArea($request, 'back');
//    }
//
//    private function stockByArea(Request $request, string $stockArea)
//    {
//        $company = app('company');
//
//        $result = hasPermission(auth()->user()->role_id, 'view_stock_reports', $company->id);
//        if ($result !== true) return $result;
//
//        $perPage = (int)$request->get('per_page', 50);
//        $perPage = $perPage > 0 && $perPage <= 200 ? $perPage : 50;
//
//        $query = ProductStock::query()
//            ->where('company_id', $company->id)
//            ->where('stock_area', $stockArea)
//            ->with([
//                'branch',
//                'product.category',
//                'product.supplier',
//                'product.uom',
//                'product.costs',
//            ]);
//
//        if ($request->filled('branch_id')) {
//            $query->where('branch_id', $request->branch_id);
//        }
//
//        if ($request->filled('product_id')) {
//            $query->where('product_id', $request->product_id);
//        }
//
//        if ($request->filled('product_type')) {
//            $query->whereHas('product', function ($productQuery) use ($request) {
//                $productQuery->where('product_type', $request->product_type);
//            });
//        }
//
//        if ($request->filled('category_id')) {
//            $query->whereHas('product', function ($productQuery) use ($request) {
//                $productQuery->where('category_id', $request->category_id);
//            });
//        }
//
//        if ($request->filled('q')) {
//            $search = trim($request->q);
//
//            $query->whereHas('product', function ($productQuery) use ($search) {
//                $productQuery->where('name', 'like', "%{$search}%")
//                    ->orWhere('code', 'like', "%{$search}%")
//                    ->orWhere('sku', 'like', "%{$search}%")
//                    ->orWhere('barcode', 'like', "%{$search}%");
//            });
//        }
//
//        $rows = $query
//            ->orderByDesc('updated_at')
//            ->paginate($perPage);
//
//        $rows->getCollection()->transform(function ($stock) {
//            $cost = optional($stock->product)->costs
//                ? $stock->product->costs->firstWhere('branch_id', $stock->branch_id)
//                : null;
//
//            if (!$cost && optional($stock->product)->costs) {
//                $cost = $stock->product->costs->first();
//            }
//
//            $stock->setAttribute('cost', $cost);
//            $stock->setAttribute('average_cost', $cost?->average_cost);
//            $stock->setAttribute('last_cost', $cost?->last_cost);
//            $stock->setAttribute('total_value', $cost?->total_value);
//            $stock->setAttribute('currency', $cost?->currency);
//
//            return $stock;
//        });
//
//        return response()->json([
//            'message' => strtoupper($stockArea) . ' stock fetched successfully.',
//            'stock_area' => $stockArea,
//            'data' => $rows,
//        ]);
//    }
//
//
////    public function issueBackStockEnterprise(Request $request, CompanySettingService $settings)
////    {
////        $company = app('company');
////
////        if (!$settings->isFastFoodMode($company->id) || !$settings->isBohEnabled($company->id)) {
////            return response()->json([
////                'message' => 'BOH is not enabled for this company.',
////            ], 422);
////        }
////
////        $result = hasPermission(auth()->user()->role_id, 'boh_issue_stock', $company->id);
////        if ($result !== true) return $result;
////
////        $validator = Validator::make($request->all(), [
////            'branch_id' => 'required|exists:company_branches,id',
////            'notes' => 'nullable|string',
////            'items' => 'required|array|min:1',
////            'items.*.product_id' => 'required|exists:products,id',
////            'items.*.quantity' => 'required|numeric|min:0.0001',
////            'items.*.issue_destination' => 'nullable|in:auto,front,department,expense,production,wastage',
////            'items.*.department_name' => 'nullable|string|max:120',
////            'items.*.cost_center' => 'nullable|string|max:120',
////            'items.*.issue_purpose' => 'nullable|string|max:255',
////            'items.*.notes' => 'nullable|string',
////        ]);
////
////        if ($validator->fails()) {
////            return response()->json([
////                'message' => 'Validation error.',
////                'errors' => $validator->errors(),
////            ], 422);
////        }
////
////        DB::beginTransaction();
////
////        try {
////            $issued = [];
////            $summary = [
////                'turnover_lines' => 0,
////                'non_turnover_lines' => 0,
////                'front_quantity' => 0,
////                'internal_quantity' => 0,
////                'front_value' => 0,
////                'internal_value' => 0,
////            ];
////
////            foreach ($request->items as $item) {
////                $product = Product::where('company_id', $company->id)
////                    ->where('id', $item['product_id'])
////                    ->first();
////
////                if (!$product) {
////                    throw new \Exception('Product not found.');
////                }
////
////                $quantity = (float)$item['quantity'];
////                $productType = strtolower((string)$product->product_type);
////                $stockUsageType = $this->resolveStockUsageType($product);
////                $requestedDestination = strtolower((string)($item['issue_destination'] ?? 'auto'));
////                $destination = $this->resolveEnterpriseIssueDestination($product, $requestedDestination);
////
////                if ($stockUsageType === 'non_turnover' && $destination === 'front') {
////                    throw new \Exception("{$product->name} is non-turnover stock and cannot be issued to front stock. Issue it to department, expense, production usage or wastage.");
////                }
////
////                $backStock = $this->getOrCreateStock($product, $request->branch_id, 'back');
////
////                if ((float)$backStock->available_quantity < $quantity) {
////                    throw new \Exception("Insufficient BOH stock for {$product->name}.");
////                }
////
////                $cost = ProductCost::where('company_id', $company->id)
////                    ->where('branch_id', $request->branch_id)
////                    ->where('product_id', $product->id)
////                    ->first();
////
////                $unitCost = (float)($cost?->average_cost ?? $cost?->last_cost ?? 0);
////                $currencyCode = (string)($cost?->currency ?? 'USD');
////                $totalCost = round($quantity * $unitCost, 6);
////
////                $backStock->quantity_on_hand -= $quantity;
////                $backStock->available_quantity -= $quantity;
////                $backStock->save();
////
////                $quantityIn = 0;
////                $quantityOut = $quantity;
////                $toStockArea = $destination;
////                $movementType = $this->enterpriseIssueMovementType($destination);
////
////                if ($destination === 'front') {
////                    $frontStock = $this->getOrCreateStock($product, $request->branch_id, 'front');
////                    $frontStock->quantity_on_hand += $quantity;
////                    $frontStock->available_quantity += $quantity;
////                    $frontStock->save();
////
////                    // Keep legacy report compatibility: old BOH issue report reads both in/out on one movement.
////                    $quantityIn = $quantity;
////                    $quantityOut = $quantity;
////                    $toStockArea = 'front';
////                }
////
////                $metadata = [
////                    'enterprise_issue' => true,
////                    'stock_usage_type' => $stockUsageType,
////                    'turnover_class' => $stockUsageType === 'turnover' ? 'turnover_stock' : 'non_turnover_stock',
////                    'issue_destination' => $destination,
////                    'department_name' => $item['department_name'] ?? null,
////                    'cost_center' => $item['cost_center'] ?? null,
////                    'issue_purpose' => $item['issue_purpose'] ?? null,
////                    'product_type' => $productType,
////                    'pl_report_bucket' => $stockUsageType === 'non_turnover' ? 'operating_consumables' : 'cost_of_sales_stock_issue',
////                    'turnover_report_bucket' => $stockUsageType === 'turnover' ? 'turnover_stock_issue' : 'non_turnover_stock_issue',
////                ];
////
////                StockMovement::create([
////                    'company_id' => $company->id,
////                    'branch_id' => $request->branch_id,
////                    'product_id' => $product->id,
////                    'movement_type' => $movementType,
////                    'stock_area' => $destination === 'front' ? 'front' : 'back',
////                    'from_stock_area' => 'back',
////                    'to_stock_area' => $toStockArea,
////                    'quantity_in' => $quantityIn,
////                    'quantity_out' => $quantityOut,
////                    'unit_cost' => $unitCost,
////                    'total_cost' => $totalCost,
////                    'currency' => $currencyCode,
////                    'reference_type' => 'enterprise_stock_issue',
////                    'reference_id' => null,
////                    'notes' => $item['notes'] ?? $request->notes,
////                    'metadata' => $metadata,
////                    'created_by' => auth()->id(),
////                ]);
////
////                if ($stockUsageType === 'turnover') {
////                    $summary['turnover_lines']++;
////                } else {
////                    $summary['non_turnover_lines']++;
////                }
////
////                if ($destination === 'front') {
////                    $summary['front_quantity'] += $quantity;
////                    $summary['front_value'] += $totalCost;
////                } else {
////                    $summary['internal_quantity'] += $quantity;
////                    $summary['internal_value'] += $totalCost;
////                }
////
////                $issued[] = [
////                    'product_id' => $product->id,
////                    'product_name' => $product->name,
////                    'sku' => $product->sku,
////                    'code' => $product->code,
////                    'product_type' => $product->product_type,
////                    'stock_usage_type' => $stockUsageType,
////                    'issue_destination' => $destination,
////                    'quantity' => $quantity,
////                    'unit_cost' => $unitCost,
////                    'total_cost' => $totalCost,
////                    'currency' => $currencyCode,
////                    'from_stock_area' => 'back',
////                    'to_stock_area' => $toStockArea,
////                    'department_name' => $item['department_name'] ?? null,
////                    'cost_center' => $item['cost_center'] ?? null,
////                    'issue_purpose' => $item['issue_purpose'] ?? null,
////                ];
////            }
////
////            DB::commit();
////
////            return response()->json([
////                'message' => 'Enterprise stock issue posted successfully.',
////                'data' => $issued,
////                'summary' => [
////                    'turnover_lines' => $summary['turnover_lines'],
////                    'non_turnover_lines' => $summary['non_turnover_lines'],
////                    'front_quantity' => round($summary['front_quantity'], 6),
////                    'internal_quantity' => round($summary['internal_quantity'], 6),
////                    'front_value' => round($summary['front_value'], 6),
////                    'internal_value' => round($summary['internal_value'], 6),
////                ],
////            ], 200);
////        } catch (\Exception $e) {
////            DB::rollBack();
////
////            return response()->json([
////                'message' => $e->getMessage(),
////            ], 422);
////        }
////    }
//
////    private function resolveStockUsageType(Product $product): string
////    {
////        if (Schema::hasColumn('products', 'stock_usage_type') && !empty($product->stock_usage_type)) {
////            return strtolower((string)$product->stock_usage_type) === 'non_turnover' ? 'non_turnover' : 'turnover';
////        }
////
////        $type = strtolower((string)$product->product_type);
////
////        // Cleaning materials, office consumables and similar products are received into stock,
////        // but when issued they become operating consumption, not front selling stock.
////        if (in_array($type, ['consumable', 'consumables'], true)) {
////            return 'non_turnover';
////        }
////
////        return 'turnover';
////    }
//
//    public function issueBackStockEnterprise(Request $request, CompanySettingService $settings)
//    {
//        $company = app('company');
//
//        if (!$settings->isFastFoodMode($company->id) || !$settings->isBohEnabled($company->id)) {
//            return response()->json([
//                'message' => 'BOH is not enabled for this company.',
//            ], 422);
//        }
//
//        $result = hasPermission(auth()->user()->role_id, 'boh_issue_stock', $company->id);
//        if ($result !== true) return $result;
//
//        $validator = Validator::make($request->all(), [
//            'branch_id' => 'required|exists:company_branches,id',
//            'issue_name' => 'required|string|max:180',
//            'issue_type' => 'required|in:front,department,expense,production,wastage',
//            'issue_destination' => 'required|in:front,department,expense,production,wastage',
//            'department_name' => 'nullable|string|max:120',
//            'cost_center' => 'nullable|string|max:120',
//            'requested_by' => 'nullable|string|max:120',
//            'issue_purpose' => 'required|string|max:255',
//            'notes' => 'nullable|string',
//            'items' => 'required|array|min:1',
//            'items.*.product_id' => 'required|exists:products,id',
//            'items.*.quantity' => 'required|numeric|min:0.0001',
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
//            $issueId = (string) Str::uuid();
//            $destination = strtolower((string) $request->issue_destination);
//            $issueType = strtolower((string) $request->issue_type);
//            $issued = [];
//            $summary = [
//                'turnover_lines' => 0,
//                'non_turnover_lines' => 0,
//                'front_quantity' => 0,
//                'internal_quantity' => 0,
//                'front_value' => 0,
//                'internal_value' => 0,
//                'total_quantity' => 0,
//                'total_value' => 0,
//            ];
//
//            foreach ($request->items as $item) {
//                $product = Product::where('company_id', $company->id)
//                    ->where('id', $item['product_id'])
//                    ->first();
//
//                if (!$product) {
//                    throw new \Exception('Product not found.');
//                }
//
//                $quantity = (float) $item['quantity'];
//                $productType = strtolower((string) $product->product_type);
//                $stockUsageType = $this->resolveStockUsageType($product);
//
//                if ($stockUsageType === 'non_turnover' && $destination === 'front') {
//                    throw new \Exception("{$product->name} is non-turnover stock and cannot be issued to front stock. Select Department, Operating Expense, Production or Wastage issue type.");
//                }
//
//                $backStock = $this->getOrCreateStock($product, $request->branch_id, 'back');
//
//                if ((float) $backStock->available_quantity < $quantity) {
//                    throw new \Exception("Insufficient BOH stock for {$product->name}.");
//                }
//
//                $cost = ProductCost::where('company_id', $company->id)
//                    ->where('branch_id', $request->branch_id)
//                    ->where('product_id', $product->id)
//                    ->first();
//
//                $unitCost = (float) ($cost?->average_cost ?? $cost?->last_cost ?? 0);
//                $currencyCode = (string) ($cost?->currency ?? 'USD');
//                $totalCost = round($quantity * $unitCost, 6);
//
//                $backStock->quantity_on_hand -= $quantity;
//                $backStock->available_quantity -= $quantity;
//                $backStock->save();
//
//                $quantityIn = 0;
//                $quantityOut = $quantity;
//                $toStockArea = $destination;
//                $movementType = $this->enterpriseIssueMovementType($destination);
//                $stockArea = 'back';
//
//                if ($destination === 'front') {
//                    $frontStock = $this->getOrCreateStock($product, $request->branch_id, 'front');
//                    $frontStock->quantity_on_hand += $quantity;
//                    $frontStock->available_quantity += $quantity;
//                    $frontStock->save();
//
//                    // Legacy report compatibility.
//                    $quantityIn = $quantity;
//                    $quantityOut = $quantity;
//                    $toStockArea = 'front';
//                    $stockArea = 'front';
//                }
//
//                $metadata = [
//                    'enterprise_issue' => true,
//                    'issue_id' => $issueId,
//                    'issue_name' => $request->issue_name,
//                    'issue_type' => $issueType,
//                    'issue_destination' => $destination,
//                    'department_name' => $request->department_name,
//                    'cost_center' => $request->cost_center,
//                    'requested_by' => $request->requested_by,
//                    'issue_purpose' => $request->issue_purpose,
//                    'header_notes' => $request->notes,
//                    'stock_usage_type' => $stockUsageType,
//                    'turnover_class' => $stockUsageType === 'turnover' ? 'turnover_stock' : 'non_turnover_stock',
//                    'product_type' => $productType,
//                    'pl_report_bucket' => $stockUsageType === 'non_turnover' ? 'operating_consumables' : 'cost_of_sales_stock_issue',
//                    'turnover_report_bucket' => $stockUsageType === 'turnover' ? 'turnover_stock_issue' : 'non_turnover_stock_issue',
//                ];
//
//                $movementPayload = [
//                    'company_id' => $company->id,
//                    'branch_id' => $request->branch_id,
//                    'product_id' => $product->id,
//                    'movement_type' => $movementType,
//                    'stock_area' => $stockArea,
//                    'from_stock_area' => 'back',
//                    'to_stock_area' => $toStockArea,
//                    'quantity_in' => $quantityIn,
//                    'quantity_out' => $quantityOut,
//                    'unit_cost' => $unitCost,
//                    'total_cost' => $totalCost,
//                    'currency' => $currencyCode,
//                    'reference_type' => 'enterprise_stock_issue',
//                    'reference_id' => $issueId,
//                    'notes' => $request->notes,
//                    'metadata' => $metadata,
//                    'created_by' => auth()->id(),
//                ];
//
//                if (Schema::hasColumn('stock_movements', 'issue_destination')) {
//                    $movementPayload['issue_destination'] = $destination;
//                }
//
//                if (Schema::hasColumn('stock_movements', 'stock_usage_type')) {
//                    $movementPayload['stock_usage_type'] = $stockUsageType;
//                }
//
//                if (Schema::hasColumn('stock_movements', 'expense_account_code')) {
//                    $movementPayload['expense_account_code'] = $product->expense_account_code ?? null;
//                }
//
//                StockMovement::create($movementPayload);
//
//                if ($stockUsageType === 'turnover') {
//                    $summary['turnover_lines']++;
//                } else {
//                    $summary['non_turnover_lines']++;
//                }
//
//                if ($destination === 'front') {
//                    $summary['front_quantity'] += $quantity;
//                    $summary['front_value'] += $totalCost;
//                } else {
//                    $summary['internal_quantity'] += $quantity;
//                    $summary['internal_value'] += $totalCost;
//                }
//
//                $summary['total_quantity'] += $quantity;
//                $summary['total_value'] += $totalCost;
//
//                $issued[] = [
//                    'issue_id' => $issueId,
//                    'issue_name' => $request->issue_name,
//                    'product_id' => $product->id,
//                    'product_name' => $product->name,
//                    'sku' => $product->sku,
//                    'code' => $product->code,
//                    'product_type' => $product->product_type,
//                    'stock_usage_type' => $stockUsageType,
//                    'issue_destination' => $destination,
//                    'quantity' => $quantity,
//                    'unit_cost' => $unitCost,
//                    'total_cost' => $totalCost,
//                    'currency' => $currencyCode,
//                    'from_stock_area' => 'back',
//                    'to_stock_area' => $toStockArea,
//                    'department_name' => $request->department_name,
//                    'cost_center' => $request->cost_center,
//                    'requested_by' => $request->requested_by,
//                    'issue_purpose' => $request->issue_purpose,
//                ];
//            }
//
//            DB::commit();
//
//            return response()->json([
//                'message' => 'Enterprise stock issue posted successfully.',
//                'issue_id' => $issueId,
//                'header' => [
//                    'issue_name' => $request->issue_name,
//                    'issue_type' => $issueType,
//                    'issue_destination' => $destination,
//                    'department_name' => $request->department_name,
//                    'cost_center' => $request->cost_center,
//                    'requested_by' => $request->requested_by,
//                    'issue_purpose' => $request->issue_purpose,
//                    'notes' => $request->notes,
//                ],
//                'data' => $issued,
//                'summary' => array_map(fn ($value) => is_numeric($value) ? round((float) $value, 6) : $value, $summary),
//            ], 200);
//        } catch (\Exception $e) {
//            DB::rollBack();
//
//            return response()->json([
//                'message' => $e->getMessage(),
//            ], 422);
//        }
//    }
//
//    private function resolveStockUsageType(Product $product): string
//    {
//        if (Schema::hasColumn('products', 'stock_usage_type') && !empty($product->stock_usage_type)) {
//            return strtolower((string) $product->stock_usage_type) === 'non_turnover' ? 'non_turnover' : 'turnover';
//        }
//
//        $type = strtolower((string) $product->product_type);
//
//        if (in_array($type, ['consumable', 'consumables'], true)) {
//            return 'non_turnover';
//        }
//
//        return 'turnover';
//    }
//
//    private function enterpriseIssueMovementType(string $destination): string
//    {
//        return match ($destination) {
//            'front' => 'boh_issue_to_front',
//            'department' => 'boh_issue_to_department',
//            'production' => 'boh_issue_to_production',
//            'wastage' => 'wastage',
//            default => 'boh_issue_to_expense',
//        };
//    }
//
//    private function resolveEnterpriseIssueDestination(Product $product, string $requestedDestination): string
//    {
//        $usageType = $this->resolveStockUsageType($product);
//
//        if ($requestedDestination === '' || $requestedDestination === 'auto') {
//            if (Schema::hasColumn('products', 'default_issue_destination') && !empty($product->default_issue_destination)) {
//                return strtolower((string)$product->default_issue_destination);
//            }
//
//            return $usageType === 'non_turnover' ? 'expense' : 'front';
//        }
//
//        return $requestedDestination;
//    }
//
////    private function enterpriseIssueMovementType(string $destination): string
////    {
////        return match ($destination) {
////            'front' => 'boh_issue_to_front',
////            'department' => 'boh_issue_to_department',
////            'production' => 'boh_issue_to_production',
////            'wastage' => 'wastage',
////            default => 'boh_issue_to_expense',
////        };
////    }
//
//    public function issueToFront(Request $request, CompanySettingService $settings)
//    {
//        $company = app('company');
//
//        if (!$settings->isFastFoodMode($company->id) || !$settings->isBohEnabled($company->id)) {
//            return response()->json([
//                'message' => 'BOH is not enabled for this company.',
//            ], 422);
//        }
//
//        $result = hasPermission(auth()->user()->role_id, 'boh_issue_stock', $company->id);
//        if ($result !== true) return $result;
//
//        $validator = Validator::make($request->all(), [
//            'branch_id' => 'required|exists:company_branches,id',
//            'items' => 'required|array|min:1',
//            'items.*.product_id' => 'required|exists:products,id',
//            'items.*.quantity' => 'required|numeric|min:0.0001',
//            'notes' => 'nullable|string',
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
//            $issued = [];
//
//            foreach ($request->items as $item) {
//                $product = Product::where('company_id', $company->id)
//                    ->where('id', $item['product_id'])
//                    ->first();
//
//                if (!$product) {
//                    throw new \Exception('Product not found.');
//                }
//
//                $quantity = (float)$item['quantity'];
//
//                $backStock = $this->getOrCreateStock($product, $request->branch_id, 'back');
//                $frontStock = $this->getOrCreateStock($product, $request->branch_id, 'front');
//
//                if ((float)$backStock->available_quantity < $quantity) {
//                    throw new \Exception("Insufficient BOH stock for {$product->name}.");
//                }
//
//                $backStock->quantity_on_hand -= $quantity;
//                $backStock->available_quantity -= $quantity;
//                $backStock->save();
//
//                $frontStock->quantity_on_hand += $quantity;
//                $frontStock->available_quantity += $quantity;
//                $frontStock->save();
//
//                StockMovement::create([
//                    'company_id' => $company->id,
//                    'branch_id' => $request->branch_id,
//                    'product_id' => $product->id,
//                    'movement_type' => 'boh_issue_to_front',
//                    'stock_area' => 'front',
//                    'from_stock_area' => 'back',
//                    'to_stock_area' => 'front',
//                    'quantity_in' => $quantity,
//                    'quantity_out' => $quantity,
//                    'unit_cost' => 0,
//                    'total_cost' => 0,
//                    'notes' => $request->notes,
//                    'created_by' => auth()->id(),
//                ]);
//
//                $issued[] = [
//                    'product_id' => $product->id,
//                    'product_name' => $product->name,
//                    'sku' => $product->sku,
//                    'code' => $product->code,
//                    'quantity' => $quantity,
//                    'from_stock_area' => 'back',
//                    'to_stock_area' => 'front',
//                ];
//            }
//
//            DB::commit();
//
//            return response()->json([
//                'message' => 'Stock issued to front successfully.',
//                'data' => $issued,
//            ], 200);
//        } catch (\Exception $e) {
//            DB::rollBack();
//
//            return response()->json([
//                'message' => $e->getMessage(),
//            ], 422);
//        }
//    }
//
//    private function loadProduct(string $id)
//    {
//        $company = app('company');
//
//        return Product::where('company_id', $company->id)
//            ->where('id', $id)
//            ->with([
//                'category',
//                'supplier',
//                'parent',
//                'subCategory',
//                'children',
//                'uom',
//                'tax',
//                'prices.priceLevel',
//                'stocks',
//                'costs',
//                'barcodes',
//                'suppliers.supplier',
//                'discounts',
//                'childBreakRule.parentProduct',
//                'parentBreakRules.childProduct',
//            ])
//            ->first();
//    }
//
//    private function breakChildProduct(Product $child, string $branchId, int $breakTimes = 1): void
//    {
//        $rule = ProductBreakRule::where('company_id', $child->company_id)
//            ->where('child_product_id', $child->id)
//            ->where('is_active', true)
//            ->first();
//
//        if (!$rule) {
//            throw new \Exception('No break rule configured for this child product.');
//        }
//
//        $parent = Product::where('company_id', $child->company_id)
//            ->where('id', $rule->parent_product_id)
//            ->first();
//
//        if (!$parent) {
//            throw new \Exception('Parent product not found.');
//        }
//
//        $parentQtyNeeded = $rule->parent_quantity * $breakTimes;
//        $childQtyCreated = $rule->units_per_parent * $breakTimes;
//
//        $parentStock = $this->getOrCreateStock($parent, $branchId);
//        $childStock = $this->getOrCreateStock($child, $branchId);
//
//        if ($parentStock->available_quantity < $parentQtyNeeded) {
//            throw new \Exception('Insufficient parent stock.');
//        }
//
//        $parentStock->quantity_on_hand -= $parentQtyNeeded;
//        $parentStock->available_quantity -= $parentQtyNeeded;
//        $parentStock->save();
//
//        $childStock->quantity_on_hand += $childQtyCreated;
//        $childStock->available_quantity += $childQtyCreated;
//        $childStock->save();
//
//        $parentCost = ProductCost::where('company_id', $parent->company_id)
//            ->where('branch_id', $branchId)
//            ->where('product_id', $parent->id)
//            ->first();
//
//        $parentUnitCost = $parentCost?->average_cost ?? 0;
//        $totalCostMoved = $parentUnitCost * $parentQtyNeeded;
//        $childUnitCost = $childQtyCreated > 0
//            ? $totalCostMoved / $childQtyCreated
//            : 0;
//
//        $this->addAverageCost($child, $branchId, $childQtyCreated, $childUnitCost);
//
//        StockMovement::create([
//            'company_id' => $parent->company_id,
//            'branch_id' => $branchId,
//            'product_id' => $parent->id,
//            'movement_type' => 'break_parent',
//            'quantity_in' => 0,
//            'quantity_out' => $parentQtyNeeded,
//            'unit_cost' => $parentUnitCost,
//            'total_cost' => $totalCostMoved,
//            'notes' => 'Parent product broken into child units',
//            'created_by' => auth()->id(),
//        ]);
//
//        StockMovement::create([
//            'company_id' => $child->company_id,
//            'branch_id' => $branchId,
//            'product_id' => $child->id,
//            'movement_type' => 'create_child',
//            'quantity_in' => $childQtyCreated,
//            'quantity_out' => 0,
//            'unit_cost' => $childUnitCost,
//            'total_cost' => $totalCostMoved,
//            'notes' => 'Child units created from parent product',
//            'created_by' => auth()->id(),
//        ]);
//    }
//
//    private function addAverageCost(Product $product, string $branchId, float $quantity, float $unitCost): void
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
//        $newValue = $quantity * $unitCost;
//        $newTotalQuantity = $cost->total_quantity + $quantity;
//        $newTotalValue = $cost->total_value + $newValue;
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
//    private function syncPrices(Product $product, array $prices): void
//    {
//        foreach ($prices as $price) {
//            ProductPrice::create([
//                'company_id' => $product->company_id,
//                'product_id' => $product->id,
//                'branch_id' => $price['branch_id'] ?? null,
//                'price_level_id' => $price['price_level_id'],
//                'price' => $price['price'],
//                'currency' => $price['currency'] ?? 'USD',
//                'is_default' => $price['is_default'] ?? false,
//                'is_active' => true,
//                'starts_at' => $price['starts_at'] ?? null,
//                'ends_at' => $price['ends_at'] ?? null,
//            ]);
//        }
//    }
//
//    private function syncBarcodes(Product $product, array $barcodes): void
//    {
//        foreach ($barcodes as $barcode) {
//            ProductBarcode::create([
//                'company_id' => $product->company_id,
//                'product_id' => $product->id,
//                'barcode' => $barcode['barcode'],
//                'type' => $barcode['type'] ?? 'alternative',
//                'is_active' => true,
//            ]);
//        }
//    }
//
//    private function syncSuppliers(Product $product, array $suppliers): void
//    {
//        foreach ($suppliers as $supplier) {
//            ProductSupplier::create([
//                'company_id' => $product->company_id,
//                'product_id' => $product->id,
//                'supplier_id' => $supplier['supplier_id'],
//                'supplier_code' => $supplier['supplier_code'] ?? null,
//                'last_purchase_price' => $supplier['last_purchase_price'] ?? 0,
//                'is_default' => $supplier['is_default'] ?? false,
//            ]);
//        }
//    }
//
//    private function syncDiscounts(Product $product, array $discountIds): void
//    {
//        $syncData = [];
//
//        foreach ($discountIds as $discountId) {
//            $syncData[$discountId] = [
//                'company_id' => $product->company_id,
//            ];
//        }
//
//        $product->discounts()->sync($syncData);
//    }
//
//    private function saveBreakRule(Product $product, array $rule): void
//    {
//        ProductBreakRule::updateOrCreate(
//            [
//                'company_id' => $product->company_id,
//                'child_product_id' => $product->id,
//            ],
//            [
//                'parent_product_id' => $rule['parent_product_id'],
//                'parent_quantity' => $rule['parent_quantity'] ?? 1,
//                'units_per_parent' => $rule['units_per_parent'],
//                'is_auto_break' => $rule['is_auto_break'] ?? true,
//                'is_active' => true,
//            ]
//        );
//    }
//
//    private function getSafeSessionId(Request $request): ?string
//    {
//        try {
//            return $request->hasSession() ? $request->session()->getId() : null;
//        } catch (\Throwable $e) {
//            return null;
//        }
//    }
//
//    private function writeAuditTrail(Request $request, array $data): void
//    {
//        try {
//            $user = auth()->user();
//
//            AuditTrail::create([
//                'company_id' => $data['company_id'] ?? null,
//                'branch_id' => $data['branch_id'] ?? null,
//                'user_id' => $user?->id,
//                'user_name' => $user?->name ?? $user?->username ?? null,
//                'user_email' => $user?->email ?? null,
//                'user_role' => $user?->role?->name ?? $user?->role_id ?? null,
//
//                'action' => $data['action'],
//                'module' => $data['module'] ?? 'inventory',
//                'description' => $data['description'] ?? null,
//
//                'auditable_type' => $data['auditable_type'] ?? null,
//                'auditable_id' => $data['auditable_id'] ?? null,
//
//                'old_values' => $data['old_values'] ?? null,
//                'new_values' => $data['new_values'] ?? null,
//                'changed_fields' => $data['changed_fields'] ?? null,
//
//                'ip_address' => $request->ip(),
//                'user_agent' => $request->userAgent(),
//                'device_name' => $request->header('X-Device-Name'),
//                'terminal_name' => $request->header('X-Terminal-Name'),
//                'session_id' => $this->getSafeSessionId($request),
//
//                'status' => $data['status'] ?? 'success',
//                'error_message' => $data['error_message'] ?? null,
//                'metadata' => $data['metadata'] ?? null,
//            ]);
//        } catch (\Throwable $e) {
//            logger()->error('Failed to write stock adjustment audit trail', [
//                'error' => $e->getMessage(),
//            ]);
//        }
//    }
//
//    private function getOrCreateStock(Product $product, string $branchId, string $stockArea = 'front'): ProductStock
//    {
//        return ProductStock::firstOrCreate(
//            [
//                'company_id' => $product->company_id,
//                'branch_id' => $branchId,
//                'product_id' => $product->id,
//                'stock_area' => $stockArea,
//            ],
//            [
//                'quantity_on_hand' => 0,
//                'reserved_quantity' => 0,
//                'available_quantity' => 0,
//            ]
//        );
//    }
//
//    private function receiveProductStock(
//        Product $product,
//        string  $branchId,
//        float   $quantity,
//        float   $unitCost,
//        ?string $currency,
//        string  $movementType,
//        ?string $referenceId = null,
//        ?string $notes = null,
//        string  $stockArea = 'front'
//    ): void
//    {
//        $currencyCode = strtoupper($currency ?: 'USD');
//
//        $stock = $this->getOrCreateStock($product, $branchId, $stockArea);
//
//        $stock->quantity_on_hand = (float)$stock->quantity_on_hand + $quantity;
//        $stock->available_quantity = (float)$stock->available_quantity + $quantity;
//        $stock->save();
//
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
//                'currency' => $currencyCode,
//            ]
//        );
//
//        $oldTotalValue = (float)$cost->total_value;
//        $oldTotalQuantity = (float)$cost->total_quantity;
//
//        $newPurchaseValue = $quantity * $unitCost;
//        $newTotalQuantity = $oldTotalQuantity + $quantity;
//        $newTotalValue = $oldTotalValue + $newPurchaseValue;
//
//        $cost->average_cost = $newTotalQuantity > 0
//            ? $newTotalValue / $newTotalQuantity
//            : 0;
//
//        $cost->last_cost = $unitCost;
//        $cost->total_quantity = $newTotalQuantity;
//        $cost->total_value = $newTotalValue;
//        $cost->currency = $currencyCode;
//        $cost->save();
//
//        StockMovement::create([
//            'company_id' => $product->company_id,
//            'branch_id' => $branchId,
//            'product_id' => $product->id,
//            'movement_type' => $movementType,
//            'stock_area' => $stockArea,
//            'quantity_in' => $quantity,
//            'quantity_out' => 0,
//            'unit_cost' => $unitCost,
//            'total_cost' => $quantity * $unitCost,
//            'currency' => $currencyCode,
//            'reference_type' => 'product',
//            'reference_id' => $referenceId ?: $product->id,
//            'notes' => $notes,
//            'created_by' => auth()->id(),
//        ]);
//    }
//
//
//    private function autoBreakChildProduct(Product $child, string $branchId, int $breakTimes = 1): void
//    {
//        $rule = ProductBreakRule::where('company_id', $child->company_id)
//            ->where('child_product_id', $child->id)
//            ->where('is_active', true)
//            ->first();
//
//        if (!$rule) {
//            throw new \Exception('No break rule configured for this child product.');
//        }
//
//        $parent = Product::find($rule->parent_product_id);
//
//        if (!$parent) {
//            throw new \Exception('Parent product not found.');
//        }
//
//        $parentQtyNeeded = $rule->parent_quantity * $breakTimes;
//        $childQtyCreated = $rule->child_quantity * $breakTimes;
//
//        $parentStock = $this->getOrCreateStock($parent, $branchId);
//        $childStock = $this->getOrCreateStock($child, $branchId);
//
//        if ($parentStock->available_quantity < $parentQtyNeeded) {
//            throw new \Exception('Insufficient parent product stock.');
//        }
//
//        $parentStock->quantity_on_hand -= $parentQtyNeeded;
//        $parentStock->available_quantity -= $parentQtyNeeded;
//        $parentStock->save();
//
//        $childStock->quantity_on_hand += $childQtyCreated;
//        $childStock->available_quantity += $childQtyCreated;
//        $childStock->save();
//
//        $parentCost = ProductCost::where('company_id', $parent->company_id)
//            ->where('branch_id', $branchId)
//            ->where('product_id', $parent->id)
//            ->first();
//
//        $unitParentCost = $parentCost?->average_cost ?? 0;
//        $totalParentCost = $unitParentCost * $parentQtyNeeded;
//        $childUnitCost = $childQtyCreated > 0
//            ? $totalParentCost / $childQtyCreated
//            : 0;
//
//        $this->updateChildAverageCost($child, $branchId, $childQtyCreated, $childUnitCost);
//
//        StockMovement::create([
//            'company_id' => $parent->company_id,
//            'branch_id' => $branchId,
//            'product_id' => $parent->id,
//            'movement_type' => 'break_parent',
//            'quantity_in' => 0,
//            'quantity_out' => $parentQtyNeeded,
//            'unit_cost' => $unitParentCost,
//            'total_cost' => $totalParentCost,
//            'notes' => 'Auto/manual product break parent deduction',
//            'created_by' => auth()->id(),
//        ]);
//
//        StockMovement::create([
//            'company_id' => $child->company_id,
//            'branch_id' => $branchId,
//            'product_id' => $child->id,
//            'movement_type' => 'create_child',
//            'quantity_in' => $childQtyCreated,
//            'quantity_out' => 0,
//            'unit_cost' => $childUnitCost,
//            'total_cost' => $totalParentCost,
//            'notes' => 'Auto/manual product break child creation',
//            'created_by' => auth()->id(),
//        ]);
//    }
//
//    private function updateChildAverageCost(
//        Product $child,
//        string  $branchId,
//        float   $quantity,
//        float   $unitCost
//    ): void
//    {
//        $cost = ProductCost::firstOrCreate(
//            [
//                'company_id' => $child->company_id,
//                'branch_id' => $branchId,
//                'product_id' => $child->id,
//            ],
//            [
//                'average_cost' => 0,
//                'last_cost' => 0,
//                'total_quantity' => 0,
//                'total_value' => 0,
//            ]
//        );
//
//        $newValue = $quantity * $unitCost;
//        $newTotalQuantity = $cost->total_quantity + $quantity;
//        $newTotalValue = $cost->total_value + $newValue;
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
//    public function generateIdentity(Request $request, ProductIdentityGenerator $generator)
//    {
//        $company = app('company');
//
//        $validator = Validator::make($request->all(), [
//            'branch_id' => 'nullable|exists:company_branches,id',
//            'type' => 'required|in:product_code,sku',
//        ]);
//
//        if ($validator->fails()) {
//            return response()->json([
//                'message' => 'Validation error.',
//                'errors' => $validator->errors(),
//            ], 422);
//        }
//
//        $branchId = $request->branch_id ?? auth()->user()->branch_id;
//
//        $value = match ($request->type) {
//            'product_code' => $generator->generateProductCode($company->id, $branchId),
//            'sku' => $generator->generateSku($company->id, $branchId),
//        };
//
//        return response()->json([
//            'message' => 'Generated successfully.',
//            'data' => [
//                'type' => $request->type,
//                'value' => $value,
//            ],
//        ]);
//    }
//
//    public function getUoms(Request $request)
//    {
//        $company = app('company');
//
//        $query = Uom::where('company_id', $company->id)
//            ->where('is_active', true)
//            ->orderBy('name');
//
//        if ($request->filled('q')) {
//            $q = $request->q;
//
//            $query->where(function ($sub) use ($q) {
//                $sub->where('name', 'like', "%{$q}%")
//                    ->orWhere('code', 'like', "%{$q}%")
//                    ->orWhere('quantity_type', 'like', "%{$q}%");
//            });
//        }
//
//        return response()->json([
//            'message' => 'UOMs fetched successfully.',
//            'data' => $query->get(),
//        ], 200);
//    }
//
//    public function getPriceLevels(Request $request)
//    {
//        $company = app('company');
//
//        $query = PriceLevel::where('company_id', $company->id)
//            ->where('is_active', true)
//            ->orderByDesc('is_default')
//            ->orderBy('name');
//
//        if ($request->filled('q')) {
//            $q = $request->q;
//
//            $query->where(function ($sub) use ($q) {
//                $sub->where('name', 'like', "%{$q}%")
//                    ->orWhere('code', 'like', "%{$q}%")
//                    ->orWhere('description', 'like', "%{$q}%");
//            });
//        }
//
//        return response()->json([
//            'message' => 'Price levels fetched successfully.',
//            'data' => $query->get(),
//        ], 200);
//    }
//
//
//    private function validateSelectedBaseCurrency(
//        string  $companyId,
//        ?string $branchId,
//        ?string $currencyCode
//    ): string
//    {
//        if (!$currencyCode) {
//            throw new \Exception('Currency is required.');
//        }
//
//        $currencyCode = strtoupper(trim($currencyCode));
//
//        $currency = Currency::where('company_id', $companyId)
//            ->where('code', $currencyCode)
//            ->where('is_active', true)
//            ->where('is_base_currency', true)
//            ->where(function ($query) use ($branchId) {
//                $query->whereNull('branch_id');
//
//                if ($branchId) {
//                    $query->orWhere('branch_id', $branchId);
//                }
//            })
//            ->first();
//
//        if (!$currency) {
//            throw new \Exception(
//                'Selected currency must exist and must be the active base currency.'
//            );
//        }
//
//        return $currencyCode;
//    }
//
////    public function uploadImage(Request $request, string $id)
////    {
////        $company = app('company');
////
////        $result = hasPermission(auth()->user()->role_id, 'product_management', $company->id);
////        if ($result !== true) return $result;
////
////        $product = Product::where('company_id', $company->id)
////            ->where('id', $id)
////            ->first();
////
////        if (!$product) {
////            return response()->json([
////                'message' => 'Product not found.',
////            ], 404);
////        }
////
////        $validator = Validator::make($request->all(), [
////            'image' => 'required|image|mimes:jpg,jpeg,png,webp|max:4096',
////        ]);
////
////        if ($validator->fails()) {
////            return response()->json([
////                'message' => 'Validation error.',
////                'errors' => $validator->errors(),
////            ], 422);
////        }
////
////        DB::beginTransaction();
////
////        try {
////            $relativeDirectory = 'menu item images/' . $company->id;
////            $absoluteDirectory = base_path('assets/' . $relativeDirectory);
////
////            if (!File::exists($absoluteDirectory)) {
////                File::makeDirectory($absoluteDirectory, 0755, true);
////            }
////
////            if (!empty($product->image_path)) {
////                $oldRelativePath = ltrim(str_replace('assets/', '', $product->image_path), '/');
////                $oldAbsolutePath = base_path('assets/' . $oldRelativePath);
////
////                if (File::exists($oldAbsolutePath) && File::isFile($oldAbsolutePath)) {
////                    File::delete($oldAbsolutePath);
////                }
////            }
////
////            $file = $request->file('image');
////            $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
////
////            $safeName = Str::slug($product->name ?: 'menu-item');
////            $filename = $safeName . '-' . $product->id . '-' . time() . '.' . $extension;
////
////            $file->move($absoluteDirectory, $filename);
////
////            $relativePath = $relativeDirectory . '/' . $filename;
////
////            $product->image_path = 'assets/' . $relativePath;
////            $product->image_url = url('api/assets/' . str_replace(' ', '%20', $relativePath));
////            $product->save();
////
////            DB::commit();
////
////            return response()->json([
////                'message' => 'Menu item image uploaded successfully.',
////                'data' => $this->loadProduct($product->id),
////                'image_path' => $product->image_path,
////                'image_url' => $product->image_url,
////            ], 200);
////        } catch (\Exception $e) {
////            DB::rollBack();
////
////            return response()->json([
////                'message' => 'Something went wrong while uploading menu item image.',
////                'error' => $e->getMessage(),
////            ], 500);
////        }
////    }
////
////    public function deleteImage(string $id)
////    {
////        $company = app('company');
////
////        $result = hasPermission(auth()->user()->role_id, 'product_management', $company->id);
////        if ($result !== true) return $result;
////
////        $product = Product::where('company_id', $company->id)
////            ->where('id', $id)
////            ->first();
////
////        if (!$product) {
////            return response()->json([
////                'message' => 'Product not found.',
////            ], 404);
////        }
////
////        DB::beginTransaction();
////
////        try {
////            if (!empty($product->image_path)) {
////                $oldRelativePath = ltrim(str_replace('assets/', '', $product->image_path), '/');
////                $oldAbsolutePath = base_path('assets/' . $oldRelativePath);
////
////                if (File::exists($oldAbsolutePath) && File::isFile($oldAbsolutePath)) {
////                    File::delete($oldAbsolutePath);
////                }
////            }
////
////            $product->image_path = null;
////            $product->image_url = null;
////            $product->save();
////
////            DB::commit();
////
////            return response()->json([
////                'message' => 'Menu item image removed successfully.',
////                'data' => $this->loadProduct($product->id),
////            ], 200);
////        } catch (\Exception $e) {
////            DB::rollBack();
////
////            return response()->json([
////                'message' => 'Something went wrong while removing menu item image.',
////                'error' => $e->getMessage(),
////            ], 500);
////        }
////    }
//
//
//    public function uploadImage(Request $request, string $id)
//    {
//        $company = app('company');
//
//        $result = hasPermission(auth()->user()->role_id, 'product_management', $company->id);
//        if ($result !== true) {
//            return $result;
//        }
//
//        $product = Product::where('company_id', $company->id)
//            ->where('id', $id)
//            ->first();
//
//        if (!$product) {
//            return response()->json([
//                'message' => 'Product not found.',
//            ], 404);
//        }
//
//        $validator = Validator::make($request->all(), [
//            'image' => 'required|image|mimes:jpg,jpeg,png,webp|max:4096',
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
//            $file = $request->file('image');
//
//            if (!$file || !$file->isValid()) {
//                return response()->json([
//                    'message' => 'Invalid uploaded image.',
//                    'error' => $file ? $file->getErrorMessage() : 'No file received.',
//                ], 422);
//            }
//
//            /*
//             |--------------------------------------------------------------------------
//             | Public assets folder
//             |--------------------------------------------------------------------------
//             | Localhost/XAMPP:
//             | base_path() = C:/xampp/htdocs/salexaapi/api
//             | assets root = C:/xampp/htdocs/salexaapi/assets
//             |
//             | Nginx:
//             | base_path() = C:/salexaapi/api
//             | assets root = C:/salexaapi/assets
//             |--------------------------------------------------------------------------
//             */
//            $relativeDirectory = 'menu-item-images/' . $company->id;
//            $absoluteDirectory = $this->publicAssetsPath($relativeDirectory);
//
//            if (!File::exists($absoluteDirectory)) {
//                File::makeDirectory($absoluteDirectory, 0775, true);
//            }
//
//            /*
//             * Delete old image.
//             */
//            if (!empty($product->image_path)) {
//                $this->deleteStoredAsset($product->image_path);
//            }
//
//            $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
//
//            $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
//            if (!in_array($extension, $allowedExtensions, true)) {
//                $extension = 'jpg';
//            }
//
//            $safeName = Str::slug($product->name ?: 'menu-item');
//            $filename = $safeName . '-' . $product->id . '-' . time() . '.' . $extension;
//
//            $file->move($absoluteDirectory, $filename);
//
//            $fullPath = $absoluteDirectory . DIRECTORY_SEPARATOR . $filename;
//
//            @chmod($absoluteDirectory, 0775);
//            @chmod($fullPath, 0664);
//
//            if (!File::exists($fullPath) || !File::isFile($fullPath)) {
//                throw new \Exception('Image file was not saved correctly.');
//            }
//
//            if (@getimagesize($fullPath) === false) {
//                File::delete($fullPath);
//
//                return response()->json([
//                    'message' => 'Uploaded file was saved but is not a valid image.',
//                ], 422);
//            }
//
//            $relativePath = $relativeDirectory . '/' . $filename;
//
//            $product->image_path = 'assets/' . $relativePath;
//            $product->image_url = $this->publicAssetUrl($relativePath);
//
//            $product->save();
//
//            DB::commit();
//
//            return response()->json([
//                'message' => 'Menu item image uploaded successfully.',
//                'data' => $this->loadProduct($product->id),
//                'image_path' => $product->image_path,
//                'image_url' => $product->image_url,
//            ], 200);
//        } catch (\Exception $e) {
//            DB::rollBack();
//
//            return response()->json([
//                'message' => 'Something went wrong while uploading menu item image.',
//                'error' => $e->getMessage(),
//            ], 500);
//        }
//    }
//
//    public function deleteImage(string $id)
//    {
//        $company = app('company');
//
//        $result = hasPermission(auth()->user()->role_id, 'product_management', $company->id);
//        if ($result !== true) {
//            return $result;
//        }
//
//        $product = Product::where('company_id', $company->id)
//            ->where('id', $id)
//            ->first();
//
//        if (!$product) {
//            return response()->json([
//                'message' => 'Product not found.',
//            ], 404);
//        }
//
//        DB::beginTransaction();
//
//        try {
//            if (!empty($product->image_path)) {
//                $this->deleteStoredAsset($product->image_path);
//            }
//
//            $product->image_path = null;
//            $product->image_url = null;
//            $product->save();
//
//            DB::commit();
//
//            return response()->json([
//                'message' => 'Menu item image removed successfully.',
//                'data' => $this->loadProduct($product->id),
//            ], 200);
//        } catch (\Exception $e) {
//            DB::rollBack();
//
//            return response()->json([
//                'message' => 'Something went wrong while removing menu item image.',
//                'error' => $e->getMessage(),
//            ], 500);
//        }
//    }
//
//    /**
//     * Encode each part of a URL path safely.
//     *
//     * Example:
//     * menu-item-images/company id/file name.png
//     * becomes:
//     * menu-item-images/company%20id/file%20name.png
//     */
//    private function publicAssetsRoot(): string
//    {
//        /*
//         * Laravel app folder:
//         * C:/xampp/htdocs/salexaapi/api
//         *
//         * Public project folder:
//         * C:/xampp/htdocs/salexaapi
//         *
//         * So assets must be saved one level above /api.
//         */
//        return dirname(base_path()) . DIRECTORY_SEPARATOR . 'assets';
//    }
//
//    private function publicAssetsPath(string $relativeDirectory): string
//    {
//        $relativeDirectory = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativeDirectory);
//
//        return $this->publicAssetsRoot() . DIRECTORY_SEPARATOR . $relativeDirectory;
//    }
//
//    private function publicAssetUrl(string $relativePath): string
//    {
//        $relativePath = $this->encodeUrlPath($relativePath);
//
//        $host = rtrim(request()->getSchemeAndHttpHost(), '/');
//
//        /*
//         * Localhost/XAMPP API base URL is usually:
//         * http://localhost/salexaapi/api
//         *
//         * But public assets are:
//         * http://localhost/salexaapi/assets
//         *
//         * So remove trailing /api from the base path.
//         */
//        $basePath = rtrim(request()->getBaseUrl(), '/');
//
//        if (Str::endsWith($basePath, '/api')) {
//            $basePath = Str::beforeLast($basePath, '/api');
//        }
//
//        return $host . $basePath . '/assets/' . $relativePath;
//    }
//
//    private function deleteStoredAsset(?string $path): void
//    {
//        if (empty($path)) {
//            return;
//        }
//
//        $relativePath = ltrim(str_replace('\\', '/', $path), '/');
//
//        if (Str::startsWith($relativePath, 'assets/')) {
//            $relativePath = Str::after($relativePath, 'assets/');
//        }
//
//        $absolutePath = $this->publicAssetsPath(dirname($relativePath))
//            . DIRECTORY_SEPARATOR
//            . basename($relativePath);
//
//        if (File::exists($absolutePath) && File::isFile($absolutePath)) {
//            File::delete($absolutePath);
//        }
//    }
//
//    private function encodeUrlPath(string $path): string
//    {
//        $path = str_replace('\\', '/', $path);
//
//        return collect(explode('/', $path))
//            ->map(fn($part) => rawurlencode($part))
//            ->implode('/');
//    }
//}
//
//
//namespace Modules\Product\Http\Controllers;
//
//use App\Http\Controllers\Controller;
//use App\Services\ProductIdentityGenerator;
//use Illuminate\Http\Request;
//use Illuminate\Support\Facades\DB;
//use Illuminate\Support\Facades\Validator;
//use Illuminate\Support\Facades\Schema;
//use Illuminate\Support\Str;
//use Illuminate\Validation\Rule;
//use Modules\Company\Models\AuditTrail;
//use Modules\Currency\Models\Currency;
//use App\Services\CompanySettingService;
//use Modules\Product\Models\PriceLevel;
//use Modules\Product\Models\Product;
//use Modules\Product\Models\ProductBarcode;
//use Modules\Product\Models\ProductBreakRule;
//use Modules\Product\Models\ProductCost;
//use Modules\Product\Models\ProductPrice;
//use Modules\Product\Models\ProductStock;
//use Modules\Product\Models\ProductSupplier;
//use Modules\Product\Models\StockMovement;
//use Modules\Product\Models\Uom;
//use Illuminate\Support\Facades\File;
//use function App\Helpers\hasPermission;
//
//class ProductController extends Controller
//{
//
//    public function index(Request $request)
//    {
//        $company = app('company');
//        $branchId = auth()->user()->branch_id;
//
//        $query = Product::where('company_id', $company->id)
//            ->with([
//                'category',
//                'supplier',
//                'parent',
//                'children',
//                'subCategory',
//                'uom',
//
//                // Optional legacy single tax from products.tax_id
//                'tax',
//
//                // New real many-tax relationships
//                'taxes.tax',
//                'productTaxes.tax',
//
//                'prices' => function ($query) use ($branchId) {
//                    $query->where(function ($sub) use ($branchId) {
//                        $sub->where('branch_id', $branchId)
//                            ->orWhereNull('branch_id');
//                    })
//                        ->where('is_active', true)
//                        ->with('priceLevel')
//                        ->orderByRaw(
//                            'CASE WHEN branch_id = ? THEN 0 ELSE 1 END',
//                            [$branchId]
//                        )
//                        ->orderByDesc('is_default');
//                },
//
//                'stocks' => function ($query) use ($branchId) {
//                    $query->where('branch_id', $branchId);
//                },
//
//                'costs' => function ($query) use ($branchId) {
//                    $query->where('branch_id', $branchId);
//                },
//
//                'barcodes',
//                'discounts',
//                'childBreakRule.parentProduct',
//            ])
//            ->latest();
//
//        if ($request->filled('q')) {
//            $q = $request->q;
//
//            $query->where(function ($sub) use ($q) {
//                $sub->where('name', 'like', "%{$q}%")
//                    ->orWhere('code', 'like', "%{$q}%")
//                    ->orWhere('sku', 'like', "%{$q}%")
//                    ->orWhere('barcode', 'like', "%{$q}%");
//            });
//        }
//
//        if ($request->filled('category_id')) {
//            $query->where('category_id', $request->category_id);
//        }
//
//        if ($request->filled('supplier_id')) {
//            $query->where('supplier_id', $request->supplier_id);
//        }
//        if ($request->filled('sub_category_id')) {
//            $query->where('sub_category_id', $request->sub_category_id);
//        }
//
//        if ($request->filled('product_type') || $request->filled('product_types')) {
//            $productTypes = $request->input(
//                'product_types',
//                $request->input('product_type')
//            );
//
//            if (is_string($productTypes)) {
//                $productTypes = explode(',', $productTypes);
//            }
//
//            $productTypes = collect($productTypes)
//                ->map(fn($type) => trim($type))
//                ->filter()
//                ->values()
//                ->all();
//
//            if (count($productTypes) > 1) {
//                $query->whereIn('product_type', $productTypes);
//            } elseif (count($productTypes) === 1) {
//                $query->where('product_type', $productTypes[0]);
//            }
//        }
//
//        if ($request->filled('is_active')) {
//            $query->where(
//                'is_active',
//                filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)
//            );
//        }
//
//        if ($request->filled('stock_usage_type') && Schema::hasColumn('products', 'stock_usage_type')) {
//            $query->where('stock_usage_type', $request->stock_usage_type);
//        }
//
//        if ($request->filled('default_issue_destination') && Schema::hasColumn('products', 'default_issue_destination')) {
//            $query->where('default_issue_destination', $request->default_issue_destination);
//        }
//
//        return response()->json([
//            'message' => 'Products fetched successfully.',
//            'data' => $query->paginate($request->per_page ?? 15),
//        ]);
//    }
//
//    public function store(Request $request, ProductIdentityGenerator $identityGenerator)
//    {
//        $company = app('company');
//
//        $result = hasPermission(auth()->user()->role_id, 'product_management', $company->id);
//        if ($result !== true) return $result;
//
//        $validator = Validator::make($request->all(), [
//            'category_id' => 'nullable|exists:product_categories,id',
//            'supplier_id' => 'nullable|exists:suppliers,id',
//            'parent_product_id' => 'nullable|exists:products,id',
//            'base_uom_id' => 'required|exists:uoms,id',
//            'sub_category_id' => [
//                'nullable',
//                Rule::exists('product_sub_categories', 'id')->where('company_id', $company->id),
//            ],
//            'tax_id' => 'nullable|exists:taxes,id',
//
//            'name' => [
//                'required',
//                'string',
//                'max:255',
//                Rule::unique('products', 'name')->where('company_id', $company->id),
//            ],
//            'code' => [
//                'nullable',
//                'string',
//                'max:100',
//                Rule::unique('products', 'code')->where('company_id', $company->id),
//            ],
//            'sku' => [
//                'nullable',
//                'string',
//                'max:100',
//                Rule::unique('products', 'sku')->where('company_id', $company->id),
//            ],
//            'barcode' => [
//                'nullable',
//                'string',
//                'max:100',
//                Rule::unique('products', 'barcode')->where('company_id', $company->id),
//            ],
//
//            'description' => 'nullable|string',
//            'product_type' => 'required|in:simple,parent,child,service,raw_material,finished_good,semi_finished,packaging,consumable',
//
//            'stock_tracking_enabled' => 'nullable|boolean',
//            'allow_negative_stock' => 'nullable|boolean',
//            'allow_discount' => 'nullable|boolean',
//            'taxable' => 'nullable|boolean',
//            'is_active' => 'nullable|boolean',
//
//            'stock_usage_type' => 'nullable|in:turnover,non_turnover',
//            'default_issue_destination' => 'nullable|in:front,department,expense,production,wastage',
//            'expense_account_code' => 'nullable|string|max:80',
//
//            'reorder_level' => 'nullable|numeric|min:0',
//            'minimum_stock' => 'nullable|numeric|min:0',
//            'maximum_stock' => 'nullable|numeric|min:0',
//
//            'prices' => 'nullable|array',
//            'prices.*.branch_id' => 'nullable|exists:company_branches,id',
//            'prices.*.price_level_id' => 'required|exists:price_levels,id',
//            'prices.*.price' => 'required|numeric|min:0',
//            'prices.*.currency' => 'nullable|string|max:10',
//            'prices.*.is_default' => 'nullable|boolean',
//
//            'barcodes' => 'nullable|array',
//            'barcodes.*.barcode' => 'required|string|max:100',
//            'barcodes.*.type' => 'nullable|in:primary,alternative,supplier',
//
//            'suppliers' => 'nullable|array',
//            'suppliers.*.supplier_id' => 'required|exists:suppliers,id',
//            'suppliers.*.supplier_code' => 'nullable|string|max:100',
//            'suppliers.*.last_purchase_price' => 'nullable|numeric|min:0',
//            'suppliers.*.is_default' => 'nullable|boolean',
//
//            'discount_ids' => 'nullable|array',
//            'discount_ids.*' => 'exists:discounts,id',
//
//            'break_rule' => 'nullable|array',
//            'break_rule.parent_product_id' => 'required_with:break_rule|exists:products,id',
//            'break_rule.parent_quantity' => 'required_with:break_rule|numeric|min:0.0001',
//            'break_rule.units_per_parent' => 'required_with:break_rule|numeric|min:0.0001',
//            'break_rule.is_auto_break' => 'nullable|boolean',
//
//            'opening_stocks' => 'nullable|array',
//            'opening_stocks.*.branch_id' => 'required|exists:company_branches,id',
//            'opening_stocks.*.quantity' => 'required|numeric|min:0',
//            'opening_stocks.*.unit_cost' => 'nullable|numeric|min:0',
//            'opening_stocks.*.currency' => 'required',
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
//            $productData = $identityGenerator->generateAllForProductCreate(
//                $company->id,
//                auth()->user()->branch_id,
//                [
//                    'company_id' => $company->id,
//                    'category_id' => $request->category_id,
//                    'supplier_id' => $request->supplier_id,
//                    'sub_category_id' => $request->sub_category_id,
//                    'parent_product_id' => $request->parent_product_id,
//                    'base_uom_id' => $request->base_uom_id,
//                    'tax_id' => $request->tax_id,
//
//                    'name' => $request->name,
//                    'code' => $request->code,
//                    'sku' => $request->sku,
//                    'barcode' => $request->barcode,
//                    'description' => $request->description,
//                    'product_type' => $request->product_type,
//
//                    'stock_tracking_enabled' => $request->stock_tracking_enabled ?? true,
//                    'allow_negative_stock' => $request->allow_negative_stock ?? false,
//                    'allow_discount' => $request->allow_discount ?? true,
//                    'taxable' => $request->taxable ?? false,
//                    'is_active' => $request->is_active ?? true,
//
//                    'stock_usage_type' => $this->resolveProductStockUsageTypeFromRequest($request),
//                    'default_issue_destination' => $this->resolveProductDefaultIssueDestinationFromRequest($request),
//                    'expense_account_code' => $request->expense_account_code,
//
//                    'reorder_level' => $request->reorder_level ?? 0,
//                    'minimum_stock' => $request->minimum_stock ?? 0,
//                    'maximum_stock' => $request->maximum_stock ?? 0,
//                ]
//            );
//
//            $identityGenerator->assertProductIdentityIsComplete($productData);
//
//            $product = Product::create($productData);
//
//            $this->syncPrices($product, $request->prices ?? []);
//            $this->syncBarcodes($product, $request->barcodes ?? []);
//            $this->syncSuppliers($product, $request->suppliers ?? []);
//            $this->syncDiscounts($product, $request->discount_ids ?? []);
//
//            if ($request->filled('break_rule')) {
//                $this->saveBreakRule($product, $request->break_rule);
//            }
//
//            if ($request->filled('opening_stocks')) {
//                foreach ($request->opening_stocks as $stockData) {
//                    $this->receiveProductStock(
//                        $product,
//                        $stockData['branch_id'],
//                        $stockData['quantity'],
//                        $stockData['unit_cost'] ?? 0,
//                        $stockData['currency'] ?? null,
//                        'opening',
//                        null,
//                        'Opening stock'
//                    );
//                }
//            }
//
//            DB::commit();
//
//            return response()->json([
//                'message' => 'Product created successfully.',
//                'data' => $this->loadProduct($product->id),
//            ], 201);
//        } catch (\Exception $e) {
//            DB::rollBack();
//
//            return response()->json([
//                'message' => 'Something went wrong while creating product.',
//                'error' => $e->getMessage(),
//            ], 500);
//        }
//    }
//
//    public function show(string $id)
//    {
//        $product = $this->loadProduct($id);
//
//        if (!$product) {
//            return response()->json(['message' => 'Product not found.'], 404);
//        }
//
//        return response()->json([
//            'message' => 'Product fetched successfully.',
//            'data' => $product,
//        ]);
//    }
//
//    public function update(Request $request, string $id)
//    {
//        $company = app('company');
//        $result = hasPermission(auth()->user()->role_id, 'product_management', $company->id);
//        if ($result !== true) return $result;
//
//        $product = Product::where('company_id', $company->id)
//            ->where('id', $id)
//            ->first();
//
//        if (!$product) {
//            return response()->json(['message' => 'Product not found.'], 404);
//        }
//
//        $validator = Validator::make($request->all(), [
//            'category_id' => 'nullable|exists:product_categories,id',
//            'supplier_id' => 'nullable|exists:suppliers,id',
//            'parent_product_id' => 'nullable|exists:products,id',
//            'base_uom_id' => 'required|exists:uoms,id',
//            'tax_id' => 'nullable|exists:taxes,id',
//            'sub_category_id' => [
//                'nullable',
//                Rule::exists('product_sub_categories', 'id')->where('company_id', $company->id),
//            ],
//
//            'name' => [
//                'required',
//                'string',
//                'max:255',
//                Rule::unique('products', 'name')->where('company_id', $company->id)->ignore($product->id),
//            ],
//            'code' => [
//                'required',
//                'string',
//                'max:100',
//                Rule::unique('products', 'code')->where('company_id', $company->id)->ignore($product->id),
//            ],
//            'sku' => [
//                'required',
//                'string',
//                'max:100',
//                Rule::unique('products', 'sku')->where('company_id', $company->id)->ignore($product->id),
//            ],
//            'barcode' => [
//                'nullable',
//                'string',
//                'max:100',
//                Rule::unique('products', 'barcode')->where('company_id', $company->id)->ignore($product->id),
//            ],
//
//            'description' => 'nullable|string',
//            'product_type' => 'required|in:simple,parent,child,service,raw_material,finished_good,semi_finished,packaging,consumable',
//
//
//            'stock_tracking_enabled' => 'nullable|boolean',
//            'allow_negative_stock' => 'nullable|boolean',
//            'allow_discount' => 'nullable|boolean',
//            'taxable' => 'nullable|boolean',
//            'is_active' => 'nullable|boolean',
//
//            'stock_usage_type' => 'nullable|in:turnover,non_turnover',
//            'default_issue_destination' => 'nullable|in:front,department,expense,production,wastage',
//            'expense_account_code' => 'nullable|string|max:80',
//
//            'reorder_level' => 'nullable|numeric|min:0',
//            'minimum_stock' => 'nullable|numeric|min:0',
//            'maximum_stock' => 'nullable|numeric|min:0',
//        ]);
//
//        if ($validator->fails()) {
//            return response()->json([
//                'message' => 'Validation error.',
//                'errors' => $validator->errors(),
//            ], 422);
//        }
//
//        $product->update([
//            'category_id' => $request->category_id,
//            'supplier_id' => $request->supplier_id,
//            'sub_category_id' => $request->sub_category_id,
//            'parent_product_id' => $request->parent_product_id,
//            'base_uom_id' => $request->base_uom_id,
//            'tax_id' => $request->tax_id,
//            'name' => $request->name,
//            'code' => $request->code,
//            'sku' => $request->sku,
//            'barcode' => $request->barcode,
//            'description' => $request->description,
//            'product_type' => $request->product_type,
//            'stock_tracking_enabled' => $request->stock_tracking_enabled ?? true,
//            'allow_negative_stock' => $request->allow_negative_stock ?? false,
//            'allow_discount' => $request->allow_discount ?? true,
//            'taxable' => $request->taxable ?? false,
//            'is_active' => $request->is_active ?? true,
//            'stock_usage_type' => $this->resolveProductStockUsageTypeFromRequest($request),
//            'default_issue_destination' => $this->resolveProductDefaultIssueDestinationFromRequest($request),
//            'expense_account_code' => $request->expense_account_code,
//            'reorder_level' => $request->reorder_level ?? 0,
//            'minimum_stock' => $request->minimum_stock ?? 0,
//            'maximum_stock' => $request->maximum_stock ?? 0,
//        ]);
//
//        return response()->json([
//            'message' => 'Product updated successfully.',
//            'data' => $this->loadProduct($product->id),
//        ]);
//    }
//
//    public function destroy(string $id)
//    {
//        $company = app('company');
//        $result = hasPermission(auth()->user()->role_id, 'product_management', $company->id);
//        if ($result !== true) return $result;
//
//        $product = Product::where('company_id', $company->id)
//            ->where('id', $id)
//            ->first();
//
//        if (!$product) {
//            return response()->json(['message' => 'Product not found.'], 404);
//        }
//
//        $product->delete();
//
//        return response()->json([
//            'message' => 'Product deleted successfully.',
//        ]);
//    }
//
////    public function addPrice(Request $request, string $id)
////    {
////        $company = app('company');
////        $user = auth()->user();
////        $result = hasPermission(auth()->user()->role_id, 'add_price', $company->id);
////        if ($result !== true) return $result;
////
////        $product = Product::where('company_id', $company->id)
////            ->where('id', $id)
////            ->first();
////
////        if (!$product) {
////            return response()->json([
////                'message' => 'Product not found.',
////            ], 404);
////        }
////
////        $validator = Validator::make($request->all(), [
////            'price_level_id' => 'required|exists:price_levels,id',
////            'branch_id' => 'nullable|exists:company_branches,id',
////            'price' => 'required|numeric|min:0',
////            'currency' => 'required|string|size:3',
////            'is_default' => 'nullable|boolean',
////            'is_active' => 'nullable|boolean',
////            'starts_at' => 'nullable|date',
////            'ends_at' => 'nullable|date|after_or_equal:starts_at',
////        ]);
////
////        if ($validator->fails()) {
////            return response()->json([
////                'message' => 'Validation error.',
////                'errors' => $validator->errors(),
////            ], 422);
////        }
////
////        DB::beginTransaction();
////
////        try {
////            $branchId = $request->branch_id ?: ($user->branch_id ?? null);
////
////            $currencyCode = $this->validateSelectedBaseCurrency(
////                $company->id,
////                $branchId,
////                $request->currency
////            );
////
////            if ($request->boolean('is_default')) {
////                ProductPrice::where('company_id', $company->id)
////                    ->where('product_id', $product->id)
////                    ->where('price_level_id', $request->price_level_id)
////                    ->where('branch_id', $branchId)
////                    ->update([
////                        'is_default' => false,
////                    ]);
////            }
////
////            $price = ProductPrice::create([
////                'company_id' => $company->id,
////                'branch_id' => $branchId,
////                'product_id' => $product->id,
////                'price_level_id' => $request->price_level_id,
////                'price' => (float) $request->price,
////                'currency' => $currencyCode,
////                'is_default' => $request->boolean('is_default', false),
////                'is_active' => $request->boolean('is_active', true),
////                'starts_at' => $request->starts_at,
////                'ends_at' => $request->ends_at,
////            ]);
////
////            DB::commit();
////
////            return response()->json([
////                'message' => 'Product price added successfully.',
////                'currency' => $currencyCode,
////                'data' => $price,
////            ], 201);
////        } catch (\Exception $e) {
////            DB::rollBack();
////
////            return response()->json([
////                'message' => 'Something went wrong while adding product price.',
////                'error' => $e->getMessage(),
////            ], 500);
////        }
////    }
//    public function addPrice(Request $request, string $id)
//    {
//        $company = app('company');
//        $user = auth()->user();
//
//        $result = hasPermission($user->role_id, 'add_price', $company->id);
//        if ($result !== true) return $result;
//
//        $product = Product::where('company_id', $company->id)
//            ->where('id', $id)
//            ->first();
//
//        if (!$product) {
//            return response()->json([
//                'message' => 'Product not found.',
//            ], 404);
//        }
//
//        $validator = Validator::make($request->all(), [
//            'price_level_id' => 'required|exists:price_levels,id',
//            'branch_id' => 'nullable|exists:company_branches,id',
//            'price' => 'required|numeric|min:0',
//            'currency' => 'required|string|size:3',
//            'is_default' => 'nullable|boolean',
//            'is_active' => 'nullable|boolean',
//            'starts_at' => 'nullable|date',
//            'ends_at' => 'nullable|date|after_or_equal:starts_at',
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
//            $branchId = $request->branch_id ?: ($user->branch_id ?? null);
//
//            $currencyCode = $this->validateSelectedBaseCurrency(
//                $company->id,
//                $branchId,
//                $request->currency
//            );
//
//            if ($request->boolean('is_default')) {
//                $defaultQuery = ProductPrice::withoutGlobalScopes()
//                    ->where('company_id', $company->id)
//                    ->where('product_id', $product->id)
//                    ->where('price_level_id', $request->price_level_id);
//
//                if ($branchId) {
//                    $defaultQuery->where('branch_id', $branchId);
//                } else {
//                    $defaultQuery->whereNull('branch_id');
//                }
//
//                $defaultQuery->update([
//                    'is_default' => false,
//                ]);
//            }
//
//            $priceQuery = ProductPrice::withoutGlobalScopes()
//                ->where('company_id', $company->id)
//                ->where('product_id', $product->id)
//                ->where('price_level_id', $request->price_level_id);
//
//            if ($branchId) {
//                $priceQuery->where('branch_id', $branchId);
//            } else {
//                $priceQuery->whereNull('branch_id');
//            }
//
//            $price = $priceQuery->first();
//
//            $payload = [
//                'company_id' => $company->id,
//                'branch_id' => $branchId,
//                'product_id' => $product->id,
//                'price_level_id' => $request->price_level_id,
//                'price' => (float)$request->price,
//                'currency' => $currencyCode,
//                'is_default' => $request->boolean('is_default', false),
//                'is_active' => $request->boolean('is_active', true),
//                'starts_at' => $request->starts_at,
//                'ends_at' => $request->ends_at,
//            ];
//
//            if ($price) {
//                $price->fill($payload);
//
//                if (array_key_exists('deleted_at', $price->getAttributes())) {
//                    $price->deleted_at = null;
//                }
//
//                $price->save();
//                $statusCode = 200;
//                $message = 'Product price updated successfully.';
//            } else {
//                $price = ProductPrice::create($payload);
//                $statusCode = 201;
//                $message = 'Product price added successfully.';
//            }
//
//            DB::commit();
//
//            return response()->json([
//                'message' => $message,
//                'currency' => $currencyCode,
//                'data' => $price,
//            ], $statusCode);
//        } catch (\Exception $e) {
//            DB::rollBack();
//
//            return response()->json([
//                'message' => 'Something went wrong while saving product price.',
//                'error' => $e->getMessage(),
//            ], 500);
//        }
//    }
//
//    public function deletePrice(string $productId, string $priceId)
//    {
//        $company = app('company');
//        $result = hasPermission(auth()->user()->role_id, 'delete_price', $company->id);
//        if ($result !== true) return $result;
//
//        $price = ProductPrice::where('company_id', $company->id)
//            ->where('product_id', $productId)
//            ->where('id', $priceId)
//            ->first();
//
//        if (!$price) {
//            return response()->json(['message' => 'Product price not found.'], 404);
//        }
//
//        $price->delete();
//
//        return response()->json([
//            'message' => 'Product price deleted successfully.',
//        ]);
//    }
//
//
//    public function receiveStock(Request $request, string $id, CompanySettingService $settings)
//    {
//        $company = app('company');
//        $user = auth()->user();
//
//        $result = hasPermission($user->role_id, 'receive_stock', $company->id);
//        if ($result !== true) return $result;
//
//        $product = Product::where('company_id', $company->id)
//            ->where('id', $id)
//            ->first();
//
//        if (!$product) {
//            return response()->json([
//                'message' => 'Product not found.',
//            ], 404);
//        }
//
//        $validator = Validator::make($request->all(), [
//            'branch_id' => 'required|exists:company_branches,id',
//            'quantity' => 'required|numeric|min:0.0001',
//            'unit_cost' => 'required|numeric|min:0',
//            'currency' => 'required|string|size:3',
//            'notes' => 'nullable|string',
//        ]);
//
//        if ($validator->fails()) {
//            return response()->json([
//                'message' => 'Validation error.',
//                'errors' => $validator->errors(),
//            ], 422);
//        }
//
//        $branchId = $request->branch_id ?: ($user->branch_id ?? null);
//
//        $stockArea = (
//            $settings->isFastFoodMode($company->id) &&
//            $settings->isBohEnabled($company->id) &&
//            $settings->get($company->id, 'receive_stock_to_boh_first', true)
//        ) ? 'back' : 'front';
//
//        DB::beginTransaction();
//
//        try {
//            $currencyCode = $this->validateSelectedBaseCurrency(
//                $company->id,
//                $branchId,
//                $request->currency
//            );
//
//            $quantity = (float)$request->quantity;
//            $unitCost = (float)$request->unit_cost;
//            $totalCost = $quantity * $unitCost;
//
//            $stock = $this->getOrCreateStock($product, $branchId, $stockArea);
//
//            $stock->quantity_on_hand += $quantity;
//            $stock->available_quantity += $quantity;
//            $stock->save();
//
//            $cost = ProductCost::firstOrCreate(
//                [
//                    'company_id' => $product->company_id,
//                    'branch_id' => $branchId,
//                    'product_id' => $product->id,
//                ],
//                [
//                    'average_cost' => 0,
//                    'last_cost' => 0,
//                    'total_quantity' => 0,
//                    'total_value' => 0,
//                    'currency' => $currencyCode,
//                ]
//            );
//
//            $previousQuantity = (float)$cost->total_quantity;
//            $previousValue = (float)$cost->total_value;
//
//            $newTotalQuantity = $previousQuantity + $quantity;
//            $newTotalValue = $previousValue + $totalCost;
//
//            $cost->last_cost = $unitCost;
//            $cost->total_quantity = $newTotalQuantity;
//            $cost->total_value = $newTotalValue;
//            $cost->average_cost = $newTotalQuantity > 0
//                ? $newTotalValue / $newTotalQuantity
//                : 0;
//            $cost->currency = $currencyCode;
//            $cost->save();
//
//            StockMovement::create([
//                'company_id' => $product->company_id,
//                'branch_id' => $branchId,
//                'product_id' => $product->id,
//                'movement_type' => $stockArea === 'back'
//                    ? 'boh_purchase_receive'
//                    : 'purchase',
//                'stock_area' => $stockArea,
//                'quantity_in' => $quantity,
//                'quantity_out' => 0,
//                'unit_cost' => $unitCost,
//                'total_cost' => $totalCost,
//                'currency' => $currencyCode,
//                'notes' => $request->notes,
//                'created_by' => auth()->id(),
//            ]);
//
//            DB::commit();
//
//            return response()->json([
//                'message' => $stockArea === 'back'
//                    ? 'Stock received into BOH/back stock successfully.'
//                    : 'Stock received successfully.',
//                'currency' => $currencyCode,
//                'stock_area' => $stockArea,
//                'data' => $this->loadProduct($product->id),
//            ], 200);
//        } catch (\Exception $e) {
//            DB::rollBack();
//
//            return response()->json([
//                'message' => 'Something went wrong while receiving stock.',
//                'error' => $e->getMessage(),
//            ], 500);
//        }
//    }
//
//    public function adjustStock(Request $request, string $id)
//    {
//        $company = app('company');
//
//        $result = hasPermission(auth()->user()->role_id, 'adjust_stock', $company->id);
//        if ($result !== true) return $result;
//
//        $product = Product::where('company_id', $company->id)
//            ->where('id', $id)
//            ->first();
//
//        if (!$product) {
//            return response()->json(['message' => 'Product not found.'], 404);
//        }
//
//        $validator = Validator::make($request->all(), [
//            'branch_id' => 'required|exists:company_branches,id',
//            'adjustment_type' => 'required|in:increase,decrease',
//            'stock_area' => 'nullable|in:front,back',
//            'quantity' => 'required|numeric|min:0.0001',
//            'notes' => 'nullable|string',
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
//            $stockArea = $request->input('stock_area', 'front');
//            $stock = $this->getOrCreateStock($product, $request->branch_id, $stockArea);
//
//            if ($request->adjustment_type === 'increase') {
//                $stock->quantity_on_hand += $request->quantity;
//                $stock->available_quantity += $request->quantity;
//
//                $quantityIn = $request->quantity;
//                $quantityOut = 0;
//            } else {
//                if (!$product->allow_negative_stock && $stock->available_quantity < $request->quantity) {
//                    DB::rollBack();
//
//                    return response()->json([
//                        'message' => 'Insufficient stock.',
//                    ], 422);
//                }
//
//                $stock->quantity_on_hand -= $request->quantity;
//                $stock->available_quantity -= $request->quantity;
//
//                $quantityIn = 0;
//                $quantityOut = $request->quantity;
//            }
//
//            $stock->save();
//
//            StockMovement::create([
//                'company_id' => $product->company_id,
//                'branch_id' => $request->branch_id,
//                'product_id' => $product->id,
//                'movement_type' => 'adjustment',
//                'stock_area' => $stockArea,
//                'quantity_in' => $quantityIn,
//                'quantity_out' => $quantityOut,
//                'unit_cost' => 0,
//                'total_cost' => 0,
//                'notes' => $request->notes,
//                'created_by' => auth()->id(),
//            ]);
//
//            DB::commit();
//
//            return response()->json([
//                'message' => 'Stock adjusted successfully.',
//                'data' => $this->loadProduct($product->id),
//            ]);
//        } catch (\Exception $e) {
//            DB::rollBack();
//
//            return response()->json([
//                'message' => 'Something went wrong while adjusting stock.',
//                'error' => $e->getMessage(),
//            ], 500);
//        }
//    }
//
//    public function adjustStockBulk(Request $request)
//    {
//        $company = app('company');
//        $user = auth()->user();
//        $result = hasPermission(auth()->user()->role_id, 'adjust_stock', $company->id);
//        if ($result !== true) return $result;
//
//        $validator = Validator::make($request->all(), [
//            'branch_id' => 'required|exists:company_branches,id',
//            'stock_area' => 'nullable|in:front,back',
//            'items' => 'required|array|min:1',
//            'items.*.product_id' => 'required|exists:products,id',
//            'items.*.quantity' => 'required|numeric|min:0',
//            'items.*.notes' => 'nullable|string',
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
//            $adjustedProducts = [];
//            $skippedProducts = [];
//            $movementIds = [];
//            $stockArea = $request->input('stock_area', 'front');
//
//            foreach ($request->items as $item) {
//                $product = Product::where('company_id', $company->id)
//                    ->where('id', $item['product_id'])
//                    ->first();
//
//                if (!$product) {
//                    $skippedProducts[] = [
//                        'product_id' => $item['product_id'],
//                        'message' => 'Product not found for this company.',
//                    ];
//                    continue;
//                }
//
//                $stock = $this->getOrCreateStock($product, $request->branch_id, $stockArea);
//
//                $previousQuantity = (float)$stock->quantity_on_hand;
//                $previousAvailable = (float)$stock->available_quantity;
//                $reservedQuantity = (float)$stock->reserved_quantity;
//
//                $newQuantity = (float)$item['quantity'];
//                $difference = $newQuantity - $previousQuantity;
//
//                if ($difference == 0) {
//                    $skippedProducts[] = [
//                        'product_id' => $product->id,
//                        'product_name' => $product->name,
//                        'sku' => $product->sku,
//                        'previous_quantity' => $previousQuantity,
//                        'new_quantity' => $newQuantity,
//                        'message' => 'No quantity change detected.',
//                    ];
//                    continue;
//                }
//
//                if ($difference > 0) {
//                    $adjustmentType = 'increase';
//                    $quantityIn = abs($difference);
//                    $quantityOut = 0;
//                } else {
//                    $adjustmentType = 'decrease';
//                    $quantityIn = 0;
//                    $quantityOut = abs($difference);
//
//                    if (!$product->allow_negative_stock && $newQuantity < 0) {
//                        DB::rollBack();
//
//                        return response()->json([
//                            'message' => "Negative stock is not allowed for {$product->name}.",
//                        ], 422);
//                    }
//                }
//
//                $stock->quantity_on_hand = $newQuantity;
//
//                if ($product->allow_negative_stock) {
//                    $stock->available_quantity = $newQuantity - $reservedQuantity;
//                } else {
//                    $stock->available_quantity = max(0, $newQuantity - $reservedQuantity);
//                }
//
//                $stock->save();
//
//                $movement = StockMovement::create([
//                    'company_id' => $product->company_id,
//                    'branch_id' => $request->branch_id,
//                    'product_id' => $product->id,
//                    'movement_type' => 'adjustment',
//                    'stock_area' => $stockArea,
//                    'quantity_in' => $quantityIn,
//                    'quantity_out' => $quantityOut,
//                    'unit_cost' => 0,
//                    'total_cost' => 0,
//                    'notes' => $item['notes'] ?? null,
//                    'created_by' => $user?->id,
//                ]);
//
//                $movementIds[] = $movement->id;
//
//                $adjustedProducts[] = [
//                    'product_id' => $product->id,
//                    'product_name' => $product->name,
//                    'sku' => $product->sku,
//                    'code' => $product->code,
//                    'barcode' => $product->barcode,
//                    'previous_quantity' => $previousQuantity,
//                    'new_quantity' => $newQuantity,
//                    'difference' => $difference,
//                    'adjustment_type' => $adjustmentType,
//                    'quantity_in' => $quantityIn,
//                    'quantity_out' => $quantityOut,
//                    'previous_available_quantity' => $previousAvailable,
//                    'available_quantity' => $stock->available_quantity,
//                    'reserved_quantity' => $stock->reserved_quantity,
//                    'notes' => $item['notes'] ?? null,
//                    'stock_area' => $stockArea,
//                    'movement_id' => $movement->id,
//                ];
//            }
//
//            $this->writeAuditTrail($request, [
//                'company_id' => $company->id,
//                'branch_id' => $request->branch_id,
//                'action' => 'stock_adjusted_bulk',
//                'module' => 'inventory',
//                'description' => 'Bulk stock adjustment completed.',
//                'auditable_type' => StockMovement::class,
//                'auditable_id' => $movementIds[0] ?? null,
//                'old_values' => null,
//                'new_values' => [
//                    'adjusted_count' => count($adjustedProducts),
//                    'skipped_count' => count($skippedProducts),
//                ],
//                'changed_fields' => ['quantity_on_hand', 'available_quantity'],
//                'metadata' => [
//                    'movement_ids' => $movementIds,
//                    'adjusted' => $adjustedProducts,
//                    'skipped' => $skippedProducts,
//                ],
//            ]);
//
//            DB::commit();
//
//            return response()->json([
//                'message' => 'Bulk stock adjustment completed successfully.',
//                'data' => [
//                    'adjusted' => $adjustedProducts,
//                    'skipped' => $skippedProducts,
//                ],
//            ], 200);
//        } catch (\Exception $e) {
//            DB::rollBack();
//
//            $this->writeAuditTrail($request, [
//                'company_id' => $company->id,
//                'branch_id' => $request->branch_id,
//                'action' => 'stock_adjust_bulk_failed',
//                'module' => 'inventory',
//                'description' => 'Bulk stock adjustment failed.',
//                'auditable_type' => StockMovement::class,
//                'auditable_id' => null,
//                'old_values' => null,
//                'new_values' => [
//                    'items' => $request->items,
//                ],
//                'changed_fields' => ['quantity_on_hand'],
//                'status' => 'failed',
//                'error_message' => $e->getMessage(),
//                'metadata' => [
//                    'branch_id' => $request->branch_id,
//                ],
//            ]);
//
//            return response()->json([
//                'message' => 'Something went wrong while adjusting stock.',
//                'error' => $e->getMessage(),
//            ], 500);
//        }
//    }
//
//    public function stockAdjustmentHistory(Request $request)
//    {
//        $company = app('company');
//        $user = auth()->user();
//
//        $query = StockMovement::where('company_id', $company->id)
//            ->where('movement_type', 'adjustment')
//            ->with([
//                'product.category',
//                'product.uom',
//                'branch',
//                'createdBy',
//            ])
//            ->orderByDesc('created_at');
//
//        if ($request->filled('branch_id')) {
//            $query->where('branch_id', $request->branch_id);
//        } else if (!empty($user?->branch_id)) {
//            $query->where('branch_id', $user->branch_id);
//        }
//
//        if ($request->filled('product_id')) {
//            $query->where('product_id', $request->product_id);
//        }
//
//        if ($request->filled('stock_area')) {
//            $query->where('stock_area', $request->stock_area);
//        }
//
//        if ($request->filled('q')) {
//            $q = $request->q;
//
//            $query->whereHas('product', function ($sub) use ($q) {
//                $sub->where('name', 'like', "%{$q}%")
//                    ->orWhere('sku', 'like', "%{$q}%")
//                    ->orWhere('code', 'like', "%{$q}%")
//                    ->orWhere('barcode', 'like', "%{$q}%");
//            });
//        }
//
//        if ($request->filled('adjustment_type')) {
//            if ($request->adjustment_type === 'increase') {
//                $query->where('quantity_in', '>', 0);
//            }
//
//            if ($request->adjustment_type === 'decrease') {
//                $query->where('quantity_out', '>', 0);
//            }
//        }
//
//        if ($request->filled('date_from')) {
//            $query->whereDate('created_at', '>=', $request->date_from);
//        }
//
//        if ($request->filled('date_to')) {
//            $query->whereDate('created_at', '<=', $request->date_to);
//        }
//
//        $perPage = (int)$request->get('per_page', 50);
//
//        $movements = $query->paginate($perPage);
//
//        return response()->json([
//            'message' => 'Stock adjustment history fetched successfully.',
//            'data' => $movements,
//        ], 200);
//    }
//
//    public function breakProduct(Request $request, string $childProductId)
//    {
//        $company = app('company');
//
//        $child = Product::where('company_id', $company->id)
//            ->where('id', $childProductId)
//            ->first();
//
//        if (!$child) {
//            return response()->json([
//                'message' => 'Child product not found.',
//            ], 404);
//        }
//
//        $validator = Validator::make($request->all(), [
//            'branch_id' => 'required|exists:company_branches,id',
//            'break_times' => 'nullable|integer|min:1',
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
//            $this->breakChildProduct(
//                $child,
//                $request->branch_id,
//                $request->break_times ?? 1
//            );
//
//            DB::commit();
//
//            return response()->json([
//                'message' => 'Product broken successfully.',
//                'data' => $this->loadProduct($child->id),
//            ], 200);
//        } catch (\Exception $e) {
//            DB::rollBack();
//
//            return response()->json([
//                'message' => $e->getMessage(),
//            ], 422);
//        }
//    }
//
//
//    public function frontStock(Request $request)
//    {
//        return $this->stockByArea($request, 'front');
//    }
//
//    public function backStock(Request $request, CompanySettingService $settings)
//    {
//        $company = app('company');
//
//        if (!$settings->isFastFoodMode($company->id) || !$settings->isBohEnabled($company->id)) {
//            return response()->json([
//                'message' => 'BOH is not enabled for this company.',
//            ], 422);
//        }
//
//        return $this->stockByArea($request, 'back');
//    }
//
//    private function stockByArea(Request $request, string $stockArea)
//    {
//        $company = app('company');
//
//        $result = hasPermission(auth()->user()->role_id, 'view_stock_reports', $company->id);
//        if ($result !== true) return $result;
//
//        $perPage = (int)$request->get('per_page', 50);
//        $perPage = $perPage > 0 && $perPage <= 200 ? $perPage : 50;
//
//        $query = ProductStock::query()
//            ->where('company_id', $company->id)
//            ->where('stock_area', $stockArea)
//            ->with([
//                'branch',
//                'product.category',
//                'product.supplier',
//                'product.uom',
//                'product.costs',
//            ]);
//
//        if ($request->filled('branch_id')) {
//            $query->where('branch_id', $request->branch_id);
//        }
//
//        if ($request->filled('product_id')) {
//            $query->where('product_id', $request->product_id);
//        }
//
//        if ($request->filled('product_type')) {
//            $query->whereHas('product', function ($productQuery) use ($request) {
//                $productQuery->where('product_type', $request->product_type);
//            });
//        }
//
//        if ($request->filled('category_id')) {
//            $query->whereHas('product', function ($productQuery) use ($request) {
//                $productQuery->where('category_id', $request->category_id);
//            });
//        }
//
//        if ($request->filled('q')) {
//            $search = trim($request->q);
//
//            $query->whereHas('product', function ($productQuery) use ($search) {
//                $productQuery->where('name', 'like', "%{$search}%")
//                    ->orWhere('code', 'like', "%{$search}%")
//                    ->orWhere('sku', 'like', "%{$search}%")
//                    ->orWhere('barcode', 'like', "%{$search}%");
//            });
//        }
//
//        $rows = $query
//            ->orderByDesc('updated_at')
//            ->paginate($perPage);
//
//        $rows->getCollection()->transform(function ($stock) {
//            $cost = optional($stock->product)->costs
//                ? $stock->product->costs->firstWhere('branch_id', $stock->branch_id)
//                : null;
//
//            if (!$cost && optional($stock->product)->costs) {
//                $cost = $stock->product->costs->first();
//            }
//
//            $stock->setAttribute('cost', $cost);
//            $stock->setAttribute('average_cost', $cost?->average_cost);
//            $stock->setAttribute('last_cost', $cost?->last_cost);
//            $stock->setAttribute('total_value', $cost?->total_value);
//            $stock->setAttribute('currency', $cost?->currency);
//
//            return $stock;
//        });
//
//        return response()->json([
//            'message' => strtoupper($stockArea) . ' stock fetched successfully.',
//            'stock_area' => $stockArea,
//            'data' => $rows,
//        ]);
//    }
//
//
////    public function issueBackStockEnterprise(Request $request, CompanySettingService $settings)
////    {
////        $company = app('company');
////
////        if (!$settings->isFastFoodMode($company->id) || !$settings->isBohEnabled($company->id)) {
////            return response()->json([
////                'message' => 'BOH is not enabled for this company.',
////            ], 422);
////        }
////
////        $result = hasPermission(auth()->user()->role_id, 'boh_issue_stock', $company->id);
////        if ($result !== true) return $result;
////
////        $validator = Validator::make($request->all(), [
////            'branch_id' => 'required|exists:company_branches,id',
////            'notes' => 'nullable|string',
////            'items' => 'required|array|min:1',
////            'items.*.product_id' => 'required|exists:products,id',
////            'items.*.quantity' => 'required|numeric|min:0.0001',
////            'items.*.issue_destination' => 'nullable|in:auto,front,department,expense,production,wastage',
////            'items.*.department_name' => 'nullable|string|max:120',
////            'items.*.cost_center' => 'nullable|string|max:120',
////            'items.*.issue_purpose' => 'nullable|string|max:255',
////            'items.*.notes' => 'nullable|string',
////        ]);
////
////        if ($validator->fails()) {
////            return response()->json([
////                'message' => 'Validation error.',
////                'errors' => $validator->errors(),
////            ], 422);
////        }
////
////        DB::beginTransaction();
////
////        try {
////            $issued = [];
////            $summary = [
////                'turnover_lines' => 0,
////                'non_turnover_lines' => 0,
////                'front_quantity' => 0,
////                'internal_quantity' => 0,
////                'front_value' => 0,
////                'internal_value' => 0,
////            ];
////
////            foreach ($request->items as $item) {
////                $product = Product::where('company_id', $company->id)
////                    ->where('id', $item['product_id'])
////                    ->first();
////
////                if (!$product) {
////                    throw new \Exception('Product not found.');
////                }
////
////                $quantity = (float)$item['quantity'];
////                $productType = strtolower((string)$product->product_type);
////                $stockUsageType = $this->resolveStockUsageType($product);
////                $requestedDestination = strtolower((string)($item['issue_destination'] ?? 'auto'));
////                $destination = $this->resolveEnterpriseIssueDestination($product, $requestedDestination);
////
////                if ($stockUsageType === 'non_turnover' && $destination === 'front') {
////                    throw new \Exception("{$product->name} is non-turnover stock and cannot be issued to front stock. Issue it to department, expense, production usage or wastage.");
////                }
////
////                $backStock = $this->getOrCreateStock($product, $request->branch_id, 'back');
////
////                if ((float)$backStock->available_quantity < $quantity) {
////                    throw new \Exception("Insufficient BOH stock for {$product->name}.");
////                }
////
////                $cost = ProductCost::where('company_id', $company->id)
////                    ->where('branch_id', $request->branch_id)
////                    ->where('product_id', $product->id)
////                    ->first();
////
////                $unitCost = (float)($cost?->average_cost ?? $cost?->last_cost ?? 0);
////                $currencyCode = (string)($cost?->currency ?? 'USD');
////                $totalCost = round($quantity * $unitCost, 6);
////
////                $backStock->quantity_on_hand -= $quantity;
////                $backStock->available_quantity -= $quantity;
////                $backStock->save();
////
////                $quantityIn = 0;
////                $quantityOut = $quantity;
////                $toStockArea = $destination;
////                $movementType = $this->enterpriseIssueMovementType($destination);
////
////                if ($destination === 'front') {
////                    $frontStock = $this->getOrCreateStock($product, $request->branch_id, 'front');
////                    $frontStock->quantity_on_hand += $quantity;
////                    $frontStock->available_quantity += $quantity;
////                    $frontStock->save();
////
////                    // Keep legacy report compatibility: old BOH issue report reads both in/out on one movement.
////                    $quantityIn = $quantity;
////                    $quantityOut = $quantity;
////                    $toStockArea = 'front';
////                }
////
////                $metadata = [
////                    'enterprise_issue' => true,
////                    'stock_usage_type' => $stockUsageType,
////                    'turnover_class' => $stockUsageType === 'turnover' ? 'turnover_stock' : 'non_turnover_stock',
////                    'issue_destination' => $destination,
////                    'department_name' => $item['department_name'] ?? null,
////                    'cost_center' => $item['cost_center'] ?? null,
////                    'issue_purpose' => $item['issue_purpose'] ?? null,
////                    'product_type' => $productType,
////                    'pl_report_bucket' => $stockUsageType === 'non_turnover' ? 'operating_consumables' : 'cost_of_sales_stock_issue',
////                    'turnover_report_bucket' => $stockUsageType === 'turnover' ? 'turnover_stock_issue' : 'non_turnover_stock_issue',
////                ];
////
////                StockMovement::create([
////                    'company_id' => $company->id,
////                    'branch_id' => $request->branch_id,
////                    'product_id' => $product->id,
////                    'movement_type' => $movementType,
////                    'stock_area' => $destination === 'front' ? 'front' : 'back',
////                    'from_stock_area' => 'back',
////                    'to_stock_area' => $toStockArea,
////                    'quantity_in' => $quantityIn,
////                    'quantity_out' => $quantityOut,
////                    'unit_cost' => $unitCost,
////                    'total_cost' => $totalCost,
////                    'currency' => $currencyCode,
////                    'reference_type' => 'enterprise_stock_issue',
////                    'reference_id' => null,
////                    'notes' => $item['notes'] ?? $request->notes,
////                    'metadata' => $metadata,
////                    'created_by' => auth()->id(),
////                ]);
////
////                if ($stockUsageType === 'turnover') {
////                    $summary['turnover_lines']++;
////                } else {
////                    $summary['non_turnover_lines']++;
////                }
////
////                if ($destination === 'front') {
////                    $summary['front_quantity'] += $quantity;
////                    $summary['front_value'] += $totalCost;
////                } else {
////                    $summary['internal_quantity'] += $quantity;
////                    $summary['internal_value'] += $totalCost;
////                }
////
////                $issued[] = [
////                    'product_id' => $product->id,
////                    'product_name' => $product->name,
////                    'sku' => $product->sku,
////                    'code' => $product->code,
////                    'product_type' => $product->product_type,
////                    'stock_usage_type' => $stockUsageType,
////                    'issue_destination' => $destination,
////                    'quantity' => $quantity,
////                    'unit_cost' => $unitCost,
////                    'total_cost' => $totalCost,
////                    'currency' => $currencyCode,
////                    'from_stock_area' => 'back',
////                    'to_stock_area' => $toStockArea,
////                    'department_name' => $item['department_name'] ?? null,
////                    'cost_center' => $item['cost_center'] ?? null,
////                    'issue_purpose' => $item['issue_purpose'] ?? null,
////                ];
////            }
////
////            DB::commit();
////
////            return response()->json([
////                'message' => 'Enterprise stock issue posted successfully.',
////                'data' => $issued,
////                'summary' => [
////                    'turnover_lines' => $summary['turnover_lines'],
////                    'non_turnover_lines' => $summary['non_turnover_lines'],
////                    'front_quantity' => round($summary['front_quantity'], 6),
////                    'internal_quantity' => round($summary['internal_quantity'], 6),
////                    'front_value' => round($summary['front_value'], 6),
////                    'internal_value' => round($summary['internal_value'], 6),
////                ],
////            ], 200);
////        } catch (\Exception $e) {
////            DB::rollBack();
////
////            return response()->json([
////                'message' => $e->getMessage(),
////            ], 422);
////        }
////    }
//
////    private function resolveStockUsageType(Product $product): string
////    {
////        if (Schema::hasColumn('products', 'stock_usage_type') && !empty($product->stock_usage_type)) {
////            return strtolower((string)$product->stock_usage_type) === 'non_turnover' ? 'non_turnover' : 'turnover';
////        }
////
////        $type = strtolower((string)$product->product_type);
////
////        // Cleaning materials, office consumables and similar products are received into stock,
////        // but when issued they become operating consumption, not front selling stock.
////        if (in_array($type, ['consumable', 'consumables'], true)) {
////            return 'non_turnover';
////        }
////
////        return 'turnover';
////    }
//
//    public function issueBackStockEnterprise(Request $request, CompanySettingService $settings)
//    {
//        $company = app('company');
//
//        if (!$settings->isFastFoodMode($company->id) || !$settings->isBohEnabled($company->id)) {
//            return response()->json([
//                'message' => 'BOH is not enabled for this company.',
//            ], 422);
//        }
//
//        $result = hasPermission(auth()->user()->role_id, 'boh_issue_stock', $company->id);
//        if ($result !== true) return $result;
//
//        $validator = Validator::make($request->all(), [
//            'branch_id' => 'required|exists:company_branches,id',
//            'issue_name' => 'required|string|max:180',
//            'issue_type' => 'required|in:front,department,expense,production,wastage',
//            'issue_destination' => 'required|in:front,department,expense,production,wastage',
//            'department_name' => 'nullable|string|max:120',
//            'cost_center' => 'nullable|string|max:120',
//            'requested_by' => 'nullable|string|max:120',
//            'issue_purpose' => 'required|string|max:255',
//            'notes' => 'nullable|string',
//            'items' => 'required|array|min:1',
//            'items.*.product_id' => 'required|exists:products,id',
//            'items.*.quantity' => 'required|numeric|min:0.0001',
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
//            $issueId = (string) Str::uuid();
//            $destination = strtolower((string) $request->issue_destination);
//            $issueType = strtolower((string) $request->issue_type);
//            $issued = [];
//            $summary = [
//                'turnover_lines' => 0,
//                'non_turnover_lines' => 0,
//                'front_quantity' => 0,
//                'internal_quantity' => 0,
//                'front_value' => 0,
//                'internal_value' => 0,
//                'total_quantity' => 0,
//                'total_value' => 0,
//            ];
//
//            foreach ($request->items as $item) {
//                $product = Product::where('company_id', $company->id)
//                    ->where('id', $item['product_id'])
//                    ->first();
//
//                if (!$product) {
//                    throw new \Exception('Product not found.');
//                }
//
//                $quantity = (float) $item['quantity'];
//                $productType = strtolower((string) $product->product_type);
//                $stockUsageType = $this->resolveStockUsageType($product);
//
//                if ($stockUsageType === 'non_turnover' && $destination === 'front') {
//                    throw new \Exception("{$product->name} is non-turnover stock and cannot be issued to front stock. Select Department, Operating Expense, Production or Wastage issue type.");
//                }
//
//                $backStock = $this->getOrCreateStock($product, $request->branch_id, 'back');
//
//                if ((float) $backStock->available_quantity < $quantity) {
//                    throw new \Exception("Insufficient BOH stock for {$product->name}.");
//                }
//
//                $cost = ProductCost::where('company_id', $company->id)
//                    ->where('branch_id', $request->branch_id)
//                    ->where('product_id', $product->id)
//                    ->first();
//
//                $unitCost = (float) ($cost?->average_cost ?? $cost?->last_cost ?? 0);
//                $currencyCode = (string) ($cost?->currency ?? 'USD');
//                $totalCost = round($quantity * $unitCost, 6);
//
//                $backStock->quantity_on_hand -= $quantity;
//                $backStock->available_quantity -= $quantity;
//                $backStock->save();
//
//                $quantityIn = 0;
//                $quantityOut = $quantity;
//                $toStockArea = $destination;
//                $movementType = $this->enterpriseIssueMovementType($destination);
//                $stockArea = 'back';
//
//                if ($destination === 'front') {
//                    $frontStock = $this->getOrCreateStock($product, $request->branch_id, 'front');
//                    $frontStock->quantity_on_hand += $quantity;
//                    $frontStock->available_quantity += $quantity;
//                    $frontStock->save();
//
//                    // Legacy report compatibility.
//                    $quantityIn = $quantity;
//                    $quantityOut = $quantity;
//                    $toStockArea = 'front';
//                    $stockArea = 'front';
//                }
//
//                $metadata = [
//                    'enterprise_issue' => true,
//                    'issue_id' => $issueId,
//                    'issue_name' => $request->issue_name,
//                    'issue_type' => $issueType,
//                    'issue_destination' => $destination,
//                    'department_name' => $request->department_name,
//                    'cost_center' => $request->cost_center,
//                    'requested_by' => $request->requested_by,
//                    'issue_purpose' => $request->issue_purpose,
//                    'header_notes' => $request->notes,
//                    'stock_usage_type' => $stockUsageType,
//                    'turnover_class' => $stockUsageType === 'turnover' ? 'turnover_stock' : 'non_turnover_stock',
//                    'product_type' => $productType,
//                    'pl_report_bucket' => $stockUsageType === 'non_turnover' ? 'operating_consumables' : 'cost_of_sales_stock_issue',
//                    'turnover_report_bucket' => $stockUsageType === 'turnover' ? 'turnover_stock_issue' : 'non_turnover_stock_issue',
//                ];
//
//                $movementPayload = [
//                    'company_id' => $company->id,
//                    'branch_id' => $request->branch_id,
//                    'product_id' => $product->id,
//                    'movement_type' => $movementType,
//                    'stock_area' => $stockArea,
//                    'from_stock_area' => 'back',
//                    'to_stock_area' => $toStockArea,
//                    'quantity_in' => $quantityIn,
//                    'quantity_out' => $quantityOut,
//                    'unit_cost' => $unitCost,
//                    'total_cost' => $totalCost,
//                    'currency' => $currencyCode,
//                    'reference_type' => 'enterprise_stock_issue',
//                    'reference_id' => $issueId,
//                    'notes' => $request->notes,
//                    'metadata' => $metadata,
//                    'created_by' => auth()->id(),
//                ];
//
//                if (Schema::hasColumn('stock_movements', 'issue_destination')) {
//                    $movementPayload['issue_destination'] = $destination;
//                }
//
//                if (Schema::hasColumn('stock_movements', 'stock_usage_type')) {
//                    $movementPayload['stock_usage_type'] = $stockUsageType;
//                }
//
//                if (Schema::hasColumn('stock_movements', 'expense_account_code')) {
//                    $movementPayload['expense_account_code'] = $product->expense_account_code ?? null;
//                }
//
//                StockMovement::create($movementPayload);
//
//                if ($stockUsageType === 'turnover') {
//                    $summary['turnover_lines']++;
//                } else {
//                    $summary['non_turnover_lines']++;
//                }
//
//                if ($destination === 'front') {
//                    $summary['front_quantity'] += $quantity;
//                    $summary['front_value'] += $totalCost;
//                } else {
//                    $summary['internal_quantity'] += $quantity;
//                    $summary['internal_value'] += $totalCost;
//                }
//
//                $summary['total_quantity'] += $quantity;
//                $summary['total_value'] += $totalCost;
//
//                $issued[] = [
//                    'issue_id' => $issueId,
//                    'issue_name' => $request->issue_name,
//                    'product_id' => $product->id,
//                    'product_name' => $product->name,
//                    'sku' => $product->sku,
//                    'code' => $product->code,
//                    'product_type' => $product->product_type,
//                    'stock_usage_type' => $stockUsageType,
//                    'issue_destination' => $destination,
//                    'quantity' => $quantity,
//                    'unit_cost' => $unitCost,
//                    'total_cost' => $totalCost,
//                    'currency' => $currencyCode,
//                    'from_stock_area' => 'back',
//                    'to_stock_area' => $toStockArea,
//                    'department_name' => $request->department_name,
//                    'cost_center' => $request->cost_center,
//                    'requested_by' => $request->requested_by,
//                    'issue_purpose' => $request->issue_purpose,
//                ];
//            }
//
//            DB::commit();
//
//            return response()->json([
//                'message' => 'Enterprise stock issue posted successfully.',
//                'issue_id' => $issueId,
//                'header' => [
//                    'issue_name' => $request->issue_name,
//                    'issue_type' => $issueType,
//                    'issue_destination' => $destination,
//                    'department_name' => $request->department_name,
//                    'cost_center' => $request->cost_center,
//                    'requested_by' => $request->requested_by,
//                    'issue_purpose' => $request->issue_purpose,
//                    'notes' => $request->notes,
//                ],
//                'data' => $issued,
//                'summary' => array_map(fn ($value) => is_numeric($value) ? round((float) $value, 6) : $value, $summary),
//            ], 200);
//        } catch (\Exception $e) {
//            DB::rollBack();
//
//            return response()->json([
//                'message' => $e->getMessage(),
//            ], 422);
//        }
//    }
//
//    private function resolveStockUsageType(Product $product): string
//    {
//        if (Schema::hasColumn('products', 'stock_usage_type') && !empty($product->stock_usage_type)) {
//            return strtolower((string) $product->stock_usage_type) === 'non_turnover' ? 'non_turnover' : 'turnover';
//        }
//
//        $type = strtolower((string) $product->product_type);
//
//        if (in_array($type, ['consumable', 'consumables'], true)) {
//            return 'non_turnover';
//        }
//
//        return 'turnover';
//    }
//
//    private function enterpriseIssueMovementType(string $destination): string
//    {
//        return match ($destination) {
//            'front' => 'boh_issue_to_front',
//            'department' => 'boh_issue_to_department',
//            'production' => 'boh_issue_to_production',
//            'wastage' => 'wastage',
//            default => 'boh_issue_to_expense',
//        };
//    }
//
//    private function resolveEnterpriseIssueDestination(Product $product, string $requestedDestination): string
//    {
//        $usageType = $this->resolveStockUsageType($product);
//
//        if ($requestedDestination === '' || $requestedDestination === 'auto') {
//            if (Schema::hasColumn('products', 'default_issue_destination') && !empty($product->default_issue_destination)) {
//                return strtolower((string)$product->default_issue_destination);
//            }
//
//            return $usageType === 'non_turnover' ? 'expense' : 'front';
//        }
//
//        return $requestedDestination;
//    }
//
////    private function enterpriseIssueMovementType(string $destination): string
////    {
////        return match ($destination) {
////            'front' => 'boh_issue_to_front',
////            'department' => 'boh_issue_to_department',
////            'production' => 'boh_issue_to_production',
////            'wastage' => 'wastage',
////            default => 'boh_issue_to_expense',
////        };
////    }
//
//    public function issueToFront(Request $request, CompanySettingService $settings)
//    {
//        $company = app('company');
//
//        if (!$settings->isFastFoodMode($company->id) || !$settings->isBohEnabled($company->id)) {
//            return response()->json([
//                'message' => 'BOH is not enabled for this company.',
//            ], 422);
//        }
//
//        $result = hasPermission(auth()->user()->role_id, 'boh_issue_stock', $company->id);
//        if ($result !== true) return $result;
//
//        $validator = Validator::make($request->all(), [
//            'branch_id' => 'required|exists:company_branches,id',
//            'items' => 'required|array|min:1',
//            'items.*.product_id' => 'required|exists:products,id',
//            'items.*.quantity' => 'required|numeric|min:0.0001',
//            'notes' => 'nullable|string',
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
//            $issued = [];
//
//            foreach ($request->items as $item) {
//                $product = Product::where('company_id', $company->id)
//                    ->where('id', $item['product_id'])
//                    ->first();
//
//                if (!$product) {
//                    throw new \Exception('Product not found.');
//                }
//
//                $quantity = (float)$item['quantity'];
//
//                $backStock = $this->getOrCreateStock($product, $request->branch_id, 'back');
//                $frontStock = $this->getOrCreateStock($product, $request->branch_id, 'front');
//
//                if ((float)$backStock->available_quantity < $quantity) {
//                    throw new \Exception("Insufficient BOH stock for {$product->name}.");
//                }
//
//                $backStock->quantity_on_hand -= $quantity;
//                $backStock->available_quantity -= $quantity;
//                $backStock->save();
//
//                $frontStock->quantity_on_hand += $quantity;
//                $frontStock->available_quantity += $quantity;
//                $frontStock->save();
//
//                StockMovement::create([
//                    'company_id' => $company->id,
//                    'branch_id' => $request->branch_id,
//                    'product_id' => $product->id,
//                    'movement_type' => 'boh_issue_to_front',
//                    'stock_area' => 'front',
//                    'from_stock_area' => 'back',
//                    'to_stock_area' => 'front',
//                    'quantity_in' => $quantity,
//                    'quantity_out' => $quantity,
//                    'unit_cost' => 0,
//                    'total_cost' => 0,
//                    'notes' => $request->notes,
//                    'created_by' => auth()->id(),
//                ]);
//
//                $issued[] = [
//                    'product_id' => $product->id,
//                    'product_name' => $product->name,
//                    'sku' => $product->sku,
//                    'code' => $product->code,
//                    'quantity' => $quantity,
//                    'from_stock_area' => 'back',
//                    'to_stock_area' => 'front',
//                ];
//            }
//
//            DB::commit();
//
//            return response()->json([
//                'message' => 'Stock issued to front successfully.',
//                'data' => $issued,
//            ], 200);
//        } catch (\Exception $e) {
//            DB::rollBack();
//
//            return response()->json([
//                'message' => $e->getMessage(),
//            ], 422);
//        }
//    }
//
//    private function loadProduct(string $id)
//    {
//        $company = app('company');
//
//        return Product::where('company_id', $company->id)
//            ->where('id', $id)
//            ->with([
//                'category',
//                'supplier',
//                'parent',
//                'subCategory',
//                'children',
//                'uom',
//                'tax',
//                'prices.priceLevel',
//                'stocks',
//                'costs',
//                'barcodes',
//                'suppliers.supplier',
//                'discounts',
//                'childBreakRule.parentProduct',
//                'parentBreakRules.childProduct',
//            ])
//            ->first();
//    }
//
//    private function breakChildProduct(Product $child, string $branchId, int $breakTimes = 1): void
//    {
//        $rule = ProductBreakRule::where('company_id', $child->company_id)
//            ->where('child_product_id', $child->id)
//            ->where('is_active', true)
//            ->first();
//
//        if (!$rule) {
//            throw new \Exception('No break rule configured for this child product.');
//        }
//
//        $parent = Product::where('company_id', $child->company_id)
//            ->where('id', $rule->parent_product_id)
//            ->first();
//
//        if (!$parent) {
//            throw new \Exception('Parent product not found.');
//        }
//
//        $parentQtyNeeded = $rule->parent_quantity * $breakTimes;
//        $childQtyCreated = $rule->units_per_parent * $breakTimes;
//
//        $parentStock = $this->getOrCreateStock($parent, $branchId);
//        $childStock = $this->getOrCreateStock($child, $branchId);
//
//        if ($parentStock->available_quantity < $parentQtyNeeded) {
//            throw new \Exception('Insufficient parent stock.');
//        }
//
//        $parentStock->quantity_on_hand -= $parentQtyNeeded;
//        $parentStock->available_quantity -= $parentQtyNeeded;
//        $parentStock->save();
//
//        $childStock->quantity_on_hand += $childQtyCreated;
//        $childStock->available_quantity += $childQtyCreated;
//        $childStock->save();
//
//        $parentCost = ProductCost::where('company_id', $parent->company_id)
//            ->where('branch_id', $branchId)
//            ->where('product_id', $parent->id)
//            ->first();
//
//        $parentUnitCost = $parentCost?->average_cost ?? 0;
//        $totalCostMoved = $parentUnitCost * $parentQtyNeeded;
//        $childUnitCost = $childQtyCreated > 0
//            ? $totalCostMoved / $childQtyCreated
//            : 0;
//
//        $this->addAverageCost($child, $branchId, $childQtyCreated, $childUnitCost);
//
//        StockMovement::create([
//            'company_id' => $parent->company_id,
//            'branch_id' => $branchId,
//            'product_id' => $parent->id,
//            'movement_type' => 'break_parent',
//            'quantity_in' => 0,
//            'quantity_out' => $parentQtyNeeded,
//            'unit_cost' => $parentUnitCost,
//            'total_cost' => $totalCostMoved,
//            'notes' => 'Parent product broken into child units',
//            'created_by' => auth()->id(),
//        ]);
//
//        StockMovement::create([
//            'company_id' => $child->company_id,
//            'branch_id' => $branchId,
//            'product_id' => $child->id,
//            'movement_type' => 'create_child',
//            'quantity_in' => $childQtyCreated,
//            'quantity_out' => 0,
//            'unit_cost' => $childUnitCost,
//            'total_cost' => $totalCostMoved,
//            'notes' => 'Child units created from parent product',
//            'created_by' => auth()->id(),
//        ]);
//    }
//
//    private function addAverageCost(Product $product, string $branchId, float $quantity, float $unitCost): void
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
//        $newValue = $quantity * $unitCost;
//        $newTotalQuantity = $cost->total_quantity + $quantity;
//        $newTotalValue = $cost->total_value + $newValue;
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
//    private function syncPrices(Product $product, array $prices): void
//    {
//        foreach ($prices as $price) {
//            ProductPrice::create([
//                'company_id' => $product->company_id,
//                'product_id' => $product->id,
//                'branch_id' => $price['branch_id'] ?? null,
//                'price_level_id' => $price['price_level_id'],
//                'price' => $price['price'],
//                'currency' => $price['currency'] ?? 'USD',
//                'is_default' => $price['is_default'] ?? false,
//                'is_active' => true,
//                'starts_at' => $price['starts_at'] ?? null,
//                'ends_at' => $price['ends_at'] ?? null,
//            ]);
//        }
//    }
//
//    private function syncBarcodes(Product $product, array $barcodes): void
//    {
//        foreach ($barcodes as $barcode) {
//            ProductBarcode::create([
//                'company_id' => $product->company_id,
//                'product_id' => $product->id,
//                'barcode' => $barcode['barcode'],
//                'type' => $barcode['type'] ?? 'alternative',
//                'is_active' => true,
//            ]);
//        }
//    }
//
//    private function syncSuppliers(Product $product, array $suppliers): void
//    {
//        foreach ($suppliers as $supplier) {
//            ProductSupplier::create([
//                'company_id' => $product->company_id,
//                'product_id' => $product->id,
//                'supplier_id' => $supplier['supplier_id'],
//                'supplier_code' => $supplier['supplier_code'] ?? null,
//                'last_purchase_price' => $supplier['last_purchase_price'] ?? 0,
//                'is_default' => $supplier['is_default'] ?? false,
//            ]);
//        }
//    }
//
//    private function syncDiscounts(Product $product, array $discountIds): void
//    {
//        $syncData = [];
//
//        foreach ($discountIds as $discountId) {
//            $syncData[$discountId] = [
//                'company_id' => $product->company_id,
//            ];
//        }
//
//        $product->discounts()->sync($syncData);
//    }
//
//    private function saveBreakRule(Product $product, array $rule): void
//    {
//        ProductBreakRule::updateOrCreate(
//            [
//                'company_id' => $product->company_id,
//                'child_product_id' => $product->id,
//            ],
//            [
//                'parent_product_id' => $rule['parent_product_id'],
//                'parent_quantity' => $rule['parent_quantity'] ?? 1,
//                'units_per_parent' => $rule['units_per_parent'],
//                'is_auto_break' => $rule['is_auto_break'] ?? true,
//                'is_active' => true,
//            ]
//        );
//    }
//
//    private function getSafeSessionId(Request $request): ?string
//    {
//        try {
//            return $request->hasSession() ? $request->session()->getId() : null;
//        } catch (\Throwable $e) {
//            return null;
//        }
//    }
//
//    private function writeAuditTrail(Request $request, array $data): void
//    {
//        try {
//            $user = auth()->user();
//
//            AuditTrail::create([
//                'company_id' => $data['company_id'] ?? null,
//                'branch_id' => $data['branch_id'] ?? null,
//                'user_id' => $user?->id,
//                'user_name' => $user?->name ?? $user?->username ?? null,
//                'user_email' => $user?->email ?? null,
//                'user_role' => $user?->role?->name ?? $user?->role_id ?? null,
//
//                'action' => $data['action'],
//                'module' => $data['module'] ?? 'inventory',
//                'description' => $data['description'] ?? null,
//
//                'auditable_type' => $data['auditable_type'] ?? null,
//                'auditable_id' => $data['auditable_id'] ?? null,
//
//                'old_values' => $data['old_values'] ?? null,
//                'new_values' => $data['new_values'] ?? null,
//                'changed_fields' => $data['changed_fields'] ?? null,
//
//                'ip_address' => $request->ip(),
//                'user_agent' => $request->userAgent(),
//                'device_name' => $request->header('X-Device-Name'),
//                'terminal_name' => $request->header('X-Terminal-Name'),
//                'session_id' => $this->getSafeSessionId($request),
//
//                'status' => $data['status'] ?? 'success',
//                'error_message' => $data['error_message'] ?? null,
//                'metadata' => $data['metadata'] ?? null,
//            ]);
//        } catch (\Throwable $e) {
//            logger()->error('Failed to write stock adjustment audit trail', [
//                'error' => $e->getMessage(),
//            ]);
//        }
//    }
//
//    private function getOrCreateStock(Product $product, string $branchId, string $stockArea = 'front'): ProductStock
//    {
//        return ProductStock::firstOrCreate(
//            [
//                'company_id' => $product->company_id,
//                'branch_id' => $branchId,
//                'product_id' => $product->id,
//                'stock_area' => $stockArea,
//            ],
//            [
//                'quantity_on_hand' => 0,
//                'reserved_quantity' => 0,
//                'available_quantity' => 0,
//            ]
//        );
//    }
//
//    private function receiveProductStock(
//        Product $product,
//        string  $branchId,
//        float   $quantity,
//        float   $unitCost,
//        ?string $currency,
//        string  $movementType,
//        ?string $referenceId = null,
//        ?string $notes = null,
//        string  $stockArea = 'front'
//    ): void
//    {
//        $currencyCode = strtoupper($currency ?: 'USD');
//
//        $stock = $this->getOrCreateStock($product, $branchId, $stockArea);
//
//        $stock->quantity_on_hand = (float)$stock->quantity_on_hand + $quantity;
//        $stock->available_quantity = (float)$stock->available_quantity + $quantity;
//        $stock->save();
//
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
//                'currency' => $currencyCode,
//            ]
//        );
//
//        $oldTotalValue = (float)$cost->total_value;
//        $oldTotalQuantity = (float)$cost->total_quantity;
//
//        $newPurchaseValue = $quantity * $unitCost;
//        $newTotalQuantity = $oldTotalQuantity + $quantity;
//        $newTotalValue = $oldTotalValue + $newPurchaseValue;
//
//        $cost->average_cost = $newTotalQuantity > 0
//            ? $newTotalValue / $newTotalQuantity
//            : 0;
//
//        $cost->last_cost = $unitCost;
//        $cost->total_quantity = $newTotalQuantity;
//        $cost->total_value = $newTotalValue;
//        $cost->currency = $currencyCode;
//        $cost->save();
//
//        StockMovement::create([
//            'company_id' => $product->company_id,
//            'branch_id' => $branchId,
//            'product_id' => $product->id,
//            'movement_type' => $movementType,
//            'stock_area' => $stockArea,
//            'quantity_in' => $quantity,
//            'quantity_out' => 0,
//            'unit_cost' => $unitCost,
//            'total_cost' => $quantity * $unitCost,
//            'currency' => $currencyCode,
//            'reference_type' => 'product',
//            'reference_id' => $referenceId ?: $product->id,
//            'notes' => $notes,
//            'created_by' => auth()->id(),
//        ]);
//    }
//
//
//    private function autoBreakChildProduct(Product $child, string $branchId, int $breakTimes = 1): void
//    {
//        $rule = ProductBreakRule::where('company_id', $child->company_id)
//            ->where('child_product_id', $child->id)
//            ->where('is_active', true)
//            ->first();
//
//        if (!$rule) {
//            throw new \Exception('No break rule configured for this child product.');
//        }
//
//        $parent = Product::find($rule->parent_product_id);
//
//        if (!$parent) {
//            throw new \Exception('Parent product not found.');
//        }
//
//        $parentQtyNeeded = $rule->parent_quantity * $breakTimes;
//        $childQtyCreated = $rule->child_quantity * $breakTimes;
//
//        $parentStock = $this->getOrCreateStock($parent, $branchId);
//        $childStock = $this->getOrCreateStock($child, $branchId);
//
//        if ($parentStock->available_quantity < $parentQtyNeeded) {
//            throw new \Exception('Insufficient parent product stock.');
//        }
//
//        $parentStock->quantity_on_hand -= $parentQtyNeeded;
//        $parentStock->available_quantity -= $parentQtyNeeded;
//        $parentStock->save();
//
//        $childStock->quantity_on_hand += $childQtyCreated;
//        $childStock->available_quantity += $childQtyCreated;
//        $childStock->save();
//
//        $parentCost = ProductCost::where('company_id', $parent->company_id)
//            ->where('branch_id', $branchId)
//            ->where('product_id', $parent->id)
//            ->first();
//
//        $unitParentCost = $parentCost?->average_cost ?? 0;
//        $totalParentCost = $unitParentCost * $parentQtyNeeded;
//        $childUnitCost = $childQtyCreated > 0
//            ? $totalParentCost / $childQtyCreated
//            : 0;
//
//        $this->updateChildAverageCost($child, $branchId, $childQtyCreated, $childUnitCost);
//
//        StockMovement::create([
//            'company_id' => $parent->company_id,
//            'branch_id' => $branchId,
//            'product_id' => $parent->id,
//            'movement_type' => 'break_parent',
//            'quantity_in' => 0,
//            'quantity_out' => $parentQtyNeeded,
//            'unit_cost' => $unitParentCost,
//            'total_cost' => $totalParentCost,
//            'notes' => 'Auto/manual product break parent deduction',
//            'created_by' => auth()->id(),
//        ]);
//
//        StockMovement::create([
//            'company_id' => $child->company_id,
//            'branch_id' => $branchId,
//            'product_id' => $child->id,
//            'movement_type' => 'create_child',
//            'quantity_in' => $childQtyCreated,
//            'quantity_out' => 0,
//            'unit_cost' => $childUnitCost,
//            'total_cost' => $totalParentCost,
//            'notes' => 'Auto/manual product break child creation',
//            'created_by' => auth()->id(),
//        ]);
//    }
//
//    private function updateChildAverageCost(
//        Product $child,
//        string  $branchId,
//        float   $quantity,
//        float   $unitCost
//    ): void
//    {
//        $cost = ProductCost::firstOrCreate(
//            [
//                'company_id' => $child->company_id,
//                'branch_id' => $branchId,
//                'product_id' => $child->id,
//            ],
//            [
//                'average_cost' => 0,
//                'last_cost' => 0,
//                'total_quantity' => 0,
//                'total_value' => 0,
//            ]
//        );
//
//        $newValue = $quantity * $unitCost;
//        $newTotalQuantity = $cost->total_quantity + $quantity;
//        $newTotalValue = $cost->total_value + $newValue;
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
//    public function generateIdentity(Request $request, ProductIdentityGenerator $generator)
//    {
//        $company = app('company');
//
//        $validator = Validator::make($request->all(), [
//            'branch_id' => 'nullable|exists:company_branches,id',
//            'type' => 'required|in:product_code,sku',
//        ]);
//
//        if ($validator->fails()) {
//            return response()->json([
//                'message' => 'Validation error.',
//                'errors' => $validator->errors(),
//            ], 422);
//        }
//
//        $branchId = $request->branch_id ?? auth()->user()->branch_id;
//
//        $value = match ($request->type) {
//            'product_code' => $generator->generateProductCode($company->id, $branchId),
//            'sku' => $generator->generateSku($company->id, $branchId),
//        };
//
//        return response()->json([
//            'message' => 'Generated successfully.',
//            'data' => [
//                'type' => $request->type,
//                'value' => $value,
//            ],
//        ]);
//    }
//
//    public function getUoms(Request $request)
//    {
//        $company = app('company');
//
//        $query = Uom::where('company_id', $company->id)
//            ->where('is_active', true)
//            ->orderBy('name');
//
//        if ($request->filled('q')) {
//            $q = $request->q;
//
//            $query->where(function ($sub) use ($q) {
//                $sub->where('name', 'like', "%{$q}%")
//                    ->orWhere('code', 'like', "%{$q}%")
//                    ->orWhere('quantity_type', 'like', "%{$q}%");
//            });
//        }
//
//        return response()->json([
//            'message' => 'UOMs fetched successfully.',
//            'data' => $query->get(),
//        ], 200);
//    }
//
//    public function getPriceLevels(Request $request)
//    {
//        $company = app('company');
//
//        $query = PriceLevel::where('company_id', $company->id)
//            ->where('is_active', true)
//            ->orderByDesc('is_default')
//            ->orderBy('name');
//
//        if ($request->filled('q')) {
//            $q = $request->q;
//
//            $query->where(function ($sub) use ($q) {
//                $sub->where('name', 'like', "%{$q}%")
//                    ->orWhere('code', 'like', "%{$q}%")
//                    ->orWhere('description', 'like', "%{$q}%");
//            });
//        }
//
//        return response()->json([
//            'message' => 'Price levels fetched successfully.',
//            'data' => $query->get(),
//        ], 200);
//    }
//
//
//    private function validateSelectedBaseCurrency(
//        string  $companyId,
//        ?string $branchId,
//        ?string $currencyCode
//    ): string
//    {
//        if (!$currencyCode) {
//            throw new \Exception('Currency is required.');
//        }
//
//        $currencyCode = strtoupper(trim($currencyCode));
//
//        $currency = Currency::where('company_id', $companyId)
//            ->where('code', $currencyCode)
//            ->where('is_active', true)
//            ->where('is_base_currency', true)
//            ->where(function ($query) use ($branchId) {
//                $query->whereNull('branch_id');
//
//                if ($branchId) {
//                    $query->orWhere('branch_id', $branchId);
//                }
//            })
//            ->first();
//
//        if (!$currency) {
//            throw new \Exception(
//                'Selected currency must exist and must be the active base currency.'
//            );
//        }
//
//        return $currencyCode;
//    }
//
////    public function uploadImage(Request $request, string $id)
////    {
////        $company = app('company');
////
////        $result = hasPermission(auth()->user()->role_id, 'product_management', $company->id);
////        if ($result !== true) return $result;
////
////        $product = Product::where('company_id', $company->id)
////            ->where('id', $id)
////            ->first();
////
////        if (!$product) {
////            return response()->json([
////                'message' => 'Product not found.',
////            ], 404);
////        }
////
////        $validator = Validator::make($request->all(), [
////            'image' => 'required|image|mimes:jpg,jpeg,png,webp|max:4096',
////        ]);
////
////        if ($validator->fails()) {
////            return response()->json([
////                'message' => 'Validation error.',
////                'errors' => $validator->errors(),
////            ], 422);
////        }
////
////        DB::beginTransaction();
////
////        try {
////            $relativeDirectory = 'menu item images/' . $company->id;
////            $absoluteDirectory = base_path('assets/' . $relativeDirectory);
////
////            if (!File::exists($absoluteDirectory)) {
////                File::makeDirectory($absoluteDirectory, 0755, true);
////            }
////
////            if (!empty($product->image_path)) {
////                $oldRelativePath = ltrim(str_replace('assets/', '', $product->image_path), '/');
////                $oldAbsolutePath = base_path('assets/' . $oldRelativePath);
////
////                if (File::exists($oldAbsolutePath) && File::isFile($oldAbsolutePath)) {
////                    File::delete($oldAbsolutePath);
////                }
////            }
////
////            $file = $request->file('image');
////            $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
////
////            $safeName = Str::slug($product->name ?: 'menu-item');
////            $filename = $safeName . '-' . $product->id . '-' . time() . '.' . $extension;
////
////            $file->move($absoluteDirectory, $filename);
////
////            $relativePath = $relativeDirectory . '/' . $filename;
////
////            $product->image_path = 'assets/' . $relativePath;
////            $product->image_url = url('api/assets/' . str_replace(' ', '%20', $relativePath));
////            $product->save();
////
////            DB::commit();
////
////            return response()->json([
////                'message' => 'Menu item image uploaded successfully.',
////                'data' => $this->loadProduct($product->id),
////                'image_path' => $product->image_path,
////                'image_url' => $product->image_url,
////            ], 200);
////        } catch (\Exception $e) {
////            DB::rollBack();
////
////            return response()->json([
////                'message' => 'Something went wrong while uploading menu item image.',
////                'error' => $e->getMessage(),
////            ], 500);
////        }
////    }
////
////    public function deleteImage(string $id)
////    {
////        $company = app('company');
////
////        $result = hasPermission(auth()->user()->role_id, 'product_management', $company->id);
////        if ($result !== true) return $result;
////
////        $product = Product::where('company_id', $company->id)
////            ->where('id', $id)
////            ->first();
////
////        if (!$product) {
////            return response()->json([
////                'message' => 'Product not found.',
////            ], 404);
////        }
////
////        DB::beginTransaction();
////
////        try {
////            if (!empty($product->image_path)) {
////                $oldRelativePath = ltrim(str_replace('assets/', '', $product->image_path), '/');
////                $oldAbsolutePath = base_path('assets/' . $oldRelativePath);
////
////                if (File::exists($oldAbsolutePath) && File::isFile($oldAbsolutePath)) {
////                    File::delete($oldAbsolutePath);
////                }
////            }
////
////            $product->image_path = null;
////            $product->image_url = null;
////            $product->save();
////
////            DB::commit();
////
////            return response()->json([
////                'message' => 'Menu item image removed successfully.',
////                'data' => $this->loadProduct($product->id),
////            ], 200);
////        } catch (\Exception $e) {
////            DB::rollBack();
////
////            return response()->json([
////                'message' => 'Something went wrong while removing menu item image.',
////                'error' => $e->getMessage(),
////            ], 500);
////        }
////    }
//
//
//    public function uploadImage(Request $request, string $id)
//    {
//        $company = app('company');
//
//        $result = hasPermission(auth()->user()->role_id, 'product_management', $company->id);
//        if ($result !== true) {
//            return $result;
//        }
//
//        $product = Product::where('company_id', $company->id)
//            ->where('id', $id)
//            ->first();
//
//        if (!$product) {
//            return response()->json([
//                'message' => 'Product not found.',
//            ], 404);
//        }
//
//        $validator = Validator::make($request->all(), [
//            'image' => 'required|image|mimes:jpg,jpeg,png,webp|max:4096',
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
//            $file = $request->file('image');
//
//            if (!$file || !$file->isValid()) {
//                return response()->json([
//                    'message' => 'Invalid uploaded image.',
//                    'error' => $file ? $file->getErrorMessage() : 'No file received.',
//                ], 422);
//            }
//
//            /*
//             |--------------------------------------------------------------------------
//             | Public assets folder
//             |--------------------------------------------------------------------------
//             | Localhost/XAMPP:
//             | base_path() = C:/xampp/htdocs/salexaapi/api
//             | assets root = C:/xampp/htdocs/salexaapi/assets
//             |
//             | Nginx:
//             | base_path() = C:/salexaapi/api
//             | assets root = C:/salexaapi/assets
//             |--------------------------------------------------------------------------
//             */
//            $relativeDirectory = 'menu-item-images/' . $company->id;
//            $absoluteDirectory = $this->publicAssetsPath($relativeDirectory);
//
//            if (!File::exists($absoluteDirectory)) {
//                File::makeDirectory($absoluteDirectory, 0775, true);
//            }
//
//            /*
//             * Delete old image.
//             */
//            if (!empty($product->image_path)) {
//                $this->deleteStoredAsset($product->image_path);
//            }
//
//            $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
//
//            $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
//            if (!in_array($extension, $allowedExtensions, true)) {
//                $extension = 'jpg';
//            }
//
//            $safeName = Str::slug($product->name ?: 'menu-item');
//            $filename = $safeName . '-' . $product->id . '-' . time() . '.' . $extension;
//
//            $file->move($absoluteDirectory, $filename);
//
//            $fullPath = $absoluteDirectory . DIRECTORY_SEPARATOR . $filename;
//
//            @chmod($absoluteDirectory, 0775);
//            @chmod($fullPath, 0664);
//
//            if (!File::exists($fullPath) || !File::isFile($fullPath)) {
//                throw new \Exception('Image file was not saved correctly.');
//            }
//
//            if (@getimagesize($fullPath) === false) {
//                File::delete($fullPath);
//
//                return response()->json([
//                    'message' => 'Uploaded file was saved but is not a valid image.',
//                ], 422);
//            }
//
//            $relativePath = $relativeDirectory . '/' . $filename;
//
//            $product->image_path = 'assets/' . $relativePath;
//            $product->image_url = $this->publicAssetUrl($relativePath);
//
//            $product->save();
//
//            DB::commit();
//
//            return response()->json([
//                'message' => 'Menu item image uploaded successfully.',
//                'data' => $this->loadProduct($product->id),
//                'image_path' => $product->image_path,
//                'image_url' => $product->image_url,
//            ], 200);
//        } catch (\Exception $e) {
//            DB::rollBack();
//
//            return response()->json([
//                'message' => 'Something went wrong while uploading menu item image.',
//                'error' => $e->getMessage(),
//            ], 500);
//        }
//    }
//
//    public function deleteImage(string $id)
//    {
//        $company = app('company');
//
//        $result = hasPermission(auth()->user()->role_id, 'product_management', $company->id);
//        if ($result !== true) {
//            return $result;
//        }
//
//        $product = Product::where('company_id', $company->id)
//            ->where('id', $id)
//            ->first();
//
//        if (!$product) {
//            return response()->json([
//                'message' => 'Product not found.',
//            ], 404);
//        }
//
//        DB::beginTransaction();
//
//        try {
//            if (!empty($product->image_path)) {
//                $this->deleteStoredAsset($product->image_path);
//            }
//
//            $product->image_path = null;
//            $product->image_url = null;
//            $product->save();
//
//            DB::commit();
//
//            return response()->json([
//                'message' => 'Menu item image removed successfully.',
//                'data' => $this->loadProduct($product->id),
//            ], 200);
//        } catch (\Exception $e) {
//            DB::rollBack();
//
//            return response()->json([
//                'message' => 'Something went wrong while removing menu item image.',
//                'error' => $e->getMessage(),
//            ], 500);
//        }
//    }
//
//    /**
//     * Encode each part of a URL path safely.
//     *
//     * Example:
//     * menu-item-images/company id/file name.png
//     * becomes:
//     * menu-item-images/company%20id/file%20name.png
//     */
//    private function publicAssetsRoot(): string
//    {
//        /*
//         * Laravel app folder:
//         * C:/xampp/htdocs/salexaapi/api
//         *
//         * Public project folder:
//         * C:/xampp/htdocs/salexaapi
//         *
//         * So assets must be saved one level above /api.
//         */
//        return dirname(base_path()) . DIRECTORY_SEPARATOR . 'assets';
//    }
//
//    private function publicAssetsPath(string $relativeDirectory): string
//    {
//        $relativeDirectory = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativeDirectory);
//
//        return $this->publicAssetsRoot() . DIRECTORY_SEPARATOR . $relativeDirectory;
//    }
//
//    private function publicAssetUrl(string $relativePath): string
//    {
//        $relativePath = $this->encodeUrlPath($relativePath);
//
//        $host = rtrim(request()->getSchemeAndHttpHost(), '/');
//
//        /*
//         * Localhost/XAMPP API base URL is usually:
//         * http://localhost/salexaapi/api
//         *
//         * But public assets are:
//         * http://localhost/salexaapi/assets
//         *
//         * So remove trailing /api from the base path.
//         */
//        $basePath = rtrim(request()->getBaseUrl(), '/');
//
//        if (Str::endsWith($basePath, '/api')) {
//            $basePath = Str::beforeLast($basePath, '/api');
//        }
//
//        return $host . $basePath . '/assets/' . $relativePath;
//    }
//
//    private function deleteStoredAsset(?string $path): void
//    {
//        if (empty($path)) {
//            return;
//        }
//
//        $relativePath = ltrim(str_replace('\\', '/', $path), '/');
//
//        if (Str::startsWith($relativePath, 'assets/')) {
//            $relativePath = Str::after($relativePath, 'assets/');
//        }
//
//        $absolutePath = $this->publicAssetsPath(dirname($relativePath))
//            . DIRECTORY_SEPARATOR
//            . basename($relativePath);
//
//        if (File::exists($absolutePath) && File::isFile($absolutePath)) {
//            File::delete($absolutePath);
//        }
//    }
//
//
//    private function resolveProductStockUsageTypeFromRequest(Request $request): string
//    {
//        $requested = strtolower((string) $request->input('stock_usage_type', ''));
//
//        if (in_array($requested, ['turnover', 'non_turnover'], true)) {
//            return $requested;
//        }
//
//        $productType = strtolower((string) $request->input('product_type', ''));
//
//        if (in_array($productType, ['consumable', 'consumables'], true)) {
//            return 'non_turnover';
//        }
//
//        return 'turnover';
//    }
//
//    private function resolveProductDefaultIssueDestinationFromRequest(Request $request): string
//    {
//        $usageType = $this->resolveProductStockUsageTypeFromRequest($request);
//        $requested = strtolower((string) $request->input('default_issue_destination', ''));
//
//        if (in_array($requested, ['front', 'department', 'expense', 'production', 'wastage'], true)) {
//            if ($usageType === 'non_turnover' && $requested === 'front') {
//                return 'expense';
//            }
//
//            return $requested;
//        }
//
//        return $usageType === 'non_turnover' ? 'expense' : 'front';
//    }
//
//    private function encodeUrlPath(string $path): string
//    {
//        $path = str_replace('\\', '/', $path);
//
//        return collect(explode('/', $path))
//            ->map(fn($part) => rawurlencode($part))
//            ->implode('/');
//    }
//}


namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\BusinessModeBranchScopeService;
use App\Services\PublicAssetStorage;
use App\Services\ProductIdentityGenerator;
use App\Services\Inventory\HospitalityStockIssueService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Company\Models\AuditTrail;
use Modules\Currency\Models\Currency;
use App\Services\CompanySettingService;
use App\Services\Inventory\InventoryVarianceAgentService;
use Modules\Product\Models\PriceLevel;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductBarcode;
use Modules\Product\Models\ProductBreakRule;
use Modules\Product\Models\ProductCost;
use Modules\Product\Models\ProductPrice;
use Modules\Product\Models\ProductStock;
use Modules\Product\Models\ProductSupplier;
use Modules\Product\Models\PurchaseReceipt;
use Modules\Product\Models\PurchaseReceiptLine;
use Modules\Product\Models\StockMovement;
use Modules\Product\Models\Uom;
use Modules\Product\Exports\ProductManagementSpreadsheetExport;
use Modules\Product\Support\HospitalityMultiBranchTradingDayGuard;
use Illuminate\Support\Facades\File;
use function App\Helpers\hasPermission;

class ProductController extends Controller
{

    /**
     * Resolve the branches that belong to the active Retail/Hospitality
     * workspace. Explicit branch values are filters, never mode switches.
     *
     * @param  array<int, mixed>  $requestedBranchIds
     * @return array{business_mode:string,context_branch_id:string,branch_ids:array<int,string>,accessible_branch_ids:array<int,string>}
     */
    private function productModeScope(Request $request, array $requestedBranchIds = []): array
    {
        return app(BusinessModeBranchScopeService::class)->resolve(
            $request,
            (string) app('company')->id,
            $requestedBranchIds
        );
    }

    /**
     * Keep company-global products available while preventing a branch-only
     * product from crossing the active operating-mode boundary.
     */
    private function applyProductModeVisibility($query, array $branchIds)
    {
        if (!Schema::hasColumn('products', 'is_global')) {
            return $query;
        }

        return $query->where(function ($scope) use ($branchIds) {
            $scope->where('is_global', true)
                ->orWhereIn('branch_id', $branchIds);
        });
    }

    /**
     * Limit Hospitality menu workspaces to branch-owned products while still
     * recognising pre-branch-scope menu rows. Legacy rows need two independent
     * markers: an active menu BOM and an active selling price on this branch.
     * That keeps ordinary company-global Retail products out of Hospitality.
     */
    private function applyBranchOnlyMenuVisibility(
        $query,
        array $branchIds,
        bool $includeLegacyHospitalityMenus
    ) {
        return $query->where(function ($branchMenu) use ($branchIds, $includeLegacyHospitalityMenus) {
            $branchMenu->where(function ($owned) use ($branchIds) {
                $owned->where('is_global', false)
                    ->whereIn('branch_id', $branchIds);
            });

            if (!$includeLegacyHospitalityMenus) {
                return;
            }

            $branchMenu->orWhere(function ($legacy) use ($branchIds) {
                $legacy->where('is_global', true)
                    ->whereNull('branch_id')
                    ->whereHas('prices', function ($price) use ($branchIds) {
                        $price->whereIn('branch_id', $branchIds)
                            ->where('is_active', true);
                    })
                    ->whereExists(function ($recipe) {
                        $recipe->selectRaw('1')
                            ->from('food_recipes')
                            ->whereColumn('food_recipes.menu_product_id', 'products.id')
                            ->where('food_recipes.recipe_type', 'menu')
                            ->where('food_recipes.is_active', true)
                            ->whereNull('food_recipes.deleted_at');
                    });
            });
        });
    }

    public function index(Request $request)
    {
        return response()->json([
            'message' => 'Products fetched successfully.',
            'data' => $this->filteredProductQuery($request)->paginate($request->per_page ?? 15),
        ]);
    }

    public function export(Request $request, string $format)
    {
        abort_unless(in_array($format, ['xlsx', 'pdf'], true), 404);

        $report = $this->buildProductReport($request);
        $filename = 'product-management-' . now()->format('Y-m-d-His') . '.' . $format;

        if ($format === 'xlsx') {
            return (new ProductManagementSpreadsheetExport($report))->download($filename);
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView(
            'product::reports.product-management-pdf',
            compact('report')
        )->setPaper('a4', 'landscape');

        return $pdf->download($filename);
    }

    private function filteredProductQuery(Request $request)
    {
        $company = app('company');
        $user = auth()->user()->loadMissing(['role', 'branches']);
        $roleName = strtolower(str_replace([' ', '-'], '_', (string) ($user->role?->name ?? '')));
        $isSuperAdmin = (bool) ($user->is_super_admin ?? false)
            || in_array($roleName, ['super_admin', 'super_administrator'], true);
        $includeAllBranches = $request->boolean('include_all_branches')
            && ($isSuperAdmin || $user->branches->count() > 1);
        $requestedBranchIds = $request->filled('branch_id')
            ? [(string) $request->input('branch_id')]
            : [];
        $scope = $this->productModeScope($request, $requestedBranchIds);
        $branchId = $requestedBranchIds[0] ?? $scope['context_branch_id'];
        $branchIds = $requestedBranchIds !== []
            ? $requestedBranchIds
            : ($includeAllBranches ? $scope['branch_ids'] : [$branchId]);

        $query = $this->applyProductModeVisibility(
            Product::where('company_id', $company->id),
            $branchIds
        )
            ->with([
                'branch',
                'category',
                'supplier',
                'parent',
                'children',
                'subCategory',
                'uom',

                'tax',
                'taxes.tax',
                'productTaxes.tax',

                'prices' => function ($query) use ($branchId, $branchIds) {
                    $query->where(function ($sub) use ($branchIds) {
                        $sub->whereIn('branch_id', $branchIds)
                            ->orWhereNull('branch_id');
                    })
                        ->where('is_active', true)
                        ->with('priceLevel')
                        ->orderByRaw(
                            'CASE WHEN branch_id = ? THEN 0 ELSE 1 END',
                            [$branchId]
                        )
                        ->orderByDesc('is_default');
                },

                'stocks' => function ($query) use ($branchIds) {
                    $query->whereIn('branch_id', $branchIds);
                },

                'costs' => function ($query) use ($branchIds) {
                    $query->whereIn('branch_id', $branchIds);
                },

                'barcodes',
                'discounts',
                'childBreakRule.parentProduct',
            ])
            ->latest();

        // Hospitality menu management is intentionally branch-owned. New
        // menu items are saved with is_global=false, however products created
        // before branch ownership was introduced were migrated as global with
        // a null branch. An active menu recipe plus a branch-specific active
        // selling price is the legacy evidence that such a product belongs on
        // that branch's Hospitality menu. Keep those records visible without
        // admitting arbitrary global Retail finished goods (or products priced
        // only for a Retail branch).
        if (
            $request->boolean('branch_only')
            && Schema::hasColumn('products', 'branch_id')
            && Schema::hasColumn('products', 'is_global')
        ) {
            $this->applyBranchOnlyMenuVisibility(
                $query,
                $branchIds,
                $scope['business_mode'] === 'hospitality' && Schema::hasTable('food_recipes')
            );
        }

        if ($request->filled('q')) {
            $q = $request->q;

            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")
                    ->orWhere('code', 'like', "%{$q}%")
                    ->orWhere('sku', 'like', "%{$q}%")
                    ->orWhere('barcode', 'like', "%{$q}%");
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }
        if ($request->filled('sub_category_id')) {
            $query->where('sub_category_id', $request->sub_category_id);
        }

        if ($request->filled('product_type') || $request->filled('product_types')) {
            $productTypes = $request->input(
                'product_types',
                $request->input('product_type')
            );

            if (is_string($productTypes)) {
                $productTypes = explode(',', $productTypes);
            }

            $productTypes = collect($productTypes)
                ->map(fn($type) => trim($type))
                ->filter()
                ->values()
                ->all();

            if (count($productTypes) > 1) {
                $query->whereIn('product_type', $productTypes);
            } elseif (count($productTypes) === 1) {
                $query->where('product_type', $productTypes[0]);
            }
        }

        if ($request->filled('is_active')) {
            $query->where(
                'is_active',
                filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)
            );
        }

        if ($request->filled('stock_usage_type') && Schema::hasColumn('products', 'stock_usage_type')) {
            $query->where('stock_usage_type', $request->stock_usage_type);
        }

        if ($request->filled('default_issue_destination') && Schema::hasColumn('products', 'default_issue_destination')) {
            $query->where('default_issue_destination', $request->default_issue_destination);
        }

        return $query;
    }

    private function buildProductReport(Request $request): array
    {
        $company = app('company');
        $products = $this->filteredProductQuery($request)->get();
        $filters = $this->productReportFilters($request);

        $rows = $products->values()->map(function (Product $product, int $index) {
            $price = $product->prices->first();
            $stock = $product->stocks;
            $cost = $product->costs->first();
            $barcode = $product->barcode
                ?: optional($product->barcodes->firstWhere('type', 'primary') ?: $product->barcodes->first())->barcode;
            $onHand = (float) $stock->sum('quantity_on_hand');
            $reserved = (float) $stock->sum('reserved_quantity');
            $available = (float) $stock->sum('available_quantity');

            return [
                'line' => $index + 1,
                'name' => $product->name ?: '-',
                'description' => $product->description ?: '-',
                'type' => Str::headline((string) ($product->product_type ?: 'simple')),
                'scope' => $product->is_global ? 'All branches' : ($product->branch?->branch_name ?: $product->branch?->name ?: 'Selected branch'),
                'code' => $product->code ?: '-',
                'sku' => $product->sku ?: '-',
                'barcode' => $barcode ?: '-',
                'category' => $product->category?->name ?: '-',
                'supplier' => $product->supplier?->name ?: '-',
                'uom' => trim(($product->uom?->name ?: '-') . ($product->uom?->code ? ' (' . $product->uom->code . ')' : '')),
                'currency' => $price?->currency ?: '',
                'selling_price' => (float) ($price?->price ?? 0),
                'on_hand' => $onHand,
                'reserved' => $reserved,
                'available' => $available,
                'average_cost' => (float) ($cost?->average_cost ?? 0),
                'stock_value' => (float) ($cost?->total_value ?? 0),
                'reorder_level' => (float) $product->reorder_level,
                'minimum_stock' => (float) $product->minimum_stock,
                'maximum_stock' => (float) $product->maximum_stock,
                'stock_tracking' => $product->stock_tracking_enabled ? 'Yes' : 'No',
                'batch_tracking' => $product->batch_tracking_enabled ? 'Yes' : 'No',
                'expiry_tracking' => $product->expiry_tracking_enabled ? 'Yes' : 'No',
                'status' => $product->is_active ? 'Active' : 'Inactive',
            ];
        })->all();

        return [
            'company_name' => $company->company_name ?: 'ServeNest POS Enterprise',
            'generated_at' => now()->format('d M Y H:i'),
            'filter_summary' => $filters ? implode(' | ', $filters) : 'No filters — all accessible products',
            'record_count' => count($rows),
            'active_count' => collect($rows)->where('status', 'Active')->count(),
            'stock_tracked_count' => collect($rows)->where('stock_tracking', 'Yes')->count(),
            'low_stock_count' => collect($rows)->filter(fn ($row) => $row['stock_tracking'] === 'Yes' && $row['on_hand'] <= $row['reorder_level'])->count(),
            'total_on_hand' => (float) collect($rows)->sum('on_hand'),
            'rows' => $rows,
        ];
    }

    private function productReportFilters(Request $request): array
    {
        $filters = [];
        if ($request->filled('q')) $filters[] = 'Search: ' . $request->string('q')->trim();
        if ($request->filled('product_type')) {
            $filters[] = 'Type: ' . Str::headline((string) $request->string('product_type'));
        } elseif ($request->filled('product_types')) {
            $types = collect(explode(',', (string) $request->input('product_types')))
                ->filter()->map(fn ($type) => Str::headline(trim($type)))->implode(', ');
            if ($types) $filters[] = 'Types: ' . $types;
        }
        if ($request->filled('category_id')) {
            $name = DB::table('product_categories')->where('company_id', app('company')->id)->where('id', $request->category_id)->value('name');
            $filters[] = 'Category: ' . ($name ?: $request->category_id);
        }
        if ($request->filled('supplier_id')) {
            $name = DB::table('suppliers')->where('company_id', app('company')->id)->where('id', $request->supplier_id)->value('name');
            $filters[] = 'Supplier: ' . ($name ?: $request->supplier_id);
        }
        if ($request->filled('sub_category_id')) {
            $name = DB::table('product_sub_categories')->where('company_id', app('company')->id)->where('id', $request->sub_category_id)->value('name');
            $filters[] = 'Sub-category: ' . ($name ?: $request->sub_category_id);
        }
        if ($request->filled('is_active')) $filters[] = 'Status: ' . ($request->boolean('is_active') ? 'Active' : 'Inactive');
        if ($request->filled('stock_usage_type')) $filters[] = 'Stock usage: ' . Str::headline((string) $request->string('stock_usage_type'));
        if ($request->filled('default_issue_destination')) $filters[] = 'Issue destination: ' . Str::headline((string) $request->string('default_issue_destination'));
        $filters[] = $request->boolean('include_all_branches') ? 'Scope: all permitted branches' : 'Scope: current branch';

        return $filters;
    }

    public function store(
        Request $request,
        ProductIdentityGenerator $identityGenerator,
        CompanySettingService $companySettings,
        HospitalityMultiBranchTradingDayGuard $tradingDayGuard
    )
    {
        $company = app('company');

        $result = hasPermission(auth()->user()->role_id, 'product_management', $company->id);
        if ($result !== true) return $result;

        $availableUom = fn () => Rule::exists('uoms', 'id')
            ->where(fn ($query) => $query
                ->where(function ($scope) use ($company) {
                    $scope->whereNull('company_id')
                        ->orWhere('company_id', $company->id);
                })
                ->where('is_active', true)
                ->whereNull('deleted_at'));

        $validator = Validator::make($request->all(), [
            'category_id' => [
                'nullable',
                Rule::exists('product_categories', 'id')->where('company_id', $company->id),
            ],
            'supplier_id' => [
                'nullable',
                Rule::exists('suppliers', 'id')->where('company_id', $company->id),
            ],
            'parent_product_id' => [
                'nullable',
                Rule::exists('products', 'id')->where('company_id', $company->id),
            ],
            'base_uom_id' => ['required', 'uuid', $availableUom()],
            'sub_category_id' => [
                'nullable',
                Rule::exists('product_sub_categories', 'id')
                    ->where('company_id', $company->id)
                    ->where('category_id', $request->category_id),
            ],
            'tax_id' => [
                'nullable',
                Rule::exists('taxes', 'id')->where('company_id', $company->id),
            ],
            'is_global' => 'nullable|boolean',
            'branch_id' => [
                'nullable',
                'required_if:is_global,false',
                Rule::exists('company_branches', 'id')->where('company_id', $company->id),
            ],

            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('products', 'name')->withoutTrashed()->where('company_id', $company->id),
            ],
            'code' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('products', 'code')->withoutTrashed()->where('company_id', $company->id),
            ],
            'sku' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('products', 'sku')->withoutTrashed()->where('company_id', $company->id),
            ],
            'barcode' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('products', 'barcode')->withoutTrashed()->where('company_id', $company->id),
            ],

            'description' => 'nullable|string',
            'hs_code' => ['nullable', 'string', 'regex:/^[0-9]{4}([0-9]{4})?$/'],
            'product_type' => 'required|in:simple,parent,child,service,raw_material,finished_good,semi_finished,packaging,consumable',

            'stock_tracking_enabled' => 'nullable|boolean',
            'batch_tracking_enabled' => 'nullable|boolean',
            'expiry_tracking_enabled' => 'nullable|boolean',
            'default_shelf_life_days' => 'nullable|integer|min:1|max:36500|required_if:expiry_tracking_enabled,true',
            'allow_negative_stock' => 'nullable|boolean',
            'allow_discount' => 'nullable|boolean',
            'taxable' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',

            'stock_usage_type' => 'nullable|in:turnover,non_turnover',
            'default_issue_destination' => 'nullable|in:front,department,expense,production,wastage',
            'expense_account_code' => 'nullable|string|max:80',

            'reorder_level' => 'nullable|numeric|min:0',
            'minimum_stock' => 'nullable|numeric|min:0',
            'maximum_stock' => 'nullable|numeric|min:0',

            'prices' => 'nullable|array',
            'prices.*.branch_id' => [
                'nullable',
                Rule::exists('company_branches', 'id')->where('company_id', $company->id),
            ],
            'prices.*.price_level_id' => [
                'required',
                Rule::exists('price_levels', 'id')->where('company_id', $company->id),
            ],
            'prices.*.price' => 'required|numeric|min:0',
            'prices.*.currency' => 'nullable|string|max:10',
            'prices.*.is_default' => 'nullable|boolean',

            'barcodes' => 'nullable|array',
            'barcodes.*.barcode' => 'required|string|max:100',
            'barcodes.*.type' => 'nullable|in:primary,alternative,supplier',

            'suppliers' => 'nullable|array',
            'suppliers.*.supplier_id' => [
                'required',
                Rule::exists('suppliers', 'id')->where('company_id', $company->id),
            ],
            'suppliers.*.supplier_code' => 'nullable|string|max:100',
            'suppliers.*.last_purchase_price' => 'nullable|numeric|min:0',
            'suppliers.*.is_default' => 'nullable|boolean',

            'discount_ids' => 'nullable|array',
            'discount_ids.*' => Rule::exists('discounts', 'id')->where('company_id', $company->id),

            'break_rule' => 'nullable|array',
            'break_rule.parent_product_id' => [
                'required_with:break_rule',
                Rule::exists('products', 'id')->where('company_id', $company->id),
            ],
            'break_rule.parent_quantity' => 'required_with:break_rule|numeric|min:0.0001',
            'break_rule.units_per_parent' => 'required_with:break_rule|numeric|min:0.0001',
            'break_rule.is_auto_break' => 'nullable|boolean',

            'opening_stocks' => 'nullable|array',
            'opening_stocks.*.branch_id' => [
                'required',
                Rule::exists('company_branches', 'id')->where('company_id', $company->id),
            ],
            'opening_stocks.*.quantity' => 'required|numeric|min:0',
            'opening_stocks.*.unit_cost' => 'nullable|numeric|min:0',
            'opening_stocks.*.currency' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $requestedBranchIds = collect([$request->input('branch_id')])
            ->merge(collect($request->input('prices', []))->pluck('branch_id'))
            ->merge(collect($request->input('opening_stocks', []))->pluck('branch_id'))
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values()
            ->all();
        $modeScope = $this->productModeScope($request);
        if ($requestedBranchIds !== []) {
            $this->productModeScope($request, $requestedBranchIds);
        }

        $user = auth()->user()->loadMissing(['role', 'branches']);
        $roleName = strtolower(str_replace([' ', '-'], '_', (string) ($user->role?->name ?? '')));
        $isSuperAdmin = (bool) ($user->is_super_admin ?? false)
            || in_array($roleName, ['super_admin', 'super_administrator'], true);
        $assignedBranchIds = collect($modeScope['branch_ids']);
        $canChooseScope = $isSuperAdmin || $assignedBranchIds->count() > 1;
        $isGlobalProduct = $canChooseScope ? $request->boolean('is_global', true) : false;
        $productBranchId = $isGlobalProduct
            ? null
            : ($request->branch_id ?: $modeScope['context_branch_id']);

        if (!$isSuperAdmin && $productBranchId && !$assignedBranchIds->contains((string) $productBranchId)) {
            return response()->json(['message' => 'Validation error.', 'errors' => ['branch_id' => ['You are not assigned to the selected branch.']]], 422);
        }

        if (!$isGlobalProduct && !$productBranchId) {
            return response()->json(['message' => 'Validation error.', 'errors' => ['branch_id' => ['A branch is required for a branch-only product.']]], 422);
        }

        if (!$isGlobalProduct && collect($request->input('opening_stocks', []))->contains(fn ($row) => (string) ($row['branch_id'] ?? '') !== (string) $productBranchId)) {
            return response()->json(['message' => 'Validation error.', 'errors' => ['opening_stocks' => ['Opening stock for a branch-only product must be assigned to the same branch.']]], 422);
        }

        $openingStockBranchIds = collect($request->input('opening_stocks', []))
            ->pluck('branch_id')
            ->filter()
            ->map(fn ($branchId) => (string) $branchId)
            ->unique()
            ->values()
            ->all();
        $tradingDaySnapshot = $tradingDayGuard->snapshot(
            (string) $company->id,
            $openingStockBranchIds
        );

        DB::beginTransaction();

        try {
            $tradingDayGuard->lockAndValidate($tradingDaySnapshot);

            $productData = $identityGenerator->generateAllForProductCreate(
                $company->id,
                $modeScope['context_branch_id'],
                [
                    'company_id' => $company->id,
                    'is_global' => $isGlobalProduct,
                    'branch_id' => $productBranchId,
                    'category_id' => $request->category_id,
                    'supplier_id' => $request->supplier_id,
                    'sub_category_id' => $request->sub_category_id,
                    'parent_product_id' => $request->parent_product_id,
                    'base_uom_id' => $request->base_uom_id,
                    'tax_id' => $request->tax_id,

                    'name' => $request->name,
                    'code' => $request->code,
                    'sku' => $request->sku,
                    'barcode' => $request->barcode,
                    'description' => $request->description,
                    'hs_code' => $request->input('hs_code'),
                    'product_type' => $request->product_type,

                    'stock_tracking_enabled' => $request->boolean('stock_tracking_enabled', false),
                    'batch_tracking_enabled' => $request->boolean('stock_tracking_enabled', false)
                        && $request->boolean('batch_tracking_enabled', false),
                    'expiry_tracking_enabled' => $request->boolean('stock_tracking_enabled', false)
                        && $request->boolean('batch_tracking_enabled', false)
                        && $request->boolean('expiry_tracking_enabled', false),
                    'default_shelf_life_days' => $request->boolean('stock_tracking_enabled', false)
                        && $request->boolean('expiry_tracking_enabled', false)
                            ? $request->default_shelf_life_days
                            : null,
                    'allow_negative_stock' => $request->allow_negative_stock ?? false,
                    'allow_discount' => $request->allow_discount ?? true,
                    'taxable' => $request->taxable ?? false,
                    'is_active' => $request->is_active ?? true,

                    'stock_usage_type' => $this->resolveProductStockUsageTypeFromRequest($request),
                    'default_issue_destination' => $this->resolveProductDefaultIssueDestinationFromRequest($request),
                    'expense_account_code' => $request->expense_account_code,

                    'reorder_level' => $request->reorder_level ?? 0,
                    'minimum_stock' => $request->minimum_stock ?? 0,
                    'maximum_stock' => $request->maximum_stock ?? 0,
                ]
            );

            $identityGenerator->assertProductIdentityIsComplete($productData);

            $product = Product::create($productData);
            $product->forceFill($this->buildProductStockUsagePayload($request));
            $product->save();

            $this->syncPrices($product, $request->prices ?? []);
            $this->syncBarcodes($product, $request->barcodes ?? []);
            $this->syncSuppliers($product, $request->suppliers ?? []);
            $this->syncDiscounts($product, $request->discount_ids ?? []);

            if ($request->filled('break_rule')) {
                $this->saveBreakRule($product, $request->break_rule);
            }

            if ($request->filled('opening_stocks')) {
                foreach ($request->opening_stocks as $stockData) {
                    $openingStockArea = $this->resolveStockUsageType($product) === 'non_turnover'
                        || $companySettings->isHospitalityMode($company->id, $stockData['branch_id'])
                            ? 'back'
                            : 'front';

                    $this->receiveProductStock(
                        $product,
                        $stockData['branch_id'],
                        $stockData['quantity'],
                        $stockData['unit_cost'] ?? 0,
                        $stockData['currency'] ?? null,
                        'opening',
                        null,
                        'Opening stock',
                        $openingStockArea
                    );
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Product created successfully.',
                'data' => $this->loadProduct($product->id),
            ], 201);
        } catch (HttpResponseException $e) {
            DB::rollBack();

            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong while creating product.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(Request $request, string $id)
    {
        $product = $this->loadProduct($id, $request);

        if (!$product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        return response()->json([
            'message' => 'Product fetched successfully.',
            'data' => $product,
        ]);
    }

    public function update(Request $request, string $id)
    {
        $company = app('company');
        $result = hasPermission(auth()->user()->role_id, 'product_management', $company->id);
        if ($result !== true) return $result;

        $modeScope = $this->productModeScope($request);
        $product = $this->applyProductModeVisibility(
            Product::where('company_id', $company->id),
            $modeScope['branch_ids']
        )->where('id', $id)
            ->first();

        if (!$product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $availableUom = fn () => Rule::exists('uoms', 'id')
            ->where(fn ($query) => $query
                ->where(function ($scope) use ($company) {
                    $scope->whereNull('company_id')
                        ->orWhere('company_id', $company->id);
                })
                ->where('is_active', true)
                ->whereNull('deleted_at'));

        $validator = Validator::make($request->all(), [
            'category_id' => [
                'nullable',
                Rule::exists('product_categories', 'id')->where('company_id', $company->id),
            ],
            'supplier_id' => [
                'nullable',
                Rule::exists('suppliers', 'id')->where('company_id', $company->id),
            ],
            'parent_product_id' => [
                'nullable',
                Rule::exists('products', 'id')->where('company_id', $company->id),
            ],
            'base_uom_id' => ['required', 'uuid', $availableUom()],
            'tax_id' => [
                'nullable',
                Rule::exists('taxes', 'id')->where('company_id', $company->id),
            ],
            'is_global' => 'nullable|boolean',
            'branch_id' => [
                'nullable',
                'required_if:is_global,false',
                Rule::exists('company_branches', 'id')->where('company_id', $company->id),
            ],
            'sub_category_id' => [
                'nullable',
                Rule::exists('product_sub_categories', 'id')
                    ->where('company_id', $company->id)
                    ->where('category_id', $request->category_id),
            ],

            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('products', 'name')->withoutTrashed()->where('company_id', $company->id)->ignore($product->id),
            ],
            'code' => [
                'required',
                'string',
                'max:100',
                Rule::unique('products', 'code')->withoutTrashed()->where('company_id', $company->id)->ignore($product->id),
            ],
            'sku' => [
                'required',
                'string',
                'max:100',
                Rule::unique('products', 'sku')->withoutTrashed()->where('company_id', $company->id)->ignore($product->id),
            ],
            'barcode' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('products', 'barcode')->withoutTrashed()->where('company_id', $company->id)->ignore($product->id),
            ],

            'description' => 'nullable|string',
            'hs_code' => ['nullable', 'string', 'regex:/^[0-9]{4}([0-9]{4})?$/'],
            'product_type' => 'required|in:simple,parent,child,service,raw_material,finished_good,semi_finished,packaging,consumable',


            'stock_tracking_enabled' => 'nullable|boolean',
            'batch_tracking_enabled' => 'nullable|boolean',
            'expiry_tracking_enabled' => 'nullable|boolean',
            'default_shelf_life_days' => 'nullable|integer|min:1|max:36500|required_if:expiry_tracking_enabled,true',
            'allow_negative_stock' => 'nullable|boolean',
            'allow_discount' => 'nullable|boolean',
            'taxable' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',

            'stock_usage_type' => 'nullable|in:turnover,non_turnover',
            'default_issue_destination' => 'nullable|in:front,department,expense,production,wastage',
            'expense_account_code' => 'nullable|string|max:80',

            'reorder_level' => 'nullable|numeric|min:0',
            'minimum_stock' => 'nullable|numeric|min:0',
            'maximum_stock' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($request->filled('branch_id')) {
            $this->productModeScope($request, [(string) $request->input('branch_id')]);
        }

        $user = auth()->user()->loadMissing(['role', 'branches']);
        $roleName = strtolower(str_replace([' ', '-'], '_', (string) ($user->role?->name ?? '')));
        $isSuperAdmin = (bool) ($user->is_super_admin ?? false)
            || in_array($roleName, ['super_admin', 'super_administrator'], true);
        $assignedBranchIds = collect($modeScope['branch_ids']);
        $canChooseScope = $isSuperAdmin || $assignedBranchIds->count() > 1;
        $isGlobalProduct = $request->has('is_global')
            ? ($canChooseScope ? $request->boolean('is_global') : false)
            : (bool) $product->is_global;
        $productBranchId = $isGlobalProduct
            ? null
            : ($request->branch_id ?: $product->branch_id ?: $modeScope['context_branch_id']);

        if (!$isSuperAdmin && $productBranchId && !$assignedBranchIds->contains((string) $productBranchId)) {
            return response()->json(['message' => 'Validation error.', 'errors' => ['branch_id' => ['You are not assigned to the selected branch.']]], 422);
        }

        if (!$isGlobalProduct && !$productBranchId) {
            return response()->json(['message' => 'Validation error.', 'errors' => ['branch_id' => ['A branch is required for a branch-only product.']]], 422);
        }

        $stockTrackingEnabled = $request->has('stock_tracking_enabled')
            ? $request->boolean('stock_tracking_enabled')
            : (bool) $product->stock_tracking_enabled;
        $batchTrackingEnabled = $stockTrackingEnabled && $request->boolean('batch_tracking_enabled', false);
        $expiryTrackingEnabled = $batchTrackingEnabled && $request->boolean('expiry_tracking_enabled', false);

        $updatePayload = [
            'is_global' => $isGlobalProduct,
            'branch_id' => $productBranchId,
            'category_id' => $request->category_id,
            'supplier_id' => $request->supplier_id,
            'sub_category_id' => $request->sub_category_id,
            'parent_product_id' => $request->parent_product_id,
            'base_uom_id' => $request->base_uom_id,
            'tax_id' => $request->tax_id,
            'name' => $request->name,
            'code' => $request->code,
            'sku' => $request->sku,
            'barcode' => $request->barcode,
            'description' => $request->description,
            'hs_code' => $request->has('hs_code') ? $request->input('hs_code') : $product->hs_code,
            'product_type' => $request->product_type,
            'stock_tracking_enabled' => $stockTrackingEnabled,
            'batch_tracking_enabled' => $batchTrackingEnabled,
            'expiry_tracking_enabled' => $expiryTrackingEnabled,
            'default_shelf_life_days' => $expiryTrackingEnabled ? $request->default_shelf_life_days : null,
            'allow_negative_stock' => $request->allow_negative_stock ?? false,
            'allow_discount' => $request->allow_discount ?? true,
            'taxable' => $request->taxable ?? false,
            'is_active' => $request->is_active ?? true,
            'reorder_level' => $request->reorder_level ?? 0,
            'minimum_stock' => $request->minimum_stock ?? 0,
            'maximum_stock' => $request->maximum_stock ?? 0,
        ];

        $product->forceFill(array_merge(
            $updatePayload,
            $this->buildProductStockUsagePayload($request)
        ));
        $product->save();

        return response()->json([
            'message' => 'Product updated successfully.',
            'data' => $this->loadProduct($product->id),
        ]);
    }

    public function destroy(Request $request, string $id)
    {
        $company = app('company');
        $result = hasPermission(auth()->user()->role_id, 'product_management', $company->id);
        if ($result !== true) return $result;

        $scope = $this->productModeScope($request);
        $product = $this->applyProductModeVisibility(
            Product::where('company_id', $company->id),
            $scope['branch_ids']
        )->where('id', $id)
            ->first();

        if (!$product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $product->delete();

        return response()->json([
            'message' => 'Product deleted successfully.',
        ]);
    }

//    public function addPrice(Request $request, string $id)
//    {
//        $company = app('company');
//        $user = auth()->user();
//        $result = hasPermission(auth()->user()->role_id, 'add_price', $company->id);
//        if ($result !== true) return $result;
//
//        $product = Product::where('company_id', $company->id)
//            ->where('id', $id)
//            ->first();
//
//        if (!$product) {
//            return response()->json([
//                'message' => 'Product not found.',
//            ], 404);
//        }
//
//        $validator = Validator::make($request->all(), [
//            'price_level_id' => 'required|exists:price_levels,id',
//            'branch_id' => 'nullable|exists:company_branches,id',
//            'price' => 'required|numeric|min:0',
//            'currency' => 'required|string|size:3',
//            'is_default' => 'nullable|boolean',
//            'is_active' => 'nullable|boolean',
//            'starts_at' => 'nullable|date',
//            'ends_at' => 'nullable|date|after_or_equal:starts_at',
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
//            $branchId = $request->branch_id ?: ($user->branch_id ?? null);
//
//            $currencyCode = $this->validateSelectedBaseCurrency(
//                $company->id,
//                $branchId,
//                $request->currency
//            );
//
//            if ($request->boolean('is_default')) {
//                ProductPrice::where('company_id', $company->id)
//                    ->where('product_id', $product->id)
//                    ->where('price_level_id', $request->price_level_id)
//                    ->where('branch_id', $branchId)
//                    ->update([
//                        'is_default' => false,
//                    ]);
//            }
//
//            $price = ProductPrice::create([
//                'company_id' => $company->id,
//                'branch_id' => $branchId,
//                'product_id' => $product->id,
//                'price_level_id' => $request->price_level_id,
//                'price' => (float) $request->price,
//                'currency' => $currencyCode,
//                'is_default' => $request->boolean('is_default', false),
//                'is_active' => $request->boolean('is_active', true),
//                'starts_at' => $request->starts_at,
//                'ends_at' => $request->ends_at,
//            ]);
//
//            DB::commit();
//
//            return response()->json([
//                'message' => 'Product price added successfully.',
//                'currency' => $currencyCode,
//                'data' => $price,
//            ], 201);
//        } catch (\Exception $e) {
//            DB::rollBack();
//
//            return response()->json([
//                'message' => 'Something went wrong while adding product price.',
//                'error' => $e->getMessage(),
//            ], 500);
//        }
//    }
    public function addPrice(Request $request, string $id)
    {
        $company = app('company');
        $user = auth()->user();

        $result = hasPermission($user->role_id, 'add_price', $company->id);
        if ($result !== true) return $result;

        $requestedBranchIds = $request->filled('branch_id')
            ? [(string) $request->input('branch_id')]
            : [];
        $scope = $this->productModeScope($request, $requestedBranchIds);
        $branchId = $requestedBranchIds[0] ?? $scope['context_branch_id'];
        $product = $this->applyProductModeVisibility(
            Product::where('company_id', $company->id),
            $scope['branch_ids']
        )->where('id', $id)
            ->first();

        if (!$product) {
            return response()->json([
                'message' => 'Product not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'price_level_id' => [
                'required',
                Rule::exists('price_levels', 'id')->where('company_id', $company->id),
            ],
            'branch_id' => [
                'nullable',
                Rule::exists('company_branches', 'id')->where('company_id', $company->id),
            ],
            'price' => 'required|numeric|min:0',
            'currency' => 'required|string|size:3',
            'is_default' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        // A currency the branch cannot price in is a form error, not a
        // server failure.
        try {
            $currencyCode = $this->validateSelectedBaseCurrency(
                $company->id,
                $branchId,
                $request->currency
            );
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['currency' => [$e->getMessage()]],
            ], 422);
        }

        DB::beginTransaction();

        try {

            if ($request->boolean('is_default')) {
                $defaultQuery = ProductPrice::withoutGlobalScopes()
                    ->where('company_id', $company->id)
                    ->where('product_id', $product->id)
                    ->where('price_level_id', $request->price_level_id);

                if ($branchId) {
                    $defaultQuery->where('branch_id', $branchId);
                } else {
                    $defaultQuery->whereNull('branch_id');
                }

                $defaultQuery->update([
                    'is_default' => false,
                ]);
            }

            $priceQuery = ProductPrice::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('product_id', $product->id)
                ->where('price_level_id', $request->price_level_id);

            if ($branchId) {
                $priceQuery->where('branch_id', $branchId);
            } else {
                $priceQuery->whereNull('branch_id');
            }

            $price = $priceQuery->first();

            $payload = [
                'company_id' => $company->id,
                'branch_id' => $branchId,
                'product_id' => $product->id,
                'price_level_id' => $request->price_level_id,
                'price' => (float)$request->price,
                'currency' => $currencyCode,
                'is_default' => $request->boolean('is_default', false),
                'is_active' => $request->boolean('is_active', true),
                'starts_at' => $request->starts_at,
                'ends_at' => $request->ends_at,
            ];

            if ($price) {
                $price->fill($payload);

                if (array_key_exists('deleted_at', $price->getAttributes())) {
                    $price->deleted_at = null;
                }

                $price->save();
                $statusCode = 200;
                $message = 'Product price updated successfully.';
            } else {
                $price = ProductPrice::create($payload);
                $statusCode = 201;
                $message = 'Product price added successfully.';
            }

            DB::commit();

            return response()->json([
                'message' => $message,
                'currency' => $currencyCode,
                'data' => $price,
            ], $statusCode);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong while saving product price.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function deletePrice(Request $request, string $productId, string $priceId)
    {
        $company = app('company');
        $result = hasPermission(auth()->user()->role_id, 'delete_price', $company->id);
        if ($result !== true) return $result;

        $scope = $this->productModeScope($request);
        $productExists = $this->applyProductModeVisibility(
            Product::where('company_id', $company->id),
            $scope['branch_ids']
        )->where('id', $productId)->exists();

        $price = ProductPrice::where('company_id', $company->id)
            ->where('product_id', $productId)
            ->where('id', $priceId)
            ->where(function ($query) use ($scope) {
                $query->whereNull('branch_id')
                    ->orWhereIn('branch_id', $scope['branch_ids']);
            })
            ->first();

        if (!$productExists || !$price) {
            return response()->json(['message' => 'Product price not found.'], 404);
        }

        $price->delete();

        return response()->json([
            'message' => 'Product price deleted successfully.',
        ]);
    }


    public function receiveStock(Request $request, string $id, CompanySettingService $settings)
    {
        $company = app('company');
        $user = auth()->user();

        $result = hasPermission($user->role_id, 'receive_stock', $company->id);
        if ($result !== true) return $result;

        $requestedBranchIds = $request->filled('branch_id')
            ? [(string) $request->input('branch_id')]
            : [];
        $scope = $this->productModeScope($request, $requestedBranchIds);
        $branchId = $requestedBranchIds[0] ?? $scope['context_branch_id'];
        $product = $this->applyProductModeVisibility(
            Product::where('company_id', $company->id),
            $scope['branch_ids']
        )->where('id', $id)
            ->first();

        if (!$product) {
            return response()->json([
                'message' => 'Product not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'branch_id' => [
                'required',
                Rule::exists('company_branches', 'id')->where('company_id', $company->id),
            ],
            'warehouse_id' => ['required', Rule::exists('inventory_warehouses', 'id')->where('company_id', $company->id)->whereNull('deleted_at')],
            'quantity' => 'required|numeric|min:0.0001',
            'unit_cost' => 'required|numeric|min:0',
            'currency' => 'required|string|size:3',
            'lot_number' => 'nullable|string|max:100',
            'manufactured_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after_or_equal:manufactured_at',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $stockArea = 'back';

        DB::beginTransaction();

        try {
            $warehouse = DB::table('inventory_warehouses')
                ->where('company_id', $company->id)
                ->where('id', $request->warehouse_id)
                ->where(fn ($query) => $query->where('branch_id', $branchId)->orWhereNull('branch_id'))
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->first();
            if (!$warehouse) {
                throw new \RuntimeException('The selected warehouse is not active for this branch.');
            }

            $location = DB::table('inventory_locations')
                ->where('company_id', $company->id)
                ->where('warehouse_id', $warehouse->id)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->orderByRaw("CASE WHEN location_type = 'receiving' THEN 0 WHEN location_type = 'storage' THEN 1 ELSE 2 END")
                ->orderBy('pick_sequence')
                ->first();
            if (!$location) {
                throw new \RuntimeException('The selected warehouse has no active receiving or storage location.');
            }
            if ($product->batch_tracking_enabled && !$request->filled('lot_number')) {
                throw new \RuntimeException('A lot number is required for this batch-tracked product.');
            }
            if ($product->expiry_tracking_enabled && !$request->filled('expires_at')) {
                throw new \RuntimeException('An expiry date is required for this expiry-tracked product.');
            }

            $currencyCode = $this->validateSelectedBaseCurrency(
                $company->id,
                $branchId,
                $request->currency
            );

            $quantity = (float)$request->quantity;
            $unitCost = (float)$request->unit_cost;
            $totalCost = $quantity * $unitCost;

            $stock = $this->getOrCreateStock($product, $branchId, $stockArea);

            $stock->quantity_on_hand += $quantity;
            $stock->available_quantity += $quantity;
            $stock->save();

            $batchId = null;
            if ($product->batch_tracking_enabled || $product->expiry_tracking_enabled || $request->filled('lot_number')) {
                $lotNumber = trim((string) ($request->lot_number ?: 'RCV-' . now()->format('YmdHis')));
                $batch = DB::table('inventory_batches')
                    ->where('company_id', $company->id)
                    ->where('branch_id', $branchId)
                    ->where('warehouse_id', $warehouse->id)
                    ->where('product_id', $product->id)
                    ->where('lot_number', $lotNumber)
                    ->lockForUpdate()
                    ->first();
                if ($batch) {
                    $batchId = $batch->id;
                    DB::table('inventory_batches')->where('id', $batchId)->update([
                        'quantity_received' => (float) $batch->quantity_received + $quantity,
                        'quantity_on_hand' => (float) $batch->quantity_on_hand + $quantity,
                        'unit_cost' => $unitCost,
                        'expires_at' => $request->expires_at ?: $batch->expires_at,
                        'updated_at' => now(),
                    ]);
                } else {
                    $batchId = (string) Str::uuid();
                    DB::table('inventory_batches')->insert([
                        'id' => $batchId, 'company_id' => $company->id, 'branch_id' => $branchId,
                        'warehouse_id' => $warehouse->id, 'location_id' => $location->id,
                        'product_id' => $product->id, 'lot_number' => $lotNumber,
                        'manufactured_at' => $request->manufactured_at, 'expires_at' => $request->expires_at,
                        'quantity_received' => $quantity, 'quantity_on_hand' => $quantity,
                        'reserved_quantity' => 0, 'quarantined_quantity' => 0,
                        'unit_cost' => $unitCost, 'base_unit_cost' => $unitCost,
                        'currency_code' => $currencyCode, 'exchange_rate' => 1,
                        'status' => 'available', 'quality_status' => 'released',
                        'created_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }

            $balanceQuery = DB::table('inventory_location_balances')
                ->where('company_id', $company->id)->where('location_id', $location->id)
                ->where('product_id', $product->id)
                ->when($batchId, fn ($query) => $query->where('batch_id', $batchId), fn ($query) => $query->whereNull('batch_id'));
            $balance = $balanceQuery->lockForUpdate()->first();
            if ($balance) {
                $balanceQuery->update([
                    'quantity_on_hand' => (float) $balance->quantity_on_hand + $quantity,
                    'available_quantity' => (float) $balance->available_quantity + $quantity,
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('inventory_location_balances')->insert([
                    'id' => (string) Str::uuid(), 'company_id' => $company->id, 'branch_id' => $branchId,
                    'warehouse_id' => $warehouse->id, 'location_id' => $location->id,
                    'product_id' => $product->id, 'batch_id' => $batchId,
                    'quantity_on_hand' => $quantity, 'reserved_quantity' => 0,
                    'available_quantity' => $quantity, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

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
                    'currency_code' => $currencyCode,
                ]
            );

            $previousQuantity = (float)$cost->total_quantity;
            $previousValue = (float)$cost->total_value;

            $newTotalQuantity = $previousQuantity + $quantity;
            $newTotalValue = $previousValue + $totalCost;

            $cost->last_cost = $unitCost;
            $cost->total_quantity = $newTotalQuantity;
            $cost->total_value = $newTotalValue;
            $cost->average_cost = $newTotalQuantity > 0
                ? $newTotalValue / $newTotalQuantity
                : 0;
            $cost->currency_code = $currencyCode;
            $cost->save();

            // Quick receive is still a formal goods-received event. Persist the
            // header and line before the movement so it appears in the same GRN
            // register and produces the same downloadable voucher as PO receipts.
            $receiptGroupKey = $request->filled('receipt_group_key') ? trim((string) $request->receipt_group_key) : null;
            $receipt = $receiptGroupKey ? PurchaseReceipt::where('company_id', $company->id)
                ->where('receipt_group_key', $receiptGroupKey)->lockForUpdate()->first() : null;
            if ($receipt && ($receipt->branch_id !== $branchId || strtoupper($receipt->currency_code) !== $currencyCode)) {
                throw new \RuntimeException('Items grouped on one goods-received voucher must use the same branch and currency.');
            }
            $receipt = $receipt ?: PurchaseReceipt::create([
                'id' => (string) Str::uuid(),
                'company_id' => $company->id,
                'branch_id' => $branchId,
                'purchase_order_id' => null,
                'supplier_id' => $product->supplier_id,
                'grn_number' => 'GRN-' . now()->format('Ymd-His') . '-' . strtoupper(Str::random(4)),
                'source' => 'quick_receive',
                'receipt_group_key' => $receiptGroupKey,
                'received_date' => $request->input('received_date', now()->toDateString()),
                'currency_code' => $currencyCode,
                'exchange_rate' => 1,
                'subtotal' => $totalCost,
                'tax_total' => 0,
                'grand_total' => $totalCost,
                'base_grand_total' => $totalCost,
                'status' => 'posted',
                'notes' => $request->notes,
                'created_by' => auth()->id(),
            ]);

            $receiptLine = PurchaseReceiptLine::create([
                'id' => (string) Str::uuid(),
                'purchase_receipt_id' => $receipt->id,
                'company_id' => $company->id,
                'branch_id' => $branchId,
                'supplier_id' => $product->supplier_id,
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'location_id' => $location->id,
                'lot_number' => $request->lot_number,
                'manufactured_at' => $request->manufactured_at,
                'expires_at' => $request->expires_at,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'currency_code' => $currencyCode,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'line_total' => $totalCost,
                'notes' => $request->notes,
            ]);
            if ($receipt->wasRecentlyCreated === false) {
                $receipt->subtotal = (float) $receipt->subtotal + $totalCost;
                $receipt->grand_total = (float) $receipt->grand_total + $totalCost;
                $receipt->base_grand_total = (float) $receipt->base_grand_total + $totalCost;
                $receipt->save();
            }

            StockMovement::create([
                'company_id' => $product->company_id,
                'branch_id' => $branchId,
                'warehouse_id' => $warehouse->id,
                'location_id' => $location->id,
                'batch_id' => $batchId,
                'product_id' => $product->id,
                'movement_type' => $stockArea === 'back'
                    ? 'boh_purchase_receive'
                    : 'purchase',
                'stock_area' => $stockArea,
                'quantity_in' => $quantity,
                'quantity_out' => 0,
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
                'currency' => $currencyCode,
                'currency_code' => $currencyCode,
                'reference_type' => PurchaseReceipt::class,
                'reference_id' => $receipt->id,
                'notes' => $request->notes,
                'created_by' => auth()->id(),
            ]);

            DB::commit();

            return response()->json([
                'message' => $stockArea === 'back'
                    ? "Stock received into {$warehouse->name} successfully."
                    : 'Stock received successfully.',
                'currency' => $currencyCode,
                'stock_area' => $stockArea,
                'warehouse_id' => $warehouse->id,
                'warehouse_name' => $warehouse->name,
                'goods_received' => [
                    'id' => $receipt->id,
                    'grn_number' => $receipt->grn_number,
                    'source' => 'quick_receive',
                    'document_url' => url("api/pos/purchases/receipts/{$receipt->id}/pdf?download=0"),
                    'download_url' => url("api/pos/purchases/receipts/{$receipt->id}/pdf?download=1"),
                ],
                'data' => $this->loadProduct($product->id, $request),
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong while receiving stock.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function adjustStock(Request $request, string $id)
    {
        $company = app('company');

        $result = hasPermission(auth()->user()->role_id, 'adjust_stock', $company->id);
        if ($result !== true) return $result;

        $requestedBranchIds = $request->filled('branch_id')
            ? [(string) $request->input('branch_id')]
            : [];
        $scope = $this->productModeScope($request, $requestedBranchIds);
        $product = $this->applyProductModeVisibility(
            Product::where('company_id', $company->id),
            $scope['branch_ids']
        )->where('id', $id)
            ->first();

        if (!$product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'branch_id' => [
                'required',
                Rule::exists('company_branches', 'id')->where('company_id', $company->id),
            ],
            'adjustment_type' => 'required|in:increase,decrease',
            'stock_area' => 'nullable|in:front,back',
            'quantity' => 'required|numeric|min:0.0001',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            $stockArea = $request->input('stock_area', 'front');
            $stock = $this->getOrCreateStock($product, $request->branch_id, $stockArea);

            if ($request->adjustment_type === 'increase') {
                $stock->quantity_on_hand += $request->quantity;
                $stock->available_quantity += $request->quantity;

                $quantityIn = $request->quantity;
                $quantityOut = 0;
            } else {
                if (!$product->allow_negative_stock && $stock->available_quantity < $request->quantity) {
                    DB::rollBack();

                    return response()->json([
                        'message' => 'Insufficient stock.',
                    ], 422);
                }

                $stock->quantity_on_hand -= $request->quantity;
                $stock->available_quantity -= $request->quantity;

                $quantityIn = 0;
                $quantityOut = $request->quantity;
            }

            $stock->save();

            $movement = StockMovement::create([
                'company_id' => $product->company_id,
                'branch_id' => $request->branch_id,
                'product_id' => $product->id,
                'movement_type' => 'adjustment',
                'stock_area' => $stockArea,
                'quantity_in' => $quantityIn,
                'quantity_out' => $quantityOut,
                'unit_cost' => 0,
                'total_cost' => 0,
                'notes' => $request->notes,
                'created_by' => auth()->id(),
            ]);

            app(InventoryVarianceAgentService::class)->resetProduct(
                (string) $company->id,
                (string) $request->branch_id,
                (string) $product->id,
                'stock_adjustment',
                'stock_movement',
                (string) $movement->id,
                (string) auth()->id(),
                (string) $stockArea
            );

            DB::commit();

            return response()->json([
                'message' => 'Stock adjusted successfully.',
                'data' => $this->loadProduct($product->id, $request),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong while adjusting stock.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function adjustStockBulk(Request $request)
    {
        $company = app('company');
        $user = auth()->user();
        $result = hasPermission(auth()->user()->role_id, 'adjust_stock', $company->id);
        if ($result !== true) return $result;

        $validator = Validator::make($request->all(), [
            'branch_id' => [
                'required',
                Rule::exists('company_branches', 'id')->where('company_id', $company->id),
            ],
            'stock_area' => 'nullable|in:front,back',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0',
            'items.*.notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $scope = $this->productModeScope(
            $request,
            [(string) $request->input('branch_id')]
        );

        DB::beginTransaction();

        try {
            $adjustedProducts = [];
            $skippedProducts = [];
            $movementIds = [];
            $stockArea = $request->input('stock_area', 'front');

            foreach ($request->items as $item) {
                $product = $this->applyProductModeVisibility(
                    Product::where('company_id', $company->id),
                    $scope['branch_ids']
                )->where('id', $item['product_id'])
                    ->first();

                if (!$product) {
                    $skippedProducts[] = [
                        'product_id' => $item['product_id'],
                        'message' => 'Product not found for this company.',
                    ];
                    continue;
                }

                $stock = $this->getOrCreateStock($product, $request->branch_id, $stockArea);

                $previousQuantity = (float)$stock->quantity_on_hand;
                $previousAvailable = (float)$stock->available_quantity;
                $reservedQuantity = (float)$stock->reserved_quantity;

                $newQuantity = (float)$item['quantity'];
                $difference = $newQuantity - $previousQuantity;

                if ($difference == 0) {
                    $skippedProducts[] = [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'sku' => $product->sku,
                        'previous_quantity' => $previousQuantity,
                        'new_quantity' => $newQuantity,
                        'message' => 'No quantity change detected.',
                    ];
                    continue;
                }

                if ($difference > 0) {
                    $adjustmentType = 'increase';
                    $quantityIn = abs($difference);
                    $quantityOut = 0;
                } else {
                    $adjustmentType = 'decrease';
                    $quantityIn = 0;
                    $quantityOut = abs($difference);

                    if (!$product->allow_negative_stock && $newQuantity < 0) {
                        DB::rollBack();

                        return response()->json([
                            'message' => "Negative stock is not allowed for {$product->name}.",
                        ], 422);
                    }
                }

                $stock->quantity_on_hand = $newQuantity;

                if ($product->allow_negative_stock) {
                    $stock->available_quantity = $newQuantity - $reservedQuantity;
                } else {
                    $stock->available_quantity = max(0, $newQuantity - $reservedQuantity);
                }

                $stock->save();

                $movement = StockMovement::create([
                    'company_id' => $product->company_id,
                    'branch_id' => $request->branch_id,
                    'product_id' => $product->id,
                    'movement_type' => 'adjustment',
                    'stock_area' => $stockArea,
                    'quantity_in' => $quantityIn,
                    'quantity_out' => $quantityOut,
                    'unit_cost' => 0,
                    'total_cost' => 0,
                    'notes' => $item['notes'] ?? null,
                    'created_by' => $user?->id,
                ]);

                $movementIds[] = $movement->id;

                app(InventoryVarianceAgentService::class)->resetProduct(
                    (string) $company->id,
                    (string) $request->branch_id,
                    (string) $product->id,
                    'stock_adjustment',
                    'stock_movement',
                    (string) $movement->id,
                    (string) $user?->id,
                    (string) $stockArea
                );

                $adjustedProducts[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'sku' => $product->sku,
                    'code' => $product->code,
                    'barcode' => $product->barcode,
                    'previous_quantity' => $previousQuantity,
                    'new_quantity' => $newQuantity,
                    'difference' => $difference,
                    'adjustment_type' => $adjustmentType,
                    'quantity_in' => $quantityIn,
                    'quantity_out' => $quantityOut,
                    'previous_available_quantity' => $previousAvailable,
                    'available_quantity' => $stock->available_quantity,
                    'reserved_quantity' => $stock->reserved_quantity,
                    'notes' => $item['notes'] ?? null,
                    'stock_area' => $stockArea,
                    'movement_id' => $movement->id,
                ];
            }

            $this->writeAuditTrail($request, [
                'company_id' => $company->id,
                'branch_id' => $request->branch_id,
                'action' => 'stock_adjusted_bulk',
                'module' => 'inventory',
                'description' => 'Bulk stock adjustment completed.',
                'auditable_type' => StockMovement::class,
                'auditable_id' => $movementIds[0] ?? null,
                'old_values' => null,
                'new_values' => [
                    'adjusted_count' => count($adjustedProducts),
                    'skipped_count' => count($skippedProducts),
                ],
                'changed_fields' => ['quantity_on_hand', 'available_quantity'],
                'metadata' => [
                    'movement_ids' => $movementIds,
                    'adjusted' => $adjustedProducts,
                    'skipped' => $skippedProducts,
                ],
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Bulk stock adjustment completed successfully.',
                'data' => [
                    'adjusted' => $adjustedProducts,
                    'skipped' => $skippedProducts,
                ],
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            $this->writeAuditTrail($request, [
                'company_id' => $company->id,
                'branch_id' => $request->branch_id,
                'action' => 'stock_adjust_bulk_failed',
                'module' => 'inventory',
                'description' => 'Bulk stock adjustment failed.',
                'auditable_type' => StockMovement::class,
                'auditable_id' => null,
                'old_values' => null,
                'new_values' => [
                    'items' => $request->items,
                ],
                'changed_fields' => ['quantity_on_hand'],
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'metadata' => [
                    'branch_id' => $request->branch_id,
                ],
            ]);

            return response()->json([
                'message' => 'Something went wrong while adjusting stock.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function stockAdjustmentHistory(Request $request)
    {
        $company = app('company');
        $requestedBranchIds = $request->filled('branch_id')
            ? [(string) $request->input('branch_id')]
            : [];
        $scope = $this->productModeScope($request, $requestedBranchIds);
        $branchIds = $requestedBranchIds !== []
            ? $requestedBranchIds
            : [$scope['context_branch_id']];

        $averageCostExpression = '0';
        $costCurrencyExpression = 'NULL';

        $query = StockMovement::query()
            ->select('stock_movements.*');

        if (Schema::hasTable('product_costs')) {
            $query->leftJoin('product_costs as history_costs', function ($join) {
                $join->on('history_costs.product_id', '=', 'stock_movements.product_id');

                if (Schema::hasColumn('product_costs', 'company_id')) {
                    $join->on('history_costs.company_id', '=', 'stock_movements.company_id');
                }

                if (Schema::hasColumn('product_costs', 'branch_id')) {
                    $join->on('history_costs.branch_id', '=', 'stock_movements.branch_id');
                }
            });

            if (Schema::hasColumn('product_costs', 'average_cost')) {
                $averageCostExpression = 'COALESCE(history_costs.average_cost, 0)';
            } elseif (Schema::hasColumn('product_costs', 'last_cost')) {
                $averageCostExpression = 'COALESCE(history_costs.last_cost, 0)';
            } elseif (Schema::hasColumn('product_costs', 'unit_cost')) {
                $averageCostExpression = 'COALESCE(history_costs.unit_cost, 0)';
            }

            if (
                Schema::hasColumn('product_costs', 'currency_code')
                && Schema::hasColumn('product_costs', 'currency')
            ) {
                $costCurrencyExpression = 'UPPER(COALESCE(
                    NULLIF(TRIM(history_costs.currency_code), ""),
                    NULLIF(TRIM(history_costs.currency), "")
                ))';
            } elseif (Schema::hasColumn('product_costs', 'currency_code')) {
                $costCurrencyExpression = 'UPPER(NULLIF(TRIM(history_costs.currency_code), ""))';
            } elseif (Schema::hasColumn('product_costs', 'currency')) {
                $costCurrencyExpression = 'UPPER(NULLIF(TRIM(history_costs.currency), ""))';
            }
        }

        $query
            ->addSelect(DB::raw($averageCostExpression . ' as average_cost'))
            ->addSelect(DB::raw('ABS(
                COALESCE(stock_movements.quantity_in, 0)
                - COALESCE(stock_movements.quantity_out, 0)
            ) * ' . $averageCostExpression . ' as cost_value'))
            ->addSelect(DB::raw($costCurrencyExpression . ' as cost_currency_code'))
            ->where('stock_movements.company_id', $company->id)
            ->where('stock_movements.movement_type', 'adjustment')
            ->whereIn('stock_movements.branch_id', $branchIds)
            ->with([
                'branch',
                'product.category',
                'product.uom',
                'createdBy',
            ])
            ->orderByDesc('stock_movements.created_at');

        if ($request->filled('product_id')) {
            $query->where('stock_movements.product_id', $request->product_id);
        }

        if ($request->filled('stock_area')) {
            $query->where('stock_movements.stock_area', $request->stock_area);
        }

        if ($request->filled('q')) {
            $q = $request->q;

            $query->whereHas('product', function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")
                    ->orWhere('sku', 'like', "%{$q}%")
                    ->orWhere('code', 'like', "%{$q}%")
                    ->orWhere('barcode', 'like', "%{$q}%");
            });
        }

        if ($request->filled('adjustment_type')) {
            if ($request->adjustment_type === 'increase') {
                $query->where('stock_movements.quantity_in', '>', 0);
            }

            if ($request->adjustment_type === 'decrease') {
                $query->where('stock_movements.quantity_out', '>', 0);
            }
        }

        if ($request->filled('date_from')) {
            $query->whereDate('stock_movements.created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('stock_movements.created_at', '<=', $request->date_to);
        }

        $perPage = (int)$request->get('per_page', 50);

        $movements = $query->paginate($perPage);
        $movements->getCollection()->each(function (StockMovement $movement) {
            $movement->makeHidden([
                'unit_cost',
                'total_cost',
                'currency_code',
                'currency',
            ]);
        });

        return response()->json([
            'message' => 'Stock adjustment history fetched successfully.',
            'data' => $movements,
        ], 200);
    }

    public function breakProduct(Request $request, string $childProductId)
    {
        $company = app('company');
        $requestedBranchIds = $request->filled('branch_id')
            ? [(string) $request->input('branch_id')]
            : [];
        $scope = $this->productModeScope($request, $requestedBranchIds);

        $child = $this->applyProductModeVisibility(
            Product::where('company_id', $company->id),
            $scope['branch_ids']
        )->where('id', $childProductId)
            ->first();

        if (!$child) {
            return response()->json([
                'message' => 'Child product not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'branch_id' => [
                'required',
                Rule::exists('company_branches', 'id')->where('company_id', $company->id),
            ],
            'break_times' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            $this->breakChildProduct(
                $child,
                $request->branch_id,
                $request->break_times ?? 1
            );

            DB::commit();

            return response()->json([
                'message' => 'Product broken successfully.',
                'data' => $this->loadProduct($child->id, $request),
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }


    public function frontStock(Request $request)
    {
        return $this->stockByArea($request, 'front');
    }

    public function backStock(Request $request, CompanySettingService $settings)
    {
        $company = app('company');
        $branchId = $this->resolveCompanyBranchId($request, $company);

        if (!$branchId || !$settings->isHospitalityMode($company->id, $branchId)) {
            return $this->hospitalityModeDenied($branchId);
        }

        $request->merge(['branch_id' => $branchId]);

        return $this->stockByArea($request, 'back');
    }

    private function stockByArea(Request $request, string $stockArea)
    {
        $company = app('company');
        $requestedBranchIds = $request->filled('branch_id')
            ? [(string) $request->input('branch_id')]
            : [];
        $scope = $this->productModeScope($request, $requestedBranchIds);
        $branchId = $requestedBranchIds[0] ?? $scope['context_branch_id'];
        $request->merge(['branch_id' => $branchId]);

        $perPage = (int)$request->get('per_page', 50);
        $perPage = $perPage > 0 && $perPage <= 200 ? $perPage : 50;

        $query = ProductStock::query()
            ->where('company_id', $company->id)
            ->where('stock_area', $stockArea)
            ->with([
                'branch',
                'product.category',
                'product.supplier',
                'product.uom',
                'product.costs',
            ]);

        $query->where('branch_id', $branchId);

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->filled('product_type')) {
            $query->whereHas('product', function ($productQuery) use ($request) {
                $productQuery->where('product_type', $request->product_type);
            });
        }

        if ($request->filled('stock_usage_type')) {
            $usage = strtolower(str_replace([' ', '-'], '_', (string) $request->stock_usage_type));
            $query->whereHas('product', function ($productQuery) use ($usage) {
                if ($usage === 'non_turnover') {
                    $productQuery->where(function ($scope) {
                        $scope->where('stock_usage_type', 'non_turnover')
                            ->orWhere(function ($fallback) {
                                $fallback->where(function ($empty) {
                                    $empty->whereNull('stock_usage_type')->orWhere('stock_usage_type', '');
                                })->whereIn(DB::raw('LOWER(product_type)'), ['consumable', 'consumables']);
                            });
                    });
                } elseif ($usage === 'turnover') {
                    $productQuery->where(function ($scope) {
                        $scope->where('stock_usage_type', 'turnover')
                            ->orWhere(function ($fallback) {
                                $fallback->where(function ($empty) {
                                    $empty->whereNull('stock_usage_type')->orWhere('stock_usage_type', '');
                                })->whereNotIn(DB::raw('LOWER(product_type)'), ['consumable', 'consumables']);
                            });
                    });
                }
            });
        }

        if ($request->filled('category_id')) {
            $query->whereHas('product', function ($productQuery) use ($request) {
                $productQuery->where('category_id', $request->category_id);
            });
        }

        if ($request->filled('q')) {
            $search = trim($request->q);

            $query->whereHas('product', function ($productQuery) use ($search) {
                $productQuery->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        $rows = $query
            ->orderByDesc('updated_at')
            ->paginate($perPage);

        $rows->getCollection()->transform(function ($stock) {
            $cost = optional($stock->product)->costs
                ? $stock->product->costs->firstWhere('branch_id', $stock->branch_id)
                : null;

            if (!$cost && optional($stock->product)->costs) {
                $cost = $stock->product->costs->first();
            }

            $stock->setAttribute('cost', $cost);
            $stock->setAttribute('average_cost', $cost?->average_cost);
            $stock->setAttribute('last_cost', $cost?->last_cost);
            $unitCost = (float) ($cost?->average_cost ?: $cost?->last_cost ?: 0);
            $stock->setAttribute('total_value', round((float) $stock->quantity_on_hand * $unitCost, 6));
            $stock->setAttribute('currency', $cost?->currency ?? $cost?->currency_code ?? 'USD');
            $stock->setAttribute('stock_usage_type', $this->resolveStockUsageType($stock->product));

            return $stock;
        });

        return response()->json([
            'message' => strtoupper($stockArea) . ' stock fetched successfully.',
            'stock_area' => $stockArea,
            'data' => $rows,
        ]);
    }


//    public function issueBackStockEnterprise(Request $request, CompanySettingService $settings)
//    {
//        $company = app('company');
//
//        if (!$settings->isFastFoodMode($company->id) || !$settings->isBohEnabled($company->id)) {
//            return response()->json([
//                'message' => 'BOH is not enabled for this company.',
//            ], 422);
//        }
//
//        $result = hasPermission(auth()->user()->role_id, 'boh_issue_stock', $company->id);
//        if ($result !== true) return $result;
//
//        $validator = Validator::make($request->all(), [
//            'branch_id' => 'required|exists:company_branches,id',
//            'notes' => 'nullable|string',
//            'items' => 'required|array|min:1',
//            'items.*.product_id' => 'required|exists:products,id',
//            'items.*.quantity' => 'required|numeric|min:0.0001',
//            'items.*.issue_destination' => 'nullable|in:auto,front,department,expense,production,wastage',
//            'items.*.department_name' => 'nullable|string|max:120',
//            'items.*.cost_center' => 'nullable|string|max:120',
//            'items.*.issue_purpose' => 'nullable|string|max:255',
//            'items.*.notes' => 'nullable|string',
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
//            $issued = [];
//            $summary = [
//                'turnover_lines' => 0,
//                'non_turnover_lines' => 0,
//                'front_quantity' => 0,
//                'internal_quantity' => 0,
//                'front_value' => 0,
//                'internal_value' => 0,
//            ];
//
//            foreach ($request->items as $item) {
//                $product = Product::where('company_id', $company->id)
//                    ->where('id', $item['product_id'])
//                    ->first();
//
//                if (!$product) {
//                    throw new \Exception('Product not found.');
//                }
//
//                $quantity = (float)$item['quantity'];
//                $productType = strtolower((string)$product->product_type);
//                $stockUsageType = $this->resolveStockUsageType($product);
//                $requestedDestination = strtolower((string)($item['issue_destination'] ?? 'auto'));
//                $destination = $this->resolveEnterpriseIssueDestination($product, $requestedDestination);
//
//                if ($stockUsageType === 'non_turnover' && $destination === 'front') {
//                    throw new \Exception("{$product->name} is non-turnover stock and cannot be issued to front stock. Issue it to department, expense, production usage or wastage.");
//                }
//
//                $backStock = $this->getOrCreateStock($product, $request->branch_id, 'back');
//
//                if ((float)$backStock->available_quantity < $quantity) {
//                    throw new \Exception("Insufficient BOH stock for {$product->name}.");
//                }
//
//                $cost = ProductCost::where('company_id', $company->id)
//                    ->where('branch_id', $request->branch_id)
//                    ->where('product_id', $product->id)
//                    ->first();
//
//                $unitCost = (float)($cost?->average_cost ?? $cost?->last_cost ?? 0);
//                $currencyCode = (string)($cost?->currency ?? 'USD');
//                $totalCost = round($quantity * $unitCost, 6);
//
//                $backStock->quantity_on_hand -= $quantity;
//                $backStock->available_quantity -= $quantity;
//                $backStock->save();
//
//                $quantityIn = 0;
//                $quantityOut = $quantity;
//                $toStockArea = $destination;
//                $movementType = $this->enterpriseIssueMovementType($destination);
//
//                if ($destination === 'front') {
//                    $frontStock = $this->getOrCreateStock($product, $request->branch_id, 'front');
//                    $frontStock->quantity_on_hand += $quantity;
//                    $frontStock->available_quantity += $quantity;
//                    $frontStock->save();
//
//                    // Keep legacy report compatibility: old BOH issue report reads both in/out on one movement.
//                    $quantityIn = $quantity;
//                    $quantityOut = $quantity;
//                    $toStockArea = 'front';
//                }
//
//                $metadata = [
//                    'enterprise_issue' => true,
//                    'stock_usage_type' => $stockUsageType,
//                    'turnover_class' => $stockUsageType === 'turnover' ? 'turnover_stock' : 'non_turnover_stock',
//                    'issue_destination' => $destination,
//                    'department_name' => $item['department_name'] ?? null,
//                    'cost_center' => $item['cost_center'] ?? null,
//                    'issue_purpose' => $item['issue_purpose'] ?? null,
//                    'product_type' => $productType,
//                    'pl_report_bucket' => $stockUsageType === 'non_turnover' ? 'operating_consumables' : 'cost_of_sales_stock_issue',
//                    'turnover_report_bucket' => $stockUsageType === 'turnover' ? 'turnover_stock_issue' : 'non_turnover_stock_issue',
//                ];
//
//                StockMovement::create([
//                    'company_id' => $company->id,
//                    'branch_id' => $request->branch_id,
//                    'product_id' => $product->id,
//                    'movement_type' => $movementType,
//                    'stock_area' => $destination === 'front' ? 'front' : 'back',
//                    'from_stock_area' => 'back',
//                    'to_stock_area' => $toStockArea,
//                    'quantity_in' => $quantityIn,
//                    'quantity_out' => $quantityOut,
//                    'unit_cost' => $unitCost,
//                    'total_cost' => $totalCost,
//                    'currency' => $currencyCode,
//                    'reference_type' => 'enterprise_stock_issue',
//                    'reference_id' => null,
//                    'notes' => $item['notes'] ?? $request->notes,
//                    'metadata' => $metadata,
//                    'created_by' => auth()->id(),
//                ]);
//
//                if ($stockUsageType === 'turnover') {
//                    $summary['turnover_lines']++;
//                } else {
//                    $summary['non_turnover_lines']++;
//                }
//
//                if ($destination === 'front') {
//                    $summary['front_quantity'] += $quantity;
//                    $summary['front_value'] += $totalCost;
//                } else {
//                    $summary['internal_quantity'] += $quantity;
//                    $summary['internal_value'] += $totalCost;
//                }
//
//                $issued[] = [
//                    'product_id' => $product->id,
//                    'product_name' => $product->name,
//                    'sku' => $product->sku,
//                    'code' => $product->code,
//                    'product_type' => $product->product_type,
//                    'stock_usage_type' => $stockUsageType,
//                    'issue_destination' => $destination,
//                    'quantity' => $quantity,
//                    'unit_cost' => $unitCost,
//                    'total_cost' => $totalCost,
//                    'currency' => $currencyCode,
//                    'from_stock_area' => 'back',
//                    'to_stock_area' => $toStockArea,
//                    'department_name' => $item['department_name'] ?? null,
//                    'cost_center' => $item['cost_center'] ?? null,
//                    'issue_purpose' => $item['issue_purpose'] ?? null,
//                ];
//            }
//
//            DB::commit();
//
//            return response()->json([
//                'message' => 'Enterprise stock issue posted successfully.',
//                'data' => $issued,
//                'summary' => [
//                    'turnover_lines' => $summary['turnover_lines'],
//                    'non_turnover_lines' => $summary['non_turnover_lines'],
//                    'front_quantity' => round($summary['front_quantity'], 6),
//                    'internal_quantity' => round($summary['internal_quantity'], 6),
//                    'front_value' => round($summary['front_value'], 6),
//                    'internal_value' => round($summary['internal_value'], 6),
//                ],
//            ], 200);
//        } catch (\Exception $e) {
//            DB::rollBack();
//
//            return response()->json([
//                'message' => $e->getMessage(),
//            ], 422);
//        }
//    }

//    private function resolveStockUsageType(Product $product): string
//    {
//        if (Schema::hasColumn('products', 'stock_usage_type') && !empty($product->stock_usage_type)) {
//            return strtolower((string)$product->stock_usage_type) === 'non_turnover' ? 'non_turnover' : 'turnover';
//        }
//
//        $type = strtolower((string)$product->product_type);
//
//        // Cleaning materials, office consumables and similar products are received into stock,
//        // but when issued they become operating consumption, not front selling stock.
//        if (in_array($type, ['consumable', 'consumables'], true)) {
//            return 'non_turnover';
//        }
//
//        return 'turnover';
//    }

    public function issueBackStockEnterprise(
        Request $request,
        CompanySettingService $settings,
        HospitalityStockIssueService $stockIssueService
    )
    {
        $company = app('company');
        $branchId = $this->resolveCompanyBranchId($request, $company);

        if (!$branchId || !$settings->isHospitalityMode($company->id, $branchId)) {
            return $this->hospitalityModeDenied($branchId);
        }

        $request->merge(['branch_id' => $branchId]);

        if (!$request->filled('idempotency_key') && $request->hasHeader('Idempotency-Key')) {
            $request->merge(['idempotency_key' => $request->header('Idempotency-Key')]);
        }

        $validator = Validator::make($request->all(), [
            'branch_id' => [
                'required',
                Rule::exists('company_branches', 'id')->where('company_id', $company->id),
            ],
            'issue_name' => 'required|string|max:180',
            'issue_type' => 'required|in:front,department,expense,production,wastage|same:issue_destination',
            'issue_destination' => 'required|in:front,department,expense,production,wastage',
            'department_name' => 'nullable|string|max:120',
            'cost_center' => 'nullable|string|max:120',
            'issue_purpose' => 'required|string|max:255',
            'notes' => 'nullable|string',
            'idempotency_key' => 'nullable|uuid',
            'items' => 'required|array|min:1',
            'items.*.product_id' => [
                'required',
                'distinct',
                Rule::exists('products', 'id')->where('company_id', $company->id),
            ],
            'items.*.quantity' => 'required|numeric|min:0.0001',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $requestingUser = $request->user() ?? auth()->user();
            $requesterName = trim((string) (
                $requestingUser?->name
                ?: $requestingUser?->username
                ?: $requestingUser?->employee_code
                ?: $requestingUser?->id
            ));

            $posting = $stockIssueService->issue([
                'company_id' => (string) $company->id,
                'branch_id' => $branchId,
                'reference_type' => 'enterprise_stock_issue',
                'idempotency_key' => $request->input('idempotency_key'),
                'issue_name' => $request->issue_name,
                'issue_type' => $request->issue_type,
                'issue_destination' => $request->issue_destination,
                'department_name' => $request->department_name,
                'cost_center' => $request->cost_center,
                // The requester is an audit identity, never client-editable input.
                'requested_by' => $requesterName,
                'requested_by_id' => $requestingUser?->id,
                'issue_purpose' => $request->issue_purpose,
                'notes' => $request->notes,
                'items' => $request->items,
                'user_id' => $requestingUser?->id,
            ]);

            if (!$posting['idempotent_replay']) {
                $this->writeAuditTrail($request, [
                    'company_id' => $company->id,
                    'branch_id' => $branchId,
                    'action' => 'hospitality_stock_issue_posted',
                    'module' => 'hospitality_inventory',
                    'description' => "Posted {$request->issue_name} from Back Stock to {$request->issue_destination}.",
                    'auditable_type' => 'enterprise_stock_issue',
                    'auditable_id' => $posting['issue_id'],
                    'new_values' => $posting,
                    'metadata' => [
                        'stock_source' => 'back',
                        'stock_destination' => $request->issue_destination,
                        'idempotency_key' => $request->input('idempotency_key'),
                    ],
                ]);
            }

            return response()->json([
                'message' => $posting['idempotent_replay']
                    ? 'Enterprise stock issue was already posted; the original result was returned.'
                    : 'Enterprise stock issue posted successfully.',
            ] + $posting, 200);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            return response()->json([
                'message' => collect($exception->errors())->flatten()->first() ?: 'Validation error.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'The stock issue could not be posted safely. No stock was moved.',
            ], 422);
        }
    }

    private function resolveStockUsageType(Product $product): string
    {
        if (Schema::hasColumn('products', 'stock_usage_type') && !empty($product->stock_usage_type)) {
            return strtolower((string) $product->stock_usage_type) === 'non_turnover' ? 'non_turnover' : 'turnover';
        }

        $type = strtolower((string) $product->product_type);

        if (in_array($type, ['consumable', 'consumables'], true)) {
            return 'non_turnover';
        }

        return 'turnover';
    }

    public function issueToFront(
        Request $request,
        CompanySettingService $settings,
        HospitalityStockIssueService $stockIssueService
    )
    {
        $company = app('company');
        $branchId = $this->resolveCompanyBranchId($request, $company);

        if (!$branchId || !$settings->isHospitalityMode($company->id, $branchId)) {
            return $this->hospitalityModeDenied($branchId);
        }

        $request->merge(['branch_id' => $branchId]);

        if (!$request->filled('idempotency_key') && $request->hasHeader('Idempotency-Key')) {
            $request->merge(['idempotency_key' => $request->header('Idempotency-Key')]);
        }

        $validator = Validator::make($request->all(), [
            'branch_id' => [
                'required',
                Rule::exists('company_branches', 'id')->where('company_id', $company->id),
            ],
            'items' => 'required|array|min:1',
            'items.*.product_id' => [
                'required',
                'distinct',
                Rule::exists('products', 'id')->where('company_id', $company->id),
            ],
            'items.*.quantity' => 'required|numeric|min:0.0001',
            'notes' => 'nullable|string',
            'idempotency_key' => 'nullable|uuid',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $requestingUser = $request->user() ?? auth()->user();
            $requesterName = trim((string) (
                $requestingUser?->name
                ?: $requestingUser?->username
                ?: $requestingUser?->employee_code
                ?: $requestingUser?->id
            ));

            $posting = $stockIssueService->issue([
                'company_id' => (string) $company->id,
                'branch_id' => $branchId,
                'reference_type' => 'boh_issue_to_front',
                'idempotency_key' => $request->input('idempotency_key'),
                'issue_name' => 'Issue to Front of House',
                'issue_type' => 'front',
                'issue_destination' => 'front',
                'requested_by' => $requesterName,
                'requested_by_id' => $requestingUser?->id,
                'issue_purpose' => $request->notes ?: 'Back Stock transfer to Front of House',
                'notes' => $request->notes,
                'items' => $request->items,
                'user_id' => $requestingUser?->id,
            ]);

            if (!$posting['idempotent_replay']) {
                $this->writeAuditTrail($request, [
                    'company_id' => $company->id,
                    'branch_id' => $branchId,
                    'action' => 'hospitality_stock_issued_to_front',
                    'module' => 'hospitality_inventory',
                    'description' => 'Transferred stock from Back Stock to Front of House.',
                    'auditable_type' => 'boh_issue_to_front',
                    'auditable_id' => $posting['issue_id'],
                    'new_values' => $posting,
                    'metadata' => [
                        'stock_source' => 'back',
                        'stock_destination' => 'front',
                        'idempotency_key' => $request->input('idempotency_key'),
                    ],
                ]);
            }

            return response()->json([
                'message' => $posting['idempotent_replay']
                    ? 'This stock transfer was already posted; the original result was returned.'
                    : 'Stock issued to Front of House successfully.',
            ] + $posting, 200);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            return response()->json([
                'message' => collect($exception->errors())->flatten()->first() ?: 'Validation error.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'The Front of House transfer could not be posted safely. No stock was moved.',
            ], 422);
        }
    }

    private function loadProduct(string $id, ?Request $request = null)
    {
        $company = app('company');
        $request ??= request();
        $scope = $this->productModeScope($request);
        $branchIds = $scope['branch_ids'];

        return $this->applyProductModeVisibility(
            Product::where('company_id', $company->id),
            $branchIds
        )->where('id', $id)
            ->with([
                'branch',
                'category',
                'supplier',
                'parent' => fn ($query) => $this->applyProductModeVisibility($query, $branchIds),
                'subCategory',
                'children' => fn ($query) => $this->applyProductModeVisibility($query, $branchIds),
                'uom',
                'tax',
                'prices' => function ($query) use ($branchIds) {
                    $query->where(function ($scope) use ($branchIds) {
                        $scope->whereNull('branch_id')->orWhereIn('branch_id', $branchIds);
                    })->with('priceLevel');
                },
                'stocks' => fn ($query) => $query->whereIn('branch_id', $branchIds),
                'costs' => fn ($query) => $query->whereIn('branch_id', $branchIds),
                'barcodes',
                'suppliers.supplier',
                'discounts',
                'childBreakRule.parentProduct',
                'parentBreakRules.childProduct',
            ])
            ->first();
    }

    private function breakChildProduct(Product $child, string $branchId, int $breakTimes = 1): void
    {
        $rule = ProductBreakRule::where('company_id', $child->company_id)
            ->where('child_product_id', $child->id)
            ->where('is_active', true)
            ->first();

        if (!$rule) {
            throw new \Exception('No break rule configured for this child product.');
        }

        $parent = Product::where('company_id', $child->company_id)
            ->where('id', $rule->parent_product_id)
            ->first();

        if (!$parent) {
            throw new \Exception('Parent product not found.');
        }

        $parentQtyNeeded = $rule->parent_quantity * $breakTimes;
        $childQtyCreated = $rule->units_per_parent * $breakTimes;

        $parentStock = $this->getOrCreateStock($parent, $branchId);
        $childStock = $this->getOrCreateStock($child, $branchId);

        if ($parentStock->available_quantity < $parentQtyNeeded) {
            throw new \Exception('Insufficient parent stock.');
        }

        $parentStock->quantity_on_hand -= $parentQtyNeeded;
        $parentStock->available_quantity -= $parentQtyNeeded;
        $parentStock->save();

        $childStock->quantity_on_hand += $childQtyCreated;
        $childStock->available_quantity += $childQtyCreated;
        $childStock->save();

        $parentCost = ProductCost::where('company_id', $parent->company_id)
            ->where('branch_id', $branchId)
            ->where('product_id', $parent->id)
            ->first();

        $parentUnitCost = $parentCost?->average_cost ?? 0;
        $totalCostMoved = $parentUnitCost * $parentQtyNeeded;
        $childUnitCost = $childQtyCreated > 0
            ? $totalCostMoved / $childQtyCreated
            : 0;

        $this->addAverageCost($child, $branchId, $childQtyCreated, $childUnitCost);

        StockMovement::create([
            'company_id' => $parent->company_id,
            'branch_id' => $branchId,
            'product_id' => $parent->id,
            'movement_type' => 'break_parent',
            'quantity_in' => 0,
            'quantity_out' => $parentQtyNeeded,
            'unit_cost' => $parentUnitCost,
            'total_cost' => $totalCostMoved,
            'notes' => 'Parent product broken into child units',
            'created_by' => auth()->id(),
        ]);

        StockMovement::create([
            'company_id' => $child->company_id,
            'branch_id' => $branchId,
            'product_id' => $child->id,
            'movement_type' => 'create_child',
            'quantity_in' => $childQtyCreated,
            'quantity_out' => 0,
            'unit_cost' => $childUnitCost,
            'total_cost' => $totalCostMoved,
            'notes' => 'Child units created from parent product',
            'created_by' => auth()->id(),
        ]);
    }

    private function addAverageCost(Product $product, string $branchId, float $quantity, float $unitCost): void
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

        $newValue = $quantity * $unitCost;
        $newTotalQuantity = $cost->total_quantity + $quantity;
        $newTotalValue = $cost->total_value + $newValue;

        $cost->average_cost = $newTotalQuantity > 0
            ? $newTotalValue / $newTotalQuantity
            : 0;

        $cost->last_cost = $unitCost;
        $cost->total_quantity = $newTotalQuantity;
        $cost->total_value = $newTotalValue;
        $cost->save();
    }

    private function syncPrices(Product $product, array $prices): void
    {
        foreach ($prices as $price) {
            ProductPrice::create([
                'company_id' => $product->company_id,
                'product_id' => $product->id,
                'branch_id' => $price['branch_id'] ?? null,
                'price_level_id' => $price['price_level_id'],
                'price' => $price['price'],
                'currency' => $price['currency'] ?? 'USD',
                'is_default' => $price['is_default'] ?? false,
                'is_active' => true,
                'starts_at' => $price['starts_at'] ?? null,
                'ends_at' => $price['ends_at'] ?? null,
            ]);
        }
    }

    private function syncBarcodes(Product $product, array $barcodes): void
    {
        foreach ($barcodes as $barcode) {
            ProductBarcode::create([
                'company_id' => $product->company_id,
                'product_id' => $product->id,
                'barcode' => $barcode['barcode'],
                'type' => $barcode['type'] ?? 'alternative',
                'is_active' => true,
            ]);
        }
    }

    private function syncSuppliers(Product $product, array $suppliers): void
    {
        foreach ($suppliers as $supplier) {
            ProductSupplier::create([
                'company_id' => $product->company_id,
                'product_id' => $product->id,
                'supplier_id' => $supplier['supplier_id'],
                'supplier_code' => $supplier['supplier_code'] ?? null,
                'last_purchase_price' => $supplier['last_purchase_price'] ?? 0,
                'is_default' => $supplier['is_default'] ?? false,
            ]);
        }
    }

    private function syncDiscounts(Product $product, array $discountIds): void
    {
        $syncData = [];

        foreach ($discountIds as $discountId) {
            $syncData[$discountId] = [
                'company_id' => $product->company_id,
            ];
        }

        $product->discounts()->sync($syncData);
    }

    private function saveBreakRule(Product $product, array $rule): void
    {
        ProductBreakRule::updateOrCreate(
            [
                'company_id' => $product->company_id,
                'child_product_id' => $product->id,
            ],
            [
                'parent_product_id' => $rule['parent_product_id'],
                'parent_quantity' => $rule['parent_quantity'] ?? 1,
                'units_per_parent' => $rule['units_per_parent'],
                'is_auto_break' => $rule['is_auto_break'] ?? true,
                'is_active' => true,
            ]
        );
    }

    private function getSafeSessionId(Request $request): ?string
    {
        try {
            return $request->hasSession() ? $request->session()->getId() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function writeAuditTrail(Request $request, array $data): void
    {
        try {
            $user = auth()->user();

            AuditTrail::create([
                'company_id' => $data['company_id'] ?? null,
                'branch_id' => $data['branch_id'] ?? null,
                'user_id' => $user?->id,
                'user_name' => $user?->name ?? $user?->username ?? null,
                'user_email' => $user?->email ?? null,
                'user_role' => $user?->role?->name ?? $user?->role_id ?? null,

                'action' => $data['action'],
                'module' => $data['module'] ?? 'inventory',
                'description' => $data['description'] ?? null,

                'auditable_type' => $data['auditable_type'] ?? null,
                'auditable_id' => $data['auditable_id'] ?? null,

                'old_values' => $data['old_values'] ?? null,
                'new_values' => $data['new_values'] ?? null,
                'changed_fields' => $data['changed_fields'] ?? null,

                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'device_name' => $request->header('X-Device-Name'),
                'terminal_name' => $request->header('X-Terminal-Name'),
                'session_id' => $this->getSafeSessionId($request),

                'status' => $data['status'] ?? 'success',
                'error_message' => $data['error_message'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]);
        } catch (\Throwable $e) {
            logger()->error('Failed to write stock adjustment audit trail', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function getOrCreateStock(Product $product, string $branchId, string $stockArea = 'front'): ProductStock
    {
        return ProductStock::firstOrCreate(
            [
                'company_id' => $product->company_id,
                'branch_id' => $branchId,
                'product_id' => $product->id,
                'stock_area' => $stockArea,
            ],
            [
                'quantity_on_hand' => 0,
                'reserved_quantity' => 0,
                'available_quantity' => 0,
            ]
        );
    }

    private function receiveProductStock(
        Product $product,
        string  $branchId,
        float   $quantity,
        float   $unitCost,
        ?string $currency,
        string  $movementType,
        ?string $referenceId = null,
        ?string $notes = null,
        string  $stockArea = 'front'
    ): void
    {
        $currencyCode = strtoupper($currency ?: 'USD');

        $stock = $this->getOrCreateStock($product, $branchId, $stockArea);

        $stock->quantity_on_hand = (float)$stock->quantity_on_hand + $quantity;
        $stock->available_quantity = (float)$stock->available_quantity + $quantity;
        $stock->save();

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
                'currency_code' => $currencyCode,
            ]
        );

        $oldTotalValue = (float)$cost->total_value;
        $oldTotalQuantity = (float)$cost->total_quantity;

        $newPurchaseValue = $quantity * $unitCost;
        $newTotalQuantity = $oldTotalQuantity + $quantity;
        $newTotalValue = $oldTotalValue + $newPurchaseValue;

        $cost->average_cost = $newTotalQuantity > 0
            ? $newTotalValue / $newTotalQuantity
            : 0;

        $cost->last_cost = $unitCost;
        $cost->total_quantity = $newTotalQuantity;
        $cost->total_value = $newTotalValue;
        $cost->currency_code = $currencyCode;
        $cost->save();

        StockMovement::create([
            'company_id' => $product->company_id,
            'branch_id' => $branchId,
            'product_id' => $product->id,
            'movement_type' => $movementType,
            'stock_area' => $stockArea,
            'quantity_in' => $quantity,
            'quantity_out' => 0,
            'unit_cost' => $unitCost,
            'total_cost' => $quantity * $unitCost,
            'currency' => $currencyCode,
            'currency_code' => $currencyCode,
            'reference_type' => 'product',
            'reference_id' => $referenceId ?: $product->id,
            'notes' => $notes,
            'created_by' => auth()->id(),
        ]);
    }


    private function autoBreakChildProduct(Product $child, string $branchId, int $breakTimes = 1): void
    {
        $rule = ProductBreakRule::where('company_id', $child->company_id)
            ->where('child_product_id', $child->id)
            ->where('is_active', true)
            ->first();

        if (!$rule) {
            throw new \Exception('No break rule configured for this child product.');
        }

        $parent = Product::find($rule->parent_product_id);

        if (!$parent) {
            throw new \Exception('Parent product not found.');
        }

        $parentQtyNeeded = $rule->parent_quantity * $breakTimes;
        $childQtyCreated = $rule->child_quantity * $breakTimes;

        $parentStock = $this->getOrCreateStock($parent, $branchId);
        $childStock = $this->getOrCreateStock($child, $branchId);

        if ($parentStock->available_quantity < $parentQtyNeeded) {
            throw new \Exception('Insufficient parent product stock.');
        }

        $parentStock->quantity_on_hand -= $parentQtyNeeded;
        $parentStock->available_quantity -= $parentQtyNeeded;
        $parentStock->save();

        $childStock->quantity_on_hand += $childQtyCreated;
        $childStock->available_quantity += $childQtyCreated;
        $childStock->save();

        $parentCost = ProductCost::where('company_id', $parent->company_id)
            ->where('branch_id', $branchId)
            ->where('product_id', $parent->id)
            ->first();

        $unitParentCost = $parentCost?->average_cost ?? 0;
        $totalParentCost = $unitParentCost * $parentQtyNeeded;
        $childUnitCost = $childQtyCreated > 0
            ? $totalParentCost / $childQtyCreated
            : 0;

        $this->updateChildAverageCost($child, $branchId, $childQtyCreated, $childUnitCost);

        StockMovement::create([
            'company_id' => $parent->company_id,
            'branch_id' => $branchId,
            'product_id' => $parent->id,
            'movement_type' => 'break_parent',
            'quantity_in' => 0,
            'quantity_out' => $parentQtyNeeded,
            'unit_cost' => $unitParentCost,
            'total_cost' => $totalParentCost,
            'notes' => 'Auto/manual product break parent deduction',
            'created_by' => auth()->id(),
        ]);

        StockMovement::create([
            'company_id' => $child->company_id,
            'branch_id' => $branchId,
            'product_id' => $child->id,
            'movement_type' => 'create_child',
            'quantity_in' => $childQtyCreated,
            'quantity_out' => 0,
            'unit_cost' => $childUnitCost,
            'total_cost' => $totalParentCost,
            'notes' => 'Auto/manual product break child creation',
            'created_by' => auth()->id(),
        ]);
    }

    private function updateChildAverageCost(
        Product $child,
        string  $branchId,
        float   $quantity,
        float   $unitCost
    ): void
    {
        $cost = ProductCost::firstOrCreate(
            [
                'company_id' => $child->company_id,
                'branch_id' => $branchId,
                'product_id' => $child->id,
            ],
            [
                'average_cost' => 0,
                'last_cost' => 0,
                'total_quantity' => 0,
                'total_value' => 0,
            ]
        );

        $newValue = $quantity * $unitCost;
        $newTotalQuantity = $cost->total_quantity + $quantity;
        $newTotalValue = $cost->total_value + $newValue;

        $cost->average_cost = $newTotalQuantity > 0
            ? $newTotalValue / $newTotalQuantity
            : 0;

        $cost->last_cost = $unitCost;
        $cost->total_quantity = $newTotalQuantity;
        $cost->total_value = $newTotalValue;
        $cost->save();
    }

    public function generateIdentity(Request $request, ProductIdentityGenerator $generator)
    {
        $company = app('company');

        $validator = Validator::make($request->all(), [
            'branch_id' => [
                'nullable',
                Rule::exists('company_branches', 'id')->where('company_id', $company->id),
            ],
            'type' => 'required|in:product_code,sku',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $requestedBranchIds = $request->filled('branch_id')
            ? [(string) $request->input('branch_id')]
            : [];
        $scope = $this->productModeScope($request, $requestedBranchIds);
        $branchId = $requestedBranchIds[0] ?? $scope['context_branch_id'];

        $value = match ($request->type) {
            'product_code' => $generator->generateProductCode($company->id, $branchId),
            'sku' => $generator->generateSku($company->id, $branchId),
        };

        return response()->json([
            'message' => 'Generated successfully.',
            'data' => [
                'type' => $request->type,
                'value' => $value,
            ],
        ]);
    }

    public function getUoms(Request $request)
    {
        $company = app('company');

        $query = Uom::query()
            ->availableTo((string) $company->id)
            ->where('is_active', true)
            ->orderBy('name');

        if ($request->filled('q')) {
            $q = $request->q;

            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")
                    ->orWhere('code', 'like', "%{$q}%")
                    ->orWhere('quantity_type', 'like', "%{$q}%");
            });
        }

        return response()->json([
            'message' => 'UOMs fetched successfully.',
            'data' => $query->get(),
        ], 200);
    }

    public function getPriceLevels(Request $request)
    {
        $company = app('company');

        $query = PriceLevel::where('company_id', $company->id)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name');

        if ($request->filled('q')) {
            $q = $request->q;

            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")
                    ->orWhere('code', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%");
            });
        }

        return response()->json([
            'message' => 'Price levels fetched successfully.',
            'data' => $query->get(),
        ], 200);
    }


    private function validateSelectedBaseCurrency(
        string  $companyId,
        ?string $branchId,
        ?string $currencyCode
    ): string
    {
        if (!$currencyCode) {
            throw new \Exception('Currency is required.');
        }

        $currencyCode = strtoupper(trim($currencyCode));

        $currency = Currency::where('company_id', $companyId)
            ->where('code', $currencyCode)
            ->where('is_active', true)
            ->where('is_base_currency', true)
            ->where(function ($query) use ($branchId) {
                $query->whereNull('branch_id');

                if ($branchId) {
                    $query->orWhere('branch_id', $branchId);
                }
            })
            ->first();

        if (!$currency) {
            throw new \Exception(
                'Selected currency must exist and must be the active base currency.'
            );
        }

        return $currencyCode;
    }

//    public function uploadImage(Request $request, string $id)
//    {
//        $company = app('company');
//
//        $result = hasPermission(auth()->user()->role_id, 'product_management', $company->id);
//        if ($result !== true) return $result;
//
//        $product = Product::where('company_id', $company->id)
//            ->where('id', $id)
//            ->first();
//
//        if (!$product) {
//            return response()->json([
//                'message' => 'Product not found.',
//            ], 404);
//        }
//
//        $validator = Validator::make($request->all(), [
//            'image' => 'required|image|mimes:jpg,jpeg,png,webp|max:4096',
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
//            $relativeDirectory = 'menu item images/' . $company->id;
//            $absoluteDirectory = base_path('assets/' . $relativeDirectory);
//
//            if (!File::exists($absoluteDirectory)) {
//                File::makeDirectory($absoluteDirectory, 0755, true);
//            }
//
//            if (!empty($product->image_path)) {
//                $oldRelativePath = ltrim(str_replace('assets/', '', $product->image_path), '/');
//                $oldAbsolutePath = base_path('assets/' . $oldRelativePath);
//
//                if (File::exists($oldAbsolutePath) && File::isFile($oldAbsolutePath)) {
//                    File::delete($oldAbsolutePath);
//                }
//            }
//
//            $file = $request->file('image');
//            $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
//
//            $safeName = Str::slug($product->name ?: 'menu-item');
//            $filename = $safeName . '-' . $product->id . '-' . time() . '.' . $extension;
//
//            $file->move($absoluteDirectory, $filename);
//
//            $relativePath = $relativeDirectory . '/' . $filename;
//
//            $product->image_path = 'assets/' . $relativePath;
//            $product->image_url = url('api/assets/' . str_replace(' ', '%20', $relativePath));
//            $product->save();
//
//            DB::commit();
//
//            return response()->json([
//                'message' => 'Menu item image uploaded successfully.',
//                'data' => $this->loadProduct($product->id),
//                'image_path' => $product->image_path,
//                'image_url' => $product->image_url,
//            ], 200);
//        } catch (\Exception $e) {
//            DB::rollBack();
//
//            return response()->json([
//                'message' => 'Something went wrong while uploading menu item image.',
//                'error' => $e->getMessage(),
//            ], 500);
//        }
//    }
//
//    public function deleteImage(string $id)
//    {
//        $company = app('company');
//
//        $result = hasPermission(auth()->user()->role_id, 'product_management', $company->id);
//        if ($result !== true) return $result;
//
//        $product = Product::where('company_id', $company->id)
//            ->where('id', $id)
//            ->first();
//
//        if (!$product) {
//            return response()->json([
//                'message' => 'Product not found.',
//            ], 404);
//        }
//
//        DB::beginTransaction();
//
//        try {
//            if (!empty($product->image_path)) {
//                $oldRelativePath = ltrim(str_replace('assets/', '', $product->image_path), '/');
//                $oldAbsolutePath = base_path('assets/' . $oldRelativePath);
//
//                if (File::exists($oldAbsolutePath) && File::isFile($oldAbsolutePath)) {
//                    File::delete($oldAbsolutePath);
//                }
//            }
//
//            $product->image_path = null;
//            $product->image_url = null;
//            $product->save();
//
//            DB::commit();
//
//            return response()->json([
//                'message' => 'Menu item image removed successfully.',
//                'data' => $this->loadProduct($product->id),
//            ], 200);
//        } catch (\Exception $e) {
//            DB::rollBack();
//
//            return response()->json([
//                'message' => 'Something went wrong while removing menu item image.',
//                'error' => $e->getMessage(),
//            ], 500);
//        }
//    }


    public function uploadImage(Request $request, string $id)
    {
        $company = app('company');

        $result = hasPermission(auth()->user()->role_id, 'product_management', $company->id);
        if ($result !== true) {
            return $result;
        }

        $scope = $this->productModeScope($request);
        $product = $this->applyProductModeVisibility(
            Product::where('company_id', $company->id),
            $scope['branch_ids']
        )->where('id', $id)
            ->first();

        if (!$product) {
            return response()->json([
                'message' => 'Product not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'image' => 'required|image|mimes:jpg,jpeg,png,webp|max:4096|dimensions:max_width=8000,max_height=8000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $oldImagePath = $product->image_path;
        $fullPath = null;
        DB::beginTransaction();

        try {
            $file = $request->file('image');

            if (!$file || !$file->isValid()) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Invalid uploaded image.',
                    'error' => $file ? $file->getErrorMessage() : 'No file received.',
                ], 422);
            }

            /*
             |--------------------------------------------------------------------------
             | Public assets folder
             |--------------------------------------------------------------------------
             | Localhost/XAMPP:
             | base_path() = C:/xampp/htdocs/salexaapi/api
             | assets root = C:/xampp/htdocs/salexaapi/assets
             |
             | Nginx:
             | base_path() = C:/salexaapi/api
             | assets root = C:/salexaapi/assets
             |--------------------------------------------------------------------------
             */
            $relativeDirectory = 'product-images/' . $company->id;
            $assets = app(PublicAssetStorage::class);
            $absoluteDirectory = $assets->ensureDirectory($relativeDirectory);

            $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');

            $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
            if (!in_array($extension, $allowedExtensions, true)) {
                $extension = 'jpg';
            }

            $safeName = Str::slug($product->name ?: 'product');
            $filename = $safeName . '-' . $product->id . '-' . time() . '.' . $extension;

            $file->move($absoluteDirectory, $filename);

            $fullPath = $absoluteDirectory . DIRECTORY_SEPARATOR . $filename;

            $assets->hardenFile($fullPath);

            if (!File::exists($fullPath) || !File::isFile($fullPath)) {
                throw new \Exception('Image file was not saved correctly.');
            }

            if (@getimagesize($fullPath) === false) {
                File::delete($fullPath);
                DB::rollBack();

                return response()->json([
                    'message' => 'Uploaded file was saved but is not a valid image.',
                ], 422);
            }

            $relativePath = $relativeDirectory . '/' . $filename;

            $product->image_path = 'assets/' . $relativePath;
            $product->image_url = $this->publicAssetUrl($relativePath);

            $product->save();

            DB::commit();
            $this->deleteStoredAsset($oldImagePath);

            return response()->json([
                'message' => 'Product image uploaded successfully.',
                'data' => $this->loadProduct($product->id, $request),
                'image_path' => $product->image_path,
                'image_url' => $product->image_url,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            if ($fullPath && File::isFile($fullPath)) {
                File::delete($fullPath);
            }

            return response()->json([
                'message' => 'Something went wrong while uploading menu item image.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function deleteImage(Request $request, string $id)
    {
        $company = app('company');

        $result = hasPermission(auth()->user()->role_id, 'product_management', $company->id);
        if ($result !== true) {
            return $result;
        }

        $scope = $this->productModeScope($request);
        $product = $this->applyProductModeVisibility(
            Product::where('company_id', $company->id),
            $scope['branch_ids']
        )->where('id', $id)
            ->first();

        if (!$product) {
            return response()->json([
                'message' => 'Product not found.',
            ], 404);
        }

        DB::beginTransaction();

        try {
            $oldImagePath = $product->image_path;

            $product->image_path = null;
            $product->image_url = null;
            $product->save();

            DB::commit();
            $this->deleteStoredAsset($oldImagePath);

            return response()->json([
                'message' => 'Product image removed successfully.',
                'data' => $this->loadProduct($product->id, $request),
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong while removing menu item image.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Encode each part of a URL path safely.
     *
     * Example:
     * menu-item-images/company id/file name.png
     * becomes:
     * menu-item-images/company%20id/file%20name.png
     */
    private function publicAssetsRoot(): string
    {
        /*
         * Laravel app folder:
         * C:/xampp/htdocs/salexaapi/api
         *
         * Public project folder:
         * C:/xampp/htdocs/salexaapi
         *
         * So assets must be saved one level above /api.
         */
        return app(PublicAssetStorage::class)->root();
    }

    private function publicAssetsPath(string $relativeDirectory): string
    {
        return app(PublicAssetStorage::class)->path($relativeDirectory);
    }

    private function publicAssetUrl(string $relativePath): string
    {
        $relativePath = $this->encodeUrlPath($relativePath);

        $host = rtrim(request()->getSchemeAndHttpHost(), '/');

        /*
         * Localhost/XAMPP API base URL is usually:
         * http://localhost/salexaapi/api
         *
         * But public assets are:
         * http://localhost/salexaapi/assets
         *
         * So remove trailing /api from the base path.
         */
        $basePath = rtrim(request()->getBaseUrl(), '/');

        if (Str::endsWith($basePath, '/api')) {
            $basePath = Str::beforeLast($basePath, '/api');
        }

        return $host . $basePath . '/assets/' . $relativePath;
    }

    private function deleteStoredAsset(?string $path): void
    {
        if (empty($path)) {
            return;
        }

        $relativePath = ltrim(str_replace('\\', '/', $path), '/');

        if (Str::startsWith($relativePath, 'assets/')) {
            $relativePath = Str::after($relativePath, 'assets/');
        }

        $absolutePath = $this->publicAssetsPath(dirname($relativePath))
            . DIRECTORY_SEPARATOR
            . basename($relativePath);

        if (File::exists($absolutePath) && File::isFile($absolutePath)) {
            File::delete($absolutePath);
        }
    }


    private function productRequestValue(Request $request, string $key, mixed $default = null): mixed
    {
        if ($request->has($key)) {
            return $request->input($key);
        }

        return $default;
    }

    private function normalizeProductToken(?string $value): string
    {
        return str_replace([' ', '-'], '_', strtolower(trim((string) $value)));
    }

    private function resolveProductStockUsageTypeFromRequest(Request $request): string
    {
        $requested = $this->normalizeProductToken((string) $this->productRequestValue($request, 'stock_usage_type', ''));

        if (in_array($requested, ['turnover', 'turn_over'], true)) {
            return 'turnover';
        }

        if (in_array($requested, ['non_turnover', 'nonturnover', 'non_turn_over'], true)) {
            return 'non_turnover';
        }

        $productType = $this->normalizeProductToken((string) $this->productRequestValue($request, 'product_type', ''));

        if (in_array($productType, ['consumable', 'consumables'], true)) {
            return 'non_turnover';
        }

        return 'turnover';
    }

    private function resolveProductDefaultIssueDestinationFromRequest(Request $request): string
    {
        $usageType = $this->resolveProductStockUsageTypeFromRequest($request);
        $requested = $this->normalizeProductToken((string) $this->productRequestValue($request, 'default_issue_destination', ''));

        if (in_array($requested, ['front', 'department', 'expense', 'production', 'wastage'], true)) {
            if ($usageType === 'non_turnover' && $requested === 'front') {
                return 'expense';
            }

            return $requested;
        }

        return $usageType === 'non_turnover' ? 'expense' : 'front';
    }

    private function buildProductStockUsagePayload(Request $request): array
    {
        $payload = [];

        if (Schema::hasColumn('products', 'stock_usage_type')) {
            $payload['stock_usage_type'] = $this->resolveProductStockUsageTypeFromRequest($request);
        }

        if (Schema::hasColumn('products', 'default_issue_destination')) {
            $payload['default_issue_destination'] = $this->resolveProductDefaultIssueDestinationFromRequest($request);
        }

        if (Schema::hasColumn('products', 'expense_account_code')) {
            $payload['expense_account_code'] = $request->filled('expense_account_code')
                ? trim((string) $request->input('expense_account_code'))
                : null;
        }

        if (Schema::hasColumn('products', 'expense_category')) {
            $payload['expense_category'] = $request->filled('expense_category')
                ? trim((string) $request->input('expense_category'))
                : null;
        }

        return $payload;
    }

    private function resolveCompanyBranchId(Request $request, mixed $company): ?string
    {
        if (!$company) return null;

        $requestedBranchIds = $request->filled('branch_id')
            ? [(string) $request->input('branch_id')]
            : [];
        $scope = $this->productModeScope($request, $requestedBranchIds);

        return $requestedBranchIds[0] ?? $scope['context_branch_id'];
    }

    private function companyBranchId(mixed $company, mixed $branchId): ?string
    {
        if (!$company || !$branchId) {
            return null;
        }

        $resolvedId = $company->branches()
            ->whereKey($branchId)
            ->value('id');

        return $resolvedId ? (string) $resolvedId : null;
    }

    private function hospitalityModeDenied(?string $branchId)
    {
        return response()->json([
            'message' => $branchId
                ? 'Hospitality operations are not enabled for the selected branch.'
                : 'A valid company branch is required for hospitality operations.',
            'code' => $branchId
                ? 'HOSPITALITY_MODE_REQUIRED'
                : 'HOSPITALITY_BRANCH_REQUIRED',
        ], 403);
    }

    private function encodeUrlPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return collect(explode('/', $path))
            ->map(fn($part) => rawurlencode($part))
            ->implode('/');
    }
}
