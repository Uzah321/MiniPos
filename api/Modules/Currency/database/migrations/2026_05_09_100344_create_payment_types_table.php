<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_types', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('company_id')->nullable();
            $table->uuid('branch_id')->nullable();

            $table->string('name');
            $table->string('money_code');
            $table->string('type')->nullable();

            $table->boolean('supportsMultiCurrency')->default(false);
            $table->boolean('requiresAmountTendered')->default(false);
            $table->string('default_currency_code')->nullable();

            $table->boolean('integration_status')->nullable();
            $table->unsignedBigInteger('integration_number')->nullable();

            $table->boolean('is_active')->default(true);

            $table->softDeletes();
            $table->timestamps();

            $table->index('company_id');
            $table->index('branch_id');
            $table->index('money_code');
            $table->index('type');
            $table->index('is_active');

            $table->unique(
                ['company_id', 'branch_id', 'money_code', 'deleted_at'],
                'payment_types_company_branch_money_code_unique'
            );

            $table->foreign('company_id')
                ->references('id')
                ->on('companies')
                ->nullOnDelete();

            $table->foreign('branch_id')
                ->references('id')
                ->on('company_branches')
                ->nullOnDelete();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_types');
    }
};
