<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE products ALTER COLUMN stock_tracking_enabled SET DEFAULT 0');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE products ALTER COLUMN stock_tracking_enabled SET DEFAULT 1');
    }
};
