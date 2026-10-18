<?php

namespace App\Jobs;

use App\Services\Inventory\InventoryVarianceAgentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ScanInventoryVariance implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 60;
    public int $uniqueFor = 600;

    /** @param array<int, string> $branchIds */
    public function __construct(
        public readonly string $companyId,
        public readonly array $branchIds,
        public readonly ?string $actorId,
        public readonly string $trigger,
        public readonly string $runId
    ) {}

    public function uniqueId(): string
    {
        $branches = $this->branchIds;
        sort($branches);
        return $this->companyId.':'.hash('sha256', implode(',', $branches));
    }

    public function handle(InventoryVarianceAgentService $agent): void
    {
        $agent->scanCompany($this->companyId, $this->branchIds, $this->actorId,
            $this->trigger, $this->runId);
    }
}
