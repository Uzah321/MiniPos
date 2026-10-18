<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL can leave earlier CREATE TABLE statements committed when a
        // later index fails. Allow this migration to resume without dropping
        // any fiscal device or receipt records.
        if (! Schema::hasTable('mobile_fiscal_devices')) {
            Schema::create('mobile_fiscal_devices', function (Blueprint $table) {
                $table->char('id', 36)->primary();
                $table->char('company_id', 36);
                $table->char('branch_id', 36);
                $table->char('till_id', 36);
                $table->char('sync_node_id', 36)->unique();
                $table->unsignedBigInteger('device_id')->unique();
                $table->string('device_serial_no', 100);
                $table->string('environment', 16);
                $table->string('device_operating_mode', 16);
                $table->string('qr_url', 500);
                $table->longText('certificate_pem');
                $table->char('certificate_sha256', 64);
                $table->dateTime('certificate_valid_until');
                $table->boolean('is_active')->default(true);
                $table->char('registered_by', 36)->nullable();
                $table->timestamps();
                $table->index(['company_id', 'branch_id', 'till_id']);
            });
        }

        if (! Schema::hasTable('mobile_fiscal_receipts')) {
            Schema::create('mobile_fiscal_receipts', function (Blueprint $table) {
                $table->char('id', 36)->primary();
                $table->char('company_id', 36);
                $table->char('branch_id', 36);
                $table->char('till_id', 36);
                $table->char('sync_node_id', 36);
                $table->char('mobile_fiscal_device_id', 36);
                $table->char('certificate_sha256', 64);
                $table->longText('certificate_pem');
                $table->char('sales_transaction_id', 36)->unique();
                $table->string('client_sale_id', 100);
                $table->unsignedBigInteger('device_id');
                $table->unsignedBigInteger('fiscal_day_no');
                $table->unsignedBigInteger('receipt_counter');
                $table->unsignedBigInteger('receipt_global_no');
                $table->string('invoice_no', 100);
                $table->string('receipt_currency', 10);
                $table->decimal('receipt_total', 18, 2);
                $table->text('previous_receipt_hash')->nullable();
                $table->text('receipt_hash');
                $table->text('receipt_signature');
                $table->text('qr_string');
                $table->json('payload');
                $table->string('status', 40)->default('device_pending');
                $table->string('fdms_receipt_id')->nullable();
                $table->string('operation_id')->nullable();
                $table->unsignedInteger('file_sequence')->nullable();
                $table->json('fdms_response')->nullable();
                $table->timestamp('device_reported_submitted_at')->nullable();
                $table->timestamps();
                $table->unique(['device_id', 'receipt_global_no']);
                $table->unique(['device_id', 'fiscal_day_no', 'receipt_counter'], 'mobile_fiscal_device_day_counter_unique');
                $table->index(['company_id', 'branch_id', 'status']);
                $table->index(['sync_node_id', 'client_sale_id']);
            });
        }

        if (! Schema::hasTable('mobile_fiscal_file_confirmations')) {
            Schema::create('mobile_fiscal_file_confirmations', function (Blueprint $table) {
                $table->char('id', 36)->primary();
                $table->char('company_id', 36);
                $table->char('branch_id', 36);
                $table->char('till_id', 36);
                $table->char('sync_node_id', 36);
                $table->unsignedBigInteger('device_id');
                $table->unsignedBigInteger('fiscal_day_no');
                $table->unsignedInteger('file_sequence');
                $table->char('file_sha256', 64);
                $table->string('operation_id');
                $table->json('receipt_hashes');
                $table->json('get_file_status');
                $table->timestamps();
                $table->unique(['device_id', 'fiscal_day_no', 'file_sequence'], 'mobile_fiscal_file_device_day_sequence_unique');
            });
        }

        foreach ([
            'mobile_fiscal_devices' => ['id', 'company_id', 'branch_id', 'till_id', 'sync_node_id', 'device_id', 'certificate_pem'],
            'mobile_fiscal_receipts' => ['id', 'company_id', 'branch_id', 'sync_node_id', 'sales_transaction_id', 'receipt_global_no', 'payload'],
            'mobile_fiscal_file_confirmations' => ['id', 'company_id', 'branch_id', 'device_id', 'fiscal_day_no', 'file_sequence', 'file_sha256'],
        ] as $table => $columns) {
            if (! Schema::hasColumns($table, $columns)) {
                throw new RuntimeException("The existing {$table} table is incomplete; inspect it before retrying this migration.");
            }
        }

        if (! Schema::hasIndex('mobile_fiscal_file_confirmations', 'mobile_fiscal_file_device_day_sequence_unique')) {
            Schema::table('mobile_fiscal_file_confirmations', function (Blueprint $table) {
                $table->unique(['device_id', 'fiscal_day_no', 'file_sequence'], 'mobile_fiscal_file_device_day_sequence_unique');
            });
        }

        // Laravel's generated name for this index is 73 characters, above
        // MySQL's 64-character limit. Add it separately so an interrupted
        // migration can finish after the table itself was already created.
        if (! Schema::hasIndex('mobile_fiscal_file_confirmations', 'mobile_fiscal_file_co_br_day_idx')) {
            Schema::table('mobile_fiscal_file_confirmations', function (Blueprint $table) {
                $table->index(['company_id', 'branch_id', 'fiscal_day_no'], 'mobile_fiscal_file_co_br_day_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_fiscal_file_confirmations');
        Schema::dropIfExists('mobile_fiscal_receipts');
        Schema::dropIfExists('mobile_fiscal_devices');
    }
};
