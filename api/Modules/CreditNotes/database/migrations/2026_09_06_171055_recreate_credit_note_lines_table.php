<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Sprint 0 shipped sale_line_id as required; a no-receipt return has no
        // original sale line to point at, so it needs to be nullable, and an
        // item_id is needed directly since there's no sale line to derive it
        // from. Recreated (rather than altered) because doctrine/dbal isn't
        // installed and this table has no data yet to preserve.
        Schema::dropIfExists('credit_note_lines');

        Schema::create('credit_note_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('credit_note_id')->constrained('credit_notes')->cascadeOnDelete();
            $table->foreignUuid('sale_line_id')->nullable()->constrained('sale_lines')->restrictOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 4);
            $table->string('condition')->nullable();
            $table->decimal('amount', 12, 4);
            $table->decimal('tax_amount', 12, 4)->default(0);
            $table->string('disposition')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credit_note_lines');

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
};
