<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_note_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('credit_note_id')->constrained('credit_notes')->cascadeOnDelete();
            $table->foreignUuid('sale_line_id')->constrained('sale_lines')->restrictOnDelete();
            $table->decimal('quantity', 12, 4);
            $table->decimal('amount', 12, 4);
            $table->string('disposition')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_note_lines');
    }
};
