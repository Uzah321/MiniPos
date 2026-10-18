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
        Schema::create('shiftconfigurations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();

            $table->boolean('enable_shifts')->default(true);
            $table->string('shift_mode')->default('manual');
            // manual, automatic, fixed_time

            $table->time('morning_shift_start')->nullable();
            $table->time('morning_shift_end')->nullable();

            $table->time('afternoon_shift_start')->nullable();
            $table->time('afternoon_shift_end')->nullable();

            $table->time('night_shift_start')->nullable();
            $table->time('night_shift_end')->nullable();

            $table->boolean('auto_shift_logout')->default(false);
            $table->integer('auto_logout_after_minutes')->nullable();

            $table->boolean('require_opening_float')->default(true);
            $table->boolean('require_closing_cashup')->default(true);
            $table->boolean('allow_multiple_open_shifts')->default(false);

            $table->string('status')->default('active');

            $table->timestamps();
            $table->softDeletes();


        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shiftconfigurations');
    }
};
