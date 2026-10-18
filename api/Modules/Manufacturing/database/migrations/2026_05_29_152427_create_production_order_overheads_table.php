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
        Schema::create('production_order_overheads', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('company_id');
            $table->uuid('branch_id');
            $table->uuid('production_order_id');
            $table->enum('cost_type', [
                'labour',
                'salary',
                'electricity',
                'fuel',
                'water',
                'rent',
                'machine_maintenance',
                'depreciation',
                'other'
            ]);

            $table->string('description')->nullable();

            $table->decimal('amount', 18, 4);
            $table->string('currency', 10)->default('USD');

            $table->uuid('expense_id')->nullable();
            $table->uuid('payroll_id')->nullable();

            $table->json('metadata')->nullable();

            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'branch_id']);
            $table->index('production_order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_order_overheads');
    }
};
