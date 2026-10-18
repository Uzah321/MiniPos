<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_cashups', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('company_id')->index();
            $table->uuid('branch_id')->index();
            $table->uuid('till_id')->nullable()->index();

            $table->uuid('shift_configuration_id')->nullable()->index();
            $table->uuid('shift_session_id')->index();

            $table->uuid('cashier_id')->index();
            $table->uuid('closed_by')->nullable()->index();

            $table->decimal('opening_float', 18, 4)->default(0);
            $table->decimal('expected_cash', 18, 4)->default(0);
            $table->decimal('counted_cash', 18, 4)->default(0);
            $table->decimal('cash_variance', 18, 4)->default(0);

            $table->decimal('expected_total', 18, 4)->default(0);
            $table->decimal('counted_total', 18, 4)->default(0);
            $table->decimal('total_variance', 18, 4)->default(0);

            $table->integer('transaction_count')->default(0);
            $table->integer('void_count')->default(0);
            $table->integer('refund_count')->default(0);

            $table->json('payment_summary')->nullable();
            $table->json('currency_summary')->nullable();
            $table->json('sales_summary')->nullable();

            $table->string('status')->default('closed'); // draft, closed, approved, rejected
            $table->text('notes')->nullable();

            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'shift_session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_cashups');
    }
};
