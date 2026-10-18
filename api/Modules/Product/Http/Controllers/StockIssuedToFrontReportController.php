<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\BusinessModeBranchScopeService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class StockIssuedToFrontReportController extends Controller
{
    private array $enterpriseMovementTypes = [
        'boh_issue_to_front',
        'boh_issue_to_department',
        'boh_issue_to_expense',
        'boh_issue_to_production',
        'wastage',
    ];

    public function preview(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->buildReport($request)]);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'search' => ['nullable', 'string', 'max:180'],
            'issue_type' => ['nullable', 'in:front,department,expense,production,wastage'],
            'issue_destination' => ['nullable', 'in:front,department,expense,production,wastage'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $request->merge([
            'date_from' => $validated['date_from'] ?? now()->startOfMonth()->toDateString(),
            'date_to' => $validated['date_to'] ?? now()->toDateString(),
        ]);

        $report = $this->buildReport($request, null, true);
        $issues = collect($report['issues'])->map(function (array $issue) {
            unset($issue['lines']);

            return $issue;
        });
        $perPage = (int) ($validated['per_page'] ?? 25);
        $page = (int) ($validated['page'] ?? 1);
        $total = $issues->count();

        return response()->json([
            'data' => $issues->forPage($page, $perPage)->values()->all(),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'date_from' => $report['date_from'],
                'date_to' => $report['date_to'],
                'summary' => $report['summary'],
            ],
        ]);
    }

    public function show(Request $request, string $issueId): JsonResponse
    {
        $report = $this->buildReport($request, $issueId, true);
        $issue = collect($report['issues'])->first();

        abort_unless($issue, 404, 'Issued stock document was not found.');

        return response()->json(['data' => $issue]);
    }

    public function downloadIssuePdf(Request $request, string $issueId)
    {
        $report = $this->buildReport($request, $issueId, true);
        $issue = collect($report['issues'])->first();

        abort_unless($issue, 404, 'Issued stock document was not found.');

        $report['title'] = 'Issued Stock ' . ($issue['issue_number'] ?? $issueId);
        $report['issues'] = [$issue];
        $issuedDate = Carbon::parse($issue['posted_at'] ?? now())->toDateString();
        $report['date_from'] = $issuedDate;
        $report['date_to'] = $issuedDate;
        $html = $this->pdfHtml($report);
        $filename = strtolower((string) ($issue['issue_number'] ?? 'issued-stock-' . $issueId)) . '.pdf';

        return \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)
            ->setPaper('a4', 'landscape')
            ->download($filename);
    }

    public function downloadExcel(Request $request)
    {
        $data = $this->buildReport($request);
        $spreadsheet = $this->buildSpreadsheet($data);
        $writer = new Xlsx($spreadsheet);

        $filename = 'enterprise-stock-issues-' . ($data['date_from'] ?? 'from') . '-to-' . ($data['date_to'] ?? 'to') . '.xlsx';

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0, no-cache, no-store, must-revalidate',
            'Pragma' => 'public',
        ]);
    }

    public function downloadPdf(Request $request)
    {
        $data = $this->buildReport($request);
        $html = $this->pdfHtml($data);
        $filename = 'enterprise-stock-issues-' . ($data['date_from'] ?? 'from') . '-to-' . ($data['date_to'] ?? 'to') . '.pdf';

        if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            return \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)
                ->setPaper('a4', 'landscape')
                ->download($filename);
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . str_replace('.pdf', '.html', $filename) . '"',
        ]);
    }

    private function buildReport(Request $request, ?string $issueId = null, bool $issuedStockOnly = false): array
    {
        $company = $this->resolveCompany($request);
        $branchId = $this->resolveBranchId($request);

        if (!$company) {
            abort(response()->json(['message' => 'Company could not be resolved.'], 422));
        }

        if (!$branchId) {
            abort(response()->json(['message' => 'Branch could not be resolved.'], 422));
        }

        $dateFrom = Carbon::parse($request->input('date_from', now()->toDateString()))->startOfDay();
        $dateTo = Carbon::parse($request->input('date_to', $dateFrom->toDateString()))->endOfDay();
        $search = trim((string) $request->input('search', ''));
        $issueTypeFilter = trim((string) $request->input('issue_type', ''));
        $destinationFilter = trim((string) $request->input('issue_destination', ''));

        $hasSmMetadata = Schema::hasColumn('stock_movements', 'metadata');
        $hasSmIssueDestination = Schema::hasColumn('stock_movements', 'issue_destination');
        $hasSmStockUsageType = Schema::hasColumn('stock_movements', 'stock_usage_type');
        $hasSmExpenseAccountCode = Schema::hasColumn('stock_movements', 'expense_account_code');
        $hasSmCurrencyCode = Schema::hasColumn('stock_movements', 'currency_code');
        $hasSmCurrency = Schema::hasColumn('stock_movements', 'currency');
        $hasProductStockUsageType = Schema::hasColumn('products', 'stock_usage_type');
        $hasProductDefaultDestination = Schema::hasColumn('products', 'default_issue_destination');
        $hasProductCostCurrencyCode = Schema::hasColumn('product_costs', 'currency_code');
        $hasProductCostCurrency = Schema::hasColumn('product_costs', 'currency');

        $branchNameSelect = $this->branchNameSelect();
        $currencyExpression = $this->currencyExpression($hasSmCurrencyCode, $hasSmCurrency);
        $costCurrencyExpression = $this->productCostCurrencyAggregateExpression(
            $hasProductCostCurrencyCode,
            $hasProductCostCurrency
        );

        $costSubquery = DB::table('product_costs')
            ->selectRaw("product_id, COALESCE(MAX(average_cost), MAX(last_cost), 0) as derived_unit_cost, {$costCurrencyExpression} as derived_currency")
            ->where('company_id', $company->id)
            ->where(function ($query) use ($branchId) {
                $query->whereNull('branch_id')->orWhere('branch_id', $branchId);
            })
            ->groupBy('product_id');

        $query = DB::table('stock_movements as sm')
            ->leftJoin('products as p', 'p.id', '=', 'sm.product_id')
            ->leftJoin('uoms as u', 'u.id', '=', 'p.base_uom_id')
            ->leftJoin('company_branches as b', 'b.id', '=', 'sm.branch_id')
            ->leftJoin('pos_users as creator', 'creator.id', '=', 'sm.created_by')
            ->leftJoinSub($costSubquery, 'pcost', function ($join) {
                $join->on('pcost.product_id', '=', 'sm.product_id');
            })
            ->where('sm.company_id', $company->id)
            ->where('sm.branch_id', $branchId)
            ->whereIn('sm.movement_type', $this->enterpriseMovementTypes);

        if ($issueId !== null && ! $issuedStockOnly) {
            $query->where('sm.reference_id', $issueId);
        } elseif ($issueId === null) {
            $query->whereBetween('sm.created_at', [$dateFrom, $dateTo]);
        }

        if ($issuedStockOnly) {
            $this->applyIssuedStockReferenceScope($query, $issueId);
        }

        if ($issueTypeFilter !== '') {
            $query->where(function ($inner) use ($issueTypeFilter, $hasSmMetadata) {
                $inner->where('sm.movement_type', $this->movementTypeForIssueType($issueTypeFilter));
                if ($hasSmMetadata) {
                    $inner->orWhere('sm.metadata', 'like', '%"issue_type":"' . $issueTypeFilter . '"%')
                        ->orWhere('sm.metadata', 'like', '%"issue_type": "' . $issueTypeFilter . '"%');
                }
            });
        }

        if ($destinationFilter !== '') {
            $query->where(function ($inner) use ($destinationFilter, $hasSmIssueDestination, $hasSmMetadata) {
                if ($hasSmIssueDestination) {
                    $inner->where('sm.issue_destination', $destinationFilter);
                } else {
                    $inner->whereRaw('1 = 0');
                }

                if ($hasSmMetadata) {
                    $inner->orWhere('sm.metadata', 'like', '%"issue_destination":"' . $destinationFilter . '"%')
                        ->orWhere('sm.metadata', 'like', '%"issue_destination": "' . $destinationFilter . '"%')
                        ->orWhere('sm.metadata', 'like', '%"destination":"' . $destinationFilter . '"%')
                        ->orWhere('sm.metadata', 'like', '%"destination": "' . $destinationFilter . '"%');
                }
            });
        }

        if ($search !== '') {
            $like = '%' . $search . '%';
            $query->where(function ($inner) use ($like, $hasSmMetadata) {
                $inner->where('p.name', 'like', $like)
                    ->orWhere('p.code', 'like', $like)
                    ->orWhere('p.sku', 'like', $like)
                    ->orWhere('sm.notes', 'like', $like);

                if ($hasSmMetadata) {
                    $inner->orWhere('sm.metadata', 'like', $like);
                }
            });
        }

        $select = [
            'sm.id',
            'sm.product_id',
            'sm.branch_id',
            'sm.created_at',
            DB::raw('DATE(sm.created_at) as issue_date'),
            'sm.movement_type',
            'sm.stock_area',
            'sm.from_stock_area',
            'sm.to_stock_area',
            DB::raw('COALESCE(sm.quantity_in, 0) as quantity_in'),
            DB::raw('COALESCE(sm.quantity_out, 0) as quantity_out'),
            DB::raw('COALESCE(NULLIF(sm.unit_cost, 0), pcost.derived_unit_cost, 0) as unit_cost'),
            DB::raw('COALESCE(sm.total_cost, 0) as stored_total_cost'),
            DB::raw($currencyExpression . ' as currency_code'),
            'sm.reference_type',
            'sm.reference_id',
            'sm.created_by',
            'sm.notes',
            DB::raw($hasSmMetadata ? 'sm.metadata as metadata' : 'NULL as metadata'),
            DB::raw($hasSmIssueDestination ? 'sm.issue_destination as row_issue_destination' : 'NULL as row_issue_destination'),
            DB::raw($hasSmStockUsageType ? 'sm.stock_usage_type as row_stock_usage_type' : 'NULL as row_stock_usage_type'),
            DB::raw($hasSmExpenseAccountCode ? 'sm.expense_account_code as row_expense_account_code' : 'NULL as row_expense_account_code'),
            DB::raw('p.name as product_name'),
            DB::raw('p.code as product_code'),
            DB::raw('p.sku as product_sku'),
            'p.product_type',
            DB::raw($hasProductStockUsageType ? 'p.stock_usage_type as product_stock_usage_type' : 'NULL as product_stock_usage_type'),
            DB::raw($hasProductDefaultDestination ? 'p.default_issue_destination as product_default_issue_destination' : 'NULL as product_default_issue_destination'),
            DB::raw('COALESCE(u.code, u.name, "EACH") as uom_code'),
            DB::raw($branchNameSelect . ' as branch_name'),
            DB::raw('COALESCE(creator.name, creator.username, "-") as created_by_name'),
        ];

        $rows = $query
            ->select($select)
            ->orderBy('sm.created_at')
            ->orderBy('p.name')
            ->get()
            ->map(fn ($row) => $this->mapMovementRow($row))
            ->values();

        $rows = $this->attachMovementLedBalances($rows, $company->id, $branchId, $dateTo);

        $issues = $this->buildIssueDocuments($rows);
        $summary = $this->buildSummary($issues, $rows);
        $days = $this->buildDays($rows);

        return [
            'title' => 'Enterprise Stock Issue Report',
            'date_from' => $dateFrom->toDateString(),
            'date_to' => $dateTo->toDateString(),
            'branch_id' => $branchId,
            'generated_at' => now()->toDateTimeString(),
            'movement_types' => $this->enterpriseMovementTypes,
            'summary' => $summary,
            'issues' => $issues->values()->all(),
            'days' => $days->all(),
            'movements' => $rows->all(),
        ];
    }

    private function branchNameSelect(): string
    {
        $table = 'company_branches';

        if (!Schema::hasTable($table)) {
            return '"-"';
        }

        $hasBranchName = Schema::hasColumn($table, 'branch_name');
        $hasName = Schema::hasColumn($table, 'name');

        if ($hasBranchName && $hasName) {
            return 'COALESCE(b.branch_name, b.name, "-")';
        }

        if ($hasBranchName) {
            return 'COALESCE(b.branch_name, "-")';
        }

        if ($hasName) {
            return 'COALESCE(b.name, "-")';
        }

        return '"-"';
    }

    private function currencyExpression(bool $hasCurrencyCode, bool $hasCurrency): string
    {
        if ($hasCurrencyCode && $hasCurrency) {
            return 'COALESCE(sm.currency_code, sm.currency, pcost.derived_currency, "USD")';
        }

        if ($hasCurrencyCode) {
            return 'COALESCE(sm.currency_code, pcost.derived_currency, "USD")';
        }

        if ($hasCurrency) {
            return 'COALESCE(sm.currency, pcost.derived_currency, "USD")';
        }

        return 'COALESCE(pcost.derived_currency, "USD")';
    }

    private function productCostCurrencyAggregateExpression(bool $hasCurrencyCode, bool $hasCurrency): string
    {
        if ($hasCurrencyCode && $hasCurrency) {
            return "COALESCE(MAX(currency_code), MAX(currency), 'USD')";
        }

        if ($hasCurrencyCode) {
            return "COALESCE(MAX(currency_code), 'USD')";
        }

        if ($hasCurrency) {
            return "COALESCE(MAX(currency), 'USD')";
        }

        return "'USD'";
    }

    private function applyIssuedStockReferenceScope($query, ?string $issueId = null): void
    {
        $query->where(function ($scope) use ($issueId) {
            $scope->where(function ($current) use ($issueId) {
                $current->whereIn('sm.reference_type', ['enterprise_stock_issue', 'boh_issue_to_front']);

                if ($issueId !== null) {
                    $current->where('sm.reference_id', $issueId);
                }
            })->orWhere(function ($legacy) use ($issueId) {
                $legacy->where('sm.movement_type', 'boh_issue_to_front')
                    ->where(function ($referenceType) {
                        $referenceType->whereNull('sm.reference_type')
                            ->orWhere('sm.reference_type', '');
                    })
                    ->where(function ($referenceId) {
                        $referenceId->whereNull('sm.reference_id')
                            ->orWhere('sm.reference_id', '');
                    });

                if ($issueId !== null) {
                    $legacy->where('sm.id', $issueId);
                }
            });
        });
    }

    private function mapMovementRow(object $row): array
    {
        $metadata = $this->metadataToArray($row->metadata ?? null);
        $qtyIn = (float) ($row->quantity_in ?? 0);
        $qtyOut = (float) ($row->quantity_out ?? 0);
        $issuedQty = max($qtyIn, $qtyOut);
        $unitCost = (float) ($row->unit_cost ?? 0);
        $totalCost = (float) ($row->stored_total_cost ?? 0);

        if ($totalCost <= 0 && $unitCost > 0) {
            $totalCost = $issuedQty * $unitCost;
        }

        $destination = $this->firstNonEmpty([
            $row->row_issue_destination ?? null,
            $metadata['issue_destination'] ?? null,
            $metadata['destination'] ?? null,
            $this->destinationFromMovementType($row->movement_type ?? null),
            $row->to_stock_area ?? null,
        ]);

        $issueType = $this->firstNonEmpty([
            $metadata['issue_type'] ?? null,
            $destination,
        ]);

        $stockUsageType = $this->firstNonEmpty([
            $row->row_stock_usage_type ?? null,
            $metadata['stock_usage_type'] ?? null,
            $row->product_stock_usage_type ?? null,
            in_array(strtolower((string) ($row->product_type ?? '')), ['consumable', 'consumables'], true) ? 'non_turnover' : 'turnover',
        ]);

        $issueId = $this->firstNonEmpty([
            $row->reference_id ?? null,
            $metadata['issue_id'] ?? null,
            $metadata['document_id'] ?? null,
            $row->id,
        ]);

        $issueName = $this->firstNonEmpty([
            $metadata['issue_name'] ?? null,
            $metadata['name'] ?? null,
            $metadata['document_name'] ?? null,
            'Stock Issue ' . Carbon::parse($row->created_at)->format('Y-m-d H:i'),
        ]);

        $issueNumber = $this->firstNonEmpty([
            $metadata['issue_number'] ?? null,
            $metadata['document_number'] ?? null,
            $this->issueNumber((string) $issueId, $row->created_at),
        ]);

        return [
            'id' => (string) $row->id,
            'movement_id' => (string) $row->id,
            'issue_id' => (string) $issueId,
            'issue_number' => (string) $issueNumber,
            'issue_name' => (string) $issueName,
            'issue_type' => $this->normalizeDestination($issueType),
            'issue_destination' => $this->normalizeDestination($destination),
            'department_name' => $this->firstNonEmpty([$metadata['department_name'] ?? null, $metadata['department'] ?? null]),
            'cost_center' => $this->firstNonEmpty([$metadata['cost_center'] ?? null, $metadata['cost_centre'] ?? null]),
            'requested_by' => $this->firstNonEmpty([
                $metadata['requested_by'] ?? null,
                $metadata['requester'] ?? null,
                $row->created_by_name ?? null,
            ]),
            'requested_by_id' => $this->firstNonEmpty([
                $metadata['requested_by_id'] ?? null,
                $row->created_by ?? null,
            ]),
            'issue_purpose' => $this->firstNonEmpty([$metadata['issue_purpose'] ?? null, $metadata['purpose'] ?? null, $row->notes ?? null]),
            'notes' => $this->firstNonEmpty([
                $metadata['header_notes'] ?? null,
                $metadata['notes'] ?? null,
                $row->notes ?? null,
            ]),
            'issue_date' => (string) $row->issue_date,
            'posted_at' => (string) $row->created_at,
            'created_at' => (string) $row->created_at,
            'created_by_name' => $row->created_by_name ?: '-',
            'branch_id' => (string) ($row->branch_id ?? ''),
            'branch_name' => $row->branch_name ?: '-',
            'product_id' => (string) $row->product_id,
            'product_name' => $row->product_name ?: 'Product',
            'product_code' => $row->product_code ?: null,
            'product_sku' => $row->product_sku ?: null,
            'product_type' => $row->product_type ?: null,
            'stock_usage_type' => $this->normalizeStockUsage($stockUsageType),
            'expense_account_code' => $this->firstNonEmpty([$row->row_expense_account_code ?? null, $metadata['expense_account_code'] ?? null]),
            'uom_code' => $row->uom_code ?: 'EACH',
            'from_stock_area' => $row->from_stock_area ?: 'back',
            'to_stock_area' => $row->to_stock_area ?: $this->normalizeDestination($destination),
            'quantity_in' => round($qtyIn, 6),
            'quantity_out' => round($qtyOut, 6),
            'issued_quantity' => round($issuedQty, 6),
            'unit_cost' => round($unitCost, 6),
            'total_cost' => round($totalCost, 6),
            'currency_code' => $row->currency_code ?: 'USD',
            'reference_type' => $row->reference_type,
            'reference_id' => $row->reference_id,
            'metadata' => $metadata,
            'front_opening_before_issue' => 0,
            'front_available_after_issue' => 0,
            'back_opening_before_issue' => 0,
            'back_available_after_issue' => 0,
        ];
    }

    private function buildIssueDocuments(Collection $rows): Collection
    {
        return $rows
            ->groupBy('issue_id')
            ->map(function (Collection $issueRows, string $issueId) {
                $first = $issueRows->first();
                $currency = $first['currency_code'] ?? 'USD';

                return [
                    'issue_id' => $issueId,
                    'issue_number' => $first['issue_number'] ?? $this->issueNumber($issueId, $first['posted_at'] ?? now()),
                    'issue_name' => $first['issue_name'] ?? 'Stock Issue',
                    'issue_type' => $first['issue_type'] ?? '-',
                    'issue_destination' => $first['issue_destination'] ?? '-',
                    'branch_id' => $first['branch_id'] ?? null,
                    'branch_name' => $first['branch_name'] ?? '-',
                    'department_name' => $first['department_name'] ?? null,
                    'cost_center' => $first['cost_center'] ?? null,
                    'requested_by' => $first['requested_by'] ?? null,
                    'requested_by_id' => $first['requested_by_id'] ?? null,
                    'issue_purpose' => $first['issue_purpose'] ?? null,
                    'notes' => $first['notes'] ?? null,
                    'posted_at' => $first['posted_at'] ?? null,
                    'created_by_name' => $first['created_by_name'] ?? '-',
                    'currency_code' => $currency,
                    'line_count' => $issueRows->count(),
                    'total_quantity' => round($issueRows->sum('issued_quantity'), 6),
                    'total_value' => round($issueRows->sum('total_cost'), 6),
                    'lines' => $issueRows->map(function ($row) {
                        return [
                            'movement_id' => $row['movement_id'],
                            'product_id' => $row['product_id'],
                            'product_name' => $row['product_name'],
                            'product_code' => $row['product_code'],
                            'product_sku' => $row['product_sku'],
                            'product_type' => $row['product_type'],
                            'stock_usage_type' => $row['stock_usage_type'],
                            'issue_destination' => $row['issue_destination'],
                            'expense_account_code' => $row['expense_account_code'],
                            'issued_quantity' => $row['issued_quantity'],
                            'uom_code' => $row['uom_code'],
                            'unit_cost' => $row['unit_cost'],
                            'total_cost' => $row['total_cost'],
                            'currency_code' => $row['currency_code'],
                            'from_stock_area' => $row['from_stock_area'],
                            'to_stock_area' => $row['to_stock_area'],
                            'back_opening_before_issue' => $row['back_opening_before_issue'],
                            'back_available_after_issue' => $row['back_available_after_issue'],
                            'front_opening_before_issue' => $row['front_opening_before_issue'],
                            'front_available_after_issue' => $row['front_available_after_issue'],
                        ];
                    })->values()->all(),
                ];
            })
            ->sortByDesc('posted_at')
            ->values();
    }

    private function buildSummary(Collection $issues, Collection $rows): array
    {
        $currencyTotals = $rows->groupBy('currency_code')->map(function ($currencyRows, $currency) {
            $totalValue = round($currencyRows->sum('total_cost'), 6);

            return [
                'currency_code' => $currency,
                'line_count' => $currencyRows->count(),
                'movement_count' => $currencyRows->count(),
                'issued_quantity' => round($currencyRows->sum('issued_quantity'), 6),
                'total_value' => $totalValue,
                'total_cost' => $totalValue,
            ];
        })->values()->all();

        return [
            'issue_count' => $issues->count(),
            'line_count' => $rows->count(),
            'movement_count' => $rows->count(),
            'distinct_products' => $rows->pluck('product_id')->unique()->count(),
            'distinct_days' => $rows->pluck('issue_date')->unique()->count(),
            'total_quantity' => round($rows->sum('issued_quantity'), 6),
            'total_value' => round($rows->sum('total_cost'), 6),
            'currency_code' => $rows->first()['currency_code'] ?? 'USD',
            'currency_totals' => $currencyTotals,
        ];
    }

    private function buildDays(Collection $rows): Collection
    {
        return $rows->groupBy('issue_date')->map(function ($dayRows, $date) {
            $productSummary = $dayRows
                ->groupBy(fn ($row) => ($row['product_id'] ?? '-') . '|' . ($row['currency_code'] ?? 'USD'))
                ->map(function (Collection $productRows) {
                    $first = $productRows->first();
                    $last = $productRows->last();
                    $quantity = (float) $productRows->sum('issued_quantity');
                    $totalCost = (float) $productRows->sum('total_cost');

                    return [
                        'product_id' => $first['product_id'],
                        'product_name' => $first['product_name'],
                        'product_type' => $first['product_type'],
                        'product_code' => $first['product_code'],
                        'product_sku' => $first['product_sku'],
                        'uom_code' => $first['uom_code'],
                        'currency_code' => $first['currency_code'],
                        'front_opening_qty' => $first['front_opening_before_issue'],
                        'issued_quantity' => round($quantity, 6),
                        'front_available_after_issue' => $last['front_available_after_issue'],
                        'back_opening_before_issue' => $first['back_opening_before_issue'],
                        'back_available_after_issue' => $last['back_available_after_issue'],
                        'average_unit_cost' => $quantity > 0 ? round($totalCost / $quantity, 6) : 0,
                        'total_cost' => round($totalCost, 6),
                    ];
                })
                ->values()
                ->all();
            $currencyTotals = $dayRows
                ->groupBy('currency_code')
                ->map(fn (Collection $currencyRows, string $currency) => [
                    'currency_code' => $currency,
                    'movement_count' => $currencyRows->count(),
                    'issued_quantity' => round($currencyRows->sum('issued_quantity'), 6),
                    'total_cost' => round($currencyRows->sum('total_cost'), 6),
                ])
                ->values()
                ->all();

            return [
                'issue_date' => (string) $date,
                'movement_count' => $dayRows->count(),
                'distinct_products' => $dayRows->pluck('product_id')->unique()->count(),
                'currency_totals' => $currencyTotals,
                'product_summary' => $productSummary,
                'movements' => $dayRows->values()->all(),
            ];
        })->sortKeys()->values();
    }

    private function attachMovementLedBalances(Collection $rows, string $companyId, string $branchId, Carbon $dateTo): Collection
    {
        if ($rows->isEmpty()) {
            return $rows;
        }

        $productIds = $rows->pluck('product_id')->unique()->values()->all();
        $targetIds = $rows->pluck('id')->map(fn ($id) => (string) $id)->all();
        $targetMap = [];

        $history = DB::table('stock_movements')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereIn('product_id', $productIds)
            ->where('created_at', '<=', $dateTo)
            ->select([
                'id',
                'product_id',
                'created_at',
                'movement_type',
                'stock_area',
                'from_stock_area',
                'to_stock_area',
                'quantity_in',
                'quantity_out',
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $frontRunning = [];
        $backRunning = [];

        foreach ($history as $movement) {
            $productId = (string) $movement->product_id;
            $frontBefore = round((float) ($frontRunning[$productId] ?? 0), 6);
            $backBefore = round((float) ($backRunning[$productId] ?? 0), 6);
            $frontAfter = round($frontBefore + $this->frontNetQty($movement), 6);
            $backAfter = round($backBefore + $this->backNetQty($movement), 6);

            if (in_array((string) $movement->id, $targetIds, true)) {
                $targetMap[(string) $movement->id] = [
                    'front_opening_before_issue' => $frontBefore,
                    'front_available_after_issue' => $frontAfter,
                    'back_opening_before_issue' => $backBefore,
                    'back_available_after_issue' => $backAfter,
                ];
            }

            $frontRunning[$productId] = $frontAfter;
            $backRunning[$productId] = $backAfter;
        }

        return $rows->map(function ($row) use ($targetMap) {
            return array_merge($row, $targetMap[(string) $row['id']] ?? []);
        })->values();
    }

    private function frontNetQty(object $movement): float
    {
        $type = strtolower((string) ($movement->movement_type ?? ''));
        $stockArea = strtolower((string) ($movement->stock_area ?? ''));
        $from = strtolower((string) ($movement->from_stock_area ?? ''));
        $to = strtolower((string) ($movement->to_stock_area ?? ''));
        $qtyIn = (float) ($movement->quantity_in ?? 0);
        $qtyOut = (float) ($movement->quantity_out ?? 0);

        $net = 0.0;

        if ($to === 'front' || ($stockArea === 'front' && $qtyIn > 0)) {
            $net += $qtyIn;
        }

        if ($from === 'front' || ($stockArea === 'front' && $type !== 'boh_issue_to_front' && $qtyOut > 0)) {
            $net -= $qtyOut;
        }

        return round($net, 6);
    }

    private function backNetQty(object $movement): float
    {
        $stockArea = strtolower((string) ($movement->stock_area ?? ''));
        $from = strtolower((string) ($movement->from_stock_area ?? ''));
        $to = strtolower((string) ($movement->to_stock_area ?? ''));
        $qtyIn = (float) ($movement->quantity_in ?? 0);
        $qtyOut = (float) ($movement->quantity_out ?? 0);

        $net = 0.0;

        if ($to === 'back' || ($stockArea === 'back' && $qtyIn > 0)) {
            $net += $qtyIn;
        }

        if ($from === 'back' || ($stockArea === 'back' && $qtyOut > 0)) {
            $net -= $qtyOut;
        }

        return round($net, 6);
    }

    private function movementTypeForIssueType(string $issueType): string
    {
        return match ($this->normalizeDestination($issueType)) {
            'front' => 'boh_issue_to_front',
            'department' => 'boh_issue_to_department',
            'expense' => 'boh_issue_to_expense',
            'production' => 'boh_issue_to_production',
            'wastage' => 'wastage',
            default => 'boh_issue_to_front',
        };
    }

    private function destinationFromMovementType(?string $movementType): string
    {
        return match (strtolower((string) $movementType)) {
            'boh_issue_to_front' => 'front',
            'boh_issue_to_department' => 'department',
            'boh_issue_to_expense' => 'expense',
            'boh_issue_to_production' => 'production',
            'wastage' => 'wastage',
            default => '-',
        };
    }

    private function normalizeDestination(?string $value): string
    {
        $value = strtolower(trim((string) $value));
        $value = str_replace(['-', ' '], '_', $value);

        return match ($value) {
            'department_use', 'department' => 'department',
            'operating_expense', 'expense' => 'expense',
            'production_use', 'production' => 'production',
            'wastage', 'spoilage' => 'wastage',
            'selling', 'front_stock', 'front' => 'front',
            default => $value ?: '-',
        };
    }

    private function normalizeStockUsage(?string $value): string
    {
        $value = strtolower(trim((string) $value));
        $value = str_replace(['-', ' '], '_', $value);

        if ($value === 'non_turnover') {
            return 'non_turnover';
        }

        return 'turnover';
    }

    private function issueNumber(string $issueId, mixed $issuedAt): string
    {
        $date = Carbon::parse($issuedAt)->format('Ymd');
        $token = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $issueId) ?: 'ISSUE', -12));

        return "ISS-{$date}-{$token}";
    }

    private function metadataToArray(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (!$metadata) {
            return [];
        }

        $decoded = json_decode((string) $metadata, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $value) {
            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    private function buildSpreadsheet(array $data): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('ServeNest POS Enterprise')
            ->setTitle($data['title'] ?? 'Enterprise Stock Issue Report')
            ->setSubject('Enterprise Stock Issue Report')
            ->setDescription('Header based enterprise stock issue report with all issue destinations.');

        $summarySheet = $spreadsheet->getActiveSheet();
        $summarySheet->setTitle('Summary');
        $this->buildSummaryWorksheet($summarySheet, $data);

        $documentSheet = $spreadsheet->createSheet();
        $documentSheet->setTitle('Issue Documents');
        $this->buildIssueDocumentsWorksheet($documentSheet, $data);

        $lineSheet = $spreadsheet->createSheet();
        $lineSheet->setTitle('Issue Lines');
        $this->buildIssueLinesWorksheet($lineSheet, $data);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function buildSummaryWorksheet($sheet, array $data): void
    {
        $summary = $data['summary'] ?? [];
        $issues = collect($data['issues'] ?? []);
        $currencyTotals = collect($summary['currency_totals'] ?? []);

        $this->prepareWorksheet($sheet);
        $this->writeReportTitle($sheet, 'ENTERPRISE STOCK ISSUE REPORT', 1, 1, 8);

        $this->setExcelCell($sheet, 1, 3, 'Date From');
        $this->setExcelCell($sheet, 2, 3, $data['date_from'] ?? '');
        $this->setExcelCell($sheet, 3, 3, 'Date To');
        $this->setExcelCell($sheet, 4, 3, $data['date_to'] ?? '');
        $this->setExcelCell($sheet, 5, 3, 'Generated');
        $this->setExcelCell($sheet, 6, 3, $data['generated_at'] ?? '');
        $this->styleMetaRow($sheet, 3, 1, 6);

        $cards = [
            ['Issue Documents', $summary['issue_count'] ?? 0],
            ['Issued Lines', $summary['line_count'] ?? 0],
            ['Distinct Products', $summary['distinct_products'] ?? 0],
            ['Total Quantity', $summary['total_quantity'] ?? 0],
            ['Total Value', ($summary['currency_code'] ?? 'USD') . ' ' . number_format((float) ($summary['total_value'] ?? 0), 2)],
        ];

        $col = 1;
        foreach ($cards as [$label, $value]) {
            $this->writeSummaryCard($sheet, $col, 5, $label, $value);
            $col += 2;
        }

        $row = 9;
        $this->writeSectionHeader($sheet, $row, 'CURRENCY TOTALS', 1, 5);
        $row++;
        $currencyHeaders = ['Currency', 'Lines', 'Issued Quantity', 'Total Value', 'Average Unit Value'];
        $this->writeTableHeader($sheet, $row, $currencyHeaders, 1);
        $row++;

        foreach ($currencyTotals as $currencyRow) {
            $qty = (float) ($currencyRow['issued_quantity'] ?? 0);
            $value = (float) ($currencyRow['total_value'] ?? 0);
            $values = [
                $currencyRow['currency_code'] ?? 'USD',
                $currencyRow['line_count'] ?? 0,
                $qty,
                $value,
                $qty > 0 ? $value / $qty : 0,
            ];
            $this->writeRowValues($sheet, $row, $values, 1);
            $this->styleDataRow($sheet, $row, 1, count($values), $row % 2 === 0);
            $sheet->getStyle($this->range(3, $row, 5, $row))->getNumberFormat()->setFormatCode('#,##0.0000');
            $row++;
        }

        $row += 2;
        $this->writeSectionHeader($sheet, $row, 'ISSUE TYPE SUMMARY', 1, 6);
        $row++;
        $this->writeTableHeader($sheet, $row, ['Issue Type', 'Destination', 'Documents', 'Lines', 'Quantity', 'Value'], 1);
        $row++;

        $typeSummary = $issues
            ->groupBy(fn ($issue) => ($issue['issue_type'] ?? '-') . '|' . ($issue['issue_destination'] ?? '-'))
            ->map(function ($group) {
                $first = $group->first();
                return [
                    $this->issueTypeLabel($first['issue_type'] ?? '-'),
                    $this->destinationLabel($first['issue_destination'] ?? '-'),
                    $group->count(),
                    $group->sum('line_count'),
                    $group->sum('total_quantity'),
                    $group->sum('total_value'),
                ];
            })
            ->values();

        foreach ($typeSummary as $values) {
            $this->writeRowValues($sheet, $row, $values, 1);
            $this->styleDataRow($sheet, $row, 1, count($values), $row % 2 === 0);
            $sheet->getStyle($this->range(5, $row, 6, $row))->getNumberFormat()->setFormatCode('#,##0.0000');
            $row++;
        }

        $row += 2;
        $this->writeSectionHeader($sheet, $row, 'ISSUE DOCUMENT LIST', 1, 9);
        $row++;
        $headers = ['Issue Name', 'Issue Type', 'Destination', 'Branch', 'Department', 'Posted At', 'Lines', 'Quantity', 'Value'];
        $this->writeTableHeader($sheet, $row, $headers, 1);
        $row++;

        foreach ($issues as $issue) {
            $values = [
                $issue['issue_name'] ?? 'Stock Issue',
                $this->issueTypeLabel($issue['issue_type'] ?? ''),
                $this->destinationLabel($issue['issue_destination'] ?? ''),
                $issue['branch_name'] ?? '-',
                $issue['department_name'] ?? '-',
                $issue['posted_at'] ?? '-',
                $issue['line_count'] ?? 0,
                $issue['total_quantity'] ?? 0,
                $issue['total_value'] ?? 0,
            ];
            $this->writeRowValues($sheet, $row, $values, 1);
            $this->styleDataRow($sheet, $row, 1, count($values), $row % 2 === 0);
            $sheet->getStyle($this->range(8, $row, 9, $row))->getNumberFormat()->setFormatCode('#,##0.0000');
            $row++;
        }

        $this->applyWorksheetWidths($sheet, [
            'A' => 24, 'B' => 18, 'C' => 22, 'D' => 22, 'E' => 22, 'F' => 20, 'G' => 13, 'H' => 16, 'I' => 16, 'J' => 16,
        ]);
        $sheet->freezePane('A10');
    }

    private function buildIssueDocumentsWorksheet($sheet, array $data): void
    {
        $this->prepareWorksheet($sheet);
        $this->writeReportTitle($sheet, 'ISSUE DOCUMENTS WITH HEADER DETAILS', 1, 1, 12);

        $this->setExcelCell($sheet, 1, 3, 'Period');
        $this->setExcelCell($sheet, 2, 3, ($data['date_from'] ?? '') . ' to ' . ($data['date_to'] ?? ''));
        $this->setExcelCell($sheet, 4, 3, 'Generated');
        $this->setExcelCell($sheet, 5, 3, $data['generated_at'] ?? '');
        $this->styleMetaRow($sheet, 3, 1, 5);

        $row = 5;
        foreach (($data['issues'] ?? []) as $issueIndex => $issue) {
            $this->writeIssueHeaderBlock($sheet, $row, $issue, $issueIndex + 1);
            $row += 6;

            $headers = ['Line', 'Stock Item', 'Code', 'SKU', 'Product Type', 'Usage', 'Destination', 'Issued Qty', 'UOM', 'Unit Cost', 'Total Cost', 'Currency'];
            $this->writeTableHeader($sheet, $row, $headers, 1);
            $row++;

            foreach (($issue['lines'] ?? []) as $lineIndex => $line) {
                $values = [
                    $lineIndex + 1,
                    $line['product_name'] ?? '-',
                    $line['product_code'] ?? '-',
                    $line['product_sku'] ?? '-',
                    $this->productTypeLabel($line['product_type'] ?? '-'),
                    $this->stockUsageLabel($line['stock_usage_type'] ?? '-'),
                    $this->destinationLabel($line['issue_destination'] ?? ($issue['issue_destination'] ?? '-')),
                    $line['issued_quantity'] ?? 0,
                    $line['uom_code'] ?? '-',
                    $line['unit_cost'] ?? 0,
                    $line['total_cost'] ?? 0,
                    $line['currency_code'] ?? ($issue['currency_code'] ?? 'USD'),
                ];
                $this->writeRowValues($sheet, $row, $values, 1);
                $this->styleDataRow($sheet, $row, 1, count($values), $row % 2 === 0);
                $sheet->getStyle($this->range(8, $row, 11, $row))->getNumberFormat()->setFormatCode('#,##0.0000');
                $row++;
            }

            $this->setExcelCell($sheet, 7, $row, 'DOCUMENT TOTAL');
            $this->setExcelCell($sheet, 8, $row, $issue['total_quantity'] ?? 0);
            $this->setExcelCell($sheet, 11, $row, $issue['total_value'] ?? 0);
            $this->setExcelCell($sheet, 12, $row, $issue['currency_code'] ?? 'USD');
            $sheet->getStyle($this->range(7, $row, 12, $row))->applyFromArray($this->totalStyle());
            $sheet->getStyle($this->range(8, $row, 11, $row))->getNumberFormat()->setFormatCode('#,##0.0000');
            $row += 2;
        }

        $this->applyWorksheetWidths($sheet, [
            'A' => 8, 'B' => 34, 'C' => 16, 'D' => 16, 'E' => 18, 'F' => 16, 'G' => 22, 'H' => 14, 'I' => 12, 'J' => 14, 'K' => 14, 'L' => 12,
        ]);
        $sheet->freezePane('A5');
    }

    private function buildIssueLinesWorksheet($sheet, array $data): void
    {
        $this->prepareWorksheet($sheet);
        $this->writeReportTitle($sheet, 'ENTERPRISE STOCK ISSUE LINE REGISTER', 1, 1, 25);

        $headers = [
            'Issue Name', 'Issue Type', 'Destination', 'Branch', 'Department', 'Cost Center', 'Requested By', 'Purpose', 'Posted At', 'Posted By',
            'Product', 'Code', 'SKU', 'Product Type', 'Usage', 'Expense Account', 'From Area', 'To Area', 'Issued Qty', 'UOM', 'Unit Cost', 'Total Cost', 'Currency', 'Notes', 'Issue ID'
        ];

        $row = 4;
        $this->writeTableHeader($sheet, $row, $headers, 1);
        $row++;

        foreach (($data['issues'] ?? []) as $issue) {
            foreach (($issue['lines'] ?? []) as $line) {
                $values = [
                    $issue['issue_name'] ?? '',
                    $this->issueTypeLabel($issue['issue_type'] ?? ''),
                    $this->destinationLabel($issue['issue_destination'] ?? ''),
                    $issue['branch_name'] ?? '',
                    $issue['department_name'] ?? '',
                    $issue['cost_center'] ?? '',
                    $issue['requested_by'] ?? '',
                    $issue['issue_purpose'] ?? '',
                    $issue['posted_at'] ?? '',
                    $issue['created_by_name'] ?? '',
                    $line['product_name'] ?? '',
                    $line['product_code'] ?? '',
                    $line['product_sku'] ?? '',
                    $this->productTypeLabel($line['product_type'] ?? ''),
                    $this->stockUsageLabel($line['stock_usage_type'] ?? ''),
                    $line['expense_account_code'] ?? '',
                    $line['from_stock_area'] ?? '',
                    $line['to_stock_area'] ?? '',
                    $line['issued_quantity'] ?? 0,
                    $line['uom_code'] ?? '',
                    $line['unit_cost'] ?? 0,
                    $line['total_cost'] ?? 0,
                    $line['currency_code'] ?? '',
                    $issue['notes'] ?? '',
                    $issue['issue_id'] ?? '',
                ];

                $this->writeRowValues($sheet, $row, $values, 1);
                $this->styleDataRow($sheet, $row, 1, count($values), $row % 2 === 0);
                $sheet->getStyle($this->range(19, $row, 22, $row))->getNumberFormat()->setFormatCode('#,##0.0000');
                $row++;
            }
        }

        $lastColumn = count($headers);
        $lastRow = max(4, $row - 1);
        $sheet->setAutoFilter($this->range(1, 4, $lastColumn, $lastRow));
        $sheet->freezePane('A5');
        $this->applyWorksheetWidths($sheet, [
            'A' => 28, 'B' => 24, 'C' => 22, 'D' => 22, 'E' => 18, 'F' => 16, 'G' => 18, 'H' => 26, 'I' => 20, 'J' => 18,
            'K' => 30, 'L' => 16, 'M' => 16, 'N' => 18, 'O' => 16, 'P' => 18, 'Q' => 12, 'R' => 12, 'S' => 14, 'T' => 12,
            'U' => 14, 'V' => 14, 'W' => 12, 'X' => 30, 'Y' => 38,
        ]);
    }

    private function prepareWorksheet($sheet): void
    {
        $sheet->getDefaultRowDimension()->setRowHeight(20);
        $sheet->getDefaultColumnDimension()->setWidth(14);

        // PhpSpreadsheet compatibility:
        // Some installed versions expose gridline control on Worksheet,
        // while others do not expose setShowGridlines on SheetView.
        if (method_exists($sheet, 'setShowGridlines')) {
            $sheet->setShowGridlines(false);
            return;
        }

        if (method_exists($sheet, 'getSheetView')) {
            $view = $sheet->getSheetView();
            if ($view && method_exists($view, 'setShowGridlines')) {
                $view->setShowGridlines(false);
            }
        }
    }

    private function writeReportTitle($sheet, string $title, int $column, int $row, int $span): void
    {
        $sheet->mergeCells($this->range($column, $row, $column + $span - 1, $row));
        $this->setExcelCell($sheet, $column, $row, $title);
        $sheet->getRowDimension($row)->setRowHeight(28);
        $sheet->getStyle($this->range($column, $row, $column + $span - 1, $row))->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '172033']],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
        ]);
    }

    private function writeSummaryCard($sheet, int $column, int $row, string $label, mixed $value): void
    {
        $sheet->mergeCells($this->range($column, $row, $column + 1, $row));
        $sheet->mergeCells($this->range($column, $row + 1, $column + 1, $row + 1));
        $this->setExcelCell($sheet, $column, $row, $label);
        $this->setExcelCell($sheet, $column, $row + 1, $value);
        $sheet->getStyle($this->range($column, $row, $column + 1, $row + 1))->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => 'CBD5E1']]],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'F8FAFC']],
            'alignment' => ['vertical' => 'center'],
        ]);
        $sheet->getStyle($this->range($column, $row, $column + 1, $row))->applyFromArray([
            'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '64748B']],
        ]);
        $sheet->getStyle($this->range($column, $row + 1, $column + 1, $row + 1))->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => '172033']],
        ]);
    }

    private function writeIssueHeaderBlock($sheet, int $row, array $issue, int $documentNumber): void
    {
        $sheet->mergeCells($this->range(1, $row, 12, $row));
        $title = 'DOCUMENT ' . $documentNumber . ': ' . ($issue['issue_name'] ?? 'Stock Issue');
        $this->setExcelCell($sheet, 1, $row, $title);
        $sheet->getStyle($this->range(1, $row, 12, $row))->applyFromArray([
            'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '172033']],
            'alignment' => ['vertical' => 'center'],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(24);

        $fields = [
            ['Issue Type', $this->issueTypeLabel($issue['issue_type'] ?? '-')],
            ['Destination', $this->destinationLabel($issue['issue_destination'] ?? '-')],
            ['Branch', $issue['branch_name'] ?? '-'],
            ['Department', $issue['department_name'] ?? '-'],
            ['Cost Center', $issue['cost_center'] ?? '-'],
            ['Requested By', $issue['requested_by'] ?? '-'],
            ['Purpose', $issue['issue_purpose'] ?? '-'],
            ['Posted At', $issue['posted_at'] ?? '-'],
            ['Posted By', $issue['created_by_name'] ?? '-'],
            ['Lines', $issue['line_count'] ?? 0],
            ['Quantity', $issue['total_quantity'] ?? 0],
            ['Value', ($issue['currency_code'] ?? 'USD') . ' ' . number_format((float) ($issue['total_value'] ?? 0), 2)],
        ];

        $index = 0;
        for ($r = $row + 1; $r <= $row + 3; $r++) {
            for ($c = 1; $c <= 12; $c += 2) {
                if (!isset($fields[$index])) {
                    continue;
                }
                [$label, $value] = $fields[$index];
                $this->setExcelCell($sheet, $c, $r, $label);
                $this->setExcelCell($sheet, $c + 1, $r, $value ?: '-');
                $sheet->getStyle($this->cell($c, $r))->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => '64748B'], 'size' => 9],
                    'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'EEF2F6']],
                ]);
                $sheet->getStyle($this->range($c, $r, $c + 1, $r))->applyFromArray([
                    'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => 'CBD5E1']]],
                    'alignment' => ['vertical' => 'center', 'wrapText' => true],
                ]);
                $index++;
            }
        }

        $sheet->mergeCells($this->range(1, $row + 4, 12, $row + 4));
        $this->setExcelCell($sheet, 1, $row + 4, 'Notes: ' . ($issue['notes'] ?: '-'));
        $sheet->getStyle($this->range(1, $row + 4, 12, $row + 4))->applyFromArray([
            'font' => ['italic' => true, 'color' => ['rgb' => '475467']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'FFFBEB']],
            'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => 'F59E0B']]],
            'alignment' => ['wrapText' => true, 'vertical' => 'center'],
        ]);
    }

    private function writeSectionHeader($sheet, int $row, string $title, int $startColumn, int $endColumn): void
    {
        $sheet->mergeCells($this->range($startColumn, $row, $endColumn, $row));
        $this->setExcelCell($sheet, $startColumn, $row, $title);
        $sheet->getStyle($this->range($startColumn, $row, $endColumn, $row))->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '334155']],
            'alignment' => ['vertical' => 'center'],
        ]);
    }

    private function writeTableHeader($sheet, int $row, array $headers, int $startColumn = 1): void
    {
        foreach ($headers as $index => $header) {
            $this->setExcelCell($sheet, $startColumn + $index, $row, $header);
        }

        $sheet->getRowDimension($row)->setRowHeight(24);
        $sheet->getStyle($this->range($startColumn, $row, $startColumn + count($headers) - 1, $row))->applyFromArray($this->headerStyle());
    }

    private function writeRowValues($sheet, int $row, array $values, int $startColumn = 1): void
    {
        foreach ($values as $index => $value) {
            $this->setExcelCell($sheet, $startColumn + $index, $row, $value);
        }
    }

    private function styleMetaRow($sheet, int $row, int $startColumn, int $endColumn): void
    {
        $sheet->getStyle($this->range($startColumn, $row, $endColumn, $row))->applyFromArray([
            'font' => ['size' => 10, 'color' => ['rgb' => '334155']],
            'alignment' => ['vertical' => 'center'],
        ]);
        for ($col = $startColumn; $col <= $endColumn; $col += 2) {
            $sheet->getStyle($this->cell($col, $row))->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => '64748B']]]);
        }
    }

    private function styleDataRow($sheet, int $row, int $startColumn, int $endColumn, bool $alternate = false): void
    {
        $sheet->getStyle($this->range($startColumn, $row, $endColumn, $row))->applyFromArray([
            'font' => ['size' => 10, 'color' => ['rgb' => '172033']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => $alternate ? 'F8FAFC' : 'FFFFFF']],
            'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => 'E2E8F0']]],
            'alignment' => ['vertical' => 'center', 'wrapText' => true],
        ]);
    }

    private function headerStyle(): array
    {
        return [
            'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '0F766E']],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => 'CBD5E1']]],
        ];
    }

    private function totalStyle(): array
    {
        return [
            'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => '172033']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'DCFCE7']],
            'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => '86EFAC']]],
            'alignment' => ['vertical' => 'center'],
        ];
    }

    private function applyWorksheetWidths($sheet, array $widths): void
    {
        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    private function setExcelCell($sheet, int $column, int $row, mixed $value): void
    {
        $sheet->setCellValue($this->cell($column, $row), $value);
    }

    private function cell(int $column, int $row): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column) . $row;
    }

    private function range(int $startColumn, int $startRow, int $endColumn, int $endRow): string
    {
        return $this->cell($startColumn, $startRow) . ':' . $this->cell($endColumn, $endRow);
    }

    private function issueTypeLabel(?string $value): string
    {
        return match ($this->normalizeDestination($value)) {
            'front' => 'Issue to Front / Selling Stock',
            'department' => 'Issue to Department Use',
            'expense' => 'Issue to Operating Expense',
            'production' => 'Issue to Production Use',
            'wastage' => 'Issue to Wastage / Spoilage',
            default => $value ?: '-',
        };
    }

    private function destinationLabel(?string $value): string
    {
        return match ($this->normalizeDestination($value)) {
            'front' => 'Front / Selling Stock',
            'department' => 'Department Use',
            'expense' => 'Operating Expense',
            'production' => 'Production Use',
            'wastage' => 'Wastage / Spoilage',
            default => $value ?: '-',
        };
    }

    private function stockUsageLabel(?string $value): string
    {
        return $this->normalizeStockUsage($value) === 'non_turnover' ? 'Non-Turnover' : 'Turnover';
    }

    private function productTypeLabel(?string $value): string
    {
        $value = trim((string) $value);
        return $value === '' ? '-' : ucwords(str_replace(['_', '-'], ' ', $value));
    }

    private function pdfHtml(array $data): string
    {
        $e = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        $q = fn ($v) => number_format((float) ($v ?? 0), 4);
        $m = fn ($v, $c) => $e($c ?: 'USD') . ' ' . number_format((float) ($v ?? 0), 2);

        $html = '<!doctype html><html><head><meta charset="utf-8"><style>
            @page{margin:14px} body{font-family:DejaVu Sans,Arial,sans-serif;color:#182235;font-size:9.5px;background:#fff}
            .title{font-size:18px;font-weight:700;margin-bottom:3px}.sub{font-size:9px;color:#64748b;margin-bottom:8px}
            .issue{margin-top:10px;border:1px solid #94a3b8;page-break-inside:avoid}.issuehead{background:#172033;color:white;padding:7px;font-size:11px;font-weight:700}
            .headergrid{display:table;width:100%;border-collapse:collapse}.cell{display:table-cell;border:1px solid #cbd5e1;padding:5px;background:#f8fafc}.label{font-size:7.5px;text-transform:uppercase;color:#64748b}.value{font-weight:700;margin-top:2px}.notes{border:1px solid #f59e0b;background:#fffbeb;padding:5px;color:#475467}
            table{width:100%;border-collapse:collapse} th{background:#e2e8f0;color:#334155;font-size:7.5px;text-transform:uppercase;text-align:left;padding:4px;border:1px solid #cbd5e1}td{padding:4px;border:1px solid #e2e8f0;vertical-align:top}.num{text-align:right}.muted{color:#64748b}
        </style></head><body>';

        $html .= '<div class="title">' . $e($data['title'] ?? 'Enterprise Stock Issue Report') . '</div>';
        $html .= '<div class="sub">Date range: ' . $e($data['date_from'] ?? '') . ' to ' . $e($data['date_to'] ?? '') . ' | Generated: ' . $e($data['generated_at'] ?? '') . '</div>';

        foreach (($data['issues'] ?? []) as $issue) {
            $html .= '<div class="issue"><div class="issuehead">' . $e($issue['issue_number'] ?? $issue['issue_id'] ?? '') . ' | ' . $e($issue['issue_name'] ?? 'Stock Issue') . ' | ' . $e($this->issueTypeLabel($issue['issue_type'] ?? '')) . ' | ' . $m($issue['total_value'] ?? 0, $issue['currency_code'] ?? 'USD') . '</div>';
            $html .= '<div class="headergrid"><div class="cell"><div class="label">Destination</div><div class="value">' . $e($this->destinationLabel($issue['issue_destination'] ?? '')) . '</div></div><div class="cell"><div class="label">Branch</div><div class="value">' . $e($issue['branch_name'] ?? '') . '</div></div><div class="cell"><div class="label">Requested By</div><div class="value">' . $e($issue['requested_by'] ?? '') . '</div></div><div class="cell"><div class="label">Issued At</div><div class="value">' . $e($issue['posted_at'] ?? '') . '</div></div><div class="cell"><div class="label">Department</div><div class="value">' . $e($issue['department_name'] ?? '') . '</div></div><div class="cell"><div class="label">Cost Center</div><div class="value">' . $e($issue['cost_center'] ?? '') . '</div></div><div class="cell"><div class="label">Purpose</div><div class="value">' . $e($issue['issue_purpose'] ?? '') . '</div></div></div>';
            if (trim((string) ($issue['notes'] ?? '')) !== '') {
                $html .= '<div class="notes"><b>Notes:</b> ' . $e($issue['notes']) . '</div>';
            }
            $html .= '<table><thead><tr><th>Product</th><th>Code/SKU</th><th>Type</th><th>Usage</th><th>Destination</th><th class="num">Qty</th><th>UOM</th><th class="num">Unit Cost</th><th class="num">Total Cost</th></tr></thead><tbody>';
            foreach (($issue['lines'] ?? []) as $line) {
                $html .= '<tr><td><b>' . $e($line['product_name'] ?? '') . '</b></td><td>' . $e(($line['product_code'] ?? '-') . ' / ' . ($line['product_sku'] ?? '-')) . '</td><td>' . $e($line['product_type'] ?? '') . '</td><td>' . $e($line['stock_usage_type'] ?? '') . '</td><td>' . $e($line['issue_destination'] ?? '') . '</td><td class="num">' . $q($line['issued_quantity'] ?? 0) . '</td><td>' . $e($line['uom_code'] ?? '') . '</td><td class="num">' . $m($line['unit_cost'] ?? 0, $line['currency_code'] ?? 'USD') . '</td><td class="num"><b>' . $m($line['total_cost'] ?? 0, $line['currency_code'] ?? 'USD') . '</b></td></tr>';
            }
            $html .= '</tbody></table></div>';
        }

        return $html . '</body></html>';
    }

    private function resolveCompany(Request $request): mixed
    {
        if (app()->bound('company')) {
            return app('company');
        }

        return $request->attributes->get('company') ?? $request->company ?? null;
    }

    private function resolveBranchId(Request $request): ?string
    {
        $company = $this->resolveCompany($request);
        if (! $company) {
            return null;
        }

        $requestedBranchIds = $request->filled('branch_id')
            ? [(string) $request->input('branch_id')]
            : [];
        $scope = app(BusinessModeBranchScopeService::class)->resolve(
            $request,
            (string) $company->id,
            $requestedBranchIds
        );

        return $requestedBranchIds[0] ?? $scope['context_branch_id'];
    }
}
