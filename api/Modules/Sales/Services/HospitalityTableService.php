<?php

namespace Modules\Sales\Services;

use App\Services\Enterprise\EnterpriseHierarchyService;
use App\Services\Enterprise\HospitalityMenuPriceService;
use DomainException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authentication\Models\PosUser;
use Modules\Authentication\Services\EnterpriseAuthorizationService;
use Modules\Authentication\Services\EnterpriseScopeResolver;
use Modules\Company\Models\Shop;
use Modules\GeneralSettings\Models\PosTill;
use Modules\Sales\Exceptions\HospitalityTableAuthorizationException;
use Modules\Sales\Exceptions\HospitalityTableConflictException;
use Modules\Sales\Exceptions\HospitalityTableScopeException;
use Modules\Sales\Models\HospitalityTable;
use Modules\Sales\Models\HospitalityTableOrder;
use Modules\Sales\Models\HospitalityTableOrderEvent;
use Modules\Sales\Models\HospitalityTableOrderLine;
use Modules\Sales\Models\HospitalityTableSetting;
use Modules\Sales\Models\SalesTransaction;

final class HospitalityTableService
{
    public function __construct(
        private readonly EnterpriseAuthorizationService $authorization,
        private readonly EnterpriseScopeResolver $scopes,
        private readonly KitchenPrintDispatchService $kitchenPrints,
        private readonly EnterpriseHierarchyService $hierarchy,
        private readonly HospitalityMenuPriceService $menuPrices,
    ) {}

