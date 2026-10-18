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
        Schema::create('pos_user_shift_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            $table->foreignUuid('branch_id')
                ->constrained('company_branches')
                ->cascadeOnDelete();

            $table->foreignUuid('pos_user_id')
                ->constrained('pos_users')
                ->cascadeOnDelete();

            $table->string('shift_name'); // morning, afternoon, night
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();

            $table->decimal('opening_float', 15, 2)->nullable();
            $table->decimal('closing_cash', 15, 2)->nullable();

            $table->string('status')->default('open'); // open, closed

            $table->timestamps();

            $table->index([
                'company_id',
                'branch_id',
                'pos_user_id',
                'status',
            ], 'pos_user_shift_sessions_lookup_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_user_shift_sessions');
    }
};
