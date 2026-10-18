<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sync_inbox_receipts')
            && ! Schema::hasIndex('sync_inbox_receipts', 'sync_inbox_node_status_event_index')) {
            Schema::table('sync_inbox_receipts', function (Blueprint $table): void {
                $table->index(
                    ['sync_node_id', 'status', 'event_id'],
                    'sync_inbox_node_status_event_index'
                );
            });
        }

        if (Schema::hasTable('sync_outbox_events')
            && ! Schema::hasIndex('sync_outbox_events', 'sync_outbox_node_scope_event_index')) {
            Schema::table('sync_outbox_events', function (Blueprint $table): void {
                $table->index(
                    ['source_node_id', 'company_id', 'branch_id', 'id'],
                    'sync_outbox_node_scope_event_index'
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sync_inbox_receipts')
            && Schema::hasIndex('sync_inbox_receipts', 'sync_inbox_node_status_event_index')) {
            Schema::table('sync_inbox_receipts', function (Blueprint $table): void {
                $table->dropIndex('sync_inbox_node_status_event_index');
            });
        }

        if (Schema::hasTable('sync_outbox_events')
            && Schema::hasIndex('sync_outbox_events', 'sync_outbox_node_scope_event_index')) {
            Schema::table('sync_outbox_events', function (Blueprint $table): void {
                $table->dropIndex('sync_outbox_node_scope_event_index');
            });
        }
    }
};
