<?php

namespace Tests\Unit;

use App\Models\SyncNode;
use App\Services\Sync\SyncConfigurationService;
use App\Services\Sync\SyncPayloadHasher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Product\Http\Controllers\ProductController;
use Modules\Product\Models\Uom;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class GlobalUomAvailabilityTest extends TestCase
{
    private bool $uomSchemaPrepared = false;

    private const COMPANY_ID = '10000000-0000-7000-8000-000000000001';

    private const OTHER_COMPANY_ID = '20000000-0000-7000-8000-000000000001';

    private const BRANCH_ID = '10000000-0000-7000-8000-000000000002';

    private const GLOBAL_UOM_ID = '30000000-0000-7000-8000-000000000001';

    private const COMPANY_UOM_ID = '30000000-0000-7000-8000-000000000002';

    private const FOREIGN_UOM_ID = '30000000-0000-7000-8000-000000000003';

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('company');
        if ($this->uomSchemaPrepared) {
            Schema::dropIfExists('uoms');
        }

        parent::tearDown();
    }

    public function test_available_scope_combines_global_and_current_company_without_leaking_foreign_custom_uoms(): void
    {
        $this->prepareUomSchema();

        $ids = Uom::query()
            ->availableTo(self::COMPANY_ID)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame([
            self::GLOBAL_UOM_ID,
            self::COMPANY_UOM_ID,
        ], $ids);
        $this->assertNotContains(self::FOREIGN_UOM_ID, $ids);
    }

    public function test_configuration_snapshot_contains_global_and_current_company_uoms_only(): void
    {
        $this->prepareUomSchema();

        $datasets = (new SyncConfigurationService(new SyncPayloadHasher))->datasets(new SyncNode([
            'company_id' => self::COMPANY_ID,
            'branch_id' => self::BRANCH_ID,
        ]));

        $uoms = collect($datasets['uoms'])->keyBy('id');

        $this->assertSame([
            self::GLOBAL_UOM_ID,
            self::COMPANY_UOM_ID,
        ], $uoms->keys()->all());
        $this->assertNull($uoms[self::GLOBAL_UOM_ID]['company_id']);
        $this->assertSame(self::COMPANY_ID, $uoms[self::COMPANY_UOM_ID]['company_id']);
        $this->assertFalse($uoms->has(self::FOREIGN_UOM_ID));
    }

    public function test_uom_endpoint_returns_active_global_and_current_company_rows_only(): void
    {
        $this->prepareUomSchema();

        $inactiveId = '30000000-0000-7000-8000-000000000004';
        $deletedId = '30000000-0000-7000-8000-000000000005';
        $this->insertUom($inactiveId, null, 'Inactive Global', 'INA', false);
        $this->insertUom($deletedId, self::COMPANY_ID, 'Deleted Tenant Unit', 'DEL', true, now());
        app()->instance('company', (object) ['id' => self::COMPANY_ID]);

        $response = (new ProductController)->getUoms(Request::create('/api/pos/uoms', 'GET'));
        $payload = $response->getData(true);
        $ids = collect($payload['data'])->pluck('id')->all();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertEqualsCanonicalizing([
            self::GLOBAL_UOM_ID,
            self::COMPANY_UOM_ID,
        ], $ids);
        $this->assertNotContains(self::FOREIGN_UOM_ID, $ids);
        $this->assertNotContains($inactiveId, $ids);
        $this->assertNotContains($deletedId, $ids);
    }

    public function test_sync_contract_keeps_globals_and_only_referenced_company_units(): void
    {
        $definitions = (new ReflectionClass(SyncConfigurationService::class))->getConstant('DATASETS');
        $datasetsSource = $this->methodSource(SyncConfigurationService::class, 'buildDatasets');
        $uomScopeSource = $this->methodSource(SyncConfigurationService::class, 'scopeBranchProductUoms');
        $applySource = $this->methodSource(SyncConfigurationService::class, 'upsertSnapshotRow');

        $this->assertSame('branch-product-uoms', $definitions['uoms']['scope']);
        $this->assertStringContainsString("'branch-product-uoms' =>", $datasetsSource);
        $this->assertStringContainsString('scopeBranchProductUoms', $datasetsSource);
        $this->assertStringContainsString("->whereNull('uoms.company_id')", $uomScopeSource);
        $this->assertStringContainsString('scopeBranchProducts', $uomScopeSource);
        $this->assertStringContainsString("\$table === 'uoms'", $applySource);
        $this->assertStringContainsString("array_key_exists('company_id', \$row)", $applySource);
        $this->assertStringContainsString("\$row['company_id'] === null", $applySource);
    }

    public function test_global_uom_changes_fan_out_dirty_signals_to_all_paired_tenants(): void
    {
        $source = $this->methodSource(SyncConfigurationService::class, 'markModelDirty');

        $this->assertStringContainsString("\$table === 'uoms' && \$companyId === ''", $source);
        $this->assertStringContainsString("->whereIn('deployment_mode', ['edge', 'android'])", $source);
        $this->assertStringContainsString("->where('status', 'active')", $source);
        $this->assertStringContainsString("->whereNull('revoked_at')", $source);
        $this->assertStringContainsString('->distinct()', $source);
        $this->assertStringContainsString('$this->markBranchDirty(', $source);
    }

    private function prepareUomSchema(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('PDO driver sqlite is not installed.');
        }

        Schema::dropIfExists('uoms');
        Schema::create('uoms', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->nullable();
            $table->string('name');
            $table->string('code');
            $table->string('quantity_type')->nullable();
            $table->unsignedTinyInteger('decimal_places')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        $this->uomSchemaPrepared = true;

        $this->insertUom(self::GLOBAL_UOM_ID, null, 'Each', 'EA');
        $this->insertUom(self::COMPANY_UOM_ID, self::COMPANY_ID, 'Tenant Case', 'CASE');
        $this->insertUom(self::FOREIGN_UOM_ID, self::OTHER_COMPANY_ID, 'Foreign Pallet', 'PAL');
    }

    private function insertUom(
        string $id,
        ?string $companyId,
        string $name,
        string $code,
        bool $isActive = true,
        mixed $deletedAt = null
    ): void {
        DB::table('uoms')->insert([
            'id' => $id,
            'company_id' => $companyId,
            'name' => $name,
            'code' => $code,
            'quantity_type' => 'count',
            'decimal_places' => 0,
            'is_active' => $isActive,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => $deletedAt,
        ]);
    }

    private function methodSource(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);
        $lines = file($reflection->getFileName());

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
    }
}
