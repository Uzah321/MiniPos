<?php

namespace Tests\Unit;

use App\Services\Sync\SyncConfigurationService;
use App\Services\Sync\SyncPayloadHasher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Company\Models\Country;
use Tests\TestCase;

class CountryConfigurationDirtyFanoutTest extends TestCase
{
    /** @var list<string> */
    private array $tables = ['sync_changes', 'sync_nodes', 'companies', 'countries'];

    protected function setUp(): void
    {
        parent::setUp();
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('PDO SQLite is required.');
        }
        foreach ($this->tables as $table) {
            Schema::dropIfExists($table);
        }
        $this->createSchema();
        config()->set('salexa_sync.deployment_mode', 'cloud');
        config()->set('salexa_sync.local_hub', false);
    }

    protected function tearDown(): void
    {
        foreach ($this->tables as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_country_mutation_marks_every_active_company_branch_in_that_country_dirty(): void
    {
        DB::table('companies')->insert([
            ['id' => 'company-a', 'country_id' => 'country-a'],
            ['id' => 'company-b', 'country_id' => 'country-a'],
            ['id' => 'company-c', 'country_id' => 'country-b'],
        ]);
        DB::table('sync_nodes')->insert([
            $this->node('node-a-edge', 'company-a', 'branch-a', 'edge'),
            $this->node('node-a-mobile', 'company-a', 'branch-a', 'android'),
            $this->node('node-b-one', 'company-b', 'branch-b-one', 'edge'),
            $this->node('node-b-two', 'company-b', 'branch-b-two', 'android'),
            $this->node('node-c', 'company-c', 'branch-c', 'edge'),
            [...$this->node('node-revoked', 'company-b', 'branch-revoked', 'edge'), 'revoked_at' => now()],
        ]);

        $country = new Country;
        $country->forceFill(['id' => 'country-a']);
        $country->exists = true;

        (new SyncConfigurationService(new SyncPayloadHasher))->markModelDirty($country);

        $signals = DB::table('sync_changes')
            ->where('resource_type', 'configuration.dirty')
            ->orderBy('company_id')
            ->orderBy('branch_id')
            ->get(['company_id', 'branch_id', 'payload']);

        $this->assertSame([
            'company-a:branch-a',
            'company-b:branch-b-one',
            'company-b:branch-b-two',
        ], $signals->map(fn (object $row): string => $row->company_id.':'.$row->branch_id)->all());
        $this->assertTrue($signals->every(function (object $row): bool {
            $payload = json_decode((string) $row->payload, true);

            return ($payload['source'] ?? null) === 'countries';
        }));
    }

    private function createSchema(): void
    {
        Schema::create('countries', function (Blueprint $table): void {
            $table->string('id')->primary();
        });
        Schema::create('companies', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('country_id');
        });
        Schema::create('sync_nodes', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_id');
            $table->string('branch_id')->nullable();
            $table->string('deployment_mode');
            $table->string('status');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('sync_changes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('event_id')->unique();
            $table->string('company_id');
            $table->string('branch_id')->nullable();
            $table->string('source_node_id')->nullable();
            $table->string('resource_type');
            $table->string('resource_id');
            $table->string('operation');
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->json('payload');
            $table->string('payload_hash');
            $table->timestamp('occurred_at');
            $table->timestamps();
        });
    }

    /** @return array<string, mixed> */
    private function node(string $id, string $companyId, string $branchId, string $mode): array
    {
        return [
            'id' => $id,
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'deployment_mode' => $mode,
            'status' => 'active',
            'revoked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
