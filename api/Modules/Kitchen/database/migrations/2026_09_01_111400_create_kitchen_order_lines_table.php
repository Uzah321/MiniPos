<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_order_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('kitchen_order_id')->constrained('kitchen_orders')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->string('station')->nullable();
            $table->string('status')->default('draft');
            $table->decimal('quantity', 12, 4)->default(1);
            $table->text('notes')->nullable();
            $table->boolean('is_duplicate')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_order_lines');
    }
};
