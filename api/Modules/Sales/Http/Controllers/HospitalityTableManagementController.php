<?php

namespace Modules\Sales\Http\Controllers;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Sales\Exceptions\HospitalityTableConflictException;
use Modules\Sales\Exceptions\HospitalityTableScopeException;
use Modules\Sales\Services\HospitalityTableService;

class HospitalityTableManagementController extends Controller
{
    public function __construct(private readonly HospitalityTableService $tables) {}

    public function settings(Request $request): JsonResponse
    {
        $payload = $request->validate($this->scopeRules());

        return $this->respond(fn () => response()->json([
            'data' => $this->tables->managementSettings(
                (string) app('company')->id,
                $payload['branch_id'],
                $payload['shop_id']
            ),
        ]));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'branch_id' => ['required', 'uuid'],
            'shop_id' => ['required', 'uuid'],
            'assignment_mode' => ['required', Rule::in(['auto', 'manual'])],
            'require_table_for_dine_in' => ['required', 'boolean'],
            'allow_cross_till_recall' => ['required', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ]);
        $branchId = $payload['branch_id'];
        $shopId = $payload['shop_id'];
        unset($payload['branch_id'], $payload['shop_id']);

        return $this->respond(fn () => response()->json([
            'data' => $this->tables->saveManagementSettings(
                (string) app('company')->id,
                $branchId,
                $shopId,
                $payload
            ),
        ]));
    }

    public function index(Request $request): JsonResponse
    {
        $payload = $request->validate($this->scopeRules());

        return $this->respond(fn () => response()->json([
            'data' => $this->tables->managementTables(
                (string) app('company')->id,
                $payload['branch_id'],
                $payload['shop_id']
            ),
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate(array_merge(
            $this->scopeRules(),
            $this->tableRules(false)
        ));
        $branchId = $payload['branch_id'];
        $shopId = $payload['shop_id'];
        unset($payload['branch_id'], $payload['shop_id']);

        return $this->respond(fn () => response()->json([
            'data' => $this->tables->createManagementTable(
                (string) app('company')->id,
                $branchId,
                $shopId,
                $payload
            ),
        ], 201));
    }

    public function update(Request $request, string $tableId): JsonResponse
    {
        $payload = $request->validate(array_merge(
            $this->scopeRules(),
            $this->tableRules(true)
        ));
        $branchId = $payload['branch_id'];
        $shopId = $payload['shop_id'];
        unset($payload['branch_id'], $payload['shop_id']);

        return $this->respond(fn () => response()->json([
            'data' => $this->tables->updateManagementTable(
                (string) app('company')->id,
                $branchId,
                $shopId,
                $tableId,
                $payload
            ),
        ]));
    }

    /** @return array<string, mixed> */
    private function scopeRules(): array
    {
        return [
            'branch_id' => ['required', 'uuid'],
            'shop_id' => ['required', 'uuid'],
        ];
    }

    /** @return array<string, mixed> */
    private function tableRules(bool $updating): array
    {
        $presence = $updating ? 'sometimes' : 'required';

        return [
            'code' => [$presence, 'string', 'max:80'],
            'name' => [$presence, 'string', 'max:255'],
            'section' => ['nullable', 'string', 'max:120'],
            'table_type' => [$presence, Rule::in(['dining'])],
            'capacity' => [$presence, 'integer', 'min:1', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    private function respond(callable $callback): JsonResponse
    {
        try {
            return $callback();
        } catch (HospitalityTableConflictException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'HOSPITALITY_TABLE_CONFLICT',
            ], 409);
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
