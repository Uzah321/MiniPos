<?php

namespace Tests\Feature;

use App\Http\Controllers\SyncNodeManagementController;
use App\Http\Controllers\SyncProtocolController;
use App\Http\Middleware\ResolveCompanyFromDomain;
use App\Http\Middleware\ResolveTillFromToken;
use App\Models\SyncLocalState;
use App\Models\SyncNode;
use App\Models\SyncNodeCursor;
use App\Services\License\LicenseService;
use App\Services\License\LicenseSignatureService;
use App\Services\Sync\SyncConfigurationService;
use App\Services\Sync\SyncTransportService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Company\Models\Company;
use Modules\Company\Models\License;
use Modules\GeneralSettings\Http\Controllers\TillController;
use PDO;
use RuntimeException;
use Tests\TestCase;

class TerminalActivationFlowTest extends TestCase
{
    private bool $databaseAvailable = false;

    private static ?string $privateKey = null;

    private static ?string $publicKey = null;

    private const COMPANY_ID = '019f9a00-0000-7000-8000-000000000001';

    private const BRANCH_ID = '019f9a00-0000-7000-8000-000000000002';

    private const NODE_ID = '019f9a00-0000-7000-8000-000000000003';

    private const OTHER_NODE_ID = '019f9a00-0000-7000-8000-000000000004';

    private const TILL_ID = '019f9a00-0000-7000-8000-000000000005';

    private const SECOND_TILL_ID = '019f9a00-0000-7000-8000-000000000006';

    private const LICENSE_ID = '019f9a00-0000-7000-8000-000000000007';

