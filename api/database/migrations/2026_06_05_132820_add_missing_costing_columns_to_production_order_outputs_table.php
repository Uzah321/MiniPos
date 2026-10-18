<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_order_outputs', function (Blueprint $table) {
            if (!Schema::hasColumn('production_order_outputs', 'quantity_produced')) {
                $table->decimal('quantity_produced', 18, 4)->default(0);
            }

            if (!Schema::hasColumn('production_order_outputs', 'quantity_rejected')) {
                $table->decimal('quantity_rejected', 18, 4)->default(0)->after('quantity_produced');
            }

            if (!Schema::hasColumn('production_order_outputs', 'quantity_wasted')) {
                $table->decimal('quantity_wasted', 18, 4)->default(0)->after('quantity_rejected');
            }

            if (!Schema::hasColumn('production_order_outputs', 'unit_cost')) {
                $table->decimal('unit_cost', 18, 6)->default(0)->after('quantity_wasted');
            }

            if (!Schema::hasColumn('production_order_outputs', 'total_cost')) {
                $table->decimal('total_cost', 18, 4)->default(0)->after('unit_cost');
            }

            if (!Schema::hasColumn('production_order_outputs', 'currency')) {
                $table->string('currency', 3)->nullable()->after('total_cost');
            }

            if (!Schema::hasColumn('production_order_outputs', 'metadata')) {
                $table->json('metadata')->nullable()->after('currency');
            }
        });
    }

    public function down(): void
    {
        Schema::table('production_order_outputs', function (Blueprint $table) {
            $columns = [
                'quantity_rejected',
                'quantity_wasted',
                'unit_cost',
                'total_cost',
                'currency',
                'metadata',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('production_order_outputs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
