<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('original_sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->string('status')->default('draft');
            $table->string('reason')->nullable();
            $table->string('refund_method')->nullable();
            $table->decimal('total', 12, 4)->default(0);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_notes');
    }
};
