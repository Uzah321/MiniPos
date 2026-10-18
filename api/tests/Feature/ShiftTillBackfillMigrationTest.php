<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShiftTillBackfillMigrationTest extends TestCase
{
    public function test_backfill_uses_unanimous_evidence_and_leaves_ambiguous_and_zero_sale_shifts_unassigned(): void
    {
        Schema::create('pos_tills', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
        });
        Schema::create('pos_user_shift_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('branch_id');
        });
        foreach (['sales_transactions', 'sales_transaction_payments', 'pos_cash_drawer_movements', 'pos_cashups'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('company_id');
                $table->uuid('branch_id');
                $table->uuid('shift_session_id');
                $table->uuid('till_id')->nullable();
            });
        }

        $company = '00000000-0000-0000-0000-000000000201';
        $branch = '00000000-0000-0000-0000-000000000202';
        $tillA = '00000000-0000-0000-0000-000000000203';
        $tillB = '00000000-0000-0000-0000-000000000204';
        $saleShift = '00000000-0000-0000-0000-000000000205';
        $cashupShift = '00000000-0000-0000-0000-000000000206';
        $ambiguousShift = '00000000-0000-0000-0000-000000000207';
        $emptyShift = '00000000-0000-0000-0000-000000000208';
        foreach ([$tillA, $tillB] as $till) {
            DB::table('pos_tills')->insert(['id' => $till, 'company_id' => $company, 'branch_id' => $branch]);
        }
        foreach ([$saleShift, $cashupShift, $ambiguousShift, $emptyShift] as $shift) {
            DB::table('pos_user_shift_sessions')->insert(['id' => $shift, 'company_id' => $company, 'branch_id' => $branch]);
        }
        foreach ([
            ['sales_transactions', $saleShift, $tillA],
            ['pos_cashups', $cashupShift, $tillB],
            ['sales_transactions', $ambiguousShift, $tillA],
            ['pos_cash_drawer_movements', $ambiguousShift, $tillB],
        ] as [$table, $shift, $till]) {
            DB::table($table)->insert([
                'id' => (string) Str::uuid(), 'company_id' => $company,
                'branch_id' => $branch, 'shift_session_id' => $shift, 'till_id' => $till,
            ]);
        }

        $migration = require dirname(__DIR__, 2).'/Modules/Authentication/database/migrations/2026_10_07_160000_bind_shift_sessions_to_tills.php';
        $migration->up();
        self::assertSame($tillA, DB::table('pos_user_shift_sessions')->where('id', $saleShift)->value('till_id'));
        self::assertSame($tillB, DB::table('pos_user_shift_sessions')->where('id', $cashupShift)->value('till_id'));
        self::assertNull(DB::table('pos_user_shift_sessions')->where('id', $ambiguousShift)->value('till_id'));
        self::assertNull(DB::table('pos_user_shift_sessions')->where('id', $emptyShift)->value('till_id'));
    }
}
