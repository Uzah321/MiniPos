<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('taxes', function (Blueprint $table) {
            if (!Schema::hasColumn('taxes', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('obliged_to_zimra');
            }

            if (!Schema::hasColumn('taxes', 'valid_from')) {
                $table->date('valid_from')->nullable()->after('is_active');
            }

            if (!Schema::hasColumn('taxes', 'valid_till')) {
                $table->date('valid_till')->nullable()->after('valid_from');
            }

            if (!Schema::hasColumn('taxes', 'description')) {
                $table->text('description')->nullable()->after('valid_till');
            }

            if (!Schema::hasColumn('taxes', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('taxes', function (Blueprint $table) {

        });
    }
};
