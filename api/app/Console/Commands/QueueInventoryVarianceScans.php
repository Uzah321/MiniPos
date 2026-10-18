<?php

namespace App\Console\Commands;

use App\Services\Inventory\InventoryVarianceAgentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class QueueInventoryVarianceScans extends Command
{
    protected $signature = 'inventory:variance-scan-queue {--max-queued=200 : Maximum company scans to queue in one run}';

    protected $description = 'Queue due Inventory Variance Agent scans for enabled companies';

    public function handle(InventoryVarianceAgentService $agent): int
    {
        if (config('salexa_sync.deployment_mode') === 'edge') {
            return self::SUCCESS;
        }

        $limit = min(1000, max(1, (int) $this->option('max-queued')));
        $queued = 0;
        $enabledCompanies = DB::table('general_settings as s')
            ->join('companies as c', 'c.id', '=', 's.company_id')
            ->where('s.setting_key', 'inventory_variance_agent_enabled')
            ->where('s.is_active', true)->whereNull('s.deleted_at')
            ->whereNull('s.branch_id')->whereNull('c.deleted_at')
            ->whereRaw("LOWER(TRIM(s.setting_value)) IN ('true', '1', 'yes', 'on')")
            ->select('s.company_id')->distinct()->orderBy('s.company_id');

        foreach ($enabledCompanies->cursor() as $company) {
            $companyId = (string) $company->company_id;
            $branchIds = DB::table('company_branches')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->orderBy('id')->pluck('id')->map(fn ($id) => (string) $id)->all();
            if ($branchIds === []) {
                continue;
            }
            if ($agent->queueScan($companyId, $branchIds, null, 'scheduled')) {
                $queued++;
            }
            if ($queued >= $limit) {
                break;
            }
        }

        $this->info("Queued {$queued} inventory variance scan(s).");
        return self::SUCCESS;
    }
}
