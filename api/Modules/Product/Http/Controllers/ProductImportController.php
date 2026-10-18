<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\CompanySettingService;
use App\Services\Inventory\InventoryVarianceAgentService;
use App\Services\Sync\SyncConfigurationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Product\Models\Product;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv as CsvWriter;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Throwable;
use function App\Helpers\hasPermission;

class ProductImportController extends Controller
{
    private array $requiredHeaders = [
        'name',
        'category_name',
    ];

    private array $supportedHeaders = [
        'action',
        'product_id',
        'name',
        'code',
        'sku',
        'barcode',
        'category_name',
        'supplier_name',
        'description',
        'hs_code',
        'product_type',
        'is_global',
        'stock_usage_type',
        'default_issue_destination',
        'expense_account_code',
        'uom_name',
        'base_uom_name',
        'stock_tracking_enabled',
        'allow_negative_stock',
        'reorder_level',
        'cost_currency',
        'average_cost',
        'last_cost',
        'opening_quantity',
        'selling_currency',
        'standard_price',
        'is_active',
        'notes',
    ];

    public function import(Request $request)
    {
        $company = app('company');
        $till = app()->bound('till') ? app('till') : null;
        $user = auth()->user();

        $permission = hasPermission($user->role_id, 'import_products', $company->id);

        if ($permission !== true) {
            return $permission;
        }

        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:10240'],

            // Keep branch_id as the main backend field.
            // Also accept branchId / selected_branch_id for frontend compatibility.
            'branch_id' => ['nullable', 'string'],
            'branchId' => ['nullable', 'string'],
            'selected_branch_id' => ['nullable', 'string'],

            'dry_run' => ['nullable', 'boolean'],
            'create_missing_categories' => ['nullable', 'boolean'],
            'create_missing_suppliers' => ['nullable', 'boolean'],
            'default_currency' => ['nullable', 'string', 'max:10'],
            'default_action' => ['nullable', 'in:create,update,upsert'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $branchId = $this->resolveImportBranchId($request, $till);

        if (!$branchId) {
            return response()->json([
                'message' => 'Branch is required for product import because stock, cost and prices are branch based.',
            ], 422);
        }

        $branchExists = DB::table('company_branches')
            ->where('company_id', $company->id)
            ->where('id', $branchId)
            ->exists();

        if (!$branchExists) {
            return response()->json([
                'message' => 'Selected branch is invalid for this company.',
            ], 422);
        }

        try {
            $rows = $this->readImportRows($request->file('file')->getRealPath(), $request->file('file')->getClientOriginalExtension());
            $this->validateHeaders($rows['headers']);

            $dryRun = $request->boolean('dry_run');
            $createMissingCategories = $request->boolean('create_missing_categories');
            $createMissingSuppliers = $request->boolean('create_missing_suppliers');
            $defaultCurrency = strtoupper($request->default_currency ?: $this->baseCurrencyCode($company->id) ?: 'USD');
            $defaultAction = $request->input('default_action', 'upsert');
            $businessMode = app(CompanySettingService::class)->businessMode((string) $company->id, $branchId);
            $importId = (string) Str::uuid();

            $summary = [
                'processed' => 0,
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => 0,
            ];

            $results = [];
            $seenIdentities = [];

            if (!$dryRun) {
                DB::beginTransaction();
            }

            foreach ($rows['rows'] as $index => $row) {
                $rowNumber = $row['_row_number'] ?? ($index + 2);

                try {
                    if ($this->isEmptyRow($row)) {
                        continue;
                    }

                    $summary['processed']++;

                    $this->assertUniqueWorkbookIdentity($row, $rowNumber, $seenIdentities);

                    $result = $this->importProductRow(
                        row: $row,
                        rowNumber: $rowNumber,
                        companyId: $company->id,
                        branchId: $branchId,
                        userId: $user->id,
                        dryRun: $dryRun,
                        createMissingCategories: $createMissingCategories,
                        createMissingSuppliers: $createMissingSuppliers,
                        defaultCurrency: $defaultCurrency,
                        defaultAction: $defaultAction,
                        businessMode: $businessMode,
                        importId: $importId
                    );

                    $summary[$result['status']]++;
                    $results[] = $result;
                } catch (Throwable $e) {
                    $summary['errors']++;
                    $results[] = [
                        'row_number' => $rowNumber,
                        'status' => 'errors',
                        'message' => $e->getMessage(),
                        'data' => $row,
                    ];
                }
            }

            if ($dryRun) {
                return response()->json([
                    'message' => 'Product import dry run completed.',
                    'data' => [
                        'dry_run' => true,
                        'summary' => $summary,
                        'results' => array_slice($results, 0, 300),
                    ],
                ]);
            }

            if ($summary['errors'] > 0) {
                DB::rollBack();

                return response()->json([
                    'message' => 'Product import failed. No products were saved because one or more rows contain errors.',
                    'data' => [
                        'dry_run' => false,
                        'summary' => $summary,
                        'results' => array_slice($results, 0, 300),
                    ],
                ], 422);
            }

            DB::commit();

            if ($summary['created'] > 0 || $summary['updated'] > 0) {
                $this->signalImportedProducts((string) $company->id);
            }

            return response()->json([
                'message' => 'Products imported successfully.',
                'data' => [
                    'dry_run' => false,
                    'summary' => $summary,
                    'results' => array_slice($results, 0, 300),
                ],
            ]);
        } catch (Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'message' => 'Product import failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function downloadTemplate(Request $request)
    {
        $format = strtolower((string) $request->query('format', 'xlsx'));
        if (!in_array($format, ['xlsx', 'csv'], true)) {
            return response()->json(['message' => 'Template format must be xlsx or csv.'], 422);
        }

        $book = new Spreadsheet();
        $products = $book->getActiveSheet();
        $products->setTitle('Products Import');
        $products->fromArray($this->supportedHeaders, null, 'A1');

        if ($format === 'xlsx') {
            $products->getStyle('A1:'.$products->getHighestColumn().'1')->getFont()->setBold(true);
            $products->getStyle('B:B')->getNumberFormat()->setFormatCode('@');
            $products->getStyle('D:F')->getNumberFormat()->setFormatCode('@');
            $hsCodeColumn = Coordinate::stringFromColumnIndex(array_search('hs_code', $this->supportedHeaders, true) + 1);
            $products->getStyle($hsCodeColumn.':'.$hsCodeColumn)->getNumberFormat()->setFormatCode('@');
            $products->getStyle($hsCodeColumn.'2')->getNumberFormat()->setFormatCode('@');
            $products->freezePane('A2');

            $guide = $book->createSheet();
            $guide->setTitle('Instructions');
            $guide->fromArray([
                ['Field', 'How to use it'],
                ['name and category_name', 'Required. Use names from this company. Missing categories can be created if enabled.'],
                ['action', 'create, update or upsert. A blank action uses the action selected on the import screen.'],
                ['product_type', 'Retail: simple. Hospitality: raw_material, finished_good, consumable, or another supported product type.'],
                ['stock_usage_type', 'turnover or non_turnover. Consumables default to non_turnover.'],
                ['is_global', 'yes shares the product catalog across company branches; no limits it to the selected branch.'],
                ['uom_name', 'Use an existing global unit name or code such as EACH, KG, L or PACK.'],
                ['hs_code', 'Optional. Enter the official 4- or 8-digit HS code as text to preserve leading zeroes. Do not use 0000 unless it is the real product code. VAT fiscal sales require a valid code.'],
                ['opening_quantity', 'Initial stock only. Leave blank when updating a branch with existing stock or movements; use Stock Adjustment or Stock Take instead.'],
                ['stock area', 'Opening stock goes to Back Stock in Hospitality and Front Stock in Retail.'],
                ['preview', 'Validate before import. The entire live import rolls back when any row has an error.'],
            ], null, 'A1');
            $guide->getStyle('A1:B1')->getFont()->setBold(true);
            $guide->getColumnDimension('A')->setWidth(25);
            $guide->getColumnDimension('B')->setWidth(105);
            $book->setActiveSheetIndex(0);
        }

        $filename = 'servenest_product_import_template.'.$format;
        $contentType = $format === 'csv'
            ? 'text/csv; charset=UTF-8'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return response()->streamDownload(function () use ($book, $format): void {
            $writer = $format === 'csv' ? new CsvWriter($book) : new XlsxWriter($book);
            $writer->save('php://output');
            $book->disconnectWorksheets();
        }, $filename, ['Content-Type' => $contentType]);
    }

    public function templateHeaders()
    {
        return response()->json([
            'message' => 'Product import template headers fetched successfully.',
            'data' => [
                'required_headers' => $this->requiredHeaders,
                'supported_headers' => $this->supportedHeaders,
                'example_row' => [
                    'action' => 'upsert',
                    'name' => 'COOKING OIL CASE 2L',
                    'code' => 'PRD-0001',
                    'sku' => 'SKU00001',
                    'barcode' => '600100000001',
                    'category_name' => 'Groceries',
                    'supplier_name' => 'Default Supplier',
                    'description' => 'Case of cooking oil 2 litres',
                    'hs_code' => null,
                    'product_type' => 'simple',
                    'uom_name' => 'EA',
                    'base_uom_name' => 'EA',
                    'is_global' => 'yes',
                    'stock_usage_type' => 'turnover',
                    'default_issue_destination' => 'front',
                    'stock_tracking_enabled' => 'yes',
                    'allow_negative_stock' => 'no',
                    'reorder_level' => 10,
                    'cost_currency' => 'USD',
                    'average_cost' => 24.50,
                    'last_cost' => 24.50,
                    'opening_quantity' => 100,
                    'selling_currency' => 'USD',
                    'standard_price' => 26.00,
                    'is_active' => 'yes',
                ],
            ],
        ]);
    }


    private function resolveImportBranchId(Request $request, $till = null): ?string
    {
        /*
         |--------------------------------------------------------------------------
         | Product import is branch based.
         |--------------------------------------------------------------------------
         | The frontend sends branch_id in FormData, query params and X-Branch-Id
         | so this backend accepts all common shapes to avoid losing the branch
         | during multipart uploads.
         */
        return $request->input('branch_id')
            ?: $request->input('branchId')
                ?: $request->input('selected_branch_id')
                    ?: $request->query('branch_id')
                        ?: $request->query('branchId')
                            ?: $request->header('X-Branch-Id')
                                ?: ($till->branch_id ?? null);
    }

    private function importProductRow(
        array $row,
        int $rowNumber,
        string $companyId,
        string $branchId,
        string $userId,
        bool $dryRun,
        bool $createMissingCategories,
        bool $createMissingSuppliers,
        string $defaultCurrency,
        string $defaultAction,
        string $businessMode,
        string $importId
    ): array {
        $name = trim((string) ($row['name'] ?? ''));
        $categoryName = trim((string) ($row['category_name'] ?? ''));
        $hsCode = $this->normaliseHsCode($row['hs_code'] ?? null);
        if ($hsCode !== null && ! Schema::hasColumn('products', 'hs_code')) {
            throw new \InvalidArgumentException('HS code storage is not installed. Run the latest Product migrations before importing.');
        }

        if ($name === '') {
            throw new \Exception('Product name is required.');
        }

        if ($categoryName === '') {
            throw new \Exception('Category name is required.');
        }

        $action = strtolower(trim((string) ($row['action'] ?? '')));
        $action = $action === '' ? $defaultAction : $action;
        if (!in_array($action, ['create', 'update', 'upsert'], true)) {
            throw new \InvalidArgumentException('Action must be create, update or upsert.');
        }

        $openingQuantity = $this->toNumber($row['opening_quantity'] ?? null);
        if ($openingQuantity !== null && $openingQuantity < 0) {
            throw new \InvalidArgumentException('Opening quantity cannot be negative.');
        }
        foreach (['reorder_level', 'average_cost', 'last_cost', 'standard_price'] as $numericField) {
            $value = $this->toNumber($row[$numericField] ?? null);
            if ($value !== null && $value < 0) {
                throw new \InvalidArgumentException("{$numericField} cannot be negative.");
            }
        }
        if ($openingQuantity !== null && $openingQuantity > 0
            && $this->hasValue($row, 'stock_tracking_enabled')
            && !$this->toBool($row['stock_tracking_enabled'])) {
            throw new \InvalidArgumentException('A product with opening stock must have stock tracking enabled.');
        }
        $stockArea = $businessMode === 'hospitality' ? 'back' : 'front';

        $categoryId = $this->resolveCategoryId(
            companyId: $companyId,
            categoryName: $categoryName,
            createIfMissing: $createMissingCategories,
            dryRun: $dryRun
        );

        $supplierId = $this->resolveSupplierId(
            companyId: $companyId,
            supplierName: trim((string) ($row['supplier_name'] ?? '')),
            createIfMissing: $createMissingSuppliers,
            dryRun: $dryRun
        );

        $existingProduct = $this->findExistingProduct($companyId, $row);
        if (!$existingProduct || $this->hasValue($row, 'base_uom_name') || $this->hasValue($row, 'uom_name')) {
            $uomName = $this->hasValue($row, 'base_uom_name') ? (string) $row['base_uom_name']
                : ($this->hasValue($row, 'uom_name') ? (string) $row['uom_name'] : 'Each');
            $uomId = $this->resolveUomId($uomName, $companyId);
        } else {
            $uomId = (string) ($existingProduct->base_uom_id ?? '');
        }
        if (!$existingProduct || $this->hasValue($row, 'product_type')) {
            $this->normaliseProductType($row['product_type'] ?? 'simple');
        }
        $stockUsageType = $this->stockUsageType($row, $existingProduct->stock_usage_type ?? null);
        $this->issueDestination($row, $stockUsageType);

        if ($action === 'create' && $existingProduct) {
            throw new \Exception('Product already exists. Use action update or upsert.');
        }

        if ($action === 'update' && !$existingProduct) {
            throw new \Exception('Product does not exist. Use action create or upsert.');
        }

        if ($existingProduct && !empty($existingProduct->branch_id)
            && (string) $existingProduct->branch_id !== $branchId
            && !(bool) ($existingProduct->is_global ?? false)) {
            throw new \Exception('This product belongs to another branch. Select that branch or use a different product identity.');
        }

        $this->assertOpeningStockIsInitial($companyId, $branchId, $existingProduct?->id, $openingQuantity, $stockArea);

        $status = $existingProduct ? 'updated' : 'created';

        if ($dryRun) {
            return [
                'row_number' => $rowNumber,
                'status' => $status,
                'message' => ($existingProduct ? 'Product would be updated.' : 'Product would be created.')
                    .($openingQuantity !== null ? " Initial {$stockArea} stock would be set." : ''),
                'product_id' => $existingProduct->id ?? null,
                'name' => $name,
                'base_uom_id' => $uomId,
            ];
        }

        $productId = $existingProduct->id ?? (string) Str::uuid();

        $productData = $this->productPayload(
            productId: $productId,
            row: $row,
            companyId: $companyId,
            categoryId: $categoryId,
            supplierId: $supplierId,
            uomId: $uomId,
            userId: $userId,
            isUpdate: (bool) $existingProduct,
            branchId: $branchId,
            stockUsageType: $stockUsageType,
            businessMode: $businessMode
        );

        if ($existingProduct) {
            DB::table('products')
                ->where('company_id', $companyId)
                ->where('id', $existingProduct->id)
                ->update($productData);
        } else {
            DB::table('products')->insert($productData);
        }

        $this->upsertProductCost($companyId, $branchId, $productId, $row, $defaultCurrency, $openingQuantity);
        $this->upsertStandardPrice($companyId, $branchId, $productId, $row, $defaultCurrency);
        if ($openingQuantity !== null) {
            $this->upsertOpeningStock($companyId, $branchId, $productId, $openingQuantity, $userId, $stockArea);
            app(InventoryVarianceAgentService::class)->resetProduct(
                $companyId, $branchId, $productId,
                'opening_import', 'product_import', $importId.':'.$rowNumber, $userId, $stockArea
            );
        }

        return [
            'row_number' => $rowNumber,
            'status' => $status,
            'message' => $existingProduct ? 'Product updated.' : 'Product created.',
            'product_id' => $productId,
            'name' => $name,
        ];
    }


    private function normaliseProductType($rawType): string
    {
        $raw = mb_strtolower(str_replace([' ', '-'], '_', trim((string) $rawType)));
        $raw = [
            '' => 'simple', 'standard' => 'simple', 'single' => 'simple',
            'inventory' => 'simple', 'stock' => 'simple', 'normal' => 'simple',
            'product' => 'simple', 'item' => 'simple',
        ][$raw] ?? $raw;

        $supported = [
            'simple', 'parent', 'child', 'service', 'raw_material',
            'finished_good', 'semi_finished', 'packaging', 'consumable',
        ];
        if (!in_array($raw, $supported, true)) {
            throw new \InvalidArgumentException("Unsupported product_type '{$rawType}'. Use simple for Retail or raw_material, finished_good, semi_finished, packaging or consumable for Hospitality.");
        }

        $allowed = $this->allowedProductTypeValues();
        if ($allowed !== [] && !in_array($raw, $allowed, true)) {
            throw new \InvalidArgumentException("product_type '{$raw}' is not available in this database. Run the latest Product migrations before importing.");
        }

        return $raw;
    }

    private function allowedProductTypeValues(): array
    {
        try {
            $column = DB::selectOne('SHOW COLUMNS FROM products LIKE ?', ['product_type']);

            if (!$column || !isset($column->Type)) {
                return [];
            }

            if (!preg_match("/^enum\((.*)\)$/", $column->Type, $matches)) {
                return [];
            }

            $values = str_getcsv($matches[1], ',', "'");

            return array_values(array_filter(array_map(
                fn ($value) => mb_strtolower(trim($value)),
                $values
            )));
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function productPayload(
        string $productId,
        array $row,
        string $companyId,
        string $categoryId,
        ?string $supplierId,
        ?string $uomId,
        string $userId,
        bool $isUpdate,
        string $branchId,
        string $stockUsageType,
        string $businessMode
    ): array {
        $columns = Schema::getColumnListing('products');
        $now = now();

        $payload = [
            'name' => trim((string) ($row['name'] ?? '')),
            'category_id' => $categoryId,
            'updated_at' => $now,
        ];

        if (!$isUpdate) {
            $payload['id'] = $productId;
            $payload['company_id'] = $companyId;
            $payload['created_at'] = $now;
            if (in_array('is_global', $columns, true)) {
                $payload['is_global'] = array_key_exists('is_global', $row) && trim((string) $row['is_global']) !== ''
                    ? $this->toBool($row['is_global'])
                    : $businessMode !== 'hospitality';
                $payload['branch_id'] = $payload['is_global'] ? null : $branchId;
            }
        } elseif (in_array('is_global', $columns, true)
            && array_key_exists('is_global', $row) && trim((string) $row['is_global']) !== '') {
            $payload['is_global'] = $this->toBool($row['is_global']);
            $payload['branch_id'] = $payload['is_global'] ? null : $branchId;
        }

        $optionalMap = [
            'code' => 'code',
            'sku' => 'sku',
            'barcode' => 'barcode',
            'description' => 'description',
            'hs_code' => 'hs_code',
            'product_type' => 'product_type',
            'reorder_level' => 'reorder_level',
        ];

        foreach ($optionalMap as $header => $column) {
            if (in_array($column, $columns, true) && array_key_exists($header, $row) && trim((string) $row[$header]) !== '') {
                if ($header === 'reorder_level') {
                    $payload[$column] = $this->toNumber($row[$header]);
                    continue;
                }

                if ($header === 'product_type') {
                    $payload[$column] = $this->normaliseProductType($row[$header]);
                    continue;
                }

                $payload[$column] = trim((string) $row[$header]);
            }
        }

        if (!$isUpdate && in_array('product_type', $columns, true) && empty($payload['product_type'])) {
            $payload['product_type'] = $this->normaliseProductType($row['product_type'] ?? 'standard');
        }

        if ($supplierId && in_array('supplier_id', $columns, true)) {
            $payload['supplier_id'] = $supplierId;
        }

        /*
         |--------------------------------------------------------------------------
         | UOM columns
         |--------------------------------------------------------------------------
         | Some POS schemas require base_uom_id and do not have a default value.
         | The previous importer only attempted uom_id, so inserts failed with:
         | Field 'base_uom_id' doesn't have a default value.
         */
        if ($uomId && (!$isUpdate || $this->hasValue($row, 'uom_name') || $this->hasValue($row, 'base_uom_name'))) {
            foreach (['uom_id', 'base_uom_id', 'purchase_uom_id', 'sales_uom_id', 'sale_uom_id'] as $uomColumn) {
                if (in_array($uomColumn, $columns, true)) {
                    $payload[$uomColumn] = $uomId;
                }
            }
        }

        if (!$uomId && in_array('base_uom_id', $columns, true) && !$isUpdate) {
            throw new \Exception('Base UOM is required. Provide uom_name/base_uom_name matching an existing UOM such as Each, EA, Kg or Pack.');
        }

        if (in_array('stock_tracking_enabled', $columns, true)) {
            if (!$isUpdate || $this->hasValue($row, 'stock_tracking_enabled')) {
                $payload['stock_tracking_enabled'] = $this->hasValue($row, 'stock_tracking_enabled')
                    ? $this->toBool($row['stock_tracking_enabled'])
                    : true;
            }
        }

        if (in_array('allow_negative_stock', $columns, true)) {
            if (!$isUpdate || $this->hasValue($row, 'allow_negative_stock')) {
                $payload['allow_negative_stock'] = $this->toBool($row['allow_negative_stock'] ?? false);
            }
        }

        if (in_array('is_active', $columns, true)) {
            if (!$isUpdate || $this->hasValue($row, 'is_active')) {
                $payload['is_active'] = $this->hasValue($row, 'is_active')
                    ? $this->toBool($row['is_active']) : true;
            }
        }

        if (in_array('stock_usage_type', $columns, true)
            && (!$isUpdate || $this->hasValue($row, 'stock_usage_type') || $this->hasValue($row, 'product_type'))) {
            $payload['stock_usage_type'] = $stockUsageType;
        }
        if (in_array('default_issue_destination', $columns, true)
            && (!$isUpdate || $this->hasValue($row, 'default_issue_destination')
                || $this->hasValue($row, 'stock_usage_type') || $this->hasValue($row, 'product_type'))) {
            $payload['default_issue_destination'] = $this->issueDestination($row, $stockUsageType);
        }
        if (in_array('expense_account_code', $columns, true) && $this->hasValue($row, 'expense_account_code')) {
            $payload['expense_account_code'] = trim((string) $row['expense_account_code']);
        }

        if (in_array('created_by', $columns, true) && !$isUpdate) {
            $payload['created_by'] = $userId;
        }

        if (in_array('updated_by', $columns, true)) {
            $payload['updated_by'] = $userId;
        }


        /*
         |--------------------------------------------------------------------------
         | Safe defaults for required product columns without DB defaults.
         |--------------------------------------------------------------------------
         | This keeps imports aligned with stricter POS product schemas.
         */
        if (!$isUpdate) {
            if (in_array('base_uom_id', $columns, true) && empty($payload['base_uom_id']) && $uomId) {
                $payload['base_uom_id'] = $uomId;
            }

            if (in_array('product_kind', $columns, true) && empty($payload['product_kind'])) {
                $payload['product_kind'] = $payload['product_type'] ?? $this->normaliseProductType($row['product_type'] ?? 'standard');
            }

            if (in_array('track_inventory', $columns, true) && !array_key_exists('track_inventory', $payload)) {
                $payload['track_inventory'] = $this->hasValue($row, 'stock_tracking_enabled')
                    ? $this->toBool($row['stock_tracking_enabled']) : true;
            }

            if (in_array('is_taxable', $columns, true) && !array_key_exists('is_taxable', $payload)) {
                $payload['is_taxable'] = true;
            }
        }

        return array_intersect_key($payload, array_flip($columns));
    }

    private function upsertProductCost(
        string $companyId, string $branchId, string $productId,
        array $row, string $defaultCurrency, ?float $openingQuantity
    ): void
    {
        if (!Schema::hasTable('product_costs')) {
            return;
        }

        $averageCost = $this->toNumber($row['average_cost'] ?? null);
        $lastCost = $this->toNumber($row['last_cost'] ?? null);
        $currency = strtoupper(trim((string) ($this->hasValue($row, 'cost_currency')
            ? $row['cost_currency'] : $defaultCurrency)));

        if ($averageCost === null && $lastCost === null && $openingQuantity === null) {
            return;
        }

        $existing = DB::table('product_costs')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->first();

        $averageCost = $averageCost ?? $lastCost ?? (float) ($existing->average_cost ?? 0);
        $lastCost = $lastCost ?? $averageCost;
        $totalValue = $openingQuantity !== null ? $openingQuantity * $averageCost : 0;

        $data = [
            'average_cost' => $averageCost,
            'last_cost' => $lastCost,
            'updated_at' => now(),
        ];

        $columns = Schema::getColumnListing('product_costs');
        if (in_array('currency', $columns, true)) {
            $data['currency'] = $currency;
        }
        if (in_array('currency_code', $columns, true)) {
            $data['currency_code'] = $currency;
        }

        if ($openingQuantity !== null) {
            $data['total_quantity'] = $openingQuantity;
            $data['total_value'] = $totalValue;
        }

        if ($existing) {
            DB::table('product_costs')->where('id', $existing->id)->update($data);
            return;
        }

        $data = array_merge([
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'product_id' => $productId,
            'total_quantity' => $openingQuantity ?? 0,
            'total_value' => $totalValue,
            'created_at' => now(),
        ], $data);

        DB::table('product_costs')->insert($data);
    }

    private function upsertStandardPrice(string $companyId, string $branchId, string $productId, array $row, string $defaultCurrency): void
    {
        if (!Schema::hasTable('product_prices')) {
            return;
        }

        $price = $this->toNumber($row['standard_price'] ?? null);

        if ($price === null) {
            return;
        }

        $currency = strtoupper(trim((string) ($this->hasValue($row, 'selling_currency')
            ? $row['selling_currency'] : $defaultCurrency)));
        $priceLevelId = $this->standardPriceLevelId($companyId);

        $existing = DB::table('product_prices')
            ->where('company_id', $companyId)
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->where('price_level_id', $priceLevelId)
            ->first();

        $data = [
            'price' => $price,
            'currency' => $currency,
            'is_default' => true,
            'is_active' => true,
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('product_prices')->where('id', $existing->id)->update($data);
            return;
        }

        DB::table('product_prices')->insert(array_merge([
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'product_id' => $productId,
            'branch_id' => $branchId,
            'price_level_id' => $priceLevelId,
            'created_at' => now(),
        ], $data));
    }

    private function upsertOpeningStock(
        string $companyId, string $branchId, string $productId,
        float $openingQuantity, string $userId, string $stockArea
    ): void
    {
        if (!Schema::hasTable('product_stocks')) {
            return;
        }

        $existing = DB::table('product_stocks')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->when(Schema::hasColumn('product_stocks', 'stock_area'), fn ($query) => $query->where('stock_area', $stockArea))
            ->first();

        $oldQty = $existing ? (float) $existing->quantity_on_hand : 0;
        $delta = $openingQuantity - $oldQty;

        $stockData = [
            'quantity_on_hand' => $openingQuantity,
            'available_quantity' => $openingQuantity - (float) ($existing->reserved_quantity ?? 0),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('product_stocks', 'stock_area')) {
            $stockData['stock_area'] = $stockArea;
        }

        if ($existing) {
            DB::table('product_stocks')->where('id', $existing->id)->update($stockData);
        } else {
            DB::table('product_stocks')->insert(array_merge([
                'id' => (string) Str::uuid(),
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'product_id' => $productId,
                'reserved_quantity' => 0,
                'created_at' => now(),
            ], $stockData));
        }

        if (abs($delta) > 0.0001 && Schema::hasTable('stock_movements')) {
            $cost = DB::table('product_costs')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('product_id', $productId)
                ->first();

            $unitCost = (float) ($cost->average_cost ?? $cost->last_cost ?? 0);
            $qtyIn = $delta > 0 ? $delta : 0;
            $qtyOut = $delta < 0 ? abs($delta) : 0;

            $movement = [
                'id' => (string) Str::uuid(),
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'product_id' => $productId,
                'movement_type' => 'opening',
                'quantity_in' => $qtyIn,
                'quantity_out' => $qtyOut,
                'balance_after' => $openingQuantity,
                'unit_cost' => $unitCost,
                'total_cost' => abs($delta) * $unitCost,
                'currency' => $cost->currency_code ?? $cost->currency ?? null,
                'currency_code' => $cost->currency_code ?? $cost->currency ?? null,
                'reference_type' => 'product_import',
                'reference_id' => $productId,
                'notes' => 'Opening stock imported from product import.',
                'metadata' => json_encode([
                    'old_quantity' => $oldQty,
                    'new_quantity' => $openingQuantity,
                    'delta' => $delta,
                ]),
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('stock_movements', 'stock_area')) {
                $movement['stock_area'] = $stockArea;
            }
            DB::table('stock_movements')->insert($movement);
        }
    }

    private function resolveCategoryId(string $companyId, string $categoryName, bool $createIfMissing, bool $dryRun): string
    {
        $category = DB::table('product_categories')
            ->where('company_id', $companyId)
            ->when(Schema::hasColumn('product_categories', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($categoryName)])
            ->first();

        if ($category) {
            return $category->id;
        }

        if (!$createIfMissing) {
            throw new \Exception("Category '{$categoryName}' was not found.");
        }

        if ($dryRun) {
            return 'DRY-RUN-CATEGORY';
        }

        $id = (string) Str::uuid();

        DB::table('product_categories')->insert([
            'id' => $id,
            'company_id' => $companyId,
            'name' => $categoryName,
            'code' => $this->makeCode($categoryName),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function resolveSupplierId(string $companyId, string $supplierName, bool $createIfMissing, bool $dryRun): ?string
    {
        if ($supplierName === '' || !Schema::hasTable('suppliers')) {
            return null;
        }

        $supplier = DB::table('suppliers')
            ->where('company_id', $companyId)
            ->when(Schema::hasColumn('suppliers', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($supplierName)])
            ->first();

        if ($supplier) {
            return $supplier->id;
        }

        if (!$createIfMissing) {
            throw new \Exception("Supplier '{$supplierName}' was not found.");
        }

        if ($dryRun) {
            return 'DRY-RUN-SUPPLIER';
        }

        $id = (string) Str::uuid();

        DB::table('suppliers')->insert([
            'id' => $id,
            'company_id' => $companyId,
            'name' => $supplierName,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function resolveUomId(string $uomName, string $companyId): string
    {
        $uomName = trim($uomName) ?: 'Each';
        $search = strtoupper($uomName);
        $search = ['EA' => 'EACH', 'KGS' => 'KG', 'PCS' => 'PCS', 'LITRE' => 'L', 'LITER' => 'L'][$search] ?? $search;

        foreach (['uoms', 'product_uoms', 'units_of_measure'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            $query = DB::table($table)
                ->where(function ($q) use ($table, $uomName, $search) {
                    $q->whereRaw('UPPER(name) IN (?, ?)', [strtoupper($uomName), $search]);

                    if (Schema::hasColumn($table, 'code')) {
                        $q->orWhereRaw('UPPER(code) = ?', [$search]);
                    }
                });

            if (Schema::hasColumn($table, 'company_id')) {
                $query->where(function ($q) use ($companyId) {
                    $q->where('company_id', $companyId)
                        ->orWhereNull('company_id');
                });
                $query->orderByRaw('CASE WHEN company_id IS NULL THEN 0 ELSE 1 END');
            }
            if (Schema::hasColumn($table, 'is_active')) {
                $query->where('is_active', true);
            }
            if (Schema::hasColumn($table, 'deleted_at')) {
                $query->whereNull('deleted_at');
            }

            $uom = $query->first();

            if ($uom) {
                return $uom->id;
            }

        }

        throw new \InvalidArgumentException("UOM '{$uomName}' was not found. Use a global UOM code such as EACH, KG, L or PACK, or configure the unit before importing.");
    }

    private function findExistingProduct(string $companyId, array $row)
    {
        $requestedId = trim((string) ($row['product_id'] ?? ''));
        if ($requestedId !== '') {
            $product = DB::table('products')
                ->where('company_id', $companyId)
                ->where('id', $requestedId)
                ->first();

            if (!$product) {
                throw new \InvalidArgumentException('Product ID was not found in the logged-in company.');
            }

            return $product;
        }

        $query = DB::table('products')->where('company_id', $companyId);

        $query->where(function ($sub) use ($row) {
            foreach (['code', 'sku', 'barcode'] as $field) {
                $value = trim((string) ($row[$field] ?? ''));

                if ($value !== '') {
                    $sub->orWhere($field, $value);
                }
            }

            if ($this->hasValue($row, 'name')) {
                $sub->orWhereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $row['name']))]);
            }
        });

        $matches = $query->limit(2)->get();
        if ($matches->count() > 1) {
            throw new \InvalidArgumentException('Product name, code, SKU or barcode match different existing products. Resolve the identity conflict before import.');
        }

        return $matches->first();
    }

    private function assertOpeningStockIsInitial(
        string $companyId, string $branchId, ?string $productId, ?float $openingQuantity, string $stockArea
    ): void {
        if ($openingQuantity === null || !$productId) {
            return;
        }

        $stock = DB::table('product_stocks')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->when(Schema::hasColumn('product_stocks', 'stock_area'), fn ($query) => $query->where('stock_area', $stockArea))
            ->first();
        $hasMovements = Schema::hasTable('stock_movements') && DB::table('stock_movements')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->when(Schema::hasColumn('stock_movements', 'stock_area'), fn ($query) => $query->where('stock_area', $stockArea))
            ->exists();

        if ($hasMovements || abs((float) ($stock->quantity_on_hand ?? 0)) > 0.00001
            || abs((float) ($stock->reserved_quantity ?? 0)) > 0.00001) {
            throw new \InvalidArgumentException(
                'Opening quantity is for initial branch stock only. This product has stock activity; leave the cell blank and use Stock Adjustment or Stock Take.'
            );
        }
    }

    private function stockUsageType(array $row, ?string $existing = null): string
    {
        $value = strtolower(str_replace([' ', '-'], '_', trim((string) ($row['stock_usage_type'] ?? ''))));
        if ($value !== '' && !in_array($value, ['turnover', 'non_turnover'], true)) {
            throw new \InvalidArgumentException('stock_usage_type must be turnover or non_turnover.');
        }
        if ($value !== '') {
            return $value;
        }

        $type = strtolower(str_replace([' ', '-'], '_', trim((string) ($row['product_type'] ?? ''))));
        if (in_array($type, ['consumable', 'consumables'], true)) {
            return 'non_turnover';
        }

        return in_array($existing, ['turnover', 'non_turnover'], true) ? $existing : 'turnover';
    }

    private function issueDestination(array $row, string $stockUsageType): string
    {
        $value = strtolower(str_replace([' ', '-'], '_', trim((string) ($row['default_issue_destination'] ?? ''))));
        if ($value === '') {
            return $stockUsageType === 'non_turnover' ? 'expense' : 'front';
        }
        if (!in_array($value, ['front', 'department', 'expense', 'production', 'wastage'], true)) {
            throw new \InvalidArgumentException('default_issue_destination must be front, department, expense, production or wastage.');
        }
        if ($value === 'front' && $stockUsageType === 'non_turnover') {
            throw new \InvalidArgumentException('A non_turnover product cannot use front as its issue destination.');
        }

        return $value;
    }

    private function assertUniqueWorkbookIdentity(array $row, int $rowNumber, array &$seen): void
    {
        foreach (['product_id', 'name', 'code', 'sku', 'barcode'] as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value === '') {
                continue;
            }
            $key = $field.':'.mb_strtolower($value);
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException("Duplicate {$field} in import rows {$seen[$key]} and {$rowNumber}.");
            }
            $seen[$key] = $rowNumber;
        }
    }

    private function hasValue(array $row, string $key): bool
    {
        return array_key_exists($key, $row) && trim((string) $row[$key]) !== '';
    }

    private function standardPriceLevelId(string $companyId): string
    {
        $level = DB::table('price_levels')
            ->where('company_id', $companyId)
            ->when(Schema::hasColumn('price_levels', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
            ->where(function ($q) {
                $q->whereRaw('LOWER(name) = ?', ['standard'])
                    ->orWhereRaw('LOWER(code) = ?', ['standard']);
            })
            ->first();

        if ($level) {
            return $level->id;
        }

        $id = (string) Str::uuid();

        DB::table('price_levels')->insert([
            'id' => $id,
            'company_id' => $companyId,
            'name' => 'Standard',
            'code' => 'standard',
            'description' => 'Default standard selling price level.',
            'is_default' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function baseCurrencyCode(string $companyId): ?string
    {
        if (!Schema::hasTable('currencies')) {
            return null;
        }

        return DB::table('currencies')
            ->where('company_id', $companyId)
            ->where('is_base_currency', true)
            ->where('is_active', true)
            ->value('code');
    }

    private function readImportRows(string $path, string $extension): array
    {
        $extension = strtolower($extension);

        if (in_array($extension, ['csv', 'txt'], true)) {
            return $this->readCsv($path);
        }

        return $this->readSpreadsheet($path);
    }

    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');

        if (!$handle) {
            throw new \Exception('Unable to open CSV file.');
        }

        $headers = null;
        $rows = [];
        $rowNumber = 0;

        while (($data = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if ($rowNumber === 1) {
                $headers = $this->normalizeHeaders($data);
                continue;
            }

            $row = $this->combineRow($headers, $data);
            $row['_row_number'] = $rowNumber;
            $rows[] = $row;
        }

        fclose($handle);

        return [
            'headers' => $headers ?: [],
            'rows' => $rows,
        ];
    }

    private function readSpreadsheet(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Products Import') ?: $spreadsheet->getActiveSheet();
        $rawRows = $sheet->toArray(null, true, true, false);

        if (count($rawRows) < 2) {
            throw new \Exception('Import file does not contain product rows.');
        }

        $headers = $this->normalizeHeaders($rawRows[0]);
        $rows = [];

        foreach (array_slice($rawRows, 1) as $index => $data) {
            $row = $this->combineRow($headers, $data);
            $row['_row_number'] = $index + 2;
            $rows[] = $row;
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    private function normalizeHeaders(array $headers): array
    {
        return array_map(function ($header) {
            $header = trim((string) $header);
            $header = preg_replace('/^\xEF\xBB\xBF/', '', $header);
            $header = strtolower($header);
            $header = preg_replace('/[^a-z0-9]+/', '_', $header);

            return trim($header, '_');
        }, $headers);
    }

    private function combineRow(array $headers, array $data): array
    {
        $row = [];

        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }

            $row[$header] = $data[$index] ?? null;
        }

        return $row;
    }

    private function validateHeaders(array $headers): void
    {
        $nonEmpty = array_values(array_filter($headers, fn ($header) => $header !== ''));
        if (count($nonEmpty) !== count(array_unique($nonEmpty))) {
            throw new \InvalidArgumentException('Import file has duplicate column headers.');
        }
        foreach ($this->requiredHeaders as $requiredHeader) {
            if (!in_array($requiredHeader, $headers, true)) {
                throw new \Exception("Missing required header: {$requiredHeader}");
            }
        }
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $key => $value) {
            if ($key === '_row_number') {
                continue;
            }

            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $text = strtolower(trim((string) $value));

        return in_array($text, ['1', 'yes', 'y', 'true', 'active'], true);
    }

    private function toNumber($value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $number = str_replace(',', '', trim((string) $value));
        if (!is_numeric($number) || !is_finite((float) $number)) {
            throw new \InvalidArgumentException("Invalid numeric value '{$value}'.");
        }

        return (float) $number;
    }

    private function normaliseHsCode(mixed $value): ?string
    {
        $code = trim((string) ($value ?? ''));
        if ($code === '') {
            return null;
        }

        if (! preg_match('/^(?:[0-9]{4}|[0-9]{8})$/D', $code)) {
            throw new \InvalidArgumentException('HS code must contain exactly 4 or 8 digits. Format the Excel column as text to preserve leading zeroes.');
        }

        return $code;
    }

    private function signalImportedProducts(string $companyId): void
    {
        try {
            // Raw DB writes do not dispatch Eloquent model events. A single
            // company-scoped signal covers both branch-local and shared products,
            // including a product switched from global to branch-local.
            app(SyncConfigurationService::class)->markModelDirty(new Product([
                'company_id' => $companyId,
            ]));
        } catch (Throwable $exception) {
            Log::warning('Unable to queue product-import configuration sync signal.', [
                'company_id' => $companyId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function makeCode(string $name): string
    {
        $code = strtoupper(preg_replace('/[^A-Z0-9]+/i', '-', $name));
        $code = trim($code, '-');

        return substr($code, 0, 50) ?: strtoupper(Str::random(8));
    }
}
