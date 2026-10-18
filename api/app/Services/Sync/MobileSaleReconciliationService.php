<?php

namespace App\Services\Sync;

use App\Models\MobileSaleReconciliation;
use App\Models\SyncNode;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Append-only evidence for an offline receipt that cannot enter closed books. */
class MobileSaleReconciliationService
{
    public function stage(
        SyncNode $node,
        string $cashierId,
        string $clientSaleId,
        string $businessDate,
        CarbonInterface $occurredAt,
        MobileSaleSnapshot $snapshot,
        array $command,
        string $reasonCode,
        array $context = []
    ): MobileSaleReconciliation {
        $canonical = $this->canonical($command);
        $hash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return DB::transaction(function () use ($node, $cashierId, $clientSaleId, $businessDate, $occurredAt, $snapshot, $canonical, $hash, $reasonCode, $context): MobileSaleReconciliation {
            // Serialize staging for this physical node before the unique
            // company/client key is checked, including concurrent retries.
            SyncNode::query()->whereKey($node->id)->lockForUpdate()->firstOrFail();
            $existing = MobileSaleReconciliation::query()
                ->where('company_id', $node->company_id)
                ->where('client_sale_id', $clientSaleId)
                ->lockForUpdate()->first();
            if ($existing) {
                if (! hash_equals((string) $existing->command_sha256, $hash)
                    || (string) $existing->sync_node_id !== (string) $node->id
                    || (string) $existing->till_id !== (string) $node->till_id
                    || (string) $existing->cashier_id !== $cashierId) {
                    throw new \InvalidArgumentException('A different queued sale already uses this client sale ID.');
                }
                return $existing;
            }

            return MobileSaleReconciliation::query()->create([
                'id' => (string) Str::uuid(),
                'company_id' => $node->company_id,
                'branch_id' => $node->branch_id,
                'till_id' => $node->till_id,
                'sync_node_id' => $node->id,
                'cashier_id' => $cashierId,
                'client_sale_id' => $clientSaleId,
                'business_date' => $businessDate,
                'occurred_at' => $occurredAt,
                'configuration_content_hash' => $snapshot->contentHash(),
                'command_sha256' => $hash,
                'reason_code' => $reasonCode,
                'status' => 'pending',
                // HMAC node authentication and acknowledged snapshot were
                // verified upstream. This raw command is evidence only: it is
                // NOT a posted sale or verified fiscal receipt.
                'command_payload' => $canonical,
                'context' => $context,
            ]);
        });
    }

    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->canonical($entry);
            }
        }
        return $value;
    }
}
