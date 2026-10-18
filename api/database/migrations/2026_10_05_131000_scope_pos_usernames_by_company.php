<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_users')
            || ! Schema::hasColumn('pos_users', 'company_id')
            || ! Schema::hasColumn('pos_users', 'username')) {
            return;
        }

        Schema::table('pos_users', function (Blueprint $table): void {
            if (Schema::hasIndex('pos_users', 'pos_users_username_unique')) {
                $table->dropUnique('pos_users_username_unique');
            }
            if (! Schema::hasIndex('pos_users', 'pos_users_company_username_unique')) {
                $table->unique(['company_id', 'username'], 'pos_users_company_username_unique');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pos_users')) {
            return;
        }

        // A rollback cannot safely restore global uniqueness after two tenants
        // have legitimately created the same username. Preserve the scoped
        // index instead of deleting or renaming user accounts.
        $hasCrossCompanyDuplicates = DB::table('pos_users')
            ->whereNotNull('username')
            ->select('username')
            ->groupBy('username')
            ->havingRaw('COUNT(DISTINCT company_id) > 1')
            ->exists();
        if ($hasCrossCompanyDuplicates) {
            return;
        }

        Schema::table('pos_users', function (Blueprint $table): void {
            if (Schema::hasIndex('pos_users', 'pos_users_company_username_unique')) {
                $table->dropUnique('pos_users_company_username_unique');
            }
            if (! Schema::hasIndex('pos_users', 'pos_users_username_unique')) {
                $table->unique('username', 'pos_users_username_unique');
            }
        });
    }
};
