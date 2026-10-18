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
        Schema::table('pos_user_shift_sessions', function (Blueprint $table) {
            $table->uuid('shift_configuration_id')->nullable()->after('branch_id');

            $table->foreign('shift_configuration_id')
                ->references('id')
                ->on('shiftconfigurations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pos_user_shift_sessions', function (Blueprint $table) {
            $table->dropForeign(['shift_configuration_id']);
            $table->dropColumn('shift_configuration_id');
        });
    }
};
