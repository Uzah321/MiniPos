<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('sales_transactions', 'is_fiscalised')) {
                $table->boolean('is_fiscalised')->default(false)->after('metadata');
            }
            if (!Schema::hasColumn('sales_transactions', 'fiscal_status')) {
                $table->string('fiscal_status')->default('skipped')->after('is_fiscalised')->index();
            }
            if (!Schema::hasColumn('sales_transactions', 'fiscal_device_id')) {
                $table->char('fiscal_device_id', 36)->nullable()->after('fiscal_status');
            }
            if (!Schema::hasColumn('sales_transactions', 'fiscal_day_id')) {
                $table->char('fiscal_day_id', 36)->nullable()->after('fiscal_device_id')->index();
            }
            if (!Schema::hasColumn('sales_transactions', 'receipt_counter')) {
                $table->unsignedBigInteger('receipt_counter')->nullable()->after('fiscal_day_id');
            }
            if (!Schema::hasColumn('sales_transactions', 'receipt_global_no')) {
                $table->unsignedBigInteger('receipt_global_no')->nullable()->after('receipt_counter');
            }
            if (!Schema::hasColumn('sales_transactions', 'fdms_receipt_id')) {
                $table->string('fdms_receipt_id')->nullable()->after('receipt_global_no');
            }
            if (!Schema::hasColumn('sales_transactions', 'qr_code_data')) {
                $table->text('qr_code_data')->nullable()->after('fdms_receipt_id');
            }
            if (!Schema::hasColumn('sales_transactions', 'qr_url')) {
                $table->text('qr_url')->nullable()->after('qr_code_data');
            }
            if (!Schema::hasColumn('sales_transactions', 'zimra_signature_hash')) {
                $table->text('zimra_signature_hash')->nullable()->after('qr_url');
            }
            if (!Schema::hasColumn('sales_transactions', 'fiscal_response')) {
                $table->json('fiscal_response')->nullable()->after('zimra_signature_hash');
            }
            if (!Schema::hasColumn('sales_transactions', 'fiscal_error')) {
                $table->text('fiscal_error')->nullable()->after('fiscal_response');
            }
            if (!Schema::hasColumn('sales_transactions', 'fiscalised_at')) {
                $table->timestamp('fiscalised_at')->nullable()->after('fiscal_error');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_transactions', function (Blueprint $table) {
            $columns = ['is_fiscalised','fiscal_status','fiscal_device_id','fiscal_day_id','receipt_counter','receipt_global_no','fdms_receipt_id','qr_code_data','qr_url','zimra_signature_hash','fiscal_response','fiscal_error','fiscalised_at'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('sales_transactions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
