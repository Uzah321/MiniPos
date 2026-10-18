<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('fast_food_order_sequences')) {
            Schema::create('fast_food_order_sequences', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('company_id')->index();
                $table->uuid('branch_id')->index();
                $table->uuid('till_id')->nullable()->index();
                $table->uuid('trading_day_id')->nullable()->index();
                $table->date('business_date')->index();
                $table->string('prefix', 30)->default('ORD');
                $table->unsignedInteger('last_number')->default(0);
                $table->timestamps();
                $table->unique(['company_id', 'branch_id', 'business_date'], 'ff_order_seq_branch_day_unique');
            });
        } else {
            Schema::table('fast_food_order_sequences', function (Blueprint $table) {
                if (!Schema::hasColumn('fast_food_order_sequences', 'company_id')) {
                    $table->uuid('company_id')->index();
                }
                if (!Schema::hasColumn('fast_food_order_sequences', 'branch_id')) {
                    $table->uuid('branch_id')->index();
                }
                if (!Schema::hasColumn('fast_food_order_sequences', 'till_id')) {
                    $table->uuid('till_id')->nullable()->index();
                }
                if (!Schema::hasColumn('fast_food_order_sequences', 'trading_day_id')) {
                    $table->uuid('trading_day_id')->nullable()->index();
                }
                if (!Schema::hasColumn('fast_food_order_sequences', 'business_date')) {
                    $table->date('business_date')->index();
                }
                if (!Schema::hasColumn('fast_food_order_sequences', 'prefix')) {
                    $table->string('prefix', 30)->default('ORD');
                }
                if (!Schema::hasColumn('fast_food_order_sequences', 'last_number')) {
                    $table->unsignedInteger('last_number')->default(0);
                }
            });
        }

        if (Schema::hasTable('sales_transactions')) {
            Schema::table('sales_transactions', function (Blueprint $table) {
                if (!Schema::hasColumn('sales_transactions', 'order_number')) {
                    $table->string('order_number', 50)->nullable()->index();
                }
                if (!Schema::hasColumn('sales_transactions', 'order_sequence')) {
                    $table->unsignedInteger('order_sequence')->nullable()->index();
                }
                if (!Schema::hasColumn('sales_transactions', 'order_business_date')) {
                    $table->date('order_business_date')->nullable()->index();
                }
                if (!Schema::hasColumn('sales_transactions', 'kitchen_print_status')) {
                    $table->string('kitchen_print_status', 30)->nullable()->index();
                }
            });
        }

        if (Schema::hasTable('receipt_lines')) {
            Schema::table('receipt_lines', function (Blueprint $table) {
                if (!Schema::hasColumn('receipt_lines', 'order_number')) {
                    $table->string('order_number', 50)->nullable()->index();
                }
                if (!Schema::hasColumn('receipt_lines', 'kitchen_print_status')) {
                    $table->string('kitchen_print_status', 30)->nullable()->index();
                }
            });
        }

        if (!Schema::hasTable('kitchen_print_jobs')) {
            Schema::create('kitchen_print_jobs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('company_id')->index();
                $table->uuid('branch_id')->index();
                $table->uuid('till_id')->nullable()->index();
                $table->uuid('sales_transaction_id')->index();
                $table->string('order_number', 50)->nullable()->index();
                $table->string('target_printing', 50)->default('kitchen')->index();
                $table->uuid('printer_id')->nullable()->index();
                $table->string('printer_name')->nullable();
                $table->string('status', 30)->default('pending')->index();
                $table->json('payload')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('printed_at')->nullable();
                $table->timestamp('failed_at')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();
                $table->unique(['sales_transaction_id', 'target_printing'], 'kitchen_print_job_sale_target_unique');
            });
        }
    }

    public function down(): void
    {
        // Safe rollback: keep transaction columns/data. Only remove the print job table.
        Schema::dropIfExists('kitchen_print_jobs');
    }
};
