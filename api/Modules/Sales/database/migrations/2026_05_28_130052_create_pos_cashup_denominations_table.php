<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_cashup_denominations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('company_id')->index();
            $table->uuid('branch_id')->index();
            $table->uuid('till_id')->nullable()->index();

            $table->uuid('cashup_id')->index();

            $table->string('currency_code', 10)->default('USD');

            // Example: 100, 50, 20, 10, 5, 2, 1, 0.50, 0.25
            $table->decimal('denomination_value', 18, 4);

            // Example: how many 100s, how many 2s, etc.
            $table->integer('quantity')->default(0);

            // denomination_value * quantity
            $table->decimal('line_total', 18, 4)->default(0);

            // note or coin
            $table->string('denomination_type')->default('note');

            $table->integer('sort_order')->default(0);

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->foreign('cashup_id')
                ->references('id')
                ->on('pos_cashups')
                ->cascadeOnDelete();

            $table->unique([
                'cashup_id',
                'currency_code',
                'denomination_value',
            ], 'pos_cashup_denom_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_cashup_denominations');
    }
};
