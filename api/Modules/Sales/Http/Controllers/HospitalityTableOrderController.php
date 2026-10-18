<?php

namespace Modules\Sales\Http\Controllers;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Modules\Authentication\Models\PosUser;
use Modules\GeneralSettings\Models\PosTill;
use Modules\Sales\Exceptions\HospitalityTableAuthorizationException;
use Modules\Sales\Exceptions\HospitalityTableConflictException;
use Modules\Sales\Exceptions\HospitalityTableScopeException;
use Modules\Sales\Services\HospitalityTableService;

class HospitalityTableOrderController extends Controller
{
    public function __construct(private readonly HospitalityTableService $tables) {}

    public function snapshot(): JsonResponse
    {
        [$companyId, $till] = $this->context();
        $snapshot = $this->tables->snapshot($companyId, $till);

        return response()->json([
            'data' => [
                'settings' => $snapshot['settings'],
                'tables' => $snapshot['tables']->map(fn ($table) => array_merge($table->toArray(), [
                    'is_available' => $table->activeOrder === null,
                    'active_order' => $table->activeOrder?->toArray(),
                ]))->values(),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        [$companyId, $till] = $this->context();
        $statuses = collect(explode(',', (string) $request->query('status', 'open,hanging')))
            ->map(fn ($status) => trim((string) $status))
            ->filter()
            ->values()
            ->all();

        return response()->json([
            'data' => $this->tables->openOrders($companyId, $till, $statuses),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate($this->cartRules(true));
        [$companyId, $till, $actor] = $this->contextWithActor();

        return $this->respond(function () use ($companyId, $till, $actor, $payload): JsonResponse {
            $result = $this->tables->openOrder($companyId, $till, $actor, $payload);

            return response()->json([
                'data' => $result['order'],
                'meta' => ['idempotent_replay' => $result['replayed']],
            ], $result['replayed'] ? 200 : 201);
        });
    }

    public function show(string $orderId): JsonResponse
    {
        [$companyId, $till] = $this->context();

        return $this->respond(fn () => response()->json([
            'data' => $this->tables->order($companyId, $till, $orderId),
        ]));
    }

    public function update(Request $request, string $orderId): JsonResponse
    {
        $payload = $request->validate(array_merge($this->cartRules(false), [
            'expected_revision' => ['required', 'integer', 'min:1'],
        ]));
        [$companyId, $till, $actor] = $this->contextWithActor();

        return $this->respond(function () use ($companyId, $till, $actor, $orderId, $payload): JsonResponse {
            $result = $this->tables->updateOrder(
                $companyId,
                $till,
                $actor,
                $orderId,
                (int) $payload['expected_revision'],
                Arr::except($payload, 'expected_revision')
            );

            return response()->json([
                'data' => $result['order'],
                'meta' => ['idempotent_replay' => $result['replayed']],
            ]);
        });
    }

    public function hang(Request $request, string $orderId): JsonResponse
    {
        return $this->transition($request, $orderId, 'hanging');
    }

    public function recall(Request $request, string $orderId): JsonResponse
    {
        return $this->transition($request, $orderId, 'open');
    }

    public function sendToKitchen(Request $request, string $orderId): JsonResponse
    {
        $payload = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
            'command_id' => ['required', 'uuid'],
        ]);
        [$companyId, $till, $actor] = $this->contextWithActor();

        return $this->respond(function () use ($companyId, $till, $actor, $orderId, $payload): JsonResponse {
            $result = $this->tables->sendToKitchen(
                $companyId,
                $till,
                $actor,
                $orderId,
                (int) $payload['expected_revision'],
                $payload['command_id']
            );

            return response()->json([
                'data' => [
                    'order' => $result['order'],
                    'kitchen_print' => $result['dispatch'],
                ],
                'meta' => ['idempotent_replay' => $result['replayed']],
            ]);
        });
    }

    public function clear(Request $request, string $orderId): JsonResponse
    {
        $payload = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'],
            'manager_approval_id' => ['nullable', 'uuid'],
            'command_id' => ['nullable', 'uuid'],
        ]);
        [$companyId, $till, $actor] = $this->contextWithActor();

        return $this->respond(function () use ($companyId, $till, $actor, $orderId, $payload): JsonResponse {
            $result = $this->tables->clearOrder(
                $companyId,
                $till,
                $actor,
                $orderId,
                (int) $payload['expected_revision'],
                $payload['reason'],
                $payload['manager_approval_id'] ?? null,
                $payload['command_id'] ?? null
            );

            return response()->json([
                'data' => $result['order'],
                'meta' => ['idempotent_replay' => $result['replayed']],
            ]);
        });
    }

    private function transition(Request $request, string $orderId, string $status): JsonResponse
    {
        $payload = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
            'command_id' => ['nullable', 'uuid'],
        ]);
        [$companyId, $till, $actor] = $this->contextWithActor();

        return $this->respond(function () use ($companyId, $till, $actor, $orderId, $payload, $status): JsonResponse {
            $result = $this->tables->transition(
                $companyId,
                $till,
                $actor,
                $orderId,
                (int) $payload['expected_revision'],
                $status,
                $payload['command_id'] ?? null
            );

            return response()->json([
                'data' => $result['order'],
                'meta' => ['idempotent_replay' => $result['replayed']],
            ]);
        });
    }

    /** @return array<string, mixed> */
    private function cartRules(bool $creating): array
    {
        return [
            'client_request_id' => [$creating ? 'required' : 'prohibited', 'uuid'],
            'command_id' => ['nullable', 'uuid'],
            'service_type' => [$creating ? 'required' : 'sometimes', Rule::in(['dine_in', 'takeaway'])],
            'assignment_mode' => ['nullable', Rule::in(['auto', 'manual'])],
            'table_id' => ['nullable', 'uuid'],
            'guest_count' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'display_label' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'currency_code' => [$creating ? 'required' : 'sometimes', 'string', 'size:3'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.product_id' => ['required', 'uuid'],
            'items.*.product_price_id' => ['nullable', 'uuid'],
            'items.*.menu_item_channel_id' => ['nullable', 'uuid'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.line_discount' => ['nullable', 'numeric', 'min:0'],
            'items.*.line_tax' => ['nullable', 'numeric', 'min:0'],
            'items.*.line_total' => ['nullable', 'numeric', 'min:0'],
            'items.*.name' => ['nullable', 'string', 'max:255'],
            'items.*.metadata' => ['nullable', 'array'],
        ];
    }

    /** @return array{0: string, 1: PosTill} */
    private function context(): array
    {
        return [(string) app('company')->id, app('till')];
    }

    /** @return array{0: string, 1: PosTill, 2: PosUser} */
    private function contextWithActor(): array
    {
        [$companyId, $till] = $this->context();
        /** @var PosUser $actor */
        $actor = auth()->user();

        return [$companyId, $till, $actor];
    }

    private function respond(callable $callback): JsonResponse
    {
        try {
            return $callback();
        } catch (HospitalityTableConflictException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'HOSPITALITY_TABLE_CONFLICT',
                'data' => ['current_revision' => $exception->currentRevision],
            ], 409);
        } catch (HospitalityTableAuthorizationException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'HOSPITALITY_TABLE_CLEAR_APPROVAL_REQUIRED',
            ], 403);
        } catch (HospitalityTableScopeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'HOSPITALITY_TABLE_SCOPE_DENIED',
            ], 403);
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'HOSPITALITY_TABLE_INVALID',
            ], 422);
        }
    }
}
