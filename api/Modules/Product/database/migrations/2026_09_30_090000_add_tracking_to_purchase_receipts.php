<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchase_receipts', function (Blueprint $table) {
            $table->string('source', 30)->default('purchase_order')->after('grn_number')->index();
            $table->string('receipt_group_key', 120)->nullable()->after('source');
            $table->decimal('exchange_rate', 18, 8)->default(1)->after('currency_code');
            $table->decimal('base_grand_total', 18, 4)->default(0)->after('grand_total');
            $table->unique(['company_id', 'receipt_group_key'], 'purchase_receipts_company_group_unique');
        });

        DB::table('purchase_receipts')->where('base_grand_total', 0)->update([
            'base_grand_total' => DB::raw('grand_total'),
        ]);
    }

    public function down(): void
    {
        Schema::table('purchase_receipts', function (Blueprint $table) {
            $table->dropUnique('purchase_receipts_company_group_unique');
            $table->dropColumn(['source', 'receipt_group_key', 'exchange_rate', 'base_grand_total']);
        });
    }
};
