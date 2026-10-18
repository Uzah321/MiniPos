<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_warehouses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('branch_id')->nullable()->index();
            $table->string('code', 40);
            $table->string('name', 160);
            $table->string('warehouse_type', 30)->default('general');
            $table->string('address')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code'], 'inventory_warehouses_company_code_unique');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('company_branches')->nullOnDelete();
        });

        Schema::create('inventory_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('branch_id')->nullable()->index();
            $table->uuid('warehouse_id')->index();
            $table->string('code', 60);
            $table->string('name', 160);
            $table->string('location_type', 30)->default('storage');
            $table->unsignedInteger('pick_sequence')->default(0);
            $table->boolean('is_quarantine')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'warehouse_id', 'code'], 'inventory_locations_company_warehouse_code_unique');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('company_branches')->nullOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('inventory_warehouses')->cascadeOnDelete();
        });

        Schema::create('inventory_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('branch_id')->index();
            $table->uuid('warehouse_id')->nullable()->index();
            $table->uuid('location_id')->nullable()->index();
            $table->uuid('product_id')->index();
            $table->uuid('purchase_receipt_line_id')->nullable()->index();
            $table->string('lot_number', 100);
            $table->date('manufactured_at')->nullable();
            $table->date('expires_at')->nullable()->index();
            $table->decimal('quantity_received', 18, 4)->default(0);
            $table->decimal('quantity_on_hand', 18, 4)->default(0);
            $table->decimal('reserved_quantity', 18, 4)->default(0);
            $table->decimal('quarantined_quantity', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('base_unit_cost', 18, 4)->default(0);
            $table->string('currency_code', 10)->nullable();
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->string('status', 30)->default('available')->index();
            $table->string('quality_status', 30)->default('released')->index();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable()->index();
            $table->timestamps();

            $table->unique(['company_id', 'branch_id', 'product_id', 'lot_number'], 'inventory_batches_company_branch_product_lot_unique');
            $table->index(['company_id', 'expires_at', 'status'], 'inventory_batches_expiry_status_index');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('company_branches')->cascadeOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('inventory_warehouses')->nullOnDelete();
            $table->foreign('location_id')->references('id')->on('inventory_locations')->nullOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('purchase_receipt_line_id')->references('id')->on('purchase_receipt_lines')->nullOnDelete();
        });

        Schema::create('inventory_reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('branch_id')->index();
            $table->uuid('warehouse_id')->nullable()->index();
            $table->uuid('location_id')->nullable()->index();
            $table->uuid('batch_id')->nullable()->index();
            $table->uuid('product_id')->index();
            $table->string('reference_type', 80);
            $table->uuid('reference_id')->nullable()->index();
            $table->decimal('quantity', 18, 4);
            $table->string('status', 30)->default('active')->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->uuid('created_by')->nullable()->index();
            $table->timestamps();

            $table->index(['company_id', 'product_id', 'status'], 'inventory_reservations_product_status_index');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('company_branches')->cascadeOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('inventory_warehouses')->nullOnDelete();
            $table->foreign('location_id')->references('id')->on('inventory_locations')->nullOnDelete();
            $table->foreign('batch_id')->references('id')->on('inventory_batches')->nullOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });

        Schema::create('inventory_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('branch_id')->nullable()->index();
            $table->uuid('product_id')->nullable()->index();
            $table->uuid('batch_id')->nullable()->index();
            $table->string('fingerprint', 191);
            $table->string('alert_type', 40)->index();
            $table->string('severity', 20)->default('warning')->index();
            $table->string('title', 180);
            $table->text('message');
            $table->json('evidence')->nullable();
            $table->string('status', 20)->default('open')->index();
            $table->timestamp('acknowledged_at')->nullable();
            $table->uuid('acknowledged_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status', 'severity'], 'inventory_alerts_company_status_severity_index');
            $table->unique(['company_id', 'fingerprint'], 'inventory_alerts_company_fingerprint_unique');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('company_branches')->nullOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            $table->foreign('batch_id')->references('id')->on('inventory_batches')->nullOnDelete();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('batch_tracking_enabled')->default(false)->after('stock_tracking_enabled');
            $table->boolean('expiry_tracking_enabled')->default(false)->after('batch_tracking_enabled');
            $table->unsignedInteger('default_shelf_life_days')->nullable()->after('expiry_tracking_enabled');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->uuid('warehouse_id')->nullable()->index()->after('branch_id');
            $table->uuid('location_id')->nullable()->index()->after('warehouse_id');
            $table->uuid('batch_id')->nullable()->index()->after('location_id');
        });

        Schema::table('purchase_receipt_lines', function (Blueprint $table) {
            $table->uuid('warehouse_id')->nullable()->index()->after('branch_id');
            $table->uuid('location_id')->nullable()->index()->after('warehouse_id');
            $table->string('lot_number', 100)->nullable()->after('product_id');
            $table->date('manufactured_at')->nullable()->after('lot_number');
            $table->date('expires_at')->nullable()->index()->after('manufactured_at');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_receipt_lines', function (Blueprint $table) {
            $table->dropColumn(['warehouse_id', 'location_id', 'lot_number', 'manufactured_at', 'expires_at']);
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn(['warehouse_id', 'location_id', 'batch_id']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['batch_tracking_enabled', 'expiry_tracking_enabled', 'default_shelf_life_days']);
        });
        Schema::dropIfExists('inventory_alerts');
        Schema::dropIfExists('inventory_reservations');
        Schema::dropIfExists('inventory_batches');
        Schema::dropIfExists('inventory_locations');
        Schema::dropIfExists('inventory_warehouses');
    }
};
