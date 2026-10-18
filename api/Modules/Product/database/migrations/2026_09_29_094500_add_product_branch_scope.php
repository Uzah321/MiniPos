<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_global')->default(true)->after('company_id');
            $table->uuid('branch_id')->nullable()->after('is_global');
            $table->foreign('branch_id')->references('id')->on('company_branches')->nullOnDelete();
            $table->index(['company_id', 'is_global', 'branch_id'], 'products_company_scope_branch_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_company_scope_branch_index');
            $table->dropForeign(['branch_id']);
            $table->dropColumn(['branch_id', 'is_global']);
        });
    }
};
