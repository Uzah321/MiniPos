<?php

namespace Tests\Unit;

use App\Models\SyncChange;
use App\Models\SyncInboxReceipt;
use App\Models\SyncLocalState;
use App\Models\SyncNode;
use App\Models\SyncNodeCursor;
use App\Models\SyncOutboxEvent;
use App\Models\SyncReplayNonce;
use Tests\TestCase;

class SyncDataLayerContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('s', 32)));
    }

    public function test_sync_is_disabled_by_default_with_bounded_operational_defaults(): void
    {
        $configSource = file_get_contents(config_path('salexa_sync.php'));
        $this->assertStringContainsString(
            "env('SALEXA_SYNC_DEPLOYMENT_MODE', 'standalone')",
            $configSource
        );
        $this->assertSame(200, config('salexa_sync.batch.push_size'));
        $this->assertSame(500, config('salexa_sync.batch.pull_size'));
        $this->assertSame(900, config('salexa_sync.security.replay_nonce_ttl_seconds'));
        $this->assertSame([5, 15, 30, 60, 300], config('salexa_sync.retry.backoff_seconds'));
    }

    public function test_local_credentials_are_encrypted_and_hidden(): void
    {
        $state = new SyncLocalState;
        $state->credential = 'edge-node-secret';
        $state->local_control_token_hash = str_repeat('b', 64);

        $this->assertNotSame('edge-node-secret', $state->getAttributes()['credential']);
        $this->assertSame('edge-node-secret', $state->credential);
        $this->assertArrayNotHasKey('credential', $state->toArray());
        $this->assertArrayNotHasKey('local_control_token_hash', $state->toArray());

        $node = new SyncNode([
            'activation_code_hash' => 'activation-hash',
            'credential_hash' => str_repeat('a', 64),
            'credential_secret' => 'server-side-hmac-secret',
        ]);

        $this->assertNotSame('server-side-hmac-secret', $node->getAttributes()['credential_secret']);
        $this->assertSame('server-side-hmac-secret', $node->credential_secret);
        $this->assertArrayNotHasKey('activation_code_hash', $node->toArray());
        $this->assertArrayNotHasKey('credential_hash', $node->toArray());
        $this->assertArrayNotHasKey('credential_secret', $node->toArray());
    }

    public function test_sync_models_expose_expected_tables_and_casts(): void
    {
        $this->assertSame('sync_nodes', (new SyncNode)->getTable());
        $this->assertSame('sync_local_states', (new SyncLocalState)->getTable());
        $this->assertSame('sync_outbox_events', (new SyncOutboxEvent)->getTable());
        $this->assertSame('sync_inbox_receipts', (new SyncInboxReceipt)->getTable());
        $this->assertSame('sync_changes', (new SyncChange)->getTable());
        $this->assertSame('sync_node_cursors', (new SyncNodeCursor)->getTable());
        $this->assertSame('sync_replay_nonces', (new SyncReplayNonce)->getTable());

        $this->assertTrue((new SyncChange)->getIncrementing());
        $this->assertSame('array', (new SyncOutboxEvent)->getCasts()['payload']);
        $this->assertSame('array', (new SyncNode)->getCasts()['metadata']);
        $this->assertSame('datetime', (new SyncReplayNonce)->getCasts()['expires_at']);
    }

    public function test_migration_declares_idempotency_and_cursor_constraints(): void
    {
        $migration = file_get_contents(base_path('database/migrations/2026_10_05_130000_create_salexa_sync_foundation.php'));

        $this->assertStringContainsString("'sync_outbox_source_event_aggregate_index'", $migration);
        $this->assertStringNotContainsString("'sync_outbox_source_event_aggregate_unique'", $migration);
        $this->assertStringContainsString("['sync_node_id', 'event_id']", $migration);
        $this->assertStringContainsString("['sync_node_id', 'stream']", $migration);
        $this->assertStringContainsString("['sync_node_id', 'nonce']", $migration);
        $this->assertStringContainsString("['company_id', 'branch_id', 'id']", $migration);
        $this->assertStringContainsString("->uuid('event_id')->unique()", $migration);
        $this->assertStringContainsString("->char('credential_hash', 64)", $migration);
        $this->assertStringContainsString("->text('credential_secret')", $migration);
        $this->assertStringContainsString("->char('local_control_token_hash', 64)", $migration);
        $this->assertStringContainsString("'sync_outbox_node_deduplication_unique'", $migration);
    }
}
