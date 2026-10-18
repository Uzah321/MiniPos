<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use Mockery;
use Modules\Sales\Http\Controllers\SalesReportController;
use ReflectionMethod;
use Tests\TestCase;

class SalesReportExportTest extends TestCase
{
    public function test_shift_sales_export_selects_and_preserves_the_shift_session_identifier(): void
    {
        $sessionQuery = Mockery::mock();
        $sessionQuery->shouldReceive('leftJoin')->twice()->andReturnSelf();
        $sessionQuery->shouldReceive('where')->once()->andReturnSelf();
        $sessionQuery->shouldReceive('whereDate')->twice()->andReturnSelf();
        $sessionQuery->shouldReceive('tap')->once()->andReturnSelf();
        $sessionQuery->shouldReceive('when')->twice()->andReturnSelf();
        $sessionQuery->shouldReceive('selectRaw')
            ->once()
            ->with(Mockery::on(function ($select) {
                $normalized = preg_replace('/\s+/', ' ', strtolower((string) $select));

                return str_contains($normalized, 'pos_user_shift_sessions.id as shift_session_id');
            }))
            ->andReturnSelf();
        $sessionQuery->shouldReceive('orderByDesc')->once()->andReturnSelf();
        $sessionQuery->shouldReceive('limit')->once()->with(100)->andReturnSelf();
        $sessionQuery->shouldReceive('get')->once()->andReturn(collect([
            (object) [
                'shift_session_id' => 'shift-session-1',
                'shift_name' => 'Morning Shift',
                'cashier_name' => 'Test Cashier',
                'opened_at' => '2026-10-01 08:00:00',
                'closed_at' => null,
                'status' => 'open',
                'total_variance' => 0,
            ],
        ]));

        $salesQuery = Mockery::mock();
        $salesQuery->shouldReceive('selectRaw')->once()->andReturnSelf();
        $salesQuery->shouldReceive('where')->twice()->andReturnSelf();
        $salesQuery->shouldReceive('whereIn')
            ->once()
            ->with('shift_session_id', Mockery::on(
                fn ($ids) => $ids->values()->all() === ['shift-session-1']
            ))
            ->andReturnSelf();
        $salesQuery->shouldReceive('when')->once()->andReturnSelf();
        $salesQuery->shouldReceive('groupBy')->once()->andReturnSelf();
        $salesQuery->shouldReceive('get')->once()->andReturn(collect([
            (object) [
                'shift_session_id' => 'shift-session-1',
                'currency_code' => 'USD',
                'transaction_count' => 1,
                'sales_value' => 125.50,
            ],
        ]));

        DB::shouldReceive('table')
            ->once()
            ->with('pos_user_shift_sessions')
            ->andReturn($sessionQuery);
        DB::shouldReceive('table')
            ->once()
            ->with('sales_transactions')
            ->andReturn($salesQuery);

        $method = new ReflectionMethod(SalesReportController::class, 'shiftSalesExportRows');
        $method->setAccessible(true);

        $rows = $method->invoke(
            app(SalesReportController::class),
            'company-1',
            [
                'date_from' => '2026-10-01',
                'date_to' => '2026-10-01',
                'branch_id' => null,
                'branch_ids' => ['branch-1'],
                'cashier_id' => null,
                'shift_session_id' => null,
                'currency_codes' => ['USD'],
            ],
            100
        );

        $this->assertCount(1, $rows);
        $this->assertSame('shift-session-1', $rows->first()->shift_session_id);
        $this->assertSame('Morning Shift', $rows->first()->shift_name);
        $this->assertSame(1, $rows->first()->transaction_count);
        $this->assertSame('125.50', $rows->first()->sales_value);
    }
}
