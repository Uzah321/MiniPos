<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('companies')) {
            if (! Schema::hasColumn('companies', 'company_code')) {
                Schema::table('companies', function (Blueprint $table) {
                    $table->string('company_code', 40)->nullable()->unique('companies_company_code_unique');
                });
            }

            // A domain identifies hosted tenants when one exists, but offline
            // edge companies may not own a DNS name. The existing unique index
            // remains in place and continues to protect every non-null domain.
            if (Schema::hasColumn('companies', 'domain')) {
                Schema::table('companies', function (Blueprint $table) {
                    $table->string('domain')->nullable()->change();
                });
            }

            $this->backfillCompanyCodes();

            // Every tenant now has a stable machine-readable identity. Keep
            // domain optional, but prevent future companies without a code.
            Schema::table('companies', function (Blueprint $table) {
                $table->string('company_code', 40)->nullable(false)->change();
            });
        }

        if (! Schema::hasTable('sync_nodes')) {
            Schema::create('sync_nodes', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('company_id');
                $table->uuid('branch_id')->nullable();
                $table->uuid('till_id')->nullable();
                $table->string('node_code', 64)->unique();
                $table->string('node_name');
                $table->string('deployment_mode', 32)->default('edge');
                $table->string('activation_code_hash')->nullable();
                $table->timestamp('activation_expires_at')->nullable();
                $table->timestamp('activated_at')->nullable();
                // SHA-256 hex fingerprint for credential lookup/audit only.
                // Request HMACs use the separately encrypted credential secret.
                $table->char('credential_hash', 64)->nullable();
                $table->text('credential_secret')->nullable();
                $table->unsignedInteger('credential_version')->default(1);
                $table->string('status', 32)->default('pending');
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamp('last_push_at')->nullable();
                $table->timestamp('last_pull_at')->nullable();
                $table->string('app_version', 64)->nullable();
                $table->json('metadata')->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'status'], 'sync_nodes_company_status_index');
                $table->index(['branch_id', 'status'], 'sync_nodes_branch_status_index');
                $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
                $table->foreign('branch_id')->references('id')->on('company_branches')->nullOnDelete();
                $table->foreign('till_id')->references('id')->on('pos_tills')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('sync_local_states')) {
            Schema::create('sync_local_states', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('node_id')->nullable()->unique();
                $table->uuid('company_id')->nullable();
                $table->uuid('branch_id')->nullable();
                $table->uuid('till_id')->nullable();
                $table->text('cloud_url')->nullable();
                $table->text('credential')->nullable();
                // Local browser/desktop control secret. Only the SHA-256
                // fingerprint is persisted; the raw token is returned once
                // during pairing and never leaves this edge installation.
                $table->char('local_control_token_hash', 64)->nullable();
                $table->unsignedBigInteger('push_cursor')->default(0);
                $table->unsignedBigInteger('pull_cursor')->default(0);
                $table->timestamp('last_sync_at')->nullable();
                $table->timestamp('last_push_at')->nullable();
                $table->timestamp('last_pull_at')->nullable();
                $table->timestamp('last_error_at')->nullable();
                $table->text('last_error')->nullable();
                $table->string('status', 32)->default('unconfigured');
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->foreign('node_id')->references('id')->on('sync_nodes')->nullOnDelete();
                $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
                $table->foreign('branch_id')->references('id')->on('company_branches')->nullOnDelete();
                $table->foreign('till_id')->references('id')->on('pos_tills')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('sync_outbox_events')) {
            Schema::create('sync_outbox_events', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('company_id');
                $table->uuid('branch_id')->nullable();
                $table->uuid('source_node_id')->nullable();
                $table->string('aggregate_type', 96);
                $table->string('aggregate_id', 64);
                $table->string('event_type', 64);
                // Optional producer key for aggregates whose event is
                // discoverable by a recovery scan (for example one completed
                // event per sale). Other aggregate versions may use different
                // keys and are not blocked by the non-unique lookup index.
                $table->string('deduplication_key', 128)->nullable();
                $table->unsignedSmallInteger('schema_version')->default(1);
                $table->json('payload');
                $table->char('payload_hash', 64);
                $table->timestamp('occurred_at');
                $table->timestamp('available_at')->nullable();
                $table->unsignedInteger('attempt_count')->default(0);
                $table->timestamp('last_attempt_at')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamps();

                $table->index(
                    ['source_node_id', 'event_type', 'aggregate_id'],
                    'sync_outbox_source_event_aggregate_index'
                );
                $table->unique(
                    ['source_node_id', 'deduplication_key'],
                    'sync_outbox_node_deduplication_unique'
                );
                $table->index(['company_id', 'delivered_at', 'available_at'], 'sync_outbox_delivery_index');
                $table->index(['branch_id', 'occurred_at'], 'sync_outbox_branch_occurred_index');
                $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
                $table->foreign('branch_id')->references('id')->on('company_branches')->nullOnDelete();
                $table->foreign('source_node_id')->references('id')->on('sync_nodes')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('sync_inbox_receipts')) {
            Schema::create('sync_inbox_receipts', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('event_id');
                $table->uuid('sync_node_id');
                $table->uuid('company_id');
                $table->uuid('branch_id')->nullable();
                $table->char('payload_hash', 64);
                $table->string('status', 32)->default('received');
                $table->timestamp('processed_at')->nullable();
                $table->text('error')->nullable();
                $table->timestamps();

                $table->unique(['sync_node_id', 'event_id'], 'sync_inbox_node_event_unique');
                $table->index(['company_id', 'status'], 'sync_inbox_company_status_index');
                $table->foreign('sync_node_id')->references('id')->on('sync_nodes')->cascadeOnDelete();
                $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
                $table->foreign('branch_id')->references('id')->on('company_branches')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('sync_changes')) {
            Schema::create('sync_changes', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('event_id')->unique();
                $table->uuid('company_id');
                $table->uuid('branch_id')->nullable();
                $table->uuid('source_node_id')->nullable();
                $table->string('resource_type', 96);
                $table->string('resource_id', 64);
                $table->string('operation', 32);
                $table->unsignedSmallInteger('schema_version')->default(1);
                $table->json('payload');
                $table->char('payload_hash', 64);
                $table->timestamp('occurred_at');
                $table->timestamps();

                $table->index(['company_id', 'branch_id', 'id'], 'sync_changes_scope_sequence_index');
                $table->index(['company_id', 'resource_type', 'resource_id'], 'sync_changes_resource_index');
                $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
                $table->foreign('branch_id')->references('id')->on('company_branches')->nullOnDelete();
                $table->foreign('source_node_id')->references('id')->on('sync_nodes')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('sync_node_cursors')) {
            Schema::create('sync_node_cursors', function (Blueprint $table) {
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

                $table->unique(['sync_node_id', 'stream'], 'sync_node_cursors_node_stream_unique');
                $table->index(['company_id', 'branch_id', 'cursor'], 'sync_node_cursors_scope_index');
                $table->foreign('sync_node_id')->references('id')->on('sync_nodes')->cascadeOnDelete();
                $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
                $table->foreign('branch_id')->references('id')->on('company_branches')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('sync_replay_nonces')) {
            Schema::create('sync_replay_nonces', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('sync_node_id');
                $table->string('nonce', 128);
                $table->timestamp('expires_at');
                $table->timestamps();

                $table->unique(['sync_node_id', 'nonce'], 'sync_replay_nonces_node_nonce_unique');
                $table->index('expires_at', 'sync_replay_nonces_expires_index');
                $table->foreign('sync_node_id')->references('id')->on('sync_nodes')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_replay_nonces');
        Schema::dropIfExists('sync_node_cursors');
        Schema::dropIfExists('sync_changes');
        Schema::dropIfExists('sync_inbox_receipts');
        Schema::dropIfExists('sync_outbox_events');
        Schema::dropIfExists('sync_local_states');
        Schema::dropIfExists('sync_nodes');

        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'company_code')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropUnique('companies_company_code_unique');
                $table->dropColumn('company_code');
            });
        }

        // Deliberately do not force companies.domain back to NOT NULL. A node
        // created while this migration was active may legitimately have no
        // domain, and a destructive rollback must not fail or invent one.
    }

    private function backfillCompanyCodes(): void
    {
        DB::table('companies')
            ->whereNull('company_code')
            ->orderBy('id')
            ->get(['id'])
            ->each(function (object $company): void {
                $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $company->id));
                $length = 12;

                do {
                    $candidate = 'CMP-'.substr($normalized, 0, $length);
                    $collision = DB::table('companies')
                        ->where('company_code', $candidate)
                        ->where('id', '!=', $company->id)
                        ->exists();
                    $length += 4;
                } while ($collision && $length <= 32);

                DB::table('companies')->where('id', $company->id)->update([
                    'company_code' => $candidate,
                ]);
            });
    }
};
