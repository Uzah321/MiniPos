<?php

namespace App\Http\Controllers;

use App\Services\Enterprise\EnterpriseHierarchyService;
use App\Services\Sync\SyncTransportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class SyncPairController extends Controller
{
    public function __construct(
        private readonly SyncProtocolController $cloudProtocol,
        private readonly SyncTransportService $transport,
        private readonly EnterpriseHierarchyService $hierarchy
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $mode = (string) config('salexa_sync.deployment_mode', 'standalone');
        if ($mode === 'cloud') {
            return $this->cloudProtocol->pair($request);
        }
        if ($mode !== 'edge') {
            return response()->json([
                'message' => 'Synchronization is disabled for this standalone installation.',
                'code' => 'SYNC_DISABLED',
            ], 409);
        }

        $validated = $request->validate([
            'company_code' => ['required', 'string', 'max:40'],
            'pairing_code' => ['required', 'string', 'max:255'],
            'cloud_url' => ['required', 'url', 'max:2048'],
            'node_name' => ['nullable', 'string', 'max:255'],
        ]);

        // Pairing applies the first configuration snapshot inline. A fatal
        // max_execution_time error bypasses the configuration-pending
        // fallback and discards the one-time browser credentials after the
        // cloud code was consumed, so give a large catalogue time to apply.
        $timeLimit = (int) ini_get('max_execution_time');
        if ($timeLimit > 0 && $timeLimit < 300 && function_exists('set_time_limit')) {
            set_time_limit(300);
        }

        try {
            $state = $this->transport->pair(
                $validated['cloud_url'],
                $validated['company_code'],
                $validated['pairing_code'],
                $validated['node_name'] ?? null,
                false
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'code' => 'SYNC_PAIR_INVALID'], 422);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'code' => 'SYNC_PAIR_FAILED'], 409);
        }

        $configurationPending = $state->status === 'error'
            && (bool) data_get($state->metadata, 'configuration_pending', false);
        $tillToken = $state->getAttribute('pairing_till_token');
        $localControlToken = $state->getAttribute('pairing_local_control_token');
        $enterpriseHierarchy = $state->till
            ? $this->hierarchy->hierarchyForTill($state->till)
            : ($state->shop ? $this->hierarchy->hierarchyForShop($state->shop) : null);

        return response()->json([
            'message' => $configurationPending
                ? 'The node is paired, but its initial configuration is pending. Use Sync now to retry.'
                : 'This edge installation is paired and configured.',
            'data' => [
                'paired' => true,
                'configuration_pending' => $configurationPending,
                'till_setup_required' => ! $state->till_id,
                'node_id' => (string) $state->node_id,
                'company_id' => (string) $state->company_id,
                'branch_id' => (string) $state->branch_id,
                'shop_id' => $state->shop_id ? (string) $state->shop_id : null,
                'till_id' => $state->till_id ? (string) $state->till_id : null,
                'till' => $state->till?->only([
                    'id', 'company_id', 'branch_id', 'shop_id', 'till_code', 'till_name',
                    'device_label', 'configuration_version', 'is_active',
                ]),
                'hierarchy' => $enterpriseHierarchy,
                'configuration' => $state->till
                    ? $this->hierarchy->configurationManifest($state->till)
                    : null,
                'till_token' => $tillToken,
                'local_control_token' => $localControlToken,
                'cloud_url' => (string) $state->cloud_url,
            ],
        ], $configurationPending ? 202 : 200);
    }
}
