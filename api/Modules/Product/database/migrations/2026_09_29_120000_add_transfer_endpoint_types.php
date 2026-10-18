<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfer_headers', function (Blueprint $table) {
            if (!Schema::hasColumn('stock_transfer_headers', 'from_location_type')) {
                $table->string('from_location_type', 20)->default('warehouse')->after('from_warehouse_id');
            }
            if (!Schema::hasColumn('stock_transfer_headers', 'to_location_type')) {
                $table->string('to_location_type', 20)->default('warehouse')->after('to_warehouse_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfer_headers', function (Blueprint $table) {
            $columns = array_values(array_filter(['from_location_type', 'to_location_type'], fn ($column) => Schema::hasColumn('stock_transfer_headers', $column)));
            if ($columns) $table->dropColumn($columns);
        });
    }
};
