<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mobile_sale_reconciliations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('branch_id')->index();
            $table->uuid('till_id')->index();
            $table->uuid('sync_node_id')->index();
            $table->uuid('cashier_id')->index();
            $table->string('client_sale_id', 100);
            $table->date('business_date');
            $table->timestamp('occurred_at');
            $table->string('configuration_content_hash', 64);
            $table->string('command_sha256', 64);
            $table->string('reason_code', 80);
            $table->string('status', 32)->default('pending');
            $table->json('command_payload');
            $table->json('context')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'client_sale_id'], 'mobile_sale_reconcile_company_client_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_sale_reconciliations');
    }
};
