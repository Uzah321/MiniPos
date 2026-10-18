<?php

namespace Tests\Feature;

use App\Http\Controllers\MobileFiscalDeviceController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MobileFiscalEligibleNodesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('company_branches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('branch_name');
            $table->string('branch_code');
            $table->softDeletes();
        });
        Schema::create('pos_tills', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->string('till_name');
            $table->string('till_code');
            $table->softDeletes();
        });
        Schema::create('sync_nodes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('till_id')->nullable();
            $table->string('node_name');
            $table->string('node_code');
            $table->string('deployment_mode');
            $table->string('status');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->text('credential_secret')->nullable();
        });
    }

    public function test_only_active_paired_android_nodes_for_the_selected_company_are_listed(): void
    {
        $companyId = '00000000-0000-4000-8000-000000000001';
        $otherCompanyId = '00000000-0000-4000-8000-000000000002';
        $branchId = '00000000-0000-4000-8000-000000000003';
        $tillId = '00000000-0000-4000-8000-000000000004';
        $otherBranchId = '00000000-0000-4000-8000-000000000005';
        $otherTillId = '00000000-0000-4000-8000-000000000006';
        app()->instance('company', (object) ['id' => $companyId]);

        DB::table('company_branches')->insert([
            'id' => $branchId, 'company_id' => $companyId,
            'branch_name' => 'Test Branch', 'branch_code' => 'T01',
        ]);
        DB::table('company_branches')->insert([
            'id' => $otherBranchId, 'company_id' => $otherCompanyId,
            'branch_name' => 'Private Branch', 'branch_code' => 'PRIVATE',
        ]);
        DB::table('pos_tills')->insert([
            'id' => $tillId, 'company_id' => $companyId, 'branch_id' => $branchId,
            'till_name' => 'Android Till', 'till_code' => 'TILL-1',
        ]);
        DB::table('pos_tills')->insert([
            'id' => $otherTillId, 'company_id' => $otherCompanyId, 'branch_id' => $otherBranchId,
            'till_name' => 'Private Till', 'till_code' => 'PRIVATE',
        ]);

        $this->insertNode('00000000-0000-4000-8000-000000000010', $companyId, $branchId, $tillId, 'android', 'active', true);
        $this->insertNode('00000000-0000-4000-8000-000000000011', $otherCompanyId, $branchId, $tillId, 'android', 'active', true);
        $this->insertNode('00000000-0000-4000-8000-000000000012', $companyId, $branchId, $tillId, 'android', 'suspended', true);
        $this->insertNode('00000000-0000-4000-8000-000000000013', $companyId, $branchId, $tillId, 'edge', 'active', true);
        $this->insertNode('00000000-0000-4000-8000-000000000014', $companyId, $branchId, $tillId, 'android', 'active', false);
        $this->insertNode('00000000-0000-4000-8000-000000000015', $companyId, $otherBranchId, $otherTillId, 'android', 'active', true);
        $this->insertNode('00000000-0000-4000-8000-000000000016', $companyId, $branchId, $otherTillId, 'android', 'active', true);

        $response = (new MobileFiscalDeviceController)->eligibleNodes()->getData(true);

        self::assertCount(1, $response['data']);
        self::assertSame('00000000-0000-4000-8000-000000000010', $response['data'][0]['id']);
        self::assertSame('Test Branch', $response['data'][0]['branch']['branch_name']);
        self::assertSame('Android Till', $response['data'][0]['till']['till_name']);
        self::assertArrayNotHasKey('credential_secret', $response['data'][0]);
    }

    private function insertNode(string $id, string $companyId, string $branchId, string $tillId, string $mode, string $status, bool $activated): void
    {
        DB::table('sync_nodes')->insert([
            'id' => $id,
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'till_id' => $tillId,
            'node_name' => $id,
            'node_code' => $id,
            'deployment_mode' => $mode,
            'status' => $status,
            'activated_at' => $activated ? now() : null,
            'credential_secret' => 'never expose',
        ]);
    }
}
