<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('fast_food_order_sequences')) {
            return;
        }

        try {
            DB::statement('ALTER TABLE fast_food_order_sequences DROP INDEX fast_food_order_sequences_company_id_branch_id_till_id_business_date_unique');
        } catch (\Throwable $e) {
            // Index may not exist, keep migration idempotent.
        }

        try {
            DB::statement('ALTER TABLE fast_food_order_sequences DROP INDEX ff_order_seq_company_branch_till_business_unique');
        } catch (\Throwable $e) {
            // Index may not exist.
        }

        try {
            DB::statement('ALTER TABLE fast_food_order_sequences ADD UNIQUE ff_order_seq_company_branch_business_unique (company_id, branch_id, business_date)');
        } catch (\Throwable $e) {
            // Already exists.
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('fast_food_order_sequences')) {
            return;
        }

        try {
            DB::statement('ALTER TABLE fast_food_order_sequences DROP INDEX ff_order_seq_company_branch_business_unique');
        } catch (\Throwable $e) {
            // Ignore.
        }
    }
};
