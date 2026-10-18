<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $indexes = [
            'food_manufacturing_production_plans',
            'pos_shifts',
            'pos_users',
            'roles',
            'role_permissions',
            'shiftconfigurations',
            'user_branches',
        ];

        foreach ($indexes as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->index('company_id', $table . '_company_id_tenant_index');
            });
        }
    }

    public function down(): void
    {
        $indexes = [
            'food_manufacturing_production_plans',
            'pos_shifts',
            'pos_users',
            'roles',
            'role_permissions',
            'shiftconfigurations',
            'user_branches',
        ];

        foreach ($indexes as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropIndex($table . '_company_id_tenant_index');
            });
        }
    }
};
