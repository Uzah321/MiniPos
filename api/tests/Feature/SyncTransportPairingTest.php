<?php

namespace Tests\Feature;

use App\Models\SyncLocalState;
use App\Models\SyncNode;
use App\Services\Sync\SyncConfigurationService;
use App\Services\Sync\SyncPayloadHasher;
use App\Services\Sync\SyncTransportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class SyncTransportPairingTest extends TestCase
{
    private const CLOUD_URL = 'https://cloud.example.test';

    private const COMPANY_ID = '019f93b4-b704-7204-875f-153630e72667';

    private const BRANCH_ID = '019f93b4-b729-70af-892b-2b6fbe2eb1a6';

    private const TILL_ID = '019f9474-907d-7173-88c8-bcd4ca5e4d04';

    private const OLD_NODE_ID = '01a11054-8093-7122-bfb9-e350a931b56f';

    private const NEW_NODE_ID = '01a11054-8093-7122-bfb9-e350a931b570';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('s', 32)));
        config()->set('salexa_sync.deployment_mode', 'edge');
        config()->set('salexa_sync.cloud_url', self::CLOUD_URL);
        config()->set('salexa_sync.allow_unpinned_cloud_url', false);

        $this->createPairingTables();
    }

    protected function tearDown(): void
    {
        foreach ([
            'sync_local_states',
            'sync_outbox_events',
            'sync_nodes',
            'pos_tills',
            'company_branches',
            'companies',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_pair_preserves_the_cloud_node_uuid_locally_and_in_signed_requests(): void
    {
        $remoteNodeId = '01a11054-8093-7122-bfb9-e350a931b56f';
        $companyId = '019f93b4-b704-7204-875f-153630e72667';
        $branchId = '019f93b4-b729-70af-892b-2b6fbe2eb1a6';
        $nodeCode = 'CMP-TEST-NODE-001';

        Http::preventStrayRequests();
        Http::fake([
            self::CLOUD_URL.'/api/pos/sync/pair' => Http::response([
                'data' => [
                    'node' => [
                        'id' => $remoteNodeId,
                        'node_code' => $nodeCode,
                        'node_name' => 'Test edge',
                        'credential_version' => 1,
                    ],
                    'company' => [
                        'id' => $companyId,
                        'company_code' => 'CMP-TEST',
                        'company_name' => 'Test Company',
                        'status' => 'active',
                    ],
                    'branch' => [
                        'id' => $branchId,
                        'company_id' => $companyId,
                        'branch_name' => 'Main Branch',
                        'branch_code' => 'MAIN',
                        'status' => 'active',
                    ],
                    'till' => null,
                    'credential' => 'test-node-credential',
                ],
            ]),
            self::CLOUD_URL.'/api/pos/sync/pull*' => Http::response([
                'data' => [
                    'changes' => [],
                    'has_more' => false,
                    'head_cursor' => 0,
                    'head_event_id' => null,
                    'head_payload_hash' => null,
                    'head_content_hash' => null,
                    'acknowledged_cursor' => 0,
                    'acknowledgement_confirmed' => true,
                ],
            ]),
        ]);

        $state = app(SyncTransportService::class)->pair(
            self::CLOUD_URL,
            'CMP-TEST',
            $nodeCode.'.one-time-secret',
            'Test edge'
        );

        $this->assertSame($remoteNodeId, (string) $state->node_id);
        $this->assertSame($remoteNodeId, (string) $state->node?->id);
        $this->assertDatabaseCount('sync_nodes', 1);
        $this->assertDatabaseHas('sync_nodes', [
            'id' => $remoteNodeId,
            'node_code' => $nodeCode,
        ]);
        $this->assertDatabaseCount('sync_local_states', 1);
        $this->assertDatabaseHas('sync_local_states', [
            'node_id' => $remoteNodeId,
            'company_id' => $companyId,
            'branch_id' => $branchId,
        ]);
        $metadata = $state->fresh()->metadata;
        $this->assertSame(0, data_get($metadata, 'remote_configuration_head.cursor'));
        $this->assertSame(0, data_get($metadata, 'last_applied_change.cursor'));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_starts_with($request->url(), self::CLOUD_URL.'/api/pos/sync/pull?')
            && $request->hasHeader('X-Sync-Node', $remoteNodeId)
        );
        Http::assertSentCount(2);
    }

    public function test_pair_applies_the_cloud_snapshot_stamped_with_the_current_schema_version(): void
    {
        // The cloud stamps edge snapshots with servnest.configuration_schema_version
        // (2). The edge transport used to accept only 1 and rejected every snapshot.
        $schemaVersion = (int) config('servnest.configuration_schema_version');
        $this->assertSame(2, $schemaVersion);
        $applied = [];
        $this->mock(SyncConfigurationService::class, function ($mock) use (&$applied): void {
            $mock->shouldReceive('applySnapshot')->once()->andReturnUsing(
                function (SyncLocalState $state, array $payload) use (&$applied): array {
                    $applied[] = $payload;

                    return [];
                }
            );
        });
        $this->fakeCloudPairingWithSnapshot($schemaVersion, $schemaVersion);

        $state = app(SyncTransportService::class)->pair(
            self::CLOUD_URL,
            'CMP-TEST',
            'CMP-TEST-NODE-002.one-time-secret',
            'Test edge'
        );

        $this->assertCount(1, $applied);
        $this->assertSame($schemaVersion, $applied[0]['schema_version']);
        $state = $state->fresh();
        $this->assertSame('paired', $state->status);
        $this->assertSame(7, (int) $state->pull_cursor);
        $this->assertFalse((bool) data_get($state->metadata, 'configuration_pending'));
    }

    public function test_pair_keeps_configuration_pending_when_snapshot_envelope_and_payload_versions_differ(): void
    {
        $this->mock(SyncConfigurationService::class, function ($mock): void {
            $mock->shouldNotReceive('applySnapshot');
        });
        $this->fakeCloudPairingWithSnapshot(2, 1);

        $state = app(SyncTransportService::class)->pair(
            self::CLOUD_URL,
            'CMP-TEST',
            'CMP-TEST-NODE-002.one-time-secret',
            'Test edge'
        );

        $state = $state->fresh();
        $this->assertSame('error', $state->status);
        $this->assertSame(0, (int) $state->pull_cursor);
        $this->assertTrue((bool) data_get($state->metadata, 'configuration_pending'));
        $this->assertStringContainsString('unsupported configuration change', (string) $state->last_error);
    }

    private function fakeCloudPairingWithSnapshot(int $changeSchemaVersion, int $payloadSchemaVersion): void
    {
        $payload = [
            'schema_version' => $payloadSchemaVersion,
            'company_id' => self::COMPANY_ID,
            'branch_id' => self::BRANCH_ID,
            'content_hash' => str_repeat('a', 64),
            'datasets' => [],
        ];
        $payloadHash = app(SyncPayloadHasher::class)->hash($payload);
        $eventId = '01a13ac9-0000-7000-8000-000000000007';

        Http::preventStrayRequests();
        Http::fake([
            self::CLOUD_URL.'/api/pos/sync/pair' => Http::response([
                'data' => [
                    'node' => [
                        'id' => self::NEW_NODE_ID,
                        'node_code' => 'CMP-TEST-NODE-002',
                        'node_name' => 'Test edge',
                        'credential_version' => 1,
                    ],
                    'company' => [
                        'id' => self::COMPANY_ID,
                        'company_code' => 'CMP-TEST',
                        'company_name' => 'Test Company',
                        'status' => 'active',
                    ],
                    'branch' => [
                        'id' => self::BRANCH_ID,
                        'company_id' => self::COMPANY_ID,
                        'branch_name' => 'Main Branch',
                        'branch_code' => 'MAIN',
                        'status' => 'active',
                    ],
                    'till' => null,
                    'credential' => 'test-node-credential',
                ],
            ]),
            self::CLOUD_URL.'/api/pos/sync/pull*' => Http::response([
                'data' => [
                    'changes' => [[
                        'cursor' => 7,
                        'event_id' => $eventId,
                        'resource_type' => 'configuration.snapshot',
                        'resource_id' => self::BRANCH_ID,
                        'operation' => 'snapshot',
                        'schema_version' => $changeSchemaVersion,
                        'payload' => $payload,
                        'payload_hash' => $payloadHash,
                        'occurred_at' => now()->toIso8601String(),
                    ]],
                    'next_cursor' => 7,
                    'has_more' => false,
                    'head_cursor' => 7,
                    'head_event_id' => $eventId,
                    'head_payload_hash' => $payloadHash,
                    'head_content_hash' => $payload['content_hash'],
                    'acknowledged_cursor' => 0,
                    'acknowledgement_confirmed' => true,
                ],
            ]),
        ]);
    }

    public function test_admin_authorized_same_scope_replacement_preserves_old_audit_rows_and_starts_a_clean_node(): void
    {
        $this->seedExistingPairing();
        DB::table('sync_outbox_events')->insert([
            'id' => '01a11054-8093-7122-bfb9-e350a931b580',
            'source_node_id' => self::OLD_NODE_ID,
            'delivered_at' => now(),
        ]);

        $newNodeCode = 'CMP-TEST-REPLACEMENT';
        Http::preventStrayRequests();
        Http::fake([
            self::CLOUD_URL.'/api/pos/sync/pair' => Http::response([
                'data' => [
                    'node' => [
                        'id' => self::NEW_NODE_ID,
                        'node_code' => $newNodeCode,
                        'node_name' => 'Replacement edge',
                        'credential_version' => 1,
                    ],
                    'replaced_node_id' => self::OLD_NODE_ID,
                    'company' => [
                        'id' => self::COMPANY_ID,
                        'company_code' => 'CMP-TEST',
                        'company_name' => 'Test Company',
                        'status' => 'active',
                    ],
                    'branch' => [
                        'id' => self::BRANCH_ID,
                        'company_id' => self::COMPANY_ID,
                        'branch_name' => 'Main Branch',
                        'branch_code' => 'MAIN',
                        'status' => 'active',
                    ],
                    'till' => [
                        'id' => self::TILL_ID,
                        'company_id' => self::COMPANY_ID,
                        'branch_id' => self::BRANCH_ID,
                        'till_code' => 'TILL-001',
                        'till_name' => 'Server 1',
                        'device_label' => 'Server 1',
                        'is_active' => true,
                    ],
                    'credential' => 'replacement-node-credential',
                ],
            ]),
            self::CLOUD_URL.'/api/pos/sync/pull*' => Http::response([
                'data' => [
                    'changes' => [],
                    'has_more' => false,
                    'head_cursor' => 0,
                    'head_event_id' => null,
                    'head_payload_hash' => null,
                    'head_content_hash' => null,
                    'acknowledged_cursor' => 0,
                    'acknowledgement_confirmed' => true,
                ],
            ]),
        ]);

        $state = app(SyncTransportService::class)->pair(
            self::CLOUD_URL,
            'CMP-TEST',
            $newNodeCode.'.ONE-TIME-SECRET',
            'Replacement edge'
        );

        $this->assertSame(self::NEW_NODE_ID, (string) $state->node_id);
        $this->assertSame(self::OLD_NODE_ID, data_get($state->metadata, 'previous_node_id'));
        $this->assertNull(data_get($state->metadata, 'outbox_event_set_proof'));
        $this->assertDatabaseHas('sync_nodes', [
            'id' => self::OLD_NODE_ID,
            'status' => 'revoked',
            'credential_hash' => null,
        ]);
        $this->assertDatabaseHas('sync_nodes', [
            'id' => self::NEW_NODE_ID,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('sync_outbox_events', [
            'id' => '01a11054-8093-7122-bfb9-e350a931b580',
            'source_node_id' => self::OLD_NODE_ID,
        ]);
        $this->assertDatabaseMissing('sync_outbox_events', [
            'source_node_id' => self::NEW_NODE_ID,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::CLOUD_URL.'/api/pos/sync/pair'
            && $request['replace_node_id'] === self::OLD_NODE_ID
        );
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_starts_with($request->url(), self::CLOUD_URL.'/api/pos/sync/pull?')
            && $request->hasHeader('X-Sync-Node', self::NEW_NODE_ID)
        );
    }

    public function test_cross_node_replacement_never_reassigns_an_undelivered_or_dead_letter_event(): void
    {
        $this->seedExistingPairing();
        DB::table('sync_outbox_events')->insert([
            [
                'id' => '01a11054-8093-7122-bfb9-e350a931b581',
                'source_node_id' => self::OLD_NODE_ID,
                'delivered_at' => null,
            ],
            [
                'id' => '01a11054-8093-7122-bfb9-e350a931b582',
                'source_node_id' => self::OLD_NODE_ID,
                'delivered_at' => null,
            ],
        ]);
        Http::preventStrayRequests();

        try {
            app(SyncTransportService::class)->pair(
                self::CLOUD_URL,
                'CMP-TEST',
                'CMP-TEST-REPLACEMENT.ONE-TIME-SECRET',
                'Replacement edge',
                true
            );
            $this->fail('Expected node replacement to be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('2 outbox events are pending', $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertSame(self::OLD_NODE_ID, (string) SyncLocalState::query()->value('node_id'));
        $this->assertDatabaseMissing('sync_nodes', ['id' => self::NEW_NODE_ID]);
    }

    private function seedExistingPairing(): void
    {
        $timestamp = now();
        DB::table('companies')->insert([
            'id' => self::COMPANY_ID,
            'company_code' => 'CMP-TEST',
            'company_name' => 'Test Company',
            'status' => 'active',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('company_branches')->insert([
            'id' => self::BRANCH_ID,
            'company_id' => self::COMPANY_ID,
            'branch_name' => 'Main Branch',
            'branch_code' => 'MAIN',
            'status' => 'active',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('pos_tills')->insert([
            'id' => self::TILL_ID,
            'company_id' => self::COMPANY_ID,
            'branch_id' => self::BRANCH_ID,
            'till_code' => 'TILL-001',
            'till_name' => 'Server 1',
            'device_label' => 'Server 1',
            'is_active' => true,
            'is_registered' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $node = new SyncNode;
        $node->setAttribute($node->getKeyName(), self::OLD_NODE_ID);
        $node->forceFill([
            'company_id' => self::COMPANY_ID,
            'branch_id' => self::BRANCH_ID,
            'till_id' => self::TILL_ID,
            'node_code' => 'CMP-TEST-OLD',
            'node_name' => 'Old edge',
            'deployment_mode' => 'edge',
            'activated_at' => $timestamp,
            'credential_secret' => 'old-node-credential',
            'credential_hash' => hash('sha256', 'old-node-credential'),
            'credential_version' => 1,
            'status' => 'active',
        ])->save();
        SyncLocalState::query()->create([
            'node_id' => self::OLD_NODE_ID,
            'company_id' => self::COMPANY_ID,
            'branch_id' => self::BRANCH_ID,
            'till_id' => self::TILL_ID,
            'cloud_url' => self::CLOUD_URL,
            'credential' => 'old-node-credential',
            'push_cursor' => 38,
            'pull_cursor' => 0,
            'status' => 'paired',
            'metadata' => [
                'outbox_event_set_proof' => [
                    'algorithm' => 'sha256-xor-v1',
                    'count' => 1,
                    'digest' => str_repeat('a', 64),
                ],
            ],
        ]);
    }

    private function createPairingTables(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('company_code')->nullable();
            $table->string('company_name')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('company_branches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('branch_name')->nullable();
            $table->string('branch_code')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('pos_tills', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->nullable();
            $table->uuid('branch_id')->nullable();
            $table->string('till_code')->nullable();
            $table->string('till_name')->nullable();
            $table->string('device_label')->nullable();
            $table->string('registration_token')->nullable();
            $table->string('activation_code')->nullable();
            $table->boolean('is_registered')->default(false);
            $table->boolean('is_active')->default(true);
            $table->uuid('registered_by')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip_address')->nullable();
            $table->text('last_user_agent')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('sync_nodes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('shop_id')->nullable();
            $table->uuid('till_id')->nullable();
            $table->string('node_code', 64)->unique();
            $table->string('node_name');
            $table->string('deployment_mode', 32);
            $table->timestamp('activated_at')->nullable();
            $table->text('credential_secret')->nullable();
            $table->char('credential_hash', 64)->nullable();
            $table->unsignedInteger('credential_version')->default(1);
            $table->string('status', 32);
            $table->json('metadata')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('sync_local_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('node_id')->nullable()->unique();
            $table->uuid('company_id')->nullable();
            $table->uuid('branch_id')->nullable();
            $table->uuid('shop_id')->nullable();
            $table->uuid('till_id')->nullable();
            $table->text('cloud_url')->nullable();
            $table->text('credential')->nullable();
            $table->char('local_control_token_hash', 64)->nullable();
            $table->unsignedBigInteger('push_cursor')->default(0);
            $table->unsignedBigInteger('pull_cursor')->default(0);
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamp('last_push_at')->nullable();
            $table->timestamp('last_pull_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('status', 32);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('sync_outbox_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('source_node_id')->nullable();
            $table->timestamp('delivered_at')->nullable();
        });
    }
}
