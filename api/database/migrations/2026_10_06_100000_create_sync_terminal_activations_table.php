<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sync_terminal_activations')) {
            return;
        }

        Schema::create('sync_terminal_activations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('sync_node_id');
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('till_id');
            // Codes carry 128 bits of randomness. A deterministic digest lets
            // the signed consume endpoint locate and lock exactly one record;
            // the raw terminal activation code is never persisted.
            $table->char('code_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->unique('code_hash', 'sync_term_act_code_unique');
            $table->index(
                ['sync_node_id', 'till_id', 'consumed_at', 'revoked_at'],
                'sync_term_act_pending_index'
            );
            $table->index('expires_at', 'sync_term_act_expires_index');
            $table->foreign('sync_node_id', 'sync_term_act_node_fk')
                ->references('id')->on('sync_nodes')->cascadeOnDelete();
            $table->foreign('company_id', 'sync_term_act_company_fk')
                ->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id', 'sync_term_act_branch_fk')
                ->references('id')->on('company_branches')->cascadeOnDelete();
            $table->foreign('till_id', 'sync_term_act_till_fk')
                ->references('id')->on('pos_tills')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_terminal_activations');
    }
};
