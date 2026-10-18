<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_user_shift_sessions', function (Blueprint $table) {
            $table->foreignUuid('till_id')->nullable()->after('branch_id')
                ->constrained('pos_tills')->nullOnDelete();
            $table->index(['company_id', 'branch_id', 'till_id', 'pos_user_id', 'status'], 'shift_sessions_till_open_lookup');
        });

        // Use every recorded till, not the newest sale: conflicting historical
        // evidence must remain unassigned for manual reconciliation.
        $sources = [
            'sales_transactions',
            'sales_transaction_payments',
            'pos_cash_drawer_movements',
            'pos_cashups',
        ];
        DB::table('pos_user_shift_sessions')->orderBy('id')->chunk(200, function ($shifts) use ($sources): void {
            foreach ($shifts as $shift) {
                $ids = [];
                foreach ($sources as $table) {
                    if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'till_id')) {
                        continue;
                    }
                    foreach (DB::table($table)
                        ->where('company_id', $shift->company_id)
                        ->where('branch_id', $shift->branch_id)
                        ->where('shift_session_id', $shift->id)
                        ->whereNotNull('till_id')->distinct()->pluck('till_id') as $id) {
                        $ids[(string) $id] = true;
                    }
                }
                if (count($ids) !== 1) {
                    continue;
                }
                $id = array_key_first($ids);
                if (DB::table('pos_tills')->where('id', $id)
                    ->where('company_id', $shift->company_id)
                    ->where('branch_id', $shift->branch_id)->exists()) {
                    DB::table('pos_user_shift_sessions')->where('id', $shift->id)->update(['till_id' => $id]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_user_shift_sessions', function (Blueprint $table) {
            $table->dropIndex('shift_sessions_till_open_lookup');
            $table->dropConstrainedForeignId('till_id');
        });
    }
};
