<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE stock_movements
            MODIFY movement_type ENUM(
                'stock_take',
                'sale_cancel',
                'opening',
                'sale',
                'purchase',
                'adjustment',
                'transfer_in',
                'transfer_out',
                'break_parent',
                'create_child',
                'return',
                'purchase_return',
                'production_order',
                'production_issue',
                'production_return',
                'production_wastage',
                'production_output'
            ) NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE stock_movements
            MODIFY movement_type ENUM(
                'stock_take',
                'sale_cancel',
                'opening',
                'sale',
                'purchase',
                'adjustment',
                'transfer_in',
                'transfer_out',
                'break_parent',
                'create_child',
                'return',
                'purchase_return',
                'production_order'
            ) NOT NULL
        ");
    }
};
