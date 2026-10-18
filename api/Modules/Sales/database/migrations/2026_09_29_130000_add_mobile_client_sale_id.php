<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_transactions', function (Blueprint $table) {
            $table->string('client_sale_id', 100)->nullable()->after('invoice_number');
            $table->unique(['company_id', 'client_sale_id'], 'sales_company_client_sale_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sales_transactions', function (Blueprint $table) {
            $table->dropUnique('sales_company_client_sale_unique');
            $table->dropColumn('client_sale_id');
        });
    }
};
