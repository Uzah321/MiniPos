<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_order_inputs', function (Blueprint $table) {
            if (!Schema::hasColumn('production_order_inputs', 'reserved_quantity')) {
                $table->decimal('reserved_quantity', 18, 4)->default(0)->after('planned_quantity');
            }

            if (!Schema::hasColumn('production_order_inputs', 'issued_quantity')) {
                $table->decimal('issued_quantity', 18, 4)->default(0)->after('reserved_quantity');
            }

            if (!Schema::hasColumn('production_order_inputs', 'returned_quantity')) {
                $table->decimal('returned_quantity', 18, 4)->default(0)->after('issued_quantity');
            }

            if (!Schema::hasColumn('production_order_inputs', 'wasted_quantity')) {
                $table->decimal('wasted_quantity', 18, 4)->default(0)->after('returned_quantity');
            }

            if (!Schema::hasColumn('production_order_inputs', 'unit_cost')) {
                $table->decimal('unit_cost', 18, 6)->default(0)->after('wasted_quantity');
            }

            if (!Schema::hasColumn('production_order_inputs', 'total_cost')) {
                $table->decimal('total_cost', 18, 4)->default(0)->after('unit_cost');
            }

            if (!Schema::hasColumn('production_order_inputs', 'currency')) {
                $table->string('currency', 3)->nullable()->after('total_cost');
            }

            if (!Schema::hasColumn('production_order_inputs', 'metadata')) {
                $table->json('metadata')->nullable()->after('currency');
            }
        });
    }

    public function down(): void
    {
        Schema::table('production_order_inputs', function (Blueprint $table) {
            $columns = [
                'reserved_quantity',
                'issued_quantity',
                'returned_quantity',
                'wasted_quantity',
                'unit_cost',
                'total_cost',
                'currency',
                'metadata',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('production_order_inputs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
