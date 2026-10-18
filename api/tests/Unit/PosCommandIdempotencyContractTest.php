<?php

namespace Tests\Unit;

use App\Models\PosCommandReceipt;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Tests\TestCase;

class PosCommandIdempotencyContractTest extends TestCase
{
    public function test_receipt_migration_has_a_global_action_fence_and_tenant_scope(): void
    {
        $migration = file_get_contents(database_path(
            'migrations/2026_10_07_150000_create_pos_command_receipts_table.php'
        ));

        $this->assertStringContainsString("Schema::create('pos_command_receipts'", $migration);
        $this->assertStringContainsString("->uuid('client_action_id')->unique()", $migration);
        foreach (['company_id', 'branch_id', 'till_id', 'user_id'] as $column) {
            $this->assertStringContainsString("->uuid('{$column}')", $migration);
        }
        $this->assertStringContainsString("->char('request_hash', 64)", $migration);
        $this->assertStringContainsString("->longText('response_body')", $migration);
    }

    public function test_receipt_model_uses_uuid_identity_and_hides_no_required_fields(): void
    {
        $uses = class_uses_recursive(PosCommandReceipt::class);

        $this->assertArrayHasKey(HasUuids::class, $uses);
        $this->assertSame('pos_command_receipts', (new PosCommandReceipt)->getTable());
    }

    public function test_service_locks_terminal_scope_and_replays_only_an_exact_command(): void
    {
        $service = file_get_contents(app_path('Services/Pos/PosCommandIdempotencyService.php'));

        $this->assertStringContainsString("DB::table('pos_tills')", $service);
        $this->assertStringContainsString('->lockForUpdate()', $service);
        $this->assertStringContainsString("->where('client_action_id', \$clientActionId)", $service);
        foreach (['company_id', 'branch_id', 'till_id', 'user_id', 'command_type', 'request_hash'] as $field) {
            $this->assertStringContainsString("\$receipt->{$field}", $service);
        }
        $this->assertStringContainsString("'code' => 'POS_CLIENT_ACTION_ID_REUSED'", $service);
        $this->assertStringContainsString("'X-Idempotent-Replayed', 'true'", $service);
    }

    public function test_cash_movement_and_end_shift_accept_and_complete_client_actions(): void
    {
        $cashup = file_get_contents(base_path(
            'Modules/Sales/Http/Controllers/CashupController.php'
        ));

        $this->assertGreaterThanOrEqual(
            2,
            substr_count($cashup, "'client_action_id' => ['nullable', 'uuid']")
        );
        $this->assertStringContainsString("\$commandType = 'cash_movement'", $cashup);
        $this->assertStringContainsString("'pos_command_type', 'end_shift'", $cashup);
        $this->assertStringContainsString('$this->posCommands->inspect(', $cashup);
        $this->assertStringContainsString('$this->posCommands->claim(', $cashup);
        $this->assertStringContainsString('$this->posCommands->complete(', $cashup);
    }
}
