<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('pos_tills', 'activation_code_expires_at')) {
            Schema::table('pos_tills', function (Blueprint $table): void {
                $table->timestamp('activation_code_expires_at')
                    ->nullable()
                    ->after('activation_code');
            });
        }

        // Earlier builds stored an unused short raw value in activation_code.
        // Standalone browser activation stores only a one-way digest there.
        DB::table('pos_tills')->whereNotNull('activation_code')->update([
            'activation_code' => null,
            'activation_code_expires_at' => null,
        ]);

        Schema::table('pos_tills', function (Blueprint $table): void {
            $table->unique('activation_code', 'pos_tills_activation_code_unique');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('pos_tills', 'activation_code_expires_at')) {
            Schema::table('pos_tills', function (Blueprint $table): void {
                $table->dropUnique('pos_tills_activation_code_unique');
                $table->dropColumn('activation_code_expires_at');
            });
        }
    }
};
