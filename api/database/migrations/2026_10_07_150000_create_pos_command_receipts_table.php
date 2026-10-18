<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_command_receipts')) {
            return;
        }

        Schema::create('pos_command_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // A mobile installation generates this UUID once and keeps it with
            // the queued command. Global uniqueness also prevents a captured
            // action id from being replayed under another tenant/till scope.
            $table->uuid('client_action_id')->unique();
            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('till_id');
            $table->uuid('user_id');
            $table->string('command_type', 64);
            $table->char('request_hash', 64);
            $table->string('status', 24)->default('processing');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('response_content_type', 128)->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(
                ['company_id', 'branch_id', 'till_id', 'command_type'],
                'pos_command_receipts_scope_index'
            );
            $table->index(['status', 'created_at'], 'pos_command_receipts_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_command_receipts');
    }
};
