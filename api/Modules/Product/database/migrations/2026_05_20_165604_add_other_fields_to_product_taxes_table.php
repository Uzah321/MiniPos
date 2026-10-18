<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_taxes', function (Blueprint $table) {
            if (!Schema::hasColumn('product_taxes', 'company_id')) {
                $table->char('company_id', 36)->nullable()->after('id');
            }
            if (!Schema::hasColumn('product_taxes', 'branch_id')) {
                $table->char('branch_id', 36)->nullable()->after('company_id');
            }
        });

        try {
            DB::statement("UPDATE product_taxes pt JOIN products p ON p.id = pt.product_id SET pt.company_id = p.company_id WHERE pt.company_id IS NULL");
        } catch (\Throwable $e) {
        }

        try {
            Schema::table('product_taxes', function (Blueprint $table) {
                $table->index(['company_id', 'branch_id'], 'product_taxes_company_branch_index');
                $table->index(['product_id', 'tax_id'], 'product_taxes_product_tax_index');
            });
        } catch (\Throwable $e) {
        }
    }

    public function down(): void
    {
        Schema::table('product_taxes', function (Blueprint $table) {
            foreach (['company_id', 'branch_id'] as $column) {
                if (Schema::hasColumn('product_taxes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
