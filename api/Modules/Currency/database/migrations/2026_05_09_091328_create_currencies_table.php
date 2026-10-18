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
        Schema::create('currencies', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();

            $table->string('code', 3);
            $table->string('name');
            $table->string('country')->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_base_currency')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index('company_id');
            $table->index('branch_id');
            $table->index('code');
            $table->index('is_active');
            $table->index('is_base_currency');

            $table->unique(
                ['company_id', 'branch_id', 'code', 'deleted_at'],
                'currencies_company_branch_code_unique'
            );

            $table->foreign('company_id')
                ->references('id')
                ->on('companies')
                ->cascadeOnDelete();

            $table->foreign('branch_id')
                ->references('id')
                ->on('company_branches')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }

};
