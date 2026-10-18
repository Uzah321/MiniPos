<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('zimra_global_counters')) {
            return;
        }

        Schema::create('zimra_global_counters', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('company_id', 36);
            $table->char('fiscal_device_id', 36);
            $table->unsignedBigInteger('device_id');
            $table->unsignedBigInteger('next_receipt_global_no')->default(1);
            $table->timestamps();
            $table->unique('fiscal_device_id');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            // fiscal_devices is created by the later FiscalDevice migration.
            // That migration installs this foreign key after its table exists.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zimra_global_counters');
    }
};
