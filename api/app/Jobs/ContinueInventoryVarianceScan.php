<?php

namespace App\Jobs;

use App\Services\Inventory\InventoryVarianceAgentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class ContinueInventoryVarianceScan implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 60;

    /** @param array<int, string> $branchIds */
    public function __construct(
        public readonly string $companyId,
        public readonly array $branchIds,
        public readonly ?string $actorId,
        public readonly string $trigger,
        public readonly string $runId,
        public readonly string $lastStockId
    ) {}

    public function handle(InventoryVarianceAgentService $agent): void
    {
        $agent->scanCompany($this->companyId, $this->branchIds, $this->actorId,
            $this->trigger, $this->runId, $this->lastStockId);
    }

    public function failed(?Throwable $exception): void
    {
        DB::table('inventory_variance_scan_runs')
            ->where('id', $this->runId)->where('company_id', $this->companyId)
            ->where('status', 'running')->update([
                'status' => 'failed',
                'error_message' => mb_substr($exception?->getMessage() ?? 'Scan continuation failed.', 0, 2000),
                'completed_at' => now(), 'updated_at' => now(),
            ]);
    }
}
