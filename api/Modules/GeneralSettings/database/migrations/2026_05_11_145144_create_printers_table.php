<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('printers', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('company_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('till_id')->nullable();

            $table->string('printer_name');
            $table->string('target_printing');
            // receipt, kitchen, bar, report, label, invoice, general

            $table->string('printer_type')->default('qz');
            // qz, browser, network

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            $table->string('paper_size')->nullable();
            // 80mm, 58mm, A4

            $table->string('connection_type')->nullable();
            // local, network, usb, bluetooth

            $table->string('ip_address')->nullable();
            $table->integer('port')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['company_id', 'branch_id', 'till_id', 'target_printing', 'printer_name'],
                'printers_unique_target_printer'
            );

            $table->index('company_id');
            $table->index('branch_id');
            $table->index('till_id');
            $table->index('target_printing');
            $table->index('is_active');

            $table->foreign('company_id')
                ->references('id')
                ->on('companies')
                ->cascadeOnDelete();

            $table->foreign('branch_id')
                ->references('id')
                ->on('company_branches')
                ->nullOnDelete();

            $table->foreign('till_id')
                ->references('id')
                ->on('pos_tills')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printers');
    }
};
