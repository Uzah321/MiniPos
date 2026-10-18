<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('sales_transactions', 'credit_customer_id')) {
                $table->uuid('credit_customer_id')->nullable()->after('customer_tax_number');
                $table->string('credit_account_number', 100)->nullable()->after('credit_customer_id');
                $table->boolean('is_credit_sale')->default(false)->after('credit_account_number');
                $table->string('credit_status', 30)->nullable()->after('is_credit_sale'); // posted, paid, partially_paid, reversed
                $table->date('credit_due_date')->nullable()->after('credit_status');
                $table->decimal('credit_amount', 18, 4)->default(0)->after('credit_due_date');
                $table->decimal('credit_paid_amount', 18, 4)->default(0)->after('credit_amount');
                $table->decimal('credit_balance_amount', 18, 4)->default(0)->after('credit_paid_amount');
                $table->index(['company_id', 'branch_id', 'credit_customer_id'], 'sales_credit_customer_idx');
                $table->index(['is_credit_sale', 'credit_status'], 'sales_credit_status_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('sales_transactions', 'credit_customer_id')) {
                $table->dropIndex('sales_credit_customer_idx');
                $table->dropIndex('sales_credit_status_idx');
                $table->dropColumn([
                    'credit_customer_id',
                    'credit_account_number',
                    'is_credit_sale',
                    'credit_status',
                    'credit_due_date',
                    'credit_amount',
                    'credit_paid_amount',
                    'credit_balance_amount',
                ]);
            }
        });
    }
};
