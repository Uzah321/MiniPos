<?php

namespace Tests\Feature;

use App\Models\SyncNode;
use App\Services\Sync\SyncSaleImportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class SyncSaleMultiTillBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('pos_tills', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
        });
        Schema::create('sales_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('till_id')->nullable();
            $table->string('status');
            $table->string('client_sale_id')->nullable();
            $table->string('invoice_number')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        foreach (['receipt_lines', 'sales_transaction_payments'] as $table) {
            Schema::create($table, function (Blueprint $definition): void {
                $definition->uuid('id')->primary();
                $definition->uuid('company_id');
                $definition->uuid('branch_id');
                $definition->uuid('sales_transaction_id')->nullable();
            });
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('sales_transaction_payments');
        Schema::dropIfExists('receipt_lines');
        Schema::dropIfExists('sales_transactions');
        Schema::dropIfExists('pos_tills');

        parent::tearDown();
    }

    public function test_branch_node_preserves_each_same_branch_till_and_rejects_cross_branch_till(): void
    {
        $companyId = '00000000-0000-0000-0000-000000000101';
        $branchId = '00000000-0000-0000-0000-000000000102';
        $defaultTillId = '00000000-0000-0000-0000-000000000103';
        $secondTillId = '00000000-0000-0000-0000-000000000104';
        $otherBranchTillId = '00000000-0000-0000-0000-000000000105';
        DB::table('pos_tills')->insert([
            ['id' => $defaultTillId, 'company_id' => $companyId, 'branch_id' => $branchId],
            ['id' => $secondTillId, 'company_id' => $companyId, 'branch_id' => $branchId],
            [
                'id' => $otherBranchTillId,
                'company_id' => $companyId,
                'branch_id' => '00000000-0000-0000-0000-000000000106',
            ],
        ]);
        $node = new SyncNode([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'till_id' => $defaultTillId,
            'node_code' => 'BRANCH-SERVER',
        ]);
        $node->setAttribute('id', '00000000-0000-0000-0000-000000000107');
        $service = new SyncSaleImportService;

        $firstSaleId = '00000000-0000-0000-0000-000000000108';
        $secondSaleId = '00000000-0000-0000-0000-000000000109';
        foreach ([
            [$firstSaleId, $defaultTillId, 'INV-1'],
            [$secondSaleId, $secondTillId, 'INV-2'],
        ] as [$saleId, $tillId, $invoice]) {
            $result = $service->import($node, [
                'sale' => [
                    'id' => $saleId,
                    'status' => 'completed',
                    'till_id' => $tillId,
                    'invoice_number' => $invoice,
                ],
                'receipt_lines' => [],
                'payments' => [],
            ]);
            $this->assertFalse($result['duplicate']);
            $this->assertDatabaseHas('sales_transactions', [
                'id' => $saleId,
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'till_id' => $tillId,
            ]);
        }

        $this->expectException(InvalidArgumentException::class);
        $service->import($node, [
            'sale' => [
                'id' => '00000000-0000-0000-0000-000000000110',
                'status' => 'completed',
                'till_id' => $otherBranchTillId,
                'invoice_number' => 'INV-3',
            ],
            'receipt_lines' => [],
            'payments' => [],
        ]);
    }
}
