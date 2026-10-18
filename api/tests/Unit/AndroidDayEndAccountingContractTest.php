<?php

namespace Tests\Unit;

use App\Models\SyncNode;
use App\Services\Sync\AndroidDayEndReadiness;
use Carbon\CarbonImmutable;
use Modules\Product\Http\Controllers\TradingDayEndController;
use Modules\Sales\Http\Controllers\SalesController;
use ReflectionMethod;
use Tests\TestCase;

class AndroidDayEndAccountingContractTest extends TestCase
{
    public function test_android_device_must_be_online_synced_and_empty_before_day_end(): void
    {
        $now = CarbonImmutable::now();
        $cutoff = $now->subSeconds(180);
        $node = new SyncNode([
            'id' => '00000000-0000-4000-8000-000000000001',
            'company_id' => '00000000-0000-4000-8000-000000000002',
            'branch_id' => '00000000-0000-4000-8000-000000000003',
            'node_code' => 'ANDROID-1',
            'node_name' => 'Counter tablet',
            'deployment_mode' => 'android',
            'status' => 'active',
            'activated_at' => $now,
            'last_seen_at' => $now,
            'metadata' => ['presence' => ['device_name' => 'Counter tablet', 'pending_actions' => 2, 'last_sync_at' => $now->toIso8601String(), 'reported_at' => $now->toIso8601String()]],
        ]);

        $readiness = app(AndroidDayEndReadiness::class);
        $blocking = fn () => $readiness->blockingDevice($node, $cutoff, $now);
        $this->assertSame(2, $blocking()['pending_actions']);

        $node->metadata = ['presence' => ['pending_actions' => 0, 'last_sync_at' => $now->toIso8601String(), 'reported_at' => $now->subMinutes(10)->toIso8601String()]];
        $this->assertNotNull($blocking());

        $node->metadata = ['presence' => ['pending_actions' => 0, 'last_sync_at' => $now->toIso8601String(), 'reported_at' => $now->toIso8601String()]];
        $this->assertNull($blocking());

        $node->metadata = ['presence' => ['pending_actions' => 0, 'last_sync_at' => null, 'reported_at' => $now->toIso8601String()]];
        $this->assertNotNull($blocking());
    }

    public function test_sale_and_day_end_gate_the_accounting_boundary(): void
    {
        $sale = $this->methodSource(SalesController::class, 'store');
        $dayEnd = $this->methodSource(TradingDayEndController::class, 'runDayEnd');
        $force = $this->methodSource(TradingDayEndController::class, 'forceMissingFrontStockClosings');
        $price = $this->methodSource(SalesController::class, 'resolveProductPrice');

        $this->assertStringContainsString("'code' => 'BUSINESS_DATE_MISMATCH'", $sale);
        $this->assertStringContainsString("'business_date' => [\$isAndroidTill ? 'required' : 'nullable'", $sale);
        $this->assertStringContainsString("'code' => 'ANDROID_NODE_NOT_ACTIVE'", $sale);
        $this->assertLessThan(strpos($sale, 'generateReceiptNumber('), strpos($sale, "'code' => 'BUSINESS_DATE_MISMATCH'"));
        $this->assertStringContainsString('AndroidDayEndReadiness::class', $dayEnd);
        $this->assertStringContainsString('->assess((string) $company->id, $branchId, true)', $dayEnd);
        $this->assertStringContainsString('AndroidDayEndReadiness::class', $force);
        $this->assertStringContainsString("'code' => 'DAY_END_ANDROID_SYNC_REQUIRED'", $dayEnd);
        $this->assertStringContainsString("->orWhere('branch_id', \$branchId)", $price);
        $this->assertStringContainsString("->orWhere('ends_at', '>=', now())", $price);
        $this->assertStringContainsString("throw new \\InvalidArgumentException('Selected price is not valid for this product and branch.')", $price);
    }

    private function methodSource(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);
        $lines = file($reflection->getFileName());

        return implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
    }
}
