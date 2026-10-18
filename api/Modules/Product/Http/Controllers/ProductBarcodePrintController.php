<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\CompanyBranchContextService;
use App\Services\Sales\SalesCostingService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductBarcodePrintController extends Controller
{
    public function search(Request $request)
    {
        $company = app('company');
        $branchId = app(CompanyBranchContextService::class)->resolve(
            $request,
            (string) $company->id
        );
        $term = trim((string) $request->input('q', ''));
        $limit = min(max((int) $request->input('limit', 50), 1), 100);
        $costCurrency = app(SalesCostingService::class)->productCostCurrencyExpression('costs') ?: 'NULL';

        $rows = DB::table('products')
            ->leftJoin('product_barcodes as pb', function ($join) {
                $join->on('pb.product_id', '=', 'products.id')
                    ->where('pb.is_active', 1)
                    ->whereNull('pb.deleted_at');
            })
            ->leftJoin('product_categories as pc', 'pc.id', '=', 'products.category_id')
            ->leftJoin('suppliers as sup', 'sup.id', '=', 'products.supplier_id')
            ->leftJoin('product_stocks as ps', function ($join) use ($branchId) {
                $join->on('ps.product_id', '=', 'products.id');
                if ($branchId) $join->where('ps.branch_id', '=', $branchId);
            })
            ->leftJoin('product_costs as costs', function ($join) use ($branchId) {
                $join->on('costs.product_id', '=', 'products.id');
                if ($branchId) $join->where('costs.branch_id', '=', $branchId);
            })
            ->where('products.company_id', $company->id)
            ->where('products.is_active', 1)
            ->whereNull('products.deleted_at')
            ->when($request->input('category_id'), fn ($q) => $q->where('products.category_id', $request->input('category_id')))
            ->when($request->input('supplier_id'), fn ($q) => $q->where('products.supplier_id', $request->input('supplier_id')))
            ->when($request->input('barcode_type'), fn ($q) => $q->where('pb.type', $request->input('barcode_type')))
            ->when(!$request->boolean('include_missing_barcode', false), function ($q) {
                $q->where(fn ($sub) => $sub->whereNotNull('pb.barcode')->orWhereNotNull('products.barcode'));
            })
            ->when($term !== '', function ($q) use ($term) {
                $q->where(function ($sub) use ($term) {
                    $sub->where('products.name', 'like', "%{$term}%")
                        ->orWhere('products.code', 'like', "%{$term}%")
                        ->orWhere('products.sku', 'like', "%{$term}%")
                        ->orWhere('products.barcode', 'like', "%{$term}%")
                        ->orWhere('pb.barcode', 'like', "%{$term}%")
                        ->orWhere('pc.name', 'like', "%{$term}%")
                        ->orWhere('sup.name', 'like', "%{$term}%");
                });
            })
            ->select([
                DB::raw('COALESCE(pb.id, products.id) as barcode_id'),
                'products.id as product_id',
                'products.name as product_name',
                'products.code',
                'products.sku',
                'products.barcode as product_barcode',
                DB::raw('COALESCE(pb.barcode, products.barcode) as barcode'),
                DB::raw('COALESCE(pb.type, "primary") as barcode_type'),
                DB::raw('COALESCE(pc.name, "Uncategorised") as category_name'),
                DB::raw('COALESCE(sup.name, "No Supplier") as supplier_name'),
                DB::raw('COALESCE(ps.quantity_on_hand, 0) as quantity_on_hand'),
                DB::raw('COALESCE(costs.average_cost, 0) as average_cost'),
                DB::raw("COALESCE({$costCurrency}, 'USD') as cost_currency"),
                DB::raw($this->standardPriceSql($branchId) . ' as selling_price'),
                DB::raw($this->standardPriceCurrencySql($branchId) . ' as selling_currency'),
            ])
            ->orderBy('products.name')
            ->orderByRaw('CASE COALESCE(pb.type, "primary") WHEN "primary" THEN 0 WHEN "alternative" THEN 1 ELSE 2 END')
            ->limit($limit)
            ->get();

        return response()->json(['message' => 'Product barcodes fetched successfully.', 'data' => $rows]);
    }

    public function resolvePrinter(Request $request)
    {
        $company = app('company');
        $branchId = app(CompanyBranchContextService::class)->resolve(
            $request,
            (string) $company->id
        );
        $tillId = $request->till_id ?: $request->header('X-Till-Id');
        $printer = $this->resolvePrinterForTarget($company->id, 'barcode', $branchId, $tillId);

        if (!$printer) {
            return response()->json(['message' => 'No active barcode printer configured for this branch/till.'], 404);
        }

        return response()->json(['message' => 'Barcode printer resolved successfully.', 'data' => $printer]);
    }

    public function preparePrint(Request $request)
    {
        $company = app('company');
        $branchId = app(CompanyBranchContextService::class)->resolve(
            $request,
            (string) $company->id
        );
        $tillId = $request->input('till_id') ?: $request->header('X-Till-Id');

        $validator = Validator::make($request->all(), [
            'branch_id' => ['nullable', 'string'],
            'till_id' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'string'],
            'items.*.barcode' => ['required', 'string', 'max:255'],
            'items.*.copies' => ['required', 'integer', 'min:1', 'max:500'],
            'label' => ['nullable', 'array'],
            'label.show_price' => ['nullable', 'boolean'],
            'label.show_product_name' => ['nullable', 'boolean'],
            'label.show_sku' => ['nullable', 'boolean'],
            'label.show_company' => ['nullable', 'boolean'],
            'label.width_mm' => ['nullable', 'numeric', 'min:20', 'max:150'],
            'label.height_mm' => ['nullable', 'numeric', 'min:10', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error.', 'errors' => $validator->errors()], 422);
        }

        $printer = $this->resolvePrinterForTarget($company->id, 'barcode', $branchId, $tillId);
        if (!$printer) {
            return response()->json(['message' => 'No active barcode printer configured for this branch/till.'], 422);
        }

        $label = array_merge([
            'show_price' => true,
            'show_product_name' => true,
            'show_sku' => true,
            'show_company' => false,
            'width_mm' => 50,
            'height_mm' => 30,
        ], $request->input('label', []) ?: []);

        $items = $this->buildItems($company->id, $branchId, $request->input('items', []));
        if ($items->isEmpty()) {
            return response()->json(['message' => 'No valid barcode items found to print.'], 422);
        }

        $html = $this->buildBarcodeHtml($company, $printer, $label, $items);
        $pdf = Pdf::loadHTML($html);
        $pdf->setPaper([0, 0, ((float) $label['width_mm']) * 2.83465, ((float) $label['height_mm']) * 2.83465], 'portrait');

        $fileName = 'barcode_labels_' . now()->format('Ymd_His') . '_' . substr(md5(json_encode($items)), 0, 8) . '.pdf';

        /*
         * Store in public/assets like the sales printable files.
         * This avoids APP_URL/local port issues such as localhost:8000/storage.
         */
        $relativeAfterAssets = 'barcode-labels/' . $company->id . '/' . $fileName;
        $relativePath = 'assets/' . $relativeAfterAssets;
        $absolutePath = base_path($relativePath);

        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, $pdf->output());

        $url = $this->apiAssetsUrl($request, $relativeAfterAssets);

        return response()->json([
            'message' => 'Barcode labels prepared successfully.',
            'data' => [
                'target' => 'barcode',
                'barcode_url' => $url,
                'pdf_url' => $url,
                'label_url' => $url,
                'pdf_path' => $relativePath,
                'asset_base' => 'base_path/assets',
                'public_path' => $absolutePath,
                'directory_created' => is_dir(dirname($absolutePath)),
                'label' => $label,
                'items' => $items,
                'total_labels' => $items->sum('copies'),
                'printer' => $printer,
                'print' => [
                    'auto_print' => true,
                    'target' => 'barcode',
                    'printer' => $printer,
                    'url' => $url,
                    'pdf_url' => $url,
                ],
                'prepared_at' => now()->toDateTimeString(),
            ],
        ]);
    }

    public function serveBarcodeLabel(Request $request, string $companyId, string $fileName): BinaryFileResponse
    {
        /*
         * This endpoint serves:
         * GET /api/assets/barcode-labels/{companyId}/{fileName}
         */
        $company = app('company');

        if ((string) $company->id !== (string) $companyId) {
            abort(404);
        }

        $safeFileName = basename($fileName);

        $candidates = [
            base_path('assets/barcode-labels/' . $companyId . '/' . $safeFileName),
            public_path('assets/barcode-labels/' . $companyId . '/' . $safeFileName),
            public_path('api/assets/barcode-labels/' . $companyId . '/' . $safeFileName),
            storage_path('app/public/assets/barcode-labels/' . $companyId . '/' . $safeFileName),
            storage_path('app/assets/barcode-labels/' . $companyId . '/' . $safeFileName),
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return response()->file($path, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'inline; filename="' . $safeFileName . '"',
                    'Cache-Control' => 'public, max-age=86400',
                ]);
            }
        }

        abort(404, 'Barcode label PDF not found.');
    }

    private function apiAssetsUrl(Request $request, string $relativeAfterAssets): string
    {
        $baseUrl = rtrim($request->getSchemeAndHttpHost() . $request->getBaseUrl(), '/');

        if (!str_ends_with($baseUrl, '/api')) {
            $baseUrl .= '/api';
        }

        return $baseUrl . '/assets/' . ltrim($relativeAfterAssets, '/');
    }

    private function resolvePrinterForTarget(string $companyId, string $target, ?string $branchId = null, ?string $tillId = null)
    {
        return DB::table('printers')
            ->where('company_id', $companyId)
            ->where('target_printing', $target)
            ->where('is_active', true)
            ->where(function ($query) use ($branchId) {
                $query->whereNull('branch_id');
                if ($branchId) $query->orWhere('branch_id', $branchId);
            })
            ->where(function ($query) use ($tillId) {
                $query->whereNull('till_id');
                if ($tillId) $query->orWhere('till_id', $tillId);
            })
            ->orderByRaw('CASE WHEN till_id IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('CASE WHEN branch_id IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('is_default')
            ->orderBy('printer_name')
            ->first();
    }

    private function buildItems(string $companyId, ?string $branchId, array $items)
    {
        return collect($items)->map(function ($item) use ($companyId, $branchId) {
            $product = DB::table('products')
                ->leftJoin('product_categories as pc', 'pc.id', '=', 'products.category_id')
                ->leftJoin('suppliers as sup', 'sup.id', '=', 'products.supplier_id')
                ->where('products.company_id', $companyId)
                ->where('products.id', $item['product_id'])
                ->select([
                    'products.id as product_id', 'products.name as product_name', 'products.code', 'products.sku',
                    'products.barcode as product_barcode',
                    DB::raw('COALESCE(pc.name, "Uncategorised") as category_name'),
                    DB::raw('COALESCE(sup.name, "No Supplier") as supplier_name'),
                    DB::raw($this->standardPriceSql($branchId) . ' as selling_price'),
                    DB::raw($this->standardPriceCurrencySql($branchId) . ' as selling_currency'),
                ])->first();

            if (!$product) return null;

            return [
                'product_id' => $product->product_id,
                'product_name' => $product->product_name,
                'code' => $product->code,
                'sku' => $product->sku,
                'category_name' => $product->category_name,
                'supplier_name' => $product->supplier_name,
                'barcode' => $item['barcode'],
                'copies' => (int) $item['copies'],
                'selling_price' => (float) $product->selling_price,
                'selling_currency' => $product->selling_currency ?: 'USD',
                'barcode_svg' => $this->barcodeSvgDataUri((string) $item['barcode']),
            ];
        })->filter()->values();
    }

    private function buildBarcodeHtml($company, $printer, array $label, $items): string
    {
        $labels = [];

        foreach ($items as $item) {
            for ($i = 0; $i < (int) $item['copies']; $i++) {
                $labels[] = $item;
            }
        }

        $width = (float) ($label['width_mm'] ?? 50);
        $height = (float) ($label['height_mm'] ?? 30);

        $showCompany = filter_var($label['show_company'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $showName = filter_var($label['show_product_name'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $showSku = filter_var($label['show_sku'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $showPrice = filter_var($label['show_price'] ?? true, FILTER_VALIDATE_BOOLEAN);

        /*
         |--------------------------------------------------------------------------
         | Same label layout
         |--------------------------------------------------------------------------
         | Everything selected on the frontend is printed on the same barcode label:
         | company name, product name, barcode image, barcode number, SKU/code and price.
         |--------------------------------------------------------------------------
         */
        $htmlLabels = collect($labels)->map(function ($item) use ($company, $showCompany, $showName, $showSku, $showPrice) {
            $barcode = e($item['barcode']);
            $svg = e($item['barcode_svg'] ?? '');
            $name = e($item['product_name'] ?? '');
            $sku = e($item['sku'] ?: ($item['code'] ?? ''));
            $price = e(($item['selling_currency'] ?: '') . ' ' . number_format((float) ($item['selling_price'] ?? 0), 2));
            $companyName = e($company->company_name ?? $company->name ?? '');

            return '
                <div class="label">
                    <div class="label-inner">
                        ' . ($showCompany ? '<div class="company">' . $companyName . '</div>' : '') . '
                        ' . ($showName ? '<div class="product-name">' . $name . '</div>' : '') . '

                        <div class="barcode-area">
                            <img src="' . $svg . '" class="barcode-img" />
                            <div class="barcode-number">' . $barcode . '</div>
                        </div>

                        <div class="bottom-row">
                            ' . ($showSku ? '<div class="sku">' . $sku . '</div>' : '<div class="sku"></div>') . '
                            ' . ($showPrice ? '<div class="price">' . $price . '</div>' : '<div class="price"></div>') . '
                        </div>
                    </div>
                </div>
            ';
        })->implode('');

        return '
            <!doctype html>
            <html>
            <head>
                <meta charset="utf-8">
                <style>
                    @page {
                        size: ' . $width . 'mm ' . $height . 'mm;
                        margin: 0;
                    }

                    * {
                        box-sizing: border-box;
                    }

                    html,
                    body {
                        margin: 0;
                        padding: 0;
                        font-family: Arial, Helvetica, sans-serif;
                        color: #000;
                        background: #fff;
                    }

                    .label {
                        width: ' . $width . 'mm;
                        height: ' . $height . 'mm;
                        page-break-after: always;
                        break-after: page;
                        overflow: hidden;
                        padding: 1.5mm;
                    }

                    .label-inner {
                        width: 100%;
                        height: 100%;
                        overflow: hidden;
                        display: flex;
                        flex-direction: column;
                        justify-content: flex-start;
                        align-items: stretch;
                    }

                    .company {
                        text-align: center;
                        font-size: 6.5pt;
                        font-weight: 800;
                        line-height: 1;
                        white-space: nowrap;
                        overflow: hidden;
                        text-overflow: ellipsis;
                        margin-bottom: 0.4mm;
                    }

                    .product-name {
                        text-align: center;
                        font-size: 7pt;
                        font-weight: 800;
                        line-height: 1.05;
                        max-height: 7.5mm;
                        overflow: hidden;
                        margin-bottom: 0.5mm;
                    }

                    .barcode-area {
                        flex: 1;
                        min-height: 11mm;
                        display: flex;
                        flex-direction: column;
                        justify-content: center;
                        align-items: stretch;
                        overflow: hidden;
                    }

                    .barcode-img {
                        width: 100%;
                        height: 13mm;
                        object-fit: fill;
                        display: block;
                    }

                    .barcode-number {
                        text-align: center;
                        font-size: 7.5pt;
                        font-weight: 800;
                        line-height: 1;
                        margin-top: 0.4mm;
                        white-space: nowrap;
                        overflow: hidden;
                        text-overflow: ellipsis;
                    }

                    .bottom-row {
                        display: flex;
                        justify-content: space-between;
                        align-items: center;
                        gap: 1.5mm;
                        font-size: 6.8pt;
                        font-weight: 800;
                        line-height: 1;
                        white-space: nowrap;
                        margin-top: 0.8mm;
                    }

                    .sku,
                    .price {
                        overflow: hidden;
                        text-overflow: ellipsis;
                    }

                    .sku {
                        text-align: left;
                        flex: 1;
                    }

                    .price {
                        text-align: right;
                        flex: 1;
                    }
                </style>
            </head>
            <body>' . $htmlLabels . '</body>
            </html>
        ';
    }

    private function barcodeSvgDataUri(string $text): string
    {
        $svg = $this->code128Svg($text);

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    private function code128Svg(string $text): string
    {
        $patterns = [
            "212222","222122","222221","121223","121322","131222","122213","122312","132212","221213",
            "221312","231212","112232","122132","122231","113222","123122","123221","223211","221132",
            "221231","213212","223112","312131","311222","321122","321221","312212","322112","322211",
            "212123","212321","232121","111323","131123","131321","112313","132113","132311","211313",
            "231113","231311","112133","112331","132131","113123","113321","133121","313121","211331",
            "231131","213113","213311","213131","311123","311321","331121","312113","312311","332111",
            "314111","221411","431111","111224","111422","121124","121421","141122","141221","112214",
            "112412","122114","122411","142112","142211","241211","221114","413111","241112","134111",
            "111242","121142","121241","114212","124112","124211","411212","421112","421211","212141",
            "214121","412121","111143","111341","131141","114113","114311","411113","411311","113141",
            "114131","311141","411131","211412","211214","211232","2331112"
        ];

        $valueText = trim($text) ?: '0';
        $values = [104];

        foreach (str_split($valueText) as $char) {
            $code = ord($char);

            if ($code < 32 || $code > 126) {
                $code = ord('?');
            }

            $values[] = $code - 32;
        }

        $checksum = 104;

        for ($i = 1; $i < count($values); $i++) {
            $checksum += $values[$i] * $i;
        }

        $values[] = $checksum % 103;
        $values[] = 106;

        $width = 360;
        $height = 95;
        $barHeight = 72;
        $totalUnits = 0;

        foreach ($values as $value) {
            foreach (str_split($patterns[$value]) as $unitWidth) {
                $totalUnits += (int) $unitWidth;
            }
        }

        $unit = $width / max($totalUnits, 1);
        $x = 0;
        $rects = '';

        foreach ($values as $value) {
            $pattern = $patterns[$value];

            foreach (str_split($pattern) as $index => $charWidth) {
                $w = ((int) $charWidth) * $unit;

                if ($index % 2 === 0) {
                    $rects .= '<rect x="' . round($x, 2) . '" y="0" width="' . max(round($w, 2), 0.75) . '" height="' . $barHeight . '" fill="#000"/>';
                }

                $x += $w;
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $width . ' ' . $height . '" width="' . $width . '" height="' . $height . '">' . $rects . '</svg>';
    }

    private function standardPriceSql(?string $branchId): string
    {
        $branchCondition = $branchId ? "AND (pp.branch_id = '{$branchId}' OR pp.branch_id IS NULL)" : "";
        return "COALESCE((SELECT pp.price FROM product_prices pp LEFT JOIN price_levels pl ON pl.id = pp.price_level_id WHERE pp.product_id = products.id AND pp.company_id = products.company_id {$branchCondition} AND pp.is_active = 1 AND pp.deleted_at IS NULL ORDER BY CASE WHEN LOWER(COALESCE(pl.name, '')) = 'standard' THEN 0 WHEN LOWER(COALESCE(pl.code, '')) = 'standard' THEN 1 WHEN COALESCE(pl.is_default, 0) = 1 THEN 2 WHEN COALESCE(pp.is_default, 0) = 1 THEN 3 ELSE 4 END, CASE WHEN pp.branch_id = '{$branchId}' THEN 0 ELSE 1 END, pp.updated_at DESC, pp.created_at DESC LIMIT 1), 0)";
    }

    private function standardPriceCurrencySql(?string $branchId): string
    {
        $branchCondition = $branchId ? "AND (pp.branch_id = '{$branchId}' OR pp.branch_id IS NULL)" : "";
        return "COALESCE((SELECT pp.currency FROM product_prices pp LEFT JOIN price_levels pl ON pl.id = pp.price_level_id WHERE pp.product_id = products.id AND pp.company_id = products.company_id {$branchCondition} AND pp.is_active = 1 AND pp.deleted_at IS NULL ORDER BY CASE WHEN LOWER(COALESCE(pl.name, '')) = 'standard' THEN 0 WHEN LOWER(COALESCE(pl.code, '')) = 'standard' THEN 1 WHEN COALESCE(pl.is_default, 0) = 1 THEN 2 WHEN COALESCE(pp.is_default, 0) = 1 THEN 3 ELSE 4 END, CASE WHEN pp.branch_id = '{$branchId}' THEN 0 ELSE 1 END, pp.updated_at DESC, pp.created_at DESC LIMIT 1), 'USD')";
    }
}
