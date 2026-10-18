<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_number_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('till_id')->nullable();

            $table->string('sequence_type')->default('sale_receipt');
            $table->string('prefix')->default('SAL');
            $table->date('sequence_date');

            $table->unsignedBigInteger('last_number')->default(0);

            $table->timestamps();

            $table->unique(
                [
                    'company_id',
                    'branch_id',
                    'till_id',
                    'sequence_type',
                    'sequence_date',
                ],
                'receipt_sequence_unique'
            );

            $table->index('company_id');
            $table->index('branch_id');
            $table->index('till_id');
            $table->index('sequence_date');

            $table->foreign('company_id')
                ->references('id')
                ->on('companies')
                ->cascadeOnDelete();

            $table->foreign('branch_id')
                ->references('id')
                ->on('company_branches')
                ->nullOnDelete();

            $table->foreign('till_id')
                ->references('id')
                ->on('pos_tills')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_number_sequences');
    }
};
