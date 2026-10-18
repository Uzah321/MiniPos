<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Only companies that opt in are scanned. Each run queues bounded background
// work; the command itself never traverses a company's stock ledger.
Schedule::command('inventory:variance-scan-queue --max-queued=200')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(10);

Artisan::command('tenancy:audit', function () {
    $systemTables = [
        'cache', 'cache_locks', 'companies', 'failed_jobs', 'jobs', 'job_batches',
        'migrations', 'password_reset_tokens', 'permissions', 'personal_access_tokens',
        'sessions', 'users', 'tenant_orphan_archives',
    ];

    $tables = collect(DB::select("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"))
        ->pluck('TABLE_NAME');
    $failures = [];

    foreach ($tables as $table) {
        if (in_array($table, $systemTables, true)) continue;

        $hasCompany = (bool) DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, 'company_id']
        )->aggregate;
        if (!$hasCompany) {
            $failures[] = "{$table}: missing company_id";
            continue;
        }

        $hasIndex = (bool) DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, 'company_id']
        )->aggregate;
        if (!$hasIndex) $failures[] = "{$table}: company_id is not indexed";

        $orphans = DB::table($table . ' as tenant_rows')
            ->leftJoin('companies as tenant_companies', 'tenant_companies.id', '=', 'tenant_rows.company_id')
            ->whereNotNull('tenant_rows.company_id')
            ->whereNull('tenant_companies.id')
            ->count();
        if ($orphans > 0) $failures[] = "{$table}: {$orphans} orphaned tenant rows";
    }

    if ($failures) {
        $this->error('Tenant isolation audit failed.');
        foreach ($failures as $failure) $this->line(' - ' . $failure);
        return self::FAILURE;
    }

    $this->info('Tenant isolation audit passed: every company-owned table has an indexed company_id and no orphaned tenant rows.');
    return self::SUCCESS;
})->purpose('Verify company ownership columns, indexes and tenant referential integrity');
