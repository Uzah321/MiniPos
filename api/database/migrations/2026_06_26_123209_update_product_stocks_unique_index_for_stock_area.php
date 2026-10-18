<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    private function indexExists(string $table, string $index): bool
    {
        $database = DB::getDatabaseName();

        $rows = DB::select("
            SELECT INDEX_NAME
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = ?
              AND TABLE_NAME = ?
              AND INDEX_NAME = ?
            LIMIT 1
        ", [$database, $table, $index]);

        return count($rows) > 0;
    }

    public function up(): void
    {
        if (!Schema::hasColumn('product_stocks', 'stock_area')) {
            Schema::table('product_stocks', function (Blueprint $table) {
                $table->string('stock_area', 20)->default('front')->after('product_id');
            });
        }

        DB::statement("
            UPDATE product_stocks
            SET stock_area = 'front'
            WHERE stock_area IS NULL OR stock_area = ''
        ");

        /*
         * These indexes are needed so MySQL foreign keys on company_id,
         * branch_id, and product_id do not depend on the old composite unique key.
         */
        if (!$this->indexExists('product_stocks', 'product_stocks_company_id_index')) {
            DB::statement("
                ALTER TABLE `product_stocks`
                ADD INDEX `product_stocks_company_id_index` (`company_id`)
            ");
        }

        if (!$this->indexExists('product_stocks', 'product_stocks_branch_id_index')) {
            DB::statement("
                ALTER TABLE `product_stocks`
                ADD INDEX `product_stocks_branch_id_index` (`branch_id`)
            ");
        }

        if (!$this->indexExists('product_stocks', 'product_stocks_product_id_index')) {
            DB::statement("
                ALTER TABLE `product_stocks`
                ADD INDEX `product_stocks_product_id_index` (`product_id`)
            ");
        }

        if ($this->indexExists('product_stocks', 'product_stocks_company_id_branch_id_product_id_unique')) {
            DB::statement("
                ALTER TABLE `product_stocks`
                DROP INDEX `product_stocks_company_id_branch_id_product_id_unique`
            ");
        }

        if (!$this->indexExists('product_stocks', 'product_stocks_company_branch_product_area_unique')) {
            DB::statement("
                ALTER TABLE `product_stocks`
                ADD UNIQUE KEY `product_stocks_company_branch_product_area_unique`
                (`company_id`, `branch_id`, `product_id`, `stock_area`)
            ");
        }
    }

    public function down(): void
    {
        if ($this->indexExists('product_stocks', 'product_stocks_company_branch_product_area_unique')) {
            DB::statement("
                ALTER TABLE `product_stocks`
                DROP INDEX `product_stocks_company_branch_product_area_unique`
            ");
        }

        if (!$this->indexExists('product_stocks', 'product_stocks_company_id_branch_id_product_id_unique')) {
            DB::statement("
                ALTER TABLE `product_stocks`
                ADD UNIQUE KEY `product_stocks_company_id_branch_id_product_id_unique`
                (`company_id`, `branch_id`, `product_id`)
            ");
        }
    }
};
