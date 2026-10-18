<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fiscal_devices')) {
            Schema::create('fiscal_devices', function (Blueprint $table) {
                $table->char('id', 36)->primary();
                $table->char('company_id', 36);
                $table->char('branch_id', 36)->nullable();
                $table->unsignedBigInteger('device_id');
                $table->string('device_serial_no')->nullable();
                $table->string('model_name');
                $table->string('model_version');
                $table->string('environment')->default('testing');
                $table->string('base_url')->nullable();
                $table->string('certificate_path');
                $table->string('private_key_path');
                $table->string('certificate_password')->nullable();
                $table->string('taxpayer_name')->nullable();
                $table->string('taxpayer_tin')->nullable();
                $table->string('vat_number')->nullable();
                $table->string('device_branch_name')->nullable();
                $table->string('device_operating_mode')->nullable();
                $table->integer('taxpayer_day_max_hrs')->nullable();
                $table->integer('taxpayer_day_end_notification_hrs')->nullable();
                $table->date('certificate_valid_till')->nullable();
                $table->string('qr_url')->nullable();
                $table->boolean('is_registered')->default(true);
                $table->boolean('is_active')->default(true);
                $table->json('last_config_response')->nullable();
                $table->json('last_status_response')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'device_id']);
                $table->index(['company_id', 'branch_id', 'is_active']);
                $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
                $table->foreign('branch_id')->references('id')->on('company_branches')->nullOnDelete();
            });
        }

        // The ZIMRA counter migration runs earlier in timestamp order. Add
        // its deferred foreign key here, including on an interrupted retry.
        if (Schema::hasTable('zimra_global_counters')) {
            $hasCounterDeviceForeignKey = collect(Schema::getForeignKeys('zimra_global_counters'))
                ->contains(fn (array $key) => in_array('fiscal_device_id', $key['columns'], true)
                    && $key['foreign_table'] === 'fiscal_devices');

            if (! $hasCounterDeviceForeignKey) {
                Schema::table('zimra_global_counters', function (Blueprint $table) {
                    $table->foreign('fiscal_device_id')->references('id')->on('fiscal_devices')->cascadeOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_devices');
    }
};
