<?php

namespace Modules\GeneralSettings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Enterprise\EnterpriseHierarchyService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\GeneralSettings\Models\PosTill;
use Modules\GeneralSettings\Models\Printer;

/**
 * Self-service settings for the physical terminal making the request.
 *
 * The route middleware resolves the X-Till-Token and binds app('till'). This
 * controller deliberately never accepts a company, branch or till identity
 * from the request, so one terminal cannot inspect or mutate another one.
 */
class CurrentTerminalController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'message' => 'Current terminal fetched successfully.',
            'data' => $this->terminalPayload($this->currentTill()),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'device_label' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $till = $this->currentTill();

        // company_id, branch_id, till_code, activation and registration fields
        // are intentionally not copied from the request.
        if ($request->exists('device_label')) {
            $label = $request->input('device_label');
            $till->update([
                'device_label' => is_string($label) && trim($label) !== ''
                    ? trim($label)
                    : null,
            ]);
        }

        return response()->json([
            'message' => 'Current terminal settings updated successfully.',
            'data' => $this->terminalPayload($till->fresh(['branch'])),
        ]);
    }

    public function printers(): JsonResponse
    {
        $till = $this->currentTill();

        $printers = $this->currentPrinterQuery($till)
            ->with(['branch', 'till'])
            ->orderBy('target_printing')
            ->orderByDesc('is_default')
            ->orderBy('printer_name')
            ->get();

        return response()->json([
            'message' => 'Current terminal printer overrides fetched successfully.',
            'data' => $printers,
        ]);
    }

    public function storePrinter(Request $request): JsonResponse
    {
        return $this->savePrinter($request);
    }

    public function updatePrinter(Request $request, string $id): JsonResponse
    {
        return $this->savePrinter($request, $id);
    }

    public function destroyPrinter(string $id): JsonResponse
    {
        $till = $this->currentTill();
        $printer = $this->currentPrinterQuery($till)->whereKey($id)->first();

        if (! $printer) {
            return response()->json([
                'message' => 'Printer override not found for this terminal.',
            ], 404);
        }

        $printer->delete();

        return response()->json([
            'message' => 'Current terminal printer override deleted successfully.',
        ]);
    }

    private function savePrinter(Request $request, ?string $id = null): JsonResponse
    {
        $till = $this->currentTill();
        $validator = Validator::make($request->all(), [
            'printer_name' => ['required', 'string', 'max:255'],
            'target_printing' => [
                'required',
                'string',
                Rule::in(['receipt', 'barcode', 'kitchen', 'bar', 'report', 'label', 'invoice', 'general']),
            ],
            'printer_type' => ['nullable', 'string', Rule::in(['qz', 'browser', 'network'])],
            'paper_size' => ['nullable', 'string', 'max:50'],
            'connection_type' => ['nullable', 'string', 'max:50'],
            'ip_address' => ['nullable', 'string', 'max:100'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $printer = $id === null
            ? new Printer
            : $this->currentPrinterQuery($till)->whereKey($id)->first();

        if (! $printer) {
            return response()->json([
                'message' => 'Printer override not found for this terminal.',
            ], 404);
        }

        $creating = ! $printer->exists;
        $target = strtolower(trim((string) $request->input('target_printing')));
        $makeDefault = $request->exists('is_default')
            ? $request->boolean('is_default')
            : ($creating ? false : (bool) $printer->is_default);

        try {
            DB::transaction(function () use ($request, $till, $printer, $creating, $target, $makeDefault): void {
                if ($makeDefault) {
                    $defaults = $this->currentPrinterQuery($till)
                        ->where('target_printing', $target);

                    if (! $creating) {
                        $defaults->where($printer->getKeyName(), '!=', $printer->getKey());
                    }

                    $defaults->update(['is_default' => false]);
                }

                // These three scope columns always come from the cryptographically
                // random till token resolved by middleware, never request data.
                $printer->company_id = $till->company_id;
                $printer->branch_id = $till->branch_id;
                $printer->till_id = $till->id;
                $printer->printer_name = trim((string) $request->input('printer_name'));
                $printer->target_printing = $target;
                $printer->printer_type = $this->valueOrExisting($request, 'printer_type', $printer, 'qz');
                $printer->paper_size = $this->valueOrExisting($request, 'paper_size', $printer);
                $printer->connection_type = $this->valueOrExisting($request, 'connection_type', $printer, 'local');
                $printer->ip_address = $this->valueOrExisting($request, 'ip_address', $printer);
                $printer->port = $this->valueOrExisting($request, 'port', $printer);
                $printer->notes = $this->valueOrExisting($request, 'notes', $printer);
                $printer->is_default = $makeDefault;
                $printer->is_active = $request->exists('is_active')
                    ? $request->boolean('is_active')
                    : ($creating ? true : (bool) $printer->is_active);
                $printer->save();
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return response()->json([
                    'message' => 'This printer is already saved for the selected target on this terminal.',
                ], 422);
            }

            throw $exception;
        }

        return response()->json([
            'message' => $creating
                ? 'Current terminal printer override created successfully.'
                : 'Current terminal printer override updated successfully.',
            'data' => $printer->fresh(['branch', 'till']),
        ], $creating ? 201 : 200);
    }

    private function currentTill(): PosTill
    {
        $till = app()->bound('till') ? app('till') : null;
        $company = app()->bound('company') ? app('company') : null;

        if (! $till instanceof PosTill
            || ! $company
            || (string) $till->company_id !== (string) $company->id) {
            throw new HttpResponseException(response()->json([
                'message' => 'A valid current terminal activation is required.',
                'code' => 'CURRENT_TERMINAL_REQUIRED',
            ], 403));
        }

        return $till;
    }

    private function currentPrinterQuery(PosTill $till)
    {
        return Printer::query()
            ->where('company_id', $till->company_id)
            ->where('branch_id', $till->branch_id)
            ->where('till_id', $till->id);
    }

    private function valueOrExisting(
        Request $request,
        string $field,
        Printer $printer,
        mixed $default = null
    ): mixed {
        if ($request->exists($field)) {
            $value = $request->input($field);

            return is_string($value) && trim($value) === '' ? null : $value;
        }

        return $printer->exists ? $printer->getAttribute($field) : $default;
    }

    private function terminalPayload(PosTill $till): array
    {
        $hierarchy = app(EnterpriseHierarchyService::class);

        return [
            'id' => $till->id,
            'company_id' => $till->company_id,
            'branch_id' => $till->branch_id,
            'shop_id' => $till->shop_id ?: $till->branch_id,
            'branch' => $till->relationLoaded('branch') ? $till->branch : $till->branch()->first(),
            'till_code' => $till->till_code,
            'till_name' => $till->till_name,
            'device_label' => $till->device_label,
            'is_registered' => (bool) $till->is_registered,
            'is_active' => (bool) $till->is_active,
            'registered_at' => $till->registered_at,
            'last_seen_at' => $till->last_seen_at,
            'deployment_mode' => (string) config('servnest.deployment_mode'),
            'hospitality_only' => true,
            'hierarchy' => $hierarchy->hierarchyForTill($till),
            'configuration' => $hierarchy->configurationManifest($till),
        ];
    }
}