    /** @return array{settings: array<string, mixed>, tables: EloquentCollection<int, HospitalityTable>} */
    public function snapshot(string $companyId, PosTill $till): array
    {
        $shop = $this->shopForTill($companyId, $till);
        $settings = $this->settings($companyId, (string) $till->branch_id, (string) $shop->id);
        $tables = HospitalityTable::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $till->branch_id)
            ->where('shop_id', $shop->id)
            ->where('is_active', true)
            ->with(['activeOrder' => fn ($query) => $query
                ->select([
                    'id', 'active_table_id', 'till_id', 'last_till_id', 'service_type',
                    'status', 'revision', 'guest_count', 'display_label', 'currency_code',
                    'content_revision', 'kitchen_status', 'last_kitchen_content_revision',
                    'kitchen_sent_at', 'total_amount', 'last_activity_at',
                ])
                ->whereIn('status', HospitalityTableOrder::ACTIVE_STATUSES)])
            ->orderBy('section')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return ['settings' => $settings, 'tables' => $tables];
    }

    /** @return EloquentCollection<int, HospitalityTableOrder> */
    public function openOrders(
        string $companyId,
        PosTill $till,
        array $statuses = HospitalityTableOrder::ACTIVE_STATUSES
    ): EloquentCollection {
        $shop = $this->shopForTill($companyId, $till);
        $settings = $this->settings($companyId, (string) $till->branch_id, (string) $shop->id);
        $statuses = array_values(array_intersect($statuses, HospitalityTableOrder::ACTIVE_STATUSES));
        if ($statuses === []) {
            $statuses = HospitalityTableOrder::ACTIVE_STATUSES;
        }

        return HospitalityTableOrder::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $till->branch_id)
            ->where('shop_id', $shop->id)
            ->whereIn('status', $statuses)
            ->when(! $settings['allow_cross_till_recall'], fn ($query) => $query->where('last_till_id', $till->id))
            ->with(['table:id,code,name,section,capacity', 'lines'])
            ->orderByDesc('last_activity_at')
            ->get();
    }

    /** @return array{order: HospitalityTableOrder, replayed: bool} */
    public function openOrder(
        string $companyId,
        PosTill $till,
        PosUser $actor,
        array $payload
    ): array {
        $shop = $this->shopForTill($companyId, $till);
        $cart = $this->cart($payload['items'], $companyId, (string) $till->branch_id, $shop);

        return DB::transaction(function () use ($companyId, $till, $actor, $payload, $shop, $cart): array {
            $existing = HospitalityTableOrder::query()
                ->where('company_id', $companyId)
                ->where('client_request_id', $payload['client_request_id'])
                ->lockForUpdate()
                ->first();
            if ($existing) {
                $this->assertSameOpenRequest($existing, $till, $shop, $payload, $cart['hash']);

                return ['order' => $existing->load(['table', 'lines']), 'replayed' => true];
            }

            $this->lockShop($companyId, $shop);
            $settings = $this->settings($companyId, (string) $till->branch_id, (string) $shop->id);
            if (! $settings['is_active']) {
                throw new DomainException('Table service is disabled for this shop.');
            }
            $serviceType = (string) $payload['service_type'];
            $table = $this->allocateTable(
                $companyId,
                $till,
                $shop,
                $settings,
                $serviceType,
                $payload['assignment_mode'] ?? null,
                $payload['table_id'] ?? null,
                (int) ($payload['guest_count'] ?? 1)
            );
            $now = now();

            try {
                $order = HospitalityTableOrder::query()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $companyId,
                    'branch_id' => $till->branch_id,
                    'shop_id' => $shop->id,
                    'till_id' => $till->id,
                    'last_till_id' => $till->id,
                    'hospitality_table_id' => $table?->id,
                    'active_table_id' => $table?->id,
                    'client_request_id' => $payload['client_request_id'],
                    'service_type' => $serviceType,
                    'status' => 'open',
                    'revision' => 1,
                    'content_revision' => 1,
                    'kitchen_status' => 'pending',
                    'guest_count' => (int) ($payload['guest_count'] ?? 1),
                    'display_label' => $payload['display_label'] ?? null,
                    'notes' => $payload['notes'] ?? null,
                    'cart_hash' => $cart['hash'],
                    'currency_code' => strtoupper((string) $payload['currency_code']),
                    'subtotal_amount' => $cart['subtotal'],
                    'discount_amount' => $cart['discount'],
                    'tax_amount' => $cart['tax'],
                    'total_amount' => $cart['total'],
                    'opened_by' => $actor->id,
                    'last_updated_by' => $actor->id,
                    'opened_at' => $now,
                    'last_activity_at' => $now,
                ]);
            } catch (QueryException $exception) {
                if (! $this->isUniqueViolation($exception)) {
                    throw $exception;
                }

                throw new HospitalityTableConflictException(
                    'The selected table was assigned by another terminal. Refresh the table list and try again.'
                );
            }

            $this->replaceLines($order, $cart['lines']);
            $this->event($order, $till, $actor, 'opened', null, 'open', $payload['command_id'] ?? null, [
                'assignment_mode' => $payload['assignment_mode'] ?? $settings['assignment_mode'],
                'table_id' => $table?->id,
            ]);

            return ['order' => $order->load(['table', 'lines']), 'replayed' => false];
        }, 3);
    }

    public function order(string $companyId, PosTill $till, string $orderId): HospitalityTableOrder
    {
        $shop = $this->shopForTill($companyId, $till);
        $settings = $this->settings($companyId, (string) $till->branch_id, (string) $shop->id);

        $order = $this->scopedOrder($companyId, $till, $shop, $orderId)
            ->with(['table', 'lines', 'events' => fn ($query) => $query->latest()->limit(30)])
            ->firstOrFail();
        $this->assertTillRecallAllowed($order, $till, $settings);

        return $order;
    }

    /** @return array{order: HospitalityTableOrder, replayed: bool} */
    public function updateOrder(
        string $companyId,
        PosTill $till,
        PosUser $actor,
        string $orderId,
        int $expectedRevision,
        array $payload
    ): array {
        $shop = $this->shopForTill($companyId, $till);
        $cart = $this->cart($payload['items'], $companyId, (string) $till->branch_id, $shop);

        return DB::transaction(function () use (
            $companyId, $till, $actor, $orderId, $expectedRevision, $payload, $shop, $cart
        ): array {
            $this->lockShop($companyId, $shop);
            $order = $this->lockScopedOrder($companyId, $till, $shop, $orderId);
            if ($replayed = $this->commandReplay($order, $payload['command_id'] ?? null, 'updated')) {
                return ['order' => $replayed, 'replayed' => true];
            }
            $this->assertMutable($order);
            $this->assertRevision($order, $expectedRevision);

            $settings = $this->settings($companyId, (string) $till->branch_id, (string) $shop->id);
            $this->assertTillRecallAllowed($order, $till, $settings);
            $serviceType = (string) ($payload['service_type'] ?? $order->service_type);
            $guestCount = (int) ($payload['guest_count'] ?? $order->guest_count);
            $assignmentRequested = array_key_exists('table_id', $payload)
                || array_key_exists('assignment_mode', $payload)
                || $serviceType !== $order->service_type
                || $guestCount !== (int) $order->guest_count;
            $table = $assignmentRequested
                ? $this->allocateTable(
                    $companyId,
                    $till,
                    $shop,
                    $settings,
                    $serviceType,
                    $payload['assignment_mode'] ?? null,
                    $payload['table_id'] ?? null,
                    $guestCount,
                    $order->active_table_id
                )
                : $order->table;

            $order->forceFill([
                'last_till_id' => $till->id,
                'hospitality_table_id' => $table?->id,
                'active_table_id' => $table?->id,
                'service_type' => $serviceType,
                'revision' => $order->revision + 1,
                'content_revision' => $order->content_revision + 1,
                'kitchen_status' => 'pending',
                'guest_count' => $guestCount,
                'display_label' => array_key_exists('display_label', $payload)
                    ? $payload['display_label']
                    : $order->display_label,
                'notes' => array_key_exists('notes', $payload) ? $payload['notes'] : $order->notes,
                'cart_hash' => $cart['hash'],
                'currency_code' => strtoupper((string) ($payload['currency_code'] ?? $order->currency_code)),
                'subtotal_amount' => $cart['subtotal'],
                'discount_amount' => $cart['discount'],
                'tax_amount' => $cart['tax'],
                'total_amount' => $cart['total'],
                'last_updated_by' => $actor->id,
                'last_activity_at' => now(),
            ])->save();

            $this->replaceLines($order, $cart['lines']);
            $this->event(
                $order,
                $till,
                $actor,
                'updated',
                $order->status,
                $order->status,
                $payload['command_id'] ?? null,
                ['table_id' => $table?->id, 'cart_hash' => $cart['hash']]
            );

            return ['order' => $order->load(['table', 'lines']), 'replayed' => false];
        }, 3);
    }

    /** @return array{order: HospitalityTableOrder, replayed: bool} */
    public function transition(
        string $companyId,
        PosTill $till,
        PosUser $actor,
        string $orderId,
        int $expectedRevision,
        string $toStatus,
        ?string $commandId = null
    ): array {
        if (! in_array($toStatus, ['open', 'hanging'], true)) {
            throw new DomainException('Unsupported table order transition.');
        }
        $eventType = $toStatus === 'open' ? 'recalled' : 'hung';
        $shop = $this->shopForTill($companyId, $till);

        return DB::transaction(function () use (
            $companyId, $till, $actor, $orderId, $expectedRevision, $toStatus, $commandId, $eventType, $shop
        ): array {
            $order = $this->lockScopedOrder($companyId, $till, $shop, $orderId);
            if ($replayed = $this->commandReplay($order, $commandId, $eventType)) {
                return ['order' => $replayed, 'replayed' => true];
            }
            $this->assertMutable($order);
            $this->assertRevision($order, $expectedRevision);
            $settings = $this->settings($companyId, (string) $till->branch_id, (string) $shop->id);
            $this->assertTillRecallAllowed($order, $till, $settings);
            $fromStatus = $order->status;

            $order->forceFill([
                'status' => $toStatus,
                'last_till_id' => $till->id,
                'revision' => $order->revision + 1,
                'last_updated_by' => $actor->id,
                'last_activity_at' => now(),
            ])->save();
            $this->event($order, $till, $actor, $eventType, $fromStatus, $toStatus, $commandId);

            return ['order' => $order->load(['table', 'lines']), 'replayed' => false];
        }, 3);
    }

    /** @return array{order: HospitalityTableOrder, dispatch: array<string, mixed>, replayed: bool} */
    public function sendToKitchen(
        string $companyId,
        PosTill $till,
        PosUser $actor,
        string $orderId,
        int $expectedRevision,
        string $commandId
    ): array {
        $shop = $this->shopForTill($companyId, $till);

        return DB::transaction(function () use (
            $companyId, $till, $actor, $orderId, $expectedRevision, $commandId, $shop
        ): array {
            $order = $this->lockScopedOrder($companyId, $till, $shop, $orderId);
            $existingEvent = HospitalityTableOrderEvent::query()
                ->where('table_order_id', $order->id)
                ->where('command_id', $commandId)
                ->first();
            if ($existingEvent && $existingEvent->event_type !== 'sent_to_kitchen') {
                throw new HospitalityTableConflictException(
                    'This command id was already used for another table operation.'
                );
            }
            if ($existingEvent) {
                $dispatch = $this->kitchenPrints->enqueueTableOrder(
                    $order->load(['table', 'lines']),
                    $till,
                    $actor,
                    $commandId
                );

                return [
                    'order' => $order->fresh(['table', 'lines']),
                    'dispatch' => $dispatch,
                    'replayed' => true,
                ];
            }

            $this->assertMutable($order);
            $this->assertRevision($order, $expectedRevision);
            $settings = $this->settings($companyId, (string) $till->branch_id, (string) $shop->id);
            $this->assertTillRecallAllowed($order, $till, $settings);
            $dispatch = $this->kitchenPrints->enqueueTableOrder(
                $order->load(['table', 'lines']),
                $till,
                $actor,
                $commandId
            );

            $alreadySent = (int) $order->last_kitchen_content_revision === (int) $order->content_revision;
            $fromStatus = $order->status;
            if (! $alreadySent) {
                $jobStatus = (string) data_get($dispatch, 'job.status', 'pending');
                $order->forceFill([
                    'revision' => $order->revision + 1,
                    // This records durable dispatch, not physical printing.
                    // The QZ acknowledgement endpoint advances it to printed
                    // or failed for the matching content revision.
                    'kitchen_status' => $jobStatus === 'pending_configuration'
                        ? 'pending_configuration'
                        : 'queued',
                    'last_kitchen_content_revision' => $order->content_revision,
                    'kitchen_sent_at' => now(),
                    'last_till_id' => $till->id,
                    'last_updated_by' => $actor->id,
                    'last_activity_at' => now(),
                ])->save();
            }
            $this->event(
                $order,
                $till,
                $actor,
                'sent_to_kitchen',
                $fromStatus,
                $fromStatus,
                $commandId,
                [
                    'content_revision' => $order->content_revision,
                    'already_sent' => $alreadySent,
                    'queued_job_ids' => array_values(array_filter([
                        data_get($dispatch, 'job.id'),
                    ])),
                ]
            );

            return [
                'order' => $order->fresh(['table', 'lines']),
                'dispatch' => $dispatch,
                'replayed' => $alreadySent,
            ];
        }, 3);
    }

    /** @return array{order: HospitalityTableOrder, replayed: bool} */
    public function clearOrder(
        string $companyId,
        PosTill $till,
        PosUser $actor,
        string $orderId,
        int $expectedRevision,
        string $reason,
        ?string $managerApprovalId = null,
        ?string $commandId = null
    ): array {
        $shop = $this->shopForTill($companyId, $till);

        return DB::transaction(function () use (
            $companyId, $till, $actor, $orderId, $expectedRevision, $reason,
            $managerApprovalId, $commandId, $shop
        ): array {
            $order = $this->lockScopedOrder($companyId, $till, $shop, $orderId);
            if ($replayed = $this->commandReplay($order, $commandId, 'cleared')) {
                return ['order' => $replayed, 'replayed' => true];
            }
            $this->assertMutable($order);
            $this->assertRevision($order, $expectedRevision);
            $settings = $this->settings($companyId, (string) $till->branch_id, (string) $shop->id);
            $this->assertTillRecallAllowed($order, $till, $settings);
            $approvalId = $this->authorizeClear($companyId, $till, $actor, $order, $managerApprovalId);
            $fromStatus = $order->status;

            $order->forceFill([
                'status' => 'cleared',
                'active_table_id' => null,
                'last_till_id' => $till->id,
                'revision' => $order->revision + 1,
                'last_updated_by' => $actor->id,
                'cleared_by' => $actor->id,
                'manager_approval_id' => $approvalId,
                'clear_reason' => $reason,
                'cleared_at' => now(),
                'last_activity_at' => now(),
            ])->save();
            $this->event(
                $order,
                $till,
                $actor,
                'cleared',
                $fromStatus,
                'cleared',
                $commandId,
                ['reason' => $reason],
                $approvalId
            );

            return ['order' => $order->load(['table', 'lines']), 'replayed' => false];
        }, 3);
    }

    public function claimForCheckout(
        string $companyId,
        PosTill $till,
        PosUser $actor,
        string $orderId,
        int $expectedRevision,
        array $saleProducts
    ): HospitalityTableOrder {
        $shop = $this->shopForTill($companyId, $till);
        $order = $this->lockScopedOrder($companyId, $till, $shop, $orderId);
        $this->assertMutable($order);
        $this->assertRevision($order, $expectedRevision);
        $settings = $this->settings($companyId, (string) $till->branch_id, (string) $shop->id);
        $this->assertTillRecallAllowed($order, $till, $settings);
        $this->assertCheckoutProductsMatch($order, $saleProducts);
        $fromStatus = $order->status;

        $order->forceFill([
            'status' => 'processing',
            'last_till_id' => $till->id,
            'revision' => $order->revision + 1,
            'last_updated_by' => $actor->id,
            'last_activity_at' => now(),
        ])->save();
        $this->event($order, $till, $actor, 'checkout_started', $fromStatus, 'processing');

        return $order->load('table');
    }

    public function completeCheckout(
        HospitalityTableOrder $order,
        SalesTransaction $sale,
        PosTill $till,
        PosUser $actor
    ): HospitalityTableOrder {
        $locked = HospitalityTableOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== 'processing') {
            throw new HospitalityTableConflictException('The table order is no longer being checked out.');
        }
        if ((string) $locked->company_id !== (string) $sale->company_id
            || (string) $locked->branch_id !== (string) $sale->branch_id
            || (string) $sale->till_id !== (string) $till->id) {
            throw new HospitalityTableScopeException('The sale and table order do not share the same company, shop and till scope.');
        }

        $locked->forceFill([
            'status' => 'completed',
            'active_table_id' => null,
            'sales_transaction_id' => $sale->id,
            'last_till_id' => $till->id,
            'revision' => $locked->revision + 1,
            'last_updated_by' => $actor->id,
            'completed_at' => now(),
            'last_activity_at' => now(),
        ])->save();
        $this->event($locked, $till, $actor, 'completed', 'processing', 'completed', null, [
            'sales_transaction_id' => $sale->id,
            'invoice_number' => $sale->invoice_number,
        ]);

        return $locked->load('table');
    }

    /** @return array<string, mixed> */
    public function managementSettings(string $companyId, string $branchId, string $shopId): array
    {
        $shop = $this->shopForManagementScope($companyId, $branchId, $shopId);

        return $this->settings($companyId, $branchId, (string) $shop->id);
    }

    public function saveManagementSettings(
        string $companyId,
        string $branchId,
        string $shopId,
        array $payload
    ): HospitalityTableSetting {
        $shop = $this->shopForManagementScope($companyId, $branchId, $shopId);

        return DB::transaction(function () use ($companyId, $branchId, $payload, $shop): HospitalityTableSetting {
            $this->lockShop($companyId, $shop);

            return HospitalityTableSetting::query()->updateOrCreate(
                ['company_id' => $companyId, 'shop_id' => $shop->id],
                [
                    'branch_id' => $branchId,
                    'shop_id' => $shop->id,
                    'assignment_mode' => $payload['assignment_mode'],
                    'require_table_for_dine_in' => (bool) $payload['require_table_for_dine_in'],
                    'allow_cross_till_recall' => (bool) $payload['allow_cross_till_recall'],
                    'is_active' => (bool) ($payload['is_active'] ?? true),
                    'metadata' => $payload['metadata'] ?? null,
                ]
            );
        });
    }

    /** @return EloquentCollection<int, HospitalityTable> */
    public function managementTables(string $companyId, string $branchId, string $shopId): EloquentCollection
    {
        $shop = $this->shopForManagementScope($companyId, $branchId, $shopId);

        return HospitalityTable::withTrashed()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('shop_id', $shop->id)
            ->with('activeOrder')
            ->orderBy('section')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function createManagementTable(
        string $companyId,
        string $branchId,
        string $shopId,
        array $payload
    ): HospitalityTable {
        $shop = $this->shopForManagementScope($companyId, $branchId, $shopId);

        return DB::transaction(function () use ($companyId, $branchId, $payload, $shop): HospitalityTable {
            $this->lockShop($companyId, $shop);
            $payload['code'] = trim((string) $payload['code']);
            $duplicate = HospitalityTable::withTrashed()
                ->where('shop_id', $shop->id)
                ->where('code', $payload['code'])
                ->exists();
            if ($duplicate) {
                throw new HospitalityTableConflictException('A table with this code already exists in the shop.');
            }

            try {
                return HospitalityTable::query()->create(array_merge($payload, [
                    'id' => (string) Str::uuid(),
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'shop_id' => $shop->id,
                ]));
            } catch (QueryException $exception) {
                if (! $this->isUniqueViolation($exception)) {
                    throw $exception;
                }

                throw new HospitalityTableConflictException(
                    'A table with this code already exists in the shop.'
                );
            }
        }, 3);
    }

    public function updateManagementTable(
        string $companyId,
        string $branchId,
        string $shopId,
        string $tableId,
        array $payload
    ): HospitalityTable {
        $shop = $this->shopForManagementScope($companyId, $branchId, $shopId);

        return DB::transaction(function () use ($companyId, $branchId, $tableId, $payload, $shop): HospitalityTable {
            $this->lockShop($companyId, $shop);
            $table = HospitalityTable::withTrashed()
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('shop_id', $shop->id)
                ->whereKey($tableId)
                ->lockForUpdate()
                ->firstOrFail();
            if (isset($payload['code'])) {
                $payload['code'] = trim((string) $payload['code']);
                $duplicate = HospitalityTable::withTrashed()
                    ->where('shop_id', $shop->id)
                    ->where('code', $payload['code'])
                    ->where('id', '!=', $table->id)
                    ->exists();
                if ($duplicate) {
                    throw new HospitalityTableConflictException('A table with this code already exists in the shop.');
                }
            }
            $occupied = HospitalityTableOrder::query()
                ->where('active_table_id', $table->id)
                ->whereIn('status', HospitalityTableOrder::ACTIVE_STATUSES)
                ->exists();
            if ($occupied && (array_key_exists('is_active', $payload) && ! $payload['is_active'])) {
                throw new HospitalityTableConflictException('An occupied table cannot be disabled.');
            }

            try {
                $table->fill($payload)->save();
            } catch (QueryException $exception) {
                if (! $this->isUniqueViolation($exception)) {
                    throw $exception;
                }

                throw new HospitalityTableConflictException(
                    'A table with this code already exists in the shop.'
                );
            }

            return $table->fresh('activeOrder');
        });
    }

    /** @return array<string, mixed> */
    private function settings(string $companyId, string $branchId, string $shopId): array
    {
        $row = HospitalityTableSetting::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('shop_id', $shopId)
            ->first();

        return [
            'id' => $row?->id,
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'shop_id' => $shopId,
            'assignment_mode' => $row?->assignment_mode ?? 'auto',
            'require_table_for_dine_in' => $row?->require_table_for_dine_in ?? true,
            'takeaway_uses_tables' => false,
            'allow_cross_till_recall' => $row?->allow_cross_till_recall ?? true,
            'is_active' => $row?->is_active ?? true,
            'metadata' => $row?->metadata,
        ];
    }

    private function shopForTill(string $companyId, PosTill $till): Shop
    {
        if ((string) $till->company_id !== $companyId || ! $till->is_active || $till->trashed()) {
            throw new HospitalityTableScopeException('The resolved till is not active in this company.');
        }

        $query = Shop::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $till->branch_id)
            ->where('status', 'active');
        if ($till->shop_id) {
            $query->whereKey($till->shop_id);
        }
        $shop = $query->first();
        if (! $shop) {
            throw new HospitalityTableScopeException('The till is not attached to an active canonical shop.');
        }

        return $shop;
    }

    private function shopForManagementScope(string $companyId, string $branchId, string $shopId): Shop
    {
        $shop = Shop::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereKey($shopId)
            ->first();
        if (! $shop) {
            throw new HospitalityTableScopeException(
                'The selected shop is not attached to this branch and company.'
            );
        }

        return $shop;
    }

    private function lockShop(string $companyId, Shop $shop): void
    {
        $locked = DB::table('shops')
            ->where('company_id', $companyId)
            ->where('id', $shop->id)
            ->where('branch_id', $shop->branch_id)
            ->lockForUpdate()
            ->value('id');
        if (! $locked) {
            throw new HospitalityTableScopeException('The shop is no longer available.');
        }
    }

    private function allocateTable(
        string $companyId,
        PosTill $till,
        Shop $shop,
        array $settings,
        string $serviceType,
        ?string $requestedMode,
        ?string $requestedTableId,
        int $guestCount,
        ?string $currentTableId = null
    ): ?HospitalityTable {
        // Takeaway still has a durable open/hanging order, but it never
        // consumes a physical dining table.
        $usesTable = $serviceType === 'dine_in'
            && (bool) $settings['require_table_for_dine_in'];
        if (! $usesTable) {
            return null;
        }

        $mode = $requestedMode ?: $settings['assignment_mode'];
        $base = HospitalityTable::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $till->branch_id)
            ->where('shop_id', $shop->id)
            ->where('table_type', 'dining')
            ->where('is_active', true);

        if ($mode === 'manual') {
            $requestedTableId = $requestedTableId ?: $currentTableId;
            if (! $requestedTableId) {
                throw new DomainException('A table must be selected because manual assignment is configured.');
            }
            $table = (clone $base)->whereKey($requestedTableId)->lockForUpdate()->first();
            if (! $table) {
                throw new HospitalityTableScopeException('The selected table is not active in this shop.');
            }
            if ((int) $table->capacity < max(1, $guestCount)) {
                throw new DomainException('The selected table does not have enough seats for this guest count.');
            }
            $occupied = HospitalityTableOrder::query()
                ->where('active_table_id', $table->id)
                ->when($currentTableId, fn ($query) => $query->where('active_table_id', '!=', $currentTableId))
                ->whereIn('status', HospitalityTableOrder::ACTIVE_STATUSES)
                ->exists();
            if ($occupied && (string) $currentTableId !== (string) $table->id) {
                throw new HospitalityTableConflictException('The selected table already has an open order.');
            }

            return $table;
        }

        $table = (clone $base)
            ->where('capacity', '>=', max(1, $guestCount))
            ->whereDoesntHave('activeOrder', fn ($query) => $query
                ->whereIn('status', HospitalityTableOrder::ACTIVE_STATUSES)
                ->when($currentTableId, fn ($active) => $active->where('active_table_id', '!=', $currentTableId)))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->lockForUpdate()
            ->first();
        if (! $table) {
            throw new HospitalityTableConflictException('No available table can be assigned automatically.');
        }

        return $table;
    }

    /** @return array{lines: array<int, array<string, mixed>>, subtotal: float, discount: float, tax: float, total: float, hash: string} */
    private function cart(array $items, string $companyId, string $branchId, Shop $shop): array
    {
        if ($items === []) {
            throw new DomainException('At least one cart item is required to open or update a table order.');
        }

        $productIds = collect($items)->pluck('product_id')->map('strval')->unique()->values();
        $channel = (string) config('servnest.catalogue.default_channel', 'POS');
        $products = $this->hierarchy->visibleMenuQuery($shop, $channel)
            ->reorder()
            ->whereIn('products.id', $productIds)
            ->select(['products.id', 'products.name'])
            ->distinct()
            ->get()
            ->keyBy('id');
        if ($products->count() !== $productIds->count()) {
            throw new HospitalityTableScopeException(
                'One or more cart products are not currently visible for this shop, brand and POS channel.'
            );
        }

        $subtotal = $discount = $tax = $total = 0.0;
        $lines = [];
        foreach (array_values($items) as $index => $item) {
            $productId = (string) $item['product_id'];
            if (isset($item['menu_item_channel_id'], $item['product_price_id'])
                && (string) $item['menu_item_channel_id'] !== (string) $item['product_price_id']) {
                throw new DomainException('The cart line contains conflicting Hospitality price identifiers.');
            }
            $menuItemChannelId = isset($item['menu_item_channel_id'])
                ? (string) $item['menu_item_channel_id']
                : (isset($item['product_price_id']) ? (string) $item['product_price_id'] : null);
            try {
                $menuPrice = $this->menuPrices->resolve(
                    companyId: $companyId,
                    branchId: $branchId,
                    productId: $productId,
                    selectedPriceId: $menuItemChannelId,
                    channel: $channel,
                    shopId: (string) $shop->id
                );
            } catch (\InvalidArgumentException $exception) {
                throw new HospitalityTableScopeException($exception->getMessage(), previous: $exception);
            }
            $menuItemChannelId = (string) $menuPrice->menu_item_channel_id;
            $quantity = round((float) $item['quantity'], 4);
            $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
            $unitPrice = round((float) $menuPrice->price + $this->modifierUplift($metadata), 4);
            if (abs($unitPrice - round((float) $item['unit_price'], 4)) > 0.0001) {
                throw new HospitalityTableConflictException(
                    'A cart line price changed. Refresh the menu and apply the item again.'
                );
            }
            $lineSubtotal = round($quantity * $unitPrice, 4);
            $lineDiscount = round(min((float) ($item['line_discount'] ?? 0), $lineSubtotal), 4);
            $lineTax = round((float) ($item['line_tax'] ?? 0), 4);
            $lineTotal = round(max(0, $lineSubtotal - $lineDiscount + $lineTax), 4);
            $lines[] = [
                'id' => (string) Str::uuid(),
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'product_id' => $productId,
                'menu_item_channel_id' => $menuItemChannelId,
                'line_no' => $index + 1,
                'line_name' => trim((string) ($item['name'] ?? '')) ?: (string) $products[$productId]->name,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_subtotal' => $lineSubtotal,
                'line_discount' => $lineDiscount,
                'line_tax' => $lineTax,
                'line_total' => $lineTotal,
                'metadata' => $metadata ?: null,
            ];
            $subtotal += $lineSubtotal;
            $discount += $lineDiscount;
            $tax += $lineTax;
            $total += $lineTotal;
        }

        $hashable = collect($lines)->map(fn (array $line) => collect($line)
            ->except(['id', 'company_id', 'branch_id'])
            ->all())->all();

        return [
            'lines' => $lines,
            'subtotal' => round($subtotal, 4),
            'discount' => round($discount, 4),
            'tax' => round($tax, 4),
            'total' => round($total, 4),
            'hash' => hash('sha256', json_encode($hashable, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)),
        ];
    }

    private function modifierUplift(array $metadata): float
    {
        $modifiers = data_get(
            $metadata,
            'pos_line.modifiers',
            data_get($metadata, 'kitchen.modifiers', data_get($metadata, 'modifiers', []))
        );

        return round(collect(is_array($modifiers) ? $modifiers : [])
            ->filter(fn ($modifier): bool => is_array($modifier))
            ->sum(fn (array $modifier): float => round(
                max(0, (float) ($modifier['price'] ?? 0))
                * max(1, (float) ($modifier['quantity'] ?? 1)),
                4
            )), 4);
    }

    private function replaceLines(HospitalityTableOrder $order, array $lines): void
    {
        HospitalityTableOrderLine::query()->where('table_order_id', $order->id)->delete();
        $now = now();
        HospitalityTableOrderLine::query()->insert(array_map(function (array $line) use ($order, $now): array {
            $line['table_order_id'] = $order->id;
            $line['metadata'] = isset($line['metadata']) ? json_encode($line['metadata'], JSON_THROW_ON_ERROR) : null;
            $line['created_at'] = $now;
            $line['updated_at'] = $now;

            return $line;
        }, $lines));
    }

    private function assertSameOpenRequest(
        HospitalityTableOrder $order,
        PosTill $till,
        Shop $shop,
        array $payload,
        string $cartHash
    ): void {
        if ((string) $order->branch_id !== (string) $till->branch_id
            || (string) $order->shop_id !== (string) $shop->id
            || (string) $order->till_id !== (string) $till->id) {
            throw new HospitalityTableScopeException('This client request id belongs to another shop or till.');
        }
        if (! hash_equals((string) $order->cart_hash, $cartHash)
            || (string) $order->service_type !== (string) $payload['service_type']) {
            throw new HospitalityTableConflictException('This client request id was already used with different cart data.');
        }
    }

    private function scopedOrder(string $companyId, PosTill $till, Shop $shop, string $orderId)
    {
        return HospitalityTableOrder::query()
            ->where('id', $orderId)
            ->where('company_id', $companyId)
            ->where('branch_id', $till->branch_id)
            ->where('shop_id', $shop->id);
    }

    private function lockScopedOrder(
        string $companyId,
        PosTill $till,
        Shop $shop,
        string $orderId
    ): HospitalityTableOrder {
        $order = $this->scopedOrder($companyId, $till, $shop, $orderId)->lockForUpdate()->first();
        if (! $order) {
            throw new HospitalityTableScopeException('The table order does not exist in this shop.');
        }

        return $order;
    }

    private function assertMutable(HospitalityTableOrder $order): void
    {
        if (! in_array($order->status, ['open', 'hanging'], true)) {
            throw new HospitalityTableConflictException('Only open or hanging table orders can be changed.');
        }
    }

    private function assertRevision(HospitalityTableOrder $order, int $expectedRevision): void
    {
        if ((int) $order->revision !== $expectedRevision) {
            throw new HospitalityTableConflictException(
                'The table order changed on another terminal. Reload it before continuing.',
                (int) $order->revision
            );
        }
    }

    private function assertTillRecallAllowed(HospitalityTableOrder $order, PosTill $till, array $settings): void
    {
        if (! $settings['allow_cross_till_recall'] && (string) $order->last_till_id !== (string) $till->id) {
            throw new HospitalityTableScopeException('This table order can only be recalled on its last till.');
        }
    }

    private function assertCheckoutProductsMatch(HospitalityTableOrder $order, array $saleProducts): void
    {
        $orderProducts = $this->normaliseCheckoutRows(
            $order->lines()->get([
                'product_id', 'menu_item_channel_id', 'quantity', 'line_discount', 'metadata',
            ])->toArray(),
            true
        );
        $checkoutProducts = $this->normaliseCheckoutRows($saleProducts, false);
        if ($orderProducts !== $checkoutProducts) {
            throw new HospitalityTableConflictException(
                'The checkout cart no longer matches the saved table. Update the table order before taking payment.'
            );
        }
    }

    /** @return array<string, array{quantity: float, discount: float}> */
    private function normaliseCheckoutRows(array $rows, bool $savedOrder): array
    {
        $normalised = [];
        foreach ($rows as $row) {
            $row = is_array($row) ? $row : (array) $row;
            $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
            $customisation = $savedOrder
                ? data_get($metadata, 'pos_line', data_get($metadata, 'kitchen', $metadata))
                : $row;
            $customisation = is_array($customisation) ? $customisation : [];
            $priceId = (string) ($row['menu_item_channel_id'] ?? $row['product_price_id'] ?? '');
            $fingerprint = hash('sha256', json_encode(
                $this->checkoutCustomisation($customisation),
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
            ));
            $key = implode('|', [(string) ($row['product_id'] ?? ''), $priceId, $fingerprint]);
            $discount = $savedOrder
                ? (float) ($row['line_discount'] ?? 0)
                : (float) ($row['discount_amount'] ?? data_get($row, 'discount_payload.discount_amount', 0));

            $normalised[$key] ??= ['quantity' => 0.0, 'discount' => 0.0];
            $normalised[$key]['quantity'] = round(
                $normalised[$key]['quantity'] + (float) ($row['quantity'] ?? 0),
                4
            );
            $normalised[$key]['discount'] = round($normalised[$key]['discount'] + $discount, 4);
        }
        ksort($normalised);

        return $normalised;
    }

    /** @return array<string, mixed> */
    private function checkoutCustomisation(array $values): array
    {
        $prepMessageIds = collect($values['prep_message_ids'] ?? [])
            ->map('strval')->filter()->sort()->values()->all();
        $prepMessages = collect($values['prep_messages'] ?? [])
            ->map(fn ($message): string => is_array($message)
                ? (string) ($message['id'] ?? $message['code'] ?? $message['message'] ?? '')
                : (string) $message)
            ->filter()->sort()->values()->all();
        $modifierIds = collect($values['modifier_ids'] ?? [])
            ->map('strval')->filter()->sort()->values()->all();
        $modifiers = collect($values['modifiers'] ?? [])
            ->filter(fn ($modifier): bool => is_array($modifier))
            ->map(fn (array $modifier): array => [
                'id' => (string) ($modifier['modifier_id'] ?? $modifier['id'] ?? ''),
                'name' => (string) ($modifier['name'] ?? ''),
                'quantity' => round(max(1, (float) ($modifier['quantity'] ?? 1)), 4),
                'price' => round(max(0, (float) ($modifier['price'] ?? 0)), 4),
                'currency' => strtoupper((string) (
                    $modifier['currency_code'] ?? $modifier['currency'] ?? ''
                )),
                'recipe_id' => (string) ($modifier['recipe_id'] ?? ''),
            ])
            ->sortBy(fn (array $modifier): string => json_encode($modifier, JSON_THROW_ON_ERROR))
            ->values()
            ->all();

        return [
            'prep_message_ids' => $prepMessageIds,
            'prep_messages' => $prepMessages,
            'prep_note' => trim((string) (
                $values['prep_note']
                ?? $values['prep_message']
                ?? $values['special_instructions']
                ?? ''
            )),
            'modifier_ids' => $modifierIds,
            'modifiers' => $modifiers,
        ];
    }

    private function authorizeClear(
        string $companyId,
        PosTill $till,
        PosUser $actor,
        HospitalityTableOrder $order,
        ?string $approvalId
    ): ?string {
        $scope = $this->scopes->fromTill($till);
        if ($this->canClear($actor, $scope)) {
            return null;
        }
        if (! $approvalId) {
            throw new HospitalityTableAuthorizationException(
                'Clearing an open table requires clear_open_tables permission or a current manager approval.'
            );
        }

        $approval = DB::table('audit_trails')
            ->where('id', $approvalId)
            ->where('company_id', $companyId)
            ->where('branch_id', $till->branch_id)
            ->where('module', 'manager-approval')
            ->whereIn('action', [
                'manager_approval_clear_open_table',
                'manager_approval_clear_table',
                'manager_approval_clear_hanging_table',
            ])
            ->where('status', 'success')
            ->where('auditable_type', 'hospitality_table_order')
            ->where('auditable_id', $order->id)
            ->where('created_at', '>=', now()->subMinutes(10))
            ->lockForUpdate()
            ->first();
        if (! $approval) {
            throw new HospitalityTableAuthorizationException('The manager approval is invalid, expired or for another table order.');
        }
        if (HospitalityTableOrder::query()->where('manager_approval_id', $approvalId)->exists()) {
            throw new HospitalityTableAuthorizationException('The manager approval has already been used.');
        }

        $approvalValues = $this->decodeJson($approval->new_values ?? null);
        if (($approvalValues['required_permission'] ?? null) !== 'clear_open_tables'
            || (string) ($approvalValues['requested_by'] ?? '') !== (string) $actor->id) {
            throw new HospitalityTableAuthorizationException('The manager approval does not authorize this cashier or operation.');
        }
        $manager = PosUser::query()
            ->where('company_id', $companyId)
            ->whereKey($approval->user_id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();
        if (! $manager || ! $this->canClear($manager, $scope)) {
            throw new HospitalityTableAuthorizationException('The approving manager no longer has permission to clear this table.');
        }

        return $approvalId;
    }

    private function canClear(PosUser $user, mixed $scope): bool
    {
        if ($this->authorization->usesScopedAssignments($user)) {
            return $this->authorization->can($user, 'clear_open_tables', $scope);
        }

        $user->loadMissing('role.permissions');
        $roleName = strtolower(str_replace([' ', '-'], '_', trim((string) $user->role?->name)));

        return in_array($roleName, ['super_admin', 'super_administrator'], true)
            || ($user->role?->permissions?->contains('name', 'clear_open_tables') ?? false);
    }

    private function event(
        HospitalityTableOrder $order,
        PosTill $till,
        PosUser $actor,
        string $type,
        ?string $fromStatus,
        ?string $toStatus,
        ?string $commandId = null,
        array $metadata = [],
        ?string $managerApprovalId = null
    ): HospitalityTableOrderEvent {
        return HospitalityTableOrderEvent::query()->create([
            'id' => (string) Str::uuid(),
            'company_id' => $order->company_id,
            'branch_id' => $order->branch_id,
            'table_order_id' => $order->id,
            'till_id' => $till->id,
            'actor_id' => $actor->id,
            'event_type' => $type,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'manager_approval_id' => $managerApprovalId,
            'command_id' => $commandId,
            'metadata' => $metadata ?: null,
        ]);
    }

    private function commandReplay(
        HospitalityTableOrder $order,
        ?string $commandId,
        string $eventType
    ): ?HospitalityTableOrder {
        if (! $commandId) {
            return null;
        }
        $event = HospitalityTableOrderEvent::query()
            ->where('table_order_id', $order->id)
            ->where('command_id', $commandId)
            ->first();
        if (! $event) {
            return null;
        }
        if ($event->event_type !== $eventType) {
            throw new HospitalityTableConflictException('This command id was already used for another table operation.');
        }

        return $order->fresh(['table', 'lines']);
    }

    /** @return array<string, mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) ($exception->errorInfo[0] ?? $exception->getCode()), ['23000', '23505'], true);
    }
}
