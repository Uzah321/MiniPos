<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_number_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();

            $table->string('branch_key')->storedAs("COALESCE(branch_id, 'company')");

            $table->enum('type', [
                'product_code',
                'sku',
            ]);

            $table->string('prefix')->nullable();
            $table->unsignedBigInteger('current_number')->default(0);
            $table->unsignedTinyInteger('padding')->default(5);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->foreign('company_id')
                ->references('id')
                ->on('companies')
                ->cascadeOnDelete();

            // MySQL 8 forbids SET NULL/CASCADE on the base column of a stored
            // generated column (branch_key). MariaDB allows it.
            $branchForeign = $table->foreign('branch_id')
                ->references('id')
                ->on('company_branches');
            $connection = DB::connection();
            if ($connection->getDriverName() === 'mysql' && ! $connection->isMaria()) {
                $branchForeign->restrictOnDelete();
            } else {
                $branchForeign->nullOnDelete();
            }

            $table->unique([
                'company_id',
                'branch_key',
                'type',
            ], 'product_number_sequence_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_number_sequences');
    }
};
