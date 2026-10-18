<?php

namespace Tests\Unit;

use Tests\TestCase;

class ConfigurationDirtySignalContractTest extends TestCase
{
    public function test_configuration_models_emit_bounded_dirty_signals(): void
    {
        $provider = file_get_contents(app_path('Providers/AppServiceProvider.php'));
        $configuration = file_get_contents(app_path('Services/Sync/SyncConfigurationService.php'));

        $this->assertStringContainsString("'eloquent.saved: *'", $provider);
        $this->assertStringContainsString('->markModelDirty($model)', $provider);
        $this->assertStringContainsString('public function markModelDirty(Model $model)', $configuration);
        $this->assertStringContainsString("'configuration.dirty'", $configuration);
        $this->assertStringContainsString('if ($latestDirty > $latestSnapshot)', $configuration);
    }

    public function test_heartbeat_reports_pending_configuration_without_building_a_snapshot(): void
    {
        $protocol = file_get_contents(app_path('Http/Controllers/SyncProtocolController.php'));
        $heartbeat = explode('public function dayEnd(', explode('public function heartbeat(', $protocol, 2)[1], 2)[0];

        $this->assertStringContainsString("'configuration_pending' =>", $heartbeat);
        $this->assertStringContainsString("'latest_configuration_cursor' =>", $heartbeat);
        $this->assertStringContainsString("'configuration.dirty'", $heartbeat);
        $this->assertStringNotContainsString('ensureSnapshotChange(', $heartbeat);
    }

    public function test_dirty_signal_is_not_delivered_as_an_edge_business_event(): void
    {
        $protocol = file_get_contents(app_path('Http/Controllers/SyncProtocolController.php'));
        $pull = explode('public function status(', explode('public function pull(', $protocol, 2)[1], 2)[0];

        $this->assertStringContainsString("->where('resource_type', '!=', 'configuration.dirty')", $pull);
    }
}