    private const CLOUD_URL = 'https://cloud.example.test';

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The PDO SQLite driver is required for this feature test.');
        }
        $this->databaseAvailable = true;

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('t', 32)));
        $this->configureIssuer();
        $this->createTables();
        $this->seedIdentity();
    }

    protected function tearDown(): void
    {
        if ($this->databaseAvailable) {
            foreach ([
                'sync_terminal_activations',
                'sync_node_cursors',
                'sync_changes',
                'sync_local_states',
                'sync_nodes',
                'pos_tills',
                'company_branches',
                'licenses',
                'companies',
            ] as $table) {
                Schema::dropIfExists($table);
            }
        }

        parent::tearDown();
    }

    public function test_cloud_issue_is_hashed_short_lived_and_supersedes_an_unused_code(): void
    {
        config()->set('salexa_sync.deployment_mode', 'cloud');
        config()->set('salexa_sync.security.terminal_activation_ttl_minutes', 10);
        app()->instance('company', Company::query()->findOrFail(self::COMPANY_ID));
        $controller = new SyncNodeManagementController;

        $first = $controller->createTerminalActivationCode(
            Request::create('/issue', 'POST', ['till_id' => self::TILL_ID]),
            self::NODE_ID
        );
        $firstData = $first->getData(true)['data'];
        $firstCode = $firstData['terminal_activation_code'];

        $this->assertSame(201, $first->getStatusCode());
        $this->assertStringContainsString('no-store', (string) $first->headers->get('Cache-Control'));
        $this->assertMatchesRegularExpression('/^TERM-(?:[A-F0-9]{8}-){3}[A-F0-9]{8}$/', $firstCode);
        $this->assertDatabaseHas('sync_terminal_activations', [
            'sync_node_id' => self::NODE_ID,
            'till_id' => self::TILL_ID,
            'code_hash' => hash('sha256', $firstCode),
            'consumed_at' => null,
            'revoked_at' => null,
        ]);
        $this->assertDatabaseMissing('sync_terminal_activations', ['code_hash' => $firstCode]);

        $second = $controller->createTerminalActivationCode(
            Request::create('/issue', 'POST', ['till_id' => self::TILL_ID]),
            self::NODE_ID
        );
        $secondCode = $second->getData(true)['data']['terminal_activation_code'];

        $this->assertNotSame($firstCode, $secondCode);
        $this->assertNotNull(DB::table('sync_terminal_activations')
            ->where('code_hash', hash('sha256', $firstCode))->value('revoked_at'));
        $this->assertSame(1, DB::table('sync_terminal_activations')
            ->whereNull('consumed_at')->whereNull('revoked_at')->count());
    }

    public function test_heartbeat_presence_does_not_advance_till_configuration_or_snapshot_head(): void
    {
        config()->set('salexa_sync.deployment_mode', 'cloud');
        $initialUpdatedAt = DB::table('pos_tills')
            ->where('id', self::TILL_ID)
            ->value('updated_at');
        DB::table('pos_tills')->insert([
            'id' => self::SECOND_TILL_ID,
            'company_id' => self::COMPANY_ID,
            'branch_id' => self::BRANCH_ID,
            'till_code' => 'TILL-003',
            'till_name' => 'LAN Terminal 3',
            'device_label' => 'Counter 3',
            'is_registered' => true,
            'is_active' => true,
            'created_at' => $initialUpdatedAt,
            'updated_at' => $initialUpdatedAt,
        ]);
        DB::table('sync_nodes')->where('id', self::NODE_ID)->update([
            'till_id' => self::TILL_ID,
        ]);

        $node = SyncNode::query()->with('till')->findOrFail(self::NODE_ID);
        $configuration = app(SyncConfigurationService::class);
        $head = $configuration->ensureSnapshotChange($node);
        $this->assertNotNull($head);
        $headPayload = $head->payload;

        Carbon::setTestNow(Carbon::parse((string) $initialUpdatedAt)->addMinutes(5));
        try {
            app()->instance('sync.node', $node);
            $heartbeat = app(SyncProtocolController::class)->heartbeat(Request::create(
                '/api/pos/sync/heartbeat',
                'POST',
                [
                    'platform' => 'web',
                    'app_version' => '4.0.0',
                    'device_name' => 'Branch POS server',
                    'pending_actions' => 0,
                    'last_pull_cursor' => (int) $head->id,
                    'last_sync_at' => now()->subMinute()->toIso8601String(),
                    'terminals' => [[
                        'till_id' => self::SECOND_TILL_ID,
                        'last_seen_at' => now()->subSecond()->toIso8601String(),
                        'device_name' => 'Counter 3 browser',
                    ]],
                ],
                [],
                [],
                [
                    'REMOTE_ADDR' => '192.168.1.10',
                    'HTTP_USER_AGENT' => 'Salexa Edge Test',
                ]
            ));

            $this->assertSame(200, $heartbeat->getStatusCode());
            $tills = DB::table('pos_tills')
                ->whereIn('id', [self::TILL_ID, self::SECOND_TILL_ID])
                ->orderBy('id')
                ->get()
                ->keyBy('id');
            $this->assertNotNull($tills[self::TILL_ID]->last_seen_at);
            $this->assertNotNull($tills[self::SECOND_TILL_ID]->last_seen_at);
            $this->assertSame(
                (string) $initialUpdatedAt,
                (string) $tills[self::TILL_ID]->updated_at
            );
            $this->assertSame(
                (string) $initialUpdatedAt,
                (string) $tills[self::SECOND_TILL_ID]->updated_at
            );

            $this->assertNull($configuration->ensureSnapshotChange($node->fresh()));
            $this->assertSame(1, DB::table('sync_changes')->count());
            $unchangedHead = DB::table('sync_changes')->first();
            $this->assertSame((int) $head->id, (int) $unchangedHead->id);
            $this->assertSame(
                data_get($headPayload, 'content_hash'),
                data_get(json_decode($unchangedHead->payload, true), 'content_hash')
            );
            $this->assertSame(
                data_get($headPayload, 'datasets.pos_tills'),
                data_get(json_decode($unchangedHead->payload, true), 'datasets.pos_tills')
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_android_signed_license_refresh_does_not_advance_catalogue_state(): void
    {
        config()->set('salexa_sync.deployment_mode', 'cloud');
        SyncNode::query()->whereKey(self::NODE_ID)->update([
            'deployment_mode' => 'android',
            'till_id' => self::TILL_ID,
        ]);
        $node = SyncNode::query()->findOrFail(self::NODE_ID);
        app()->instance('sync.node', $node);

        SyncNodeCursor::query()->create([
            'sync_node_id' => self::NODE_ID,
            'company_id' => self::COMPANY_ID,
            'branch_id' => self::BRANCH_ID,
            'stream' => 'changes',
            'cursor' => 37,
            'metadata' => [
                'acknowledged_content_hash' => str_repeat('a', 64),
                'acknowledgement_confirmed' => true,
            ],
        ]);
        $before = SyncNodeCursor::query()->where('sync_node_id', self::NODE_ID)->firstOrFail()->toArray();

        $license = License::query()->findOrFail(self::LICENSE_ID);
        $license->forceFill([
            'status' => 'revoked',
            'revoked_at' => now(),
        ])->save();
        app(LicenseService::class)->signEntitlement($license->fresh());

        $response = app(SyncProtocolController::class)->mobileLicense(
            Request::create('/api/pos/sync/license', 'GET')
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $data = $response->getData(true)['data'];
        $this->assertEqualsCanonicalizing(
            ['license_entitlement', 'license', 'server_date'],
            array_keys($data)
        );
        $this->assertSame('LICENSE_REVOKED', $data['license']['code']);
        $this->assertFalse($data['license']['valid']);
        $payload = json_decode(
            (string) base64_decode($data['license_entitlement']['payload'], true),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $this->assertSame('revoked', $payload['authorization_state']);
        $this->assertArrayNotHasKey('license_key', $payload);
        $this->assertStringNotContainsString(
            'ABCD-EFGH-JKMP-QRST',
            json_encode($data, JSON_THROW_ON_ERROR)
        );

        $after = SyncNodeCursor::query()->where('sync_node_id', self::NODE_ID)->firstOrFail()->toArray();
        $this->assertSame($before, $after);
        $this->assertSame(0, DB::table('sync_changes')->count());
    }

    public function test_request_presence_paths_preserve_till_configuration_timestamp_and_snapshot_head(): void
    {
        config()->set('salexa_sync.deployment_mode', 'cloud');
        $rawTillToken = 'request-presence-token';
        DB::table('pos_tills')->where('id', self::TILL_ID)->update([
            'registration_token' => hash('sha256', $rawTillToken),
            'is_registered' => true,
        ]);
        DB::table('sync_nodes')->where('id', self::NODE_ID)->update([
            'till_id' => self::TILL_ID,
        ]);

        $initialUpdatedAt = (string) DB::table('pos_tills')
            ->where('id', self::TILL_ID)
            ->value('updated_at');
        $node = SyncNode::query()->findOrFail(self::NODE_ID);
        $configuration = app(SyncConfigurationService::class);
        $head = $configuration->ensureSnapshotChange($node);
        $this->assertNotNull($head);

        $testNow = Carbon::parse($initialUpdatedAt)->addMinutes(5);
        Carbon::setTestNow($testNow);
        try {
            $companyRequest = Request::create(
                '/api/pos/workspace/bootstrap',
                'GET',
                [],
                [],
                [],
                [
                    'REMOTE_ADDR' => '192.168.1.20',
                    'HTTP_USER_AGENT' => 'Company resolver test',
                ]
            );
            $companyRequest->headers->set('X-Till-Token', $rawTillToken);
            $companyResponse = app(ResolveCompanyFromDomain::class)->handle(
                $companyRequest,
                fn () => response()->json(['resolved' => true])
            );
            $this->assertSame(200, $companyResponse->getStatusCode());
            $this->assertPresenceDidNotAdvanceConfiguration(
                $configuration,
                $node,
                (int) $head->id,
                $initialUpdatedAt,
                $testNow
            );

            $testNow = $testNow->copy()->addMinute();
            Carbon::setTestNow($testNow);
            $tillRequest = Request::create(
                '/api/pos/tills/current/settings',
                'GET',
                [],
                [],
                [],
                [
                    'REMOTE_ADDR' => '192.168.1.21',
                    'HTTP_USER_AGENT' => 'Till resolver test',
                ]
            );
            $tillRequest->headers->set('X-Till-Token', $rawTillToken);
            $tillResponse = (new ResolveTillFromToken)->handle(
                $tillRequest,
                fn () => response()->json(['resolved' => true])
            );
            $this->assertSame(200, $tillResponse->getStatusCode());
            $this->assertPresenceDidNotAdvanceConfiguration(
                $configuration,
                $node,
                (int) $head->id,
                $initialUpdatedAt,
                $testNow
            );

            $testNow = $testNow->copy()->addMinute();
            Carbon::setTestNow($testNow);
            $currentRequest = Request::create(
                '/api/pos/tills/current/resolve',
                'GET',
                [],
                [],
                [],
                [
                    'REMOTE_ADDR' => '192.168.1.22',
                    'HTTP_USER_AGENT' => 'Current till resolver test',
                ]
            );
            $currentRequest->headers->set('X-Till-Token', $rawTillToken);
            $licenseService = \Mockery::mock(LicenseService::class);
            $licenseService->shouldReceive('isValid')->once()->andReturnTrue();
            $currentResponse = (new TillController($licenseService))
                ->resolveCurrentTill($currentRequest);
            $this->assertSame(200, $currentResponse->getStatusCode());
            $this->assertTrue($currentResponse->getData(true)['registered']);
            $this->assertPresenceDidNotAdvanceConfiguration(
                $configuration,
                $node,
                (int) $head->id,
                $initialUpdatedAt,
                $testNow
            );

            $lastWrittenAt = $testNow->copy();
            $testNow = $testNow->copy()->addSeconds(10);
            Carbon::setTestNow($testNow);
            $throttledRequest = Request::create(
                '/api/pos/tills/current/settings',
                'GET',
                [],
                [],
                [],
                [
                    'REMOTE_ADDR' => '192.168.1.99',
                    'HTTP_USER_AGENT' => 'Throttled presence test',
                ]
            );
            $throttledRequest->headers->set('X-Till-Token', $rawTillToken);
            $throttledResponse = (new ResolveTillFromToken)->handle(
                $throttledRequest,
                fn () => response()->json(['resolved' => true])
            );
            $this->assertSame(200, $throttledResponse->getStatusCode());
            $this->assertPresenceDidNotAdvanceConfiguration(
                $configuration,
                $node,
                (int) $head->id,
                $initialUpdatedAt,
                $lastWrittenAt
            );
            $throttledTill = DB::table('pos_tills')->where('id', self::TILL_ID)->first();
            $this->assertSame('192.168.1.22', $throttledTill->last_ip_address);
            $this->assertSame('Current till resolver test', $throttledTill->last_user_agent);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_till_snapshot_ignores_generic_timestamp_but_detects_real_configuration_changes(): void
    {
        config()->set('salexa_sync.deployment_mode', 'cloud');
        $node = SyncNode::query()->findOrFail(self::NODE_ID);
        $configuration = app(SyncConfigurationService::class);
        $head = $configuration->ensureSnapshotChange($node);
        $this->assertNotNull($head);
        $initialHash = (string) data_get($head->payload, 'content_hash');

        $initialTill = collect(data_get($head->payload, 'datasets.pos_tills', []))
            ->firstWhere('id', self::TILL_ID);
        $this->assertIsArray($initialTill);
        $this->assertArrayNotHasKey('updated_at', $initialTill);

        DB::table('pos_tills')->where('id', self::TILL_ID)->update([
            'updated_at' => now()->addHour(),
        ]);

        $this->assertNull($configuration->ensureSnapshotChange($node->fresh()));
        $this->assertSame(1, DB::table('sync_changes')->count());
        $this->assertSame((int) $head->id, (int) DB::table('sync_changes')->max('id'));

        DB::table('pos_tills')->where('id', self::TILL_ID)->update([
            'device_label' => 'Renamed counter',
        ]);
        $changed = $configuration->ensureSnapshotChange($node->fresh());

        $this->assertNotNull($changed);
        $this->assertGreaterThan((int) $head->id, (int) $changed->id);
        $this->assertNotSame($initialHash, (string) data_get($changed->payload, 'content_hash'));
        $this->assertSame(2, DB::table('sync_changes')->count());
        $changedTill = collect(data_get($changed->payload, 'datasets.pos_tills', []))
            ->firstWhere('id', self::TILL_ID);
        $this->assertIsArray($changedTill);
        $this->assertSame('Renamed counter', $changedTill['device_label'] ?? null);
        $this->assertArrayNotHasKey('updated_at', $changedTill);
    }

    public function test_signed_consume_is_node_scoped_and_one_time(): void
    {
        config()->set('salexa_sync.deployment_mode', 'cloud');
        app()->instance('company', Company::query()->findOrFail(self::COMPANY_ID));
        $issued = (new SyncNodeManagementController)->createTerminalActivationCode(
            Request::create('/issue', 'POST', ['till_id' => self::TILL_ID]),
            self::NODE_ID
        );
        $code = $issued->getData(true)['data']['terminal_activation_code'];
        $controller = app(SyncProtocolController::class);

        app()->instance('sync.node', SyncNode::query()->findOrFail(self::OTHER_NODE_ID));
        $wrongNode = $controller->consumeTerminalActivation(Request::create(
            '/consume',
            'POST',
            ['terminal_activation_code' => $code]
        ));
        $this->assertSame(422, $wrongNode->getStatusCode());
        $this->assertNull(DB::table('sync_terminal_activations')
            ->where('code_hash', hash('sha256', $code))->value('consumed_at'));

        app()->instance('sync.node', SyncNode::query()->findOrFail(self::NODE_ID));
        $accepted = $controller->consumeTerminalActivation(Request::create(
            '/consume',
            'POST',
            ['terminal_activation_code' => $code]
        ));
        $replay = $controller->consumeTerminalActivation(Request::create(
            '/consume',
            'POST',
            ['terminal_activation_code' => $code]
        ));

        $this->assertSame(200, $accepted->getStatusCode());
        $this->assertSame(self::TILL_ID, $accepted->getData(true)['data']['terminal']['id']);
        $this->assertSame(422, $replay->getStatusCode());
        $this->assertSame(
            'TERMINAL_ACTIVATION_CODE_INVALID',
            $replay->getData(true)['code']
        );

        $expiredIssue = (new SyncNodeManagementController)->createTerminalActivationCode(
            Request::create('/issue', 'POST', ['till_id' => self::TILL_ID]),
            self::NODE_ID
        );
        $expiredCode = $expiredIssue->getData(true)['data']['terminal_activation_code'];
        DB::table('sync_terminal_activations')
            ->where('code_hash', hash('sha256', $expiredCode))
            ->update(['expires_at' => now()->subMinute()]);
        $expired = $controller->consumeTerminalActivation(Request::create(
            '/consume',
            'POST',
            ['terminal_activation_code' => $expiredCode]
        ));
        $this->assertSame(422, $expired->getStatusCode());
        $this->assertNull(DB::table('sync_terminal_activations')
            ->where('code_hash', hash('sha256', $expiredCode))->value('consumed_at'));
    }

    public function test_edge_redeem_stores_only_a_local_hash_and_returns_the_raw_credential_once(): void
    {
        config()->set('salexa_sync.deployment_mode', 'edge');
        config()->set('salexa_sync.cloud_url', self::CLOUD_URL);
        $nodeCredential = 'node-hmac-credential';
        SyncNode::query()->whereKey(self::NODE_ID)->update([
            'credential_secret' => encrypt($nodeCredential),
            'credential_hash' => hash('sha256', $nodeCredential),
        ]);
        $state = SyncLocalState::query()->create([
            'node_id' => self::NODE_ID,
            'company_id' => self::COMPANY_ID,
            'branch_id' => self::BRANCH_ID,
            'cloud_url' => self::CLOUD_URL,
            'credential' => $nodeCredential,
            'status' => 'paired',
        ]);
        $activationCode = 'TERM-01234567-89ABCDEF-01234567-89ABCDEF';

        Http::preventStrayRequests();
        Http::fake([
            self::CLOUD_URL.'/api/pos/sync/terminal-activations/consume' => Http::response([
                'data' => [
                    'terminal' => [
                        'id' => self::TILL_ID,
                        'company_id' => self::COMPANY_ID,
                        'branch_id' => self::BRANCH_ID,
                        'till_code' => 'TILL-002',
                        'till_name' => 'LAN Terminal 2',
                        'device_label' => 'Counter 2',
                        'is_active' => true,
                    ],
                ],
            ]),
        ]);

        $result = app(SyncTransportService::class)->redeemTerminalActivation(
            $state,
            $activationCode,
            '192.168.1.22',
            'Terminal Browser'
        );
        $rawCredential = $result['terminal_credential'];
        $stored = DB::table('pos_tills')->where('id', self::TILL_ID)->first();

        $this->assertNotSame('', $rawCredential);
        $this->assertSame(hash('sha256', $rawCredential), $stored->registration_token);
        $this->assertNotSame($rawCredential, $stored->registration_token);
        $this->assertTrue((bool) $stored->is_registered);
        $this->assertSame('192.168.1.22', $stored->last_ip_address);
        $this->assertSame('Terminal Browser', $stored->last_user_agent);
        Http::assertSent(function (ClientRequest $request) use ($activationCode): bool {
            return $request->url() === self::CLOUD_URL.'/api/pos/sync/terminal-activations/consume'
                && $request['terminal_activation_code'] === $activationCode
                && ! isset($request['terminal_credential'])
                && $request->hasHeader('X-Sync-Node', self::NODE_ID);
        });
    }

    public function test_additional_terminal_activation_reuses_branch_bootstrap_without_rebinding_the_node(): void
    {
        config()->set('salexa_sync.deployment_mode', 'edge');
        config()->set('salexa_sync.cloud_url', self::CLOUD_URL);
        $nodeCredential = 'node-hmac-credential';
        SyncNode::query()->whereKey(self::NODE_ID)->update([
            'till_id' => self::TILL_ID,
            'credential_secret' => encrypt($nodeCredential),
            'credential_hash' => hash('sha256', $nodeCredential),
        ]);
        $branchBootstrap = [
            'configuration_pending' => false,
            'operational_bootstrap_complete' => true,
            'last_applied_change' => [
                'cursor' => 19,
                'event_id' => '019f9a00-0000-7000-8000-000000000019',
                'payload_hash' => str_repeat('a', 64),
            ],
        ];
        $state = SyncLocalState::query()->create([
            'node_id' => self::NODE_ID,
            'company_id' => self::COMPANY_ID,
            'branch_id' => self::BRANCH_ID,
            'till_id' => self::TILL_ID,
            'cloud_url' => self::CLOUD_URL,
            'credential' => $nodeCredential,
            'pull_cursor' => 19,
            'status' => 'paired',
            'metadata' => $branchBootstrap,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            self::CLOUD_URL.'/api/pos/sync/terminal-activations/consume' => Http::response([
                'data' => [
                    'terminal' => [
                        'id' => self::SECOND_TILL_ID,
                        'company_id' => self::COMPANY_ID,
                        'branch_id' => self::BRANCH_ID,
                        'till_code' => 'TILL-003',
                        'till_name' => 'LAN Terminal 3',
                        'device_label' => 'Counter 3',
                        'is_active' => true,
                    ],
                ],
            ]),
        ]);

        $result = app(SyncTransportService::class)->redeemTerminalActivation(
            $state,
            'TERM-89ABCDEF-01234567-89ABCDEF-01234567',
            '192.168.1.23',
            'Second Terminal Browser'
        );
        $freshState = $state->fresh();
        $firstTill = DB::table('pos_tills')->where('id', self::TILL_ID)->first();
        $secondTill = DB::table('pos_tills')->where('id', self::SECOND_TILL_ID)->first();

        $this->assertSame(self::TILL_ID, (string) $freshState->till_id);
        $this->assertSame(19, (int) $freshState->pull_cursor);
        $this->assertSame($branchBootstrap, $freshState->metadata);
        $this->assertSame(self::SECOND_TILL_ID, (string) $result['terminal']->id);
        $this->assertNull($firstTill->registration_token);
        $this->assertFalse((bool) $firstTill->is_registered);
        $this->assertSame(
            hash('sha256', $result['terminal_credential']),
            $secondTill->registration_token
        );
        $this->assertTrue((bool) $secondTill->is_registered);
        $this->assertSame('192.168.1.23', $secondTill->last_ip_address);
        $this->assertSame('Second Terminal Browser', $secondTill->last_user_agent);
    }

    public function test_admin_issued_replacement_is_same_scope_explicit_and_leaves_revoked_node_intact(): void
    {
        config()->set('salexa_sync.deployment_mode', 'cloud');
        app()->instance('company', Company::query()->findOrFail(self::COMPANY_ID));
        SyncNode::query()->whereKey(self::NODE_ID)->update([
            'till_id' => self::TILL_ID,
            'status' => 'revoked',
            'revoked_at' => now(),
            'credential_hash' => null,
            'credential_secret' => null,
        ]);

        $withoutConfirmation = (new SyncNodeManagementController)->createPairingCode(Request::create(
            '/pairing-codes',
            'POST',
            [
                'branch_id' => self::BRANCH_ID,
                'till_id' => self::TILL_ID,
                'replacement_node_id' => self::NODE_ID,
            ]
        ));
        $this->assertSame(409, $withoutConfirmation->getStatusCode());
        $this->assertSame(
            'SYNC_REPLACEMENT_AUTHORIZATION_REQUIRED',
            $withoutConfirmation->getData(true)['code']
        );

        $issued = (new SyncNodeManagementController)->createPairingCode(Request::create(
            '/pairing-codes',
            'POST',
            [
                'branch_id' => self::BRANCH_ID,
                'till_id' => self::TILL_ID,
                'node_name' => 'Replacement node',
                'replacement_node_id' => self::NODE_ID,
                'replacement_authorized' => true,
            ]
        ));
        $issuedData = $issued->getData(true)['data'];
        $newNodeId = (string) $issuedData['node']['id'];

        $this->assertSame(201, $issued->getStatusCode());
        $this->assertNotSame(self::NODE_ID, $newNodeId);
        $this->assertSame(self::NODE_ID, $issuedData['replacement_node_id']);
        $this->assertDatabaseHas('sync_nodes', [
            'id' => $newNodeId,
            'company_id' => self::COMPANY_ID,
            'branch_id' => self::BRANCH_ID,
            'till_id' => self::TILL_ID,
            'status' => 'pending',
        ]);
        $candidate = SyncNode::query()->findOrFail($newNodeId);
        $this->assertTrue((bool) data_get($candidate->metadata, 'replacement_authorized'));
        $this->assertSame(self::NODE_ID, data_get($candidate->metadata, 'replaces_node_id'));
        $this->assertSame(
            $newNodeId,
            data_get(SyncNode::query()->findOrFail(self::NODE_ID)->metadata, 'replacement_candidate_node_id')
        );

        // A wiped Android installation may use the server-owned grant, but an
        // edge replacement must still prove which local node it is replacing.
        $edgeWithoutReplacementId = app(SyncProtocolController::class)->pair(Request::create(
            '/pair',
            'POST',
            [
                'company_code' => 'CMP-ACTIVATION',
                'pairing_code' => $issuedData['pairing_code'],
                'node_name' => 'Replacement node',
            ]
        ));
        $this->assertSame(401, $edgeWithoutReplacementId->getStatusCode());
        $this->assertNotNull(SyncNode::query()->findOrFail($newNodeId)->activation_code_hash);

        $duplicate = (new SyncNodeManagementController)->createPairingCode(Request::create(
            '/pairing-codes',
            'POST',
            [
                'branch_id' => self::BRANCH_ID,
                'till_id' => self::TILL_ID,
                'replacement_node_id' => self::NODE_ID,
                'replacement_authorized' => true,
            ]
        ));
        $this->assertSame(409, $duplicate->getStatusCode());
        $this->assertSame('SYNC_REPLACEMENT_ALREADY_ISSUED', $duplicate->getData(true)['code']);

        $paired = app(SyncProtocolController::class)->pair(Request::create(
            '/pair',
            'POST',
            [
                'company_code' => 'CMP-ACTIVATION',
                'pairing_code' => $issuedData['pairing_code'],
                'node_name' => 'Replacement node',
                'replace_node_id' => self::NODE_ID,
            ]
        ));

        $this->assertSame(200, $paired->getStatusCode());
        $this->assertSame($newNodeId, $paired->getData(true)['data']['node']['id']);
        $this->assertSame(self::NODE_ID, $paired->getData(true)['data']['replaced_node_id']);
        $this->assertDatabaseHas('sync_nodes', [
            'id' => self::NODE_ID,
            'status' => 'revoked',
        ]);
        $this->assertNotNull(SyncNode::query()->findOrFail(self::NODE_ID)->revoked_at);
        $this->assertDatabaseHas('sync_nodes', [
            'id' => $newNodeId,
            'status' => 'active',
            'activation_code_hash' => null,
        ]);
        $this->assertFalse((bool) data_get(
            SyncNode::query()->findOrFail($newNodeId)->metadata,
            'replacement_authorized'
        ));
    }

    public function test_clean_android_install_can_consume_a_reciprocal_replacement_grant_without_the_old_node_id(): void
    {
        $issuedData = $this->issueAndroidReplacement();
        $newNodeId = (string) $issuedData['node']['id'];
        $deviceFingerprint = 'fresh-android-installation-0001';

        $paired = app(SyncProtocolController::class)->pair(Request::create(
            '/pair',
            'POST',
            [
                'company_code' => 'CMP-ACTIVATION',
                'pairing_code' => $issuedData['pairing_code'],
                'target_platform' => 'android',
                ...$this->androidLicenseCompatibility(),
                'device_fingerprint' => $deviceFingerprint,
            ]
        ));

        $this->assertSame(200, $paired->getStatusCode());
        $data = $paired->getData(true)['data'];
        $this->assertSame($newNodeId, $data['node']['id']);
        $this->assertSame(self::NODE_ID, $data['replaced_node_id']);
        $this->assertNotEmpty($data['credential']);
        $this->assertNotEmpty($data['till_token']);
        $this->assertSame(1, $data['license_entitlement']['revision']);
        $this->assertSame('terminal-flow-primary', $data['license_entitlement']['key_id']);
        $this->assertSame('LICENSE_VALID', $data['license']['code']);
        $payload = json_decode(
            (string) base64_decode($data['license_entitlement']['payload'], true),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $this->assertSame(self::COMPANY_ID, $payload['company_id']);
        $this->assertArrayNotHasKey('license_key', $payload);
        $this->assertStringNotContainsString(
            'ABCD-EFGH-JKMP-QRST',
            json_encode($data['license_entitlement'], JSON_THROW_ON_ERROR)
        );

        $candidate = SyncNode::query()->findOrFail($newNodeId);
        $this->assertSame('active', $candidate->status);
        $this->assertNull($candidate->activation_code_hash);
        $this->assertSame(
            hash('sha256', $deviceFingerprint),
            data_get($candidate->metadata, 'device_fingerprint_hash')
        );
        $this->assertFalse((bool) data_get($candidate->metadata, 'replacement_authorized'));

        $replaced = SyncNode::query()->findOrFail(self::NODE_ID);
        $this->assertSame('revoked', $replaced->status);
        $this->assertSame($newNodeId, data_get($replaced->metadata, 'replacement_candidate_node_id'));
        $this->assertSame($newNodeId, data_get($replaced->metadata, 'replaced_by_node_id'));
        $this->assertNotEmpty(data_get($replaced->metadata, 'replacement_grant_consumed_at'));
    }

    public function test_android_license_preflight_failures_do_not_consume_the_pairing_grant(): void
    {
        $issuedData = $this->issueAndroidReplacement();
        $nodeId = (string) $issuedData['node']['id'];
        $request = [
            'company_code' => 'CMP-ACTIVATION',
            'pairing_code' => $issuedData['pairing_code'],
            'target_platform' => 'android',
            ...$this->androidLicenseCompatibility(),
            'device_fingerprint' => 'license-preflight-installation-0001',
        ];
        $request['supported_license_key_fingerprints']['terminal-flow-primary'] = str_repeat('0', 64);

        $unsupported = app(SyncProtocolController::class)->pair(
            Request::create('/pair', 'POST', $request)
        );
        $this->assertSame(409, $unsupported->getStatusCode());
        $this->assertSame('MOBILE_LICENSE_CONTRACT_UNSUPPORTED', $unsupported->getData(true)['code']);
        $this->assertSame('pending', SyncNode::query()->findOrFail($nodeId)->status);
        $this->assertNotNull(SyncNode::query()->findOrFail($nodeId)->activation_code_hash);

        [$privateKey] = $this->generateOpenSslKeyPair();
        [, $wrongPublicKey] = $this->generateOpenSslKeyPair();
        config()->set('license.signing.active_key_id', 'terminal-flow-rotated');
        config()->set('license.signing.private_key', $privateKey);
        config()->set('license.signing.public_keys', [
            'terminal-flow-primary' => self::$publicKey,
            'terminal-flow-rotated' => $wrongPublicKey,
        ]);
        $unavailableRequest = [
            ...$request,
            ...$this->androidLicenseCompatibility(),
        ];
        $unavailable = app(SyncProtocolController::class)->pair(
            Request::create('/pair', 'POST', $unavailableRequest)
        );

        $this->assertSame(503, $unavailable->getStatusCode());
        $this->assertSame('LICENSE_ENTITLEMENT_UNAVAILABLE', $unavailable->getData(true)['code']);
        $this->assertSame('pending', SyncNode::query()->findOrFail($nodeId)->status);
        $this->assertNotNull(SyncNode::query()->findOrFail($nodeId)->activation_code_hash);
        $license = License::query()->findOrFail(self::LICENSE_ID);
        $this->assertSame(1, (int) $license->entitlement_revision);
        $this->assertSame('terminal-flow-primary', $license->signature_key_id);
    }

    public function test_clean_android_replacement_rejects_unapproved_nonreciprocal_cross_scope_and_wrong_id_grants(): void
    {
        $issuedData = $this->issueAndroidReplacement();
        $newNodeId = (string) $issuedData['node']['id'];
        $pairingCode = (string) $issuedData['pairing_code'];
        $pair = function (?string $replaceNodeId = null) use ($pairingCode): JsonResponse {
            return app(SyncProtocolController::class)->pair(Request::create('/pair', 'POST', [
                'company_code' => 'CMP-ACTIVATION',
                'pairing_code' => $pairingCode,
                'target_platform' => 'android',
                ...$this->androidLicenseCompatibility(),
                'device_fingerprint' => 'fresh-android-installation-0002',
                ...($replaceNodeId ? ['replace_node_id' => $replaceNodeId] : []),
            ]));
        };
        $assertGrantUnconsumed = function () use ($newNodeId): void {
            $candidate = SyncNode::query()->findOrFail($newNodeId);
            $this->assertSame('pending', $candidate->status);
            $this->assertNotNull($candidate->activation_code_hash);
        };

        $candidate = SyncNode::query()->findOrFail($newNodeId);
        $candidateMetadata = $candidate->metadata;
        $candidate->forceFill(['metadata' => [
            ...$candidateMetadata,
            'replacement_authorized' => false,
        ]])->save();
        $this->assertSame(401, $pair()->getStatusCode());
        $assertGrantUnconsumed();

        $candidate->forceFill(['metadata' => $candidateMetadata])->save();
        $replaced = SyncNode::query()->findOrFail(self::NODE_ID);
        $replacementMetadata = $replaced->metadata;
        $nonreciprocalMetadata = $replacementMetadata;
        unset($nonreciprocalMetadata['replacement_candidate_node_id']);
        $replaced->forceFill(['metadata' => $nonreciprocalMetadata])->save();
        $this->assertSame(401, $pair()->getStatusCode());
        $this->assertSame('', (string) data_get(
            SyncNode::query()->findOrFail(self::NODE_ID)->metadata,
            'replacement_candidate_node_id',
            ''
        ));
        $assertGrantUnconsumed();

        $replaced->forceFill(['metadata' => $replacementMetadata])->save();
        DB::table('pos_tills')->insert([
            'id' => self::SECOND_TILL_ID,
            'company_id' => self::COMPANY_ID,
            'branch_id' => self::BRANCH_ID,
            'till_code' => 'TILL-003',
            'till_name' => 'Other Android Till',
            'is_registered' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $candidate->forceFill(['till_id' => self::SECOND_TILL_ID])->save();
        $this->assertSame(401, $pair()->getStatusCode());
        $assertGrantUnconsumed();

        $candidate->forceFill(['till_id' => self::TILL_ID])->save();
        $this->assertSame(401, $pair(self::OTHER_NODE_ID)->getStatusCode());
        $assertGrantUnconsumed();
    }

    public function test_activated_android_successor_can_recover_without_resending_its_historical_predecessor_id(): void
    {
        $issuedData = $this->issueAndroidReplacement();
        $newNodeId = (string) $issuedData['node']['id'];
        $deviceFingerprint = 'fresh-android-installation-0003';
        $firstPair = app(SyncProtocolController::class)->pair(Request::create('/pair', 'POST', [
            'company_code' => 'CMP-ACTIVATION',
            'pairing_code' => $issuedData['pairing_code'],
            'target_platform' => 'android',
            ...$this->androidLicenseCompatibility(),
            'device_fingerprint' => $deviceFingerprint,
        ]));
        $this->assertSame(200, $firstPair->getStatusCode());
        $this->assertSame(
            self::NODE_ID,
            data_get(SyncNode::query()->findOrFail($newNodeId)->metadata, 'replaces_node_id')
        );

        $recovery = (new SyncNodeManagementController)->createPairingCode(Request::create(
            '/pairing-codes',
            'POST',
            [
                'branch_id' => self::BRANCH_ID,
                'till_id' => self::TILL_ID,
                'target_platform' => 'android',
                'recovery_node_id' => $newNodeId,
                'recovery_authorized' => true,
            ]
        ));
        $this->assertSame(200, $recovery->getStatusCode());

        $recovered = app(SyncProtocolController::class)->pair(Request::create('/pair', 'POST', [
            'company_code' => 'CMP-ACTIVATION',
            'pairing_code' => $recovery->getData(true)['data']['pairing_code'],
            'target_platform' => 'android',
            ...$this->androidLicenseCompatibility(),
            'device_fingerprint' => $deviceFingerprint,
        ]));

        $this->assertSame(200, $recovered->getStatusCode());
        $this->assertSame($newNodeId, $recovered->getData(true)['data']['node']['id']);
        $this->assertNull($recovered->getData(true)['data']['replaced_node_id']);
        $this->assertSame(2, $recovered->getData(true)['data']['node']['credential_version']);
    }

    public function test_admin_authorized_android_recovery_rotates_the_same_node_without_losing_its_cursor(): void
    {
        config()->set('salexa_sync.deployment_mode', 'cloud');
        app()->instance('company', Company::query()->findOrFail(self::COMPANY_ID));
        SyncNode::query()->findOrFail(self::NODE_ID)->forceFill([
            'till_id' => self::TILL_ID,
            'node_name' => 'LAN Terminal 2',
            'deployment_mode' => 'android',
            'status' => 'active',
            'credential_version' => 4,
            'credential_hash' => hash('sha256', 'old-credential'),
            'credential_secret' => 'old-credential',
        ])->save();
        DB::table('sync_node_cursors')->insert([
            'id' => (string) Str::uuid(),
            'sync_node_id' => self::NODE_ID,
            'company_id' => self::COMPANY_ID,
            'branch_id' => self::BRANCH_ID,
            'stream' => 'changes',
            'cursor' => 41,
            'metadata' => json_encode([
                'acknowledgement_confirmed' => true,
                'acknowledged_content_hash' => str_repeat('a', 64),
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $issued = (new SyncNodeManagementController)->createPairingCode(Request::create(
            '/pairing-codes',
            'POST',
            [
                'branch_id' => self::BRANCH_ID,
                'till_id' => self::TILL_ID,
                'target_platform' => 'android',
                'recovery_node_id' => self::NODE_ID,
                'recovery_authorized' => true,
            ]
        ));
        $this->assertSame(200, $issued->getStatusCode());
        $pairingCode = (string) $issued->getData(true)['data']['pairing_code'];

        // A same-node recovery is deliberately distinct from successor-node
        // replacement. Supplying replace_node_id must fail without consuming
        // the one-time recovery grant.
        $wrongMode = app(SyncProtocolController::class)->pair(Request::create('/pair', 'POST', [
            'company_code' => 'CMP-ACTIVATION',
            'pairing_code' => $pairingCode,
            'target_platform' => 'android',
            ...$this->androidLicenseCompatibility(),
            'device_fingerprint' => 'android-installation-0001',
            'replace_node_id' => self::NODE_ID,
        ]));
        $this->assertSame(401, $wrongMode->getStatusCode());
        $this->assertNotNull(SyncNode::query()->findOrFail(self::NODE_ID)->activation_code_hash);

        $paired = app(SyncProtocolController::class)->pair(Request::create('/pair', 'POST', [
            'company_code' => 'CMP-ACTIVATION',
            'pairing_code' => $pairingCode,
            'target_platform' => 'android',
            ...$this->androidLicenseCompatibility(),
            'device_fingerprint' => 'android-installation-0001',
        ]));
        $this->assertSame(200, $paired->getStatusCode());
        $data = $paired->getData(true)['data'];
        $this->assertSame(self::NODE_ID, $data['node']['id']);
        $this->assertNull($data['replaced_node_id']);
        $this->assertSame(5, $data['node']['credential_version']);
        $this->assertNotSame('old-credential', $data['credential']);
        $this->assertSame(41, (int) DB::table('sync_node_cursors')
            ->where('sync_node_id', self::NODE_ID)
            ->where('stream', 'changes')
            ->value('cursor'));
        $this->assertFalse((bool) data_get(
            SyncNode::query()->findOrFail(self::NODE_ID)->metadata,
            'recovery_authorized'
        ));
    }

    public function test_android_pairing_checks_and_insert_share_a_transaction_and_one_till_cannot_get_two_nodes(): void
    {
        config()->set('salexa_sync.deployment_mode', 'cloud');
        app()->instance('company', Company::query()->findOrFail(self::COMPANY_ID));

        // An edge node can serve the same till; the Android-only uniqueness
        // rule must not reject the first Android pairing on that account.
        SyncNode::query()->whereKey(self::NODE_ID)->update(['till_id' => self::TILL_ID]);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = [strtolower($query->sql), DB::transactionLevel()];
        });

        $controller = new SyncNodeManagementController;
        $request = fn (string $name): Request => Request::create('/pairing-codes', 'POST', [
            'branch_id' => self::BRANCH_ID,
            'till_id' => self::TILL_ID,
            'target_platform' => 'android',
            'node_name' => $name,
        ]);

        $first = $controller->createPairingCode($request('Android Counter A'));
        $this->assertSame(201, $first->getStatusCode());
        $firstId = $first->getData(true)['data']['node']['id'];
        $firstHash = SyncNode::query()->findOrFail($firstId)->activation_code_hash;

        $second = $controller->createPairingCode($request('Android Counter B'));
        $this->assertSame(409, $second->getStatusCode());
        $this->assertSame('ANDROID_TILL_ALREADY_PAIRED', $second->getData(true)['code']);
        $this->assertSame(1, SyncNode::query()
            ->where('company_id', self::COMPANY_ID)
            ->where('branch_id', self::BRANCH_ID)
            ->where('till_id', self::TILL_ID)
            ->where('deployment_mode', 'android')
            ->where('status', 'pending')
            ->count());
        $this->assertSame($firstHash, SyncNode::query()->findOrFail($firstId)->activation_code_hash);

        foreach (['company_branches', 'pos_tills', 'sync_nodes'] as $table) {
            $this->assertTrue(collect($queries)->contains(
                fn (array $query): bool => str_contains($query[0], '"'.$table.'"')
                    && $query[1] > 0
            ), "Expected {$table} to be read inside the provisioning transaction.");
        }
        $this->assertTrue(collect($queries)->contains(
            fn (array $query): bool => str_contains($query[0], 'insert into "sync_nodes"')
                && $query[1] > 0
        ), 'Expected the node insert inside the provisioning transaction.');
    }

    public function test_replacement_pairing_rejects_scope_changes_and_unapproved_candidates(): void
    {
        config()->set('salexa_sync.deployment_mode', 'cloud');
        SyncNode::query()->whereKey(self::NODE_ID)->update([
            'till_id' => self::TILL_ID,
            'status' => 'revoked',
            'revoked_at' => now(),
        ]);
        $pairingCode = 'NODE-2.ONE-TIME-PAIRING-SECRET';
        SyncNode::query()->whereKey(self::OTHER_NODE_ID)->update([
            'till_id' => null,
            'status' => 'pending',
            'activated_at' => null,
            'activation_code_hash' => Hash::make($pairingCode),
            'activation_expires_at' => now()->addMinutes(30),
            'metadata' => json_encode([
                'replacement_authorized' => true,
                'replaces_node_id' => self::NODE_ID,
            ]),
        ]);
        $controller = app(SyncProtocolController::class);
        $request = fn (): Request => Request::create('/pair', 'POST', [
            'company_code' => 'CMP-ACTIVATION',
            'pairing_code' => $pairingCode,
            'replace_node_id' => self::NODE_ID,
        ]);

        $scopeMismatch = $controller->pair($request());
        $this->assertSame(401, $scopeMismatch->getStatusCode());

        SyncNode::query()->whereKey(self::OTHER_NODE_ID)->update([
            'till_id' => self::TILL_ID,
            'metadata' => json_encode([]),
        ]);
        $notAuthorized = $controller->pair($request());
        $this->assertSame(401, $notAuthorized->getStatusCode());

        SyncNode::query()->whereKey(self::OTHER_NODE_ID)->update([
            'metadata' => json_encode([
                'replacement_authorized' => true,
                'replaces_node_id' => self::NODE_ID,
            ]),
        ]);
        $replacementIdOmitted = $controller->pair(Request::create('/pair', 'POST', [
            'company_code' => 'CMP-ACTIVATION',
            'pairing_code' => $pairingCode,
        ]));
        $this->assertSame(401, $replacementIdOmitted->getStatusCode());
        $this->assertDatabaseHas('sync_nodes', [
            'id' => self::OTHER_NODE_ID,
            'status' => 'pending',
        ]);
        $this->assertNotNull(SyncNode::query()->findOrFail(self::OTHER_NODE_ID)->activation_code_hash);
    }

    public function test_node_pairing_code_gets_a_type_specific_terminal_activation_error(): void
    {
        config()->set('salexa_sync.deployment_mode', 'edge');

        $this->postJson('/api/pos/sync/terminal-activations/redeem', [
            'terminal_activation_code' => 'CMP-ACTIVATION-MAIN-ABC12345.ONE-TIME-NODE-SECRET',
        ])->assertStatus(422)->assertJson([
            'code' => 'NODE_PAIRING_CODE_NOT_TERMINAL_ACTIVATION',
        ]);
    }

    public function test_authoritative_revocation_is_persisted_and_edge_status_requests_replacement(): void
    {
        config()->set('salexa_sync.deployment_mode', 'edge');
        config()->set('salexa_sync.cloud_url', self::CLOUD_URL);
        $credential = 'node-hmac-credential';
        SyncNode::query()->whereKey(self::NODE_ID)->update([
            'till_id' => self::TILL_ID,
            'credential_secret' => encrypt($credential),
            'credential_hash' => hash('sha256', $credential),
        ]);
        $state = SyncLocalState::query()->create([
            'node_id' => self::NODE_ID,
            'company_id' => self::COMPANY_ID,
            'branch_id' => self::BRANCH_ID,
            'till_id' => self::TILL_ID,
            'cloud_url' => self::CLOUD_URL,
            'credential' => $credential,
            'status' => 'paired',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            self::CLOUD_URL.'/api/pos/sync/terminal-activations/consume' => Http::response([
                'message' => 'The sync node has been revoked.',
                'code' => 'SYNC_NODE_REVOKED',
            ], 401),
        ]);

        try {
            app(SyncTransportService::class)->redeemTerminalActivation(
                $state,
                'TERM-01234567-89ABCDEF-01234567-89ABCDEF'
            );
            $this->fail('Expected terminal activation to stop after authoritative revocation.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('could not complete', $exception->getMessage());
        }

        $this->assertSame('revoked', $state->fresh()->status);
        $this->assertNull($state->fresh()->credential);
        $this->assertDatabaseHas('sync_nodes', [
            'id' => self::NODE_ID,
            'status' => 'revoked',
            'credential_hash' => null,
        ]);
        $this->getJson('/api/pos/sync/status')
            ->assertOk()
            ->assertJson([
                'code' => 'SYNC_NODE_REVOKED',
                'data' => [
                    'paired' => false,
                    'pairing_required' => true,
                    'replacement_required' => true,
                    'status' => 'revoked',
                ],
            ]);
    }

    private function issueAndroidReplacement(): array
    {
        config()->set('salexa_sync.deployment_mode', 'cloud');
        app()->instance('company', Company::query()->findOrFail(self::COMPANY_ID));
        SyncNode::query()->findOrFail(self::NODE_ID)->forceFill([
            'till_id' => self::TILL_ID,
            'deployment_mode' => 'android',
            'status' => 'revoked',
            'revoked_at' => now(),
            'credential_hash' => null,
            'credential_secret' => null,
            'metadata' => [],
        ])->save();

        $issued = (new SyncNodeManagementController)->createPairingCode(Request::create(
            '/pairing-codes',
            'POST',
            [
                'branch_id' => self::BRANCH_ID,
                'till_id' => self::TILL_ID,
                'node_name' => 'Fresh Android replacement',
                'target_platform' => 'android',
                'replacement_node_id' => self::NODE_ID,
                'replacement_authorized' => true,
            ]
        ));

        $this->assertSame(201, $issued->getStatusCode());

        return $issued->getData(true)['data'];
    }

    private function createTables(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('company_code');
            $table->string('company_name');
            $table->string('status');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('company_branches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('branch_name');
            $table->string('branch_code');
            $table->string('status');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('licenses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('license_key');
            $table->string('license_type')->default('enterprise');
            $table->unsignedInteger('max_branches')->default(1);
            $table->unsignedInteger('max_users')->default(5);
            $table->string('status');
            $table->unsignedInteger('max_devices')->default(1);
            $table->date('starts_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('renewal_source')->nullable();
            $table->text('license_token')->nullable();
            $table->longText('entitlement_payload')->nullable();
            $table->string('entitlement_signature', 64)->nullable();
            $table->longText('entitlement_payload_v2')->nullable();
            $table->text('entitlement_signature_v2')->nullable();
            $table->string('signature_algorithm', 32)->nullable();
            $table->string('signature_key_id', 64)->nullable();
            $table->unsignedSmallInteger('entitlement_version')->default(1);
            $table->unsignedBigInteger('entitlement_revision')->default(1);
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('pos_tills', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->string('till_code');
            $table->string('till_name');
            $table->string('device_label')->nullable();
            $table->string('registration_token', 128)->nullable()->unique();
            $table->string('activation_code', 30)->nullable();
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
            $table->uuid('branch_id');
            $table->uuid('till_id')->nullable();
            $table->string('node_code')->unique();
            $table->string('node_name');
            $table->string('deployment_mode')->default('edge');
            $table->string('activation_code_hash')->nullable();
            $table->timestamp('activation_expires_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->char('credential_hash', 64)->nullable();
            $table->text('credential_secret')->nullable();
            $table->unsignedInteger('credential_version')->default(1);
            $table->string('status');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_push_at')->nullable();
            $table->timestamp('last_pull_at')->nullable();
            $table->string('app_version')->nullable();
            $table->json('metadata')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('sync_local_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('node_id')->nullable()->unique();
            $table->uuid('company_id')->nullable();
            $table->uuid('branch_id')->nullable();
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
            $table->string('status');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('sync_changes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('source_node_id')->nullable();
            $table->string('resource_type');
            $table->uuid('resource_id')->nullable();
            $table->string('operation');
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->json('payload');
            $table->char('payload_hash', 64);
            $table->timestamp('occurred_at');
            $table->timestamps();
        });
        Schema::create('sync_node_cursors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('sync_node_id');
            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->string('stream', 64)->default('changes');
            $table->unsignedBigInteger('cursor')->default(0);
            $table->uuid('last_event_id')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['sync_node_id', 'stream']);
        });
        Schema::create('sync_terminal_activations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('sync_node_id');
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('till_id');
            $table->char('code_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
        });
    }

    private function seedIdentity(): void
    {
        $timestamp = now();
        DB::table('companies')->insert([
            'id' => self::COMPANY_ID,
            'company_code' => 'CMP-ACTIVATION',
            'company_name' => 'Activation Company',
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
        DB::table('licenses')->insert([
            'id' => self::LICENSE_ID,
            'company_id' => self::COMPANY_ID,
            'license_key' => 'ABCD-EFGH-JKMP-QRST',
            'license_type' => 'enterprise',
            'max_branches' => 3,
            'max_users' => 20,
            'status' => 'active',
            'max_devices' => 10,
            'starts_at' => now()->subDay()->toDateString(),
            'expires_at' => now()->addYear()->toDateString(),
            'activated_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        app(LicenseService::class)->signEntitlement(License::query()->findOrFail(self::LICENSE_ID));
        DB::table('pos_tills')->insert([
            'id' => self::TILL_ID,
            'company_id' => self::COMPANY_ID,
            'branch_id' => self::BRANCH_ID,
            'till_code' => 'TILL-002',
            'till_name' => 'LAN Terminal 2',
            'device_label' => 'Counter 2',
            'is_registered' => false,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        foreach ([self::NODE_ID => 'NODE-1', self::OTHER_NODE_ID => 'NODE-2'] as $id => $code) {
            DB::table('sync_nodes')->insert([
                'id' => $id,
                'company_id' => self::COMPANY_ID,
                'branch_id' => self::BRANCH_ID,
                'node_code' => $code,
                'node_name' => $code,
                'deployment_mode' => 'edge',
                'activated_at' => $timestamp,
                'credential_version' => 1,
                'status' => 'active',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }
    }

    private function assertPresenceDidNotAdvanceConfiguration(
        SyncConfigurationService $configuration,
        SyncNode $node,
        int $headId,
        string $initialUpdatedAt,
        Carbon $expectedSeenAt
    ): void {
        $till = DB::table('pos_tills')->where('id', self::TILL_ID)->first();
        $this->assertNotNull($till);
        $this->assertSame($initialUpdatedAt, (string) $till->updated_at);
        $this->assertSame(
            $expectedSeenAt->format('Y-m-d H:i:s'),
            (string) $till->last_seen_at
        );
        $this->assertNull($configuration->ensureSnapshotChange($node->fresh()));
        $this->assertSame(1, DB::table('sync_changes')->count());
        $this->assertSame($headId, (int) DB::table('sync_changes')->max('id'));
    }

    /** @return array<string, mixed> */
    private function androidLicenseCompatibility(): array
    {
        $keyId = (string) config('license.signing.active_key_id');
        $algorithm = (string) config('license.signing.algorithm');

        return [
            'license_contract_version' => 1,
            'supported_license_key_ids' => [$keyId],
            'supported_license_algorithms' => [$algorithm],
            'supported_license_key_fingerprints' => [
                $keyId => app(LicenseSignatureService::class)->publicKeyFingerprint($keyId, $algorithm),
            ],
        ];
    }

    private function configureIssuer(): void
    {
        if (self::$privateKey === null || self::$publicKey === null) {
            [self::$privateKey, self::$publicKey] = $this->generateOpenSslKeyPair();
        }

        config()->set('license.signing', [
            'mode' => 'issuer',
            'algorithm' => LicenseSignatureService::ALGORITHM_OPENSSL_SHA256,
            'active_key_id' => 'terminal-flow-primary',
            'private_key' => self::$privateKey,
            'private_key_passphrase' => null,
            'public_keys' => ['terminal-flow-primary' => self::$publicKey],
            'legacy_hmac_verify' => false,
            'legacy_hmac_sign' => false,
            'legacy_hmac_key' => null,
            'legacy_app_key_fallback' => false,
            'allow_unsigned_legacy' => false,
            'auto_upgrade_unsigned' => false,
            'legacy_offline_token_verify' => false,
        ]);
    }

    /** @return array{0: string, 1: string} */
    private function generateOpenSslKeyPair(): array
    {
        $key = openssl_pkey_new($this->openSslOptions([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]));
        $this->assertNotFalse($key);
        $privateKey = '';
        $this->assertTrue(openssl_pkey_export($key, $privateKey, null, $this->openSslOptions()));
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);

        return [$privateKey, $details['key']];
    }
}
