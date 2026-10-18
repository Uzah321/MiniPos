<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class SalesAnalyticsService
{
    private $baseUrl;
    private $apiKey;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.simbisa.base'), '/').'/sales';
        $this->apiKey = trim((string) config('services.simbisa.key'));
    }

    /**
     * Main function to fetch and process sales data with filters
     */
    public function getSalesData(array $filters = [], array $businessFunctions = [])
    {
        try {
            // Build query parameters with smaller limit for better performance
            $queryParams = $this->buildQueryParams($filters);

            // Add smaller limit if not specified
            if (!isset($queryParams['limit'])) {
                $queryParams['limit'] = 100; // Reduced from 1000
            }

            // Make API request with better timeout settings
            $response = Http::timeout(60) // Increased to 60 seconds
            ->connectTimeout(10)
                ->retry(3, 100) // Retry 3 times with 100ms delay
                ->get($this->baseUrl, $queryParams);

            if (!$response->successful()) {
                throw new \Exception('API request failed: ' . $response->status());
            }

            $salesData = $response->json();

            // Ensure salesData is an array
            if (!is_array($salesData)) {
                $salesData = [$salesData];
            }

            // Apply business functions
            $processedData = $this->applyBusinessFunctions($salesData, $businessFunctions);

            return $processedData;

        } catch (\Exception $e) {
            \Log::error('Sales API Error: ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get sales data with pagination for large datasets
     */
    public function getSalesDataWithPagination(array $filters = [], array $businessFunctions = [], $pageSize = 50)
    {
        $allSales = [];
        $skip = 0;
        $hasMore = true;
        $maxRequests = 20; // Safety limit to prevent infinite loops

        for ($i = 0; $i < $maxRequests && $hasMore; $i++) {
            try {
                $filters['limit'] = $pageSize;
                $filters['skip'] = $skip;

                $queryParams = $this->buildQueryParams($filters);

                $response = Http::timeout(30)
                    ->get($this->baseUrl, $queryParams);

                if (!$response->successful()) {
                    break;
                }

                $salesData = $response->json();

                if (empty($salesData)) {
                    $hasMore = false;
                    break;
                }

                if (!is_array($salesData)) {
                    $salesData = [$salesData];
                }

                $allSales = array_merge($allSales, $salesData);
                $skip += $pageSize;

                // If we got less than pageSize, we've reached the end
                if (count($salesData) < $pageSize) {
                    $hasMore = false;
                }

                // Small delay to be respectful to the API
                usleep(100000); // 100ms

            } catch (\Exception $e) {
                \Log::warning('Pagination request failed: ' . $e->getMessage());
                break;
            }
        }

        // Apply business functions to all collected data
        $processedData = $this->applyBusinessFunctions($allSales, $businessFunctions);
        $processedData['pagination_info'] = [
            'total_records' => count($allSales),
            'pages_fetched' => $i,
            'page_size' => $pageSize
        ];

        return $processedData;
    }

    /**
     * Get daily summary without fetching all detailed data
     */
    public function getLightweightDailySummary($startDate, $endDate)
    {
        try {
            $filters = [
                'match' => [
                    ['createdDate', 'gte', $startDate],
                    ['createdDate', 'lte', $endDate]
                ],
                'limit' => 500, // Smaller limit for summary
                'sort' => [['closedDate', 'desc']]
            ];

            $queryParams = $this->buildQueryParams($filters);

            $response = Http::timeout(45)
                ->get($this->baseUrl, $queryParams);

            if (!$response->successful()) {
                throw new \Exception('API request failed: ' . $response->status());
            }

            $salesData = $response->json();

            if (!is_array($salesData)) {
                $salesData = [$salesData];
            }

            return $this->calculateDailySales($salesData);

        } catch (\Exception $e) {
            \Log::error('Lightweight summary error: ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get today's sales with optimized approach
     */
    public function getTodaySalesOptimized()
    {
        $today = Carbon::today()->format('Ymd');

        $filters = [
            'match' => [
                ['createdDate', 'gte', $today],
                ['createdDate', 'lte', $today]
            ],
            'limit' => 50, // Further reduced limit
            'sort' => [['closedDate', 'desc']]
        ];

        return $this->getSalesData($filters, ['calculate_daily_sales']);
    }

    /**
     * Build query parameters from filters
     */
    private function buildQueryParams(array $filters): array
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('The Simbisa API integration is not configured.');
        }

        $queryParams = ['apikey' => $this->apiKey];

        // Handle match filters
        if (isset($filters['match']) && is_array($filters['match'])) {
            $matchConditions = [];
            foreach ($filters['match'] as $condition) {
                if (is_array($condition) && count($condition) === 3) {
                    $matchConditions[] = $condition;
                }
            }
            if (!empty($matchConditions)) {
                $queryParams['match'] = json_encode($matchConditions);
            }
        }

        // Handle sort
        if (isset($filters['sort']) && is_array($filters['sort'])) {
            $queryParams['sort'] = json_encode($filters['sort']);
        }

        // Handle pagination
        if (isset($filters['limit'])) {
            $queryParams['limit'] = (int)$filters['limit'];
        }

        if (isset($filters['skip'])) {
            $queryParams['skip'] = (int)$filters['skip'];
        }

        return $queryParams;
    }

    /**
     * Apply business functions to the sales data
     */
    private function applyBusinessFunctions(array $salesData, array $businessFunctions): array
    {
        $processedData = ['raw_sales' => $salesData];

        foreach ($businessFunctions as $function) {
            switch ($function) {
                case 'calculate_daily_sales':
                    $processedData['daily_sales_summary'] = $this->calculateDailySales($salesData);
                    break;
                case 'analyze_product_performance':
                    $processedData['product_performance'] = $this->analyzeProductPerformance($salesData);
                    break;
                case 'employee_sales_analysis':
                    $processedData['employee_performance'] = $this->employeeSalesAnalysis($salesData);
                    break;
                case 'tax_analysis':
                    $processedData['tax_analysis'] = $this->taxAnalysis($salesData);
                    break;
                case 'payment_method_analysis':
                    $processedData['payment_analysis'] = $this->paymentMethodAnalysis($salesData);
                    break;
                case 'customer_behavior_analysis':
                    $processedData['customer_behavior'] = $this->customerBehaviorAnalysis($salesData);
                    break;
                case 'peak_hours_analysis':
                    $processedData['peak_hours_analysis'] = $this->peakHoursAnalysis($salesData);
                    break;
                case 'table_turnover_analysis':
                    $processedData['table_turnover_analysis'] = $this->tableTurnoverAnalysis($salesData);
                    break;
            }
        }

        return $processedData;
    }

    /**
     * BUSINESS FUNCTIONS - All methods should be private
     */

    /**
     * Calculate daily sales summary
     */
    private function calculateDailySales(array $salesData): array
    {
        $dailySales = [];

        foreach ($salesData as $sale) {
            if (!is_array($sale) || !isset($sale['closedDate'])) continue;

            $date = Carbon::parse($sale['closedDate'])->format('Y-m-d');

            if (!isset($dailySales[$date])) {
                $dailySales[$date] = [
                    'date' => $date,
                    'total_sales' => 0,
                    'total_tax' => 0,
                    'total_orders' => 0,
                    'average_order_value' => 0,
                    'items_sold' => 0
                ];
            }

            $dailySales[$date]['total_sales'] += $sale['totals']['turnover'] ?? 0;
            $dailySales[$date]['total_tax'] += $sale['totals']['tax'] ?? 0;
            $dailySales[$date]['total_orders']++;
            $dailySales[$date]['items_sold'] += $this->countItemsSold($sale);
        }

        // Calculate averages
        foreach ($dailySales as &$day) {
            $day['average_order_value'] = $day['total_orders'] > 0
                ? $day['total_sales'] / $day['total_orders']
                : 0;
        }

        return array_values($dailySales);
    }

    /**
     * Analyze product performance
     */
    private function analyzeProductPerformance(array $salesData): array
    {
        $productPerformance = [];

        foreach ($salesData as $sale) {
            if (!is_array($sale)) continue;

            foreach ($sale['items'] ?? [] as $item) {
                $productId = $item['product']['_id'] ?? 'unknown';
                $productName = $item['product']['name'] ?? 'Unknown Product';

                if (!isset($productPerformance[$productId])) {
                    $productPerformance[$productId] = [
                        'product_id' => $productId,
                        'product_name' => $productName,
                        'total_quantity' => 0,
                        'total_revenue' => 0,
                        'total_tax' => 0,
                        'average_price' => 0,
                        'order_count' => 0
                    ];
                }

                $productPerformance[$productId]['total_quantity'] += $item['qty'] ?? 0;
                $productPerformance[$productId]['total_revenue'] += $item['lineTotals']['turnover'] ?? 0;
                $productPerformance[$productId]['total_tax'] += $item['lineTotals']['tax'] ?? 0;
                $productPerformance[$productId]['order_count']++;
            }
        }

        // Calculate averages
        foreach ($productPerformance as &$product) {
            $product['average_price'] = $product['total_quantity'] > 0
                ? $product['total_revenue'] / $product['total_quantity']
                : 0;
        }

        // Sort by revenue
        usort($productPerformance, function($a, $b) {
            return $b['total_revenue'] <=> $a['total_revenue'];
        });

        return $productPerformance;
    }

    /**
     * Employee sales analysis
     */
    private function employeeSalesAnalysis(array $salesData): array
    {
        $employeePerformance = [];

        foreach ($salesData as $sale) {
            if (!is_array($sale)) continue;

            $employeeId = $sale['owner']['id'] ?? 'unknown';
            $employeeName = $sale['owner']['name'] ?? 'Unknown Employee';

            if (!isset($employeePerformance[$employeeId])) {
                $employeePerformance[$employeeId] = [
                    'employee_id' => $employeeId,
                    'employee_name' => $employeeName,
                    'total_sales' => 0,
                    'total_orders' => 0,
                    'average_order_value' => 0,
                    'items_sold' => 0
                ];
            }

            $employeePerformance[$employeeId]['total_sales'] += $sale['totals']['turnover'] ?? 0;
            $employeePerformance[$employeeId]['total_orders']++;
            $employeePerformance[$employeeId]['items_sold'] += $this->countItemsSold($sale);
        }

        // Calculate averages
        foreach ($employeePerformance as &$employee) {
            $employee['average_order_value'] = $employee['total_orders'] > 0
                ? $employee['total_sales'] / $employee['total_orders']
                : 0;
        }

        // Sort by total sales
        usort($employeePerformance, function($a, $b) {
            return $b['total_sales'] <=> $a['total_sales'];
        });

        return array_values($employeePerformance);
    }

    /**
     * Tax analysis
     */
    private function taxAnalysis(array $salesData): array
    {
        $taxSummary = [
            'total_tax_collected' => 0,
            'tax_by_product' => [],
            'tax_rate_analysis' => []
        ];

        foreach ($salesData as $sale) {
            if (!is_array($sale)) continue;

            $taxSummary['total_tax_collected'] += $sale['totals']['tax'] ?? 0;

            // Analyze tax by product
            foreach ($sale['items'] ?? [] as $item) {
                $productId = $item['product']['_id'] ?? 'unknown';
                $taxRate = $item['product']['taxRateAmount'] ?? 1.15; // Default 15%

                if (!isset($taxSummary['tax_by_product'][$productId])) {
                    $taxSummary['tax_by_product'][$productId] = [
                        'product_name' => $item['product']['name'] ?? 'Unknown',
                        'total_tax' => 0,
                        'tax_rate' => $taxRate
                    ];
                }

                $taxSummary['tax_by_product'][$productId]['total_tax'] += $item['lineTotals']['tax'] ?? 0;

                // Tax rate analysis
                $taxRateKey = (string)$taxRate;
                if (!isset($taxSummary['tax_rate_analysis'][$taxRateKey])) {
                    $taxSummary['tax_rate_analysis'][$taxRateKey] = [
                        'tax_rate' => $taxRate,
                        'total_tax' => 0,
                        'number_of_items' => 0
                    ];
                }

                $taxSummary['tax_rate_analysis'][$taxRateKey]['total_tax'] += $item['lineTotals']['tax'] ?? 0;
                $taxSummary['tax_rate_analysis'][$taxRateKey]['number_of_items']++;
            }
        }

        // Convert associative arrays to indexed arrays
        $taxSummary['tax_by_product'] = array_values($taxSummary['tax_by_product']);
        $taxSummary['tax_rate_analysis'] = array_values($taxSummary['tax_rate_analysis']);

        return $taxSummary;
    }

    /**
     * Payment method analysis
     */
    private function paymentMethodAnalysis(array $salesData): array
    {
        $paymentAnalysis = [];

        foreach ($salesData as $sale) {
            if (!is_array($sale)) continue;

            foreach ($sale['payments'] ?? [] as $payment) {
                $methodId = $payment['paymentMethodId'] ?? 'unknown';
                $methodName = $payment['name'] ?? 'Unknown Method';

                if (!isset($paymentAnalysis[$methodId])) {
                    $paymentAnalysis[$methodId] = [
                        'method_id' => $methodId,
                        'method_name' => $methodName,
                        'total_amount' => 0,
                        'transaction_count' => 0,
                        'average_transaction' => 0
                    ];
                }

                $paymentAnalysis[$methodId]['total_amount'] += $payment['amount'] ?? 0;
                $paymentAnalysis[$methodId]['transaction_count']++;
            }
        }

        // Calculate averages
        foreach ($paymentAnalysis as &$method) {
            $method['average_transaction'] = $method['transaction_count'] > 0
                ? $method['total_amount'] / $method['transaction_count']
                : 0;
        }

        return array_values($paymentAnalysis);
    }

    /**
     * Customer behavior analysis
     */
    private function customerBehaviorAnalysis(array $salesData): array
    {
        $customerAnalysis = [
            'average_guest_count' => 0,
            'popular_tables' => [],
            'order_timing' => []
        ];

        $totalGuests = 0;
        $orderCount = 0;
        $tableUsage = [];

        foreach ($salesData as $sale) {
            if (!is_array($sale)) continue;

            // Guest count analysis
            $guestCount = $sale['guestCount'] ?? 1;
            $totalGuests += $guestCount;
            $orderCount++;

            // Table analysis
            $tableNumber = $sale['tableNumber'] ?? null;
            if ($tableNumber) {
                if (!isset($tableUsage[$tableNumber])) {
                    $tableUsage[$tableNumber] = 0;
                }
                $tableUsage[$tableNumber]++;
            }

            // Order timing analysis
            if (isset($sale['closedDate'])) {
                $hour = Carbon::parse($sale['closedDate'])->hour;
                if (!isset($customerAnalysis['order_timing'][$hour])) {
                    $customerAnalysis['order_timing'][$hour] = 0;
                }
                $customerAnalysis['order_timing'][$hour]++;
            }
        }

        $customerAnalysis['average_guest_count'] = $orderCount > 0 ? $totalGuests / $orderCount : 0;

        // Sort tables by usage
        arsort($tableUsage);
        $customerAnalysis['popular_tables'] = $tableUsage;

        return $customerAnalysis;
    }

    /**
     * Peak hours analysis
     */
    private function peakHoursAnalysis(array $salesData): array
    {
        $hourlyAnalysis = [];

        for ($i = 0; $i < 24; $i++) {
            $hourlyAnalysis[$i] = [
                'hour' => $i,
                'orders' => 0,
                'revenue' => 0,
                'average_order_value' => 0
            ];
        }

        foreach ($salesData as $sale) {
            if (!is_array($sale)) continue;

            if (isset($sale['closedDate'])) {
                $hour = Carbon::parse($sale['closedDate'])->hour;
                $hourlyAnalysis[$hour]['orders']++;
                $hourlyAnalysis[$hour]['revenue'] += $sale['totals']['turnover'] ?? 0;
            }
        }

        // Calculate averages
        foreach ($hourlyAnalysis as &$hourData) {
            $hourData['average_order_value'] = $hourData['orders'] > 0
                ? $hourData['revenue'] / $hourData['orders']
                : 0;
        }

        return array_values($hourlyAnalysis);
    }

    /**
     * Table turnover analysis
     */
    private function tableTurnoverAnalysis(array $salesData): array
    {
        $tableAnalysis = [];

        foreach ($salesData as $sale) {
            if (!is_array($sale)) continue;

            $tableNumber = $sale['tableNumber'] ?? null;
            if (!$tableNumber) continue;

            if (!isset($tableAnalysis[$tableNumber])) {
                $tableAnalysis[$tableNumber] = [
                    'table_number' => $tableNumber,
                    'total_orders' => 0,
                    'total_revenue' => 0,
                    'average_guests' => 0,
                    'total_guests' => 0
                ];
            }

            $tableAnalysis[$tableNumber]['total_orders']++;
            $tableAnalysis[$tableNumber]['total_revenue'] += $sale['totals']['turnover'] ?? 0;
            $tableAnalysis[$tableNumber]['total_guests'] += $sale['guestCount'] ?? 1;
        }

        // Calculate averages
        foreach ($tableAnalysis as &$table) {
            $table['average_guests'] = $table['total_orders'] > 0
                ? $table['total_guests'] / $table['total_orders']
                : 0;
            $table['revenue_per_order'] = $table['total_orders'] > 0
                ? $table['total_revenue'] / $table['total_orders']
                : 0;
        }

        return array_values($tableAnalysis);
    }

    /**
     * Helper function to count total items sold in a sale
     */
    private function countItemsSold(array $sale): int
    {
        $count = 0;
        foreach ($sale['items'] ?? [] as $item) {
            $count += $item['qty'] ?? 0;
        }
        return $count;
    }

    /**
     * PRE-BUILT FILTER EXAMPLES - OPTIMIZED
     */

    public function getSalesByDateRangeOptimized($startDate, $endDate)
    {
        // For large date ranges, use pagination
        $daysDiff = Carbon::createFromFormat('Ymd', $startDate)
            ->diffInDays(Carbon::createFromFormat('Ymd', $endDate));

        if ($daysDiff > 7) {
            // For large ranges, use pagination and limit analytics
            return $this->getSalesDataWithPagination(
                [
                    'match' => [
                        ['createdDate', 'gte', $startDate],
                        ['createdDate', 'lte', $endDate]
                    ],
                    'sort' => [['closedDate', 'asc']]
                ],
                ['calculate_daily_sales', 'analyze_product_performance'], // Limited analytics
                100 // Page size
            );
        } else {
            // For small ranges, use normal approach
            return $this->getSalesData(
                [
                    'match' => [
                        ['createdDate', 'gte', $startDate],
                        ['createdDate', 'lte', $endDate]
                    ],
                    'sort' => [['closedDate', 'asc']],
                    'limit' => 500
                ],
                ['calculate_daily_sales', 'analyze_product_performance', 'employee_sales_analysis']
            );
        }
    }

    public function getProductPerformanceWithDebug(array $filters = [])
    {
        $result = $this->getSalesData($filters, ['analyze_product_performance']);

        \Log::info('Product Performance Final Result:', [
            'has_product_performance' => isset($result['product_performance']),
            'product_count' => isset($result['product_performance']) ? count($result['product_performance']) : 0,
            'raw_sales_count' => isset($result['raw_sales']) ? count($result['raw_sales']) : 0,
            'has_error' => isset($result['error'])
        ]);

        return $result;
    }
}
