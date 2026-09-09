<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->decimal('opening_float', 12, 4);
            $table->decimal('closing_count', 12, 4)->nullable();
            $table->decimal('variance', 12, 4)->nullable();
            $table->string('status')->default('open');
            $table->foreignId('manager_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tills');
    }
};
