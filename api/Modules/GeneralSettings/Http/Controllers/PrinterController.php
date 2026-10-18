<?php

namespace Modules\GeneralSettings\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\GeneralSettings\Models\PosTill;
use Modules\GeneralSettings\Models\Printer;
use function App\Helpers\hasPermission;

class PrinterController extends Controller
{
    public function index(Request $request)
    {
        $company = app('company');
        $user = auth()->user();

        $result = hasPermission($user->role_id, 'printer_management', $company->id);
        if ($result !== true) return $result;

        $query = Printer::where('company_id', $company->id)
            ->with(['branch', 'till'])
            ->orderBy('target_printing')
            ->orderByDesc('is_default')
            ->orderBy('printer_name');

        if ($request->filled('q')) {
            $q = trim((string)$request->q);

            $query->where(function ($sub) use ($q) {
                $sub->where('printer_name', 'like', "%{$q}%")
                    ->orWhere('target_printing', 'like', "%{$q}%")
                    ->orWhere('printer_type', 'like', "%{$q}%")
                    ->orWhere('paper_size', 'like', "%{$q}%");
            });
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('till_id')) {
            $query->where('till_id', $request->till_id);
        }

        if ($request->filled('target_printing')) {
            $query->where('target_printing', $request->target_printing);
        }

        if ($request->filled('is_active')) {
            $query->where(
                'is_active',
                filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)
            );
        }

        $perPage = (int)$request->get('per_page', 50);

        return response()->json([
            'message' => 'Printers fetched successfully.',
            'data' => $query->paginate($perPage),
        ], 200);
    }

    public function store(Request $request)
    {
        $company = app('company');
        $user = auth()->user();

        $result = hasPermission($user->role_id, 'printer_management', $company->id);
        if ($result !== true) return $result;

        $validator = Validator::make($request->all(), [
            'branch_id' => [
                'nullable',
                Rule::exists('company_branches', 'id')->where('company_id', $company->id),
            ],
            'till_id' => [
                'nullable',
                Rule::exists('pos_tills', 'id')->where('company_id', $company->id),
            ],
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

        if ($request->filled('till_id')) {
            $till = PosTill::where('company_id', $company->id)
                ->where('id', $request->till_id)
                ->first();

            if (!$till) {
                return response()->json([
                    'message' => 'Selected till is invalid.',
                ], 422);
            }

            if ($request->filled('branch_id') && $till->branch_id !== $request->branch_id) {
                return response()->json([
                    'message' => 'Selected till does not belong to the selected branch.',
                ], 422);
            }
        }

        DB::beginTransaction();

        try {
            $branchId = $request->branch_id ?: null;
            $tillId = $request->till_id ?: null;
            $target = strtolower(trim((string)$request->target_printing));

            if ($request->boolean('is_default', false)) {
                Printer::where('company_id', $company->id)
                    ->where('branch_id', $branchId)
                    ->where('till_id', $tillId)
                    ->where('target_printing', $target)
                    ->update(['is_default' => false]);
            }

            $printer = Printer::create([
                'company_id' => $company->id,
                'branch_id' => $branchId,
                'till_id' => $tillId,
                'printer_name' => trim((string)$request->printer_name),
                'target_printing' => $target,
                'printer_type' => $request->printer_type ?: 'qz',
                'paper_size' => $request->paper_size ?: null,
                'connection_type' => $request->connection_type ?: 'local',
                'ip_address' => $request->ip_address ?: null,
                'port' => $request->port ?: null,
                'is_default' => $request->boolean('is_default', false),
                'is_active' => $request->boolean('is_active', true),
                'notes' => $request->notes ?: null,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Printer saved successfully.',
                'data' => $printer->load(['branch', 'till']),
            ], 201);
        } catch (QueryException $e) {
            DB::rollBack();

            if ($e->getCode() === '23000') {
                return response()->json([
                    'message' => 'This printer is already saved for the selected target.',
                    'error' => $e->getMessage(),
                ], 422);
            }

            return response()->json([
                'message' => 'Database error while saving printer.',
                'error' => $e->getMessage(),
            ], 500);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong while saving printer.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(string $id)
    {
        $company = app('company');
        $user = auth()->user();

        $result = hasPermission($user->role_id, 'printer_management', $company->id);
        if ($result !== true) return $result;

        $printer = Printer::where('company_id', $company->id)
            ->where('id', $id)
            ->with(['branch', 'till'])
            ->first();

        if (!$printer) {
            return response()->json([
                'message' => 'Printer not found.',
            ], 404);
        }

        return response()->json([
            'message' => 'Printer fetched successfully.',
            'data' => $printer,
        ], 200);
    }

    public function update(Request $request, string $id)
    {
        $company = app('company');
        $user = auth()->user();

        $result = hasPermission($user->role_id, 'printer_management', $company->id);
        if ($result !== true) return $result;

        $printer = Printer::where('company_id', $company->id)
            ->where('id', $id)
            ->first();

        if (!$printer) {
            return response()->json([
                'message' => 'Printer not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'branch_id' => [
                'nullable',
                Rule::exists('company_branches', 'id')->where('company_id', $company->id),
            ],
            'till_id' => [
                'nullable',
                Rule::exists('pos_tills', 'id')->where('company_id', $company->id),
            ],
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

        if ($request->filled('till_id')) {
            $till = PosTill::where('company_id', $company->id)
                ->where('id', $request->till_id)
                ->first();

            if (!$till) {
                return response()->json([
                    'message' => 'Selected till is invalid.',
                ], 422);
            }

            if ($request->filled('branch_id') && $till->branch_id !== $request->branch_id) {
                return response()->json([
                    'message' => 'Selected till does not belong to the selected branch.',
                ], 422);
            }
        }

        DB::beginTransaction();

        try {
            $branchId = $request->branch_id ?: null;
            $tillId = $request->till_id ?: null;
            $target = strtolower(trim((string)$request->target_printing));

            if ($request->boolean('is_default', false)) {
                Printer::where('company_id', $company->id)
                    ->where('branch_id', $branchId)
                    ->where('till_id', $tillId)
                    ->where('target_printing', $target)
                    ->where('id', '!=', $printer->id)
                    ->update(['is_default' => false]);
            }

            $printer->update([
                'branch_id' => $branchId,
                'till_id' => $tillId,
                'printer_name' => trim((string)$request->printer_name),
                'target_printing' => $target,
                'printer_type' => $request->printer_type ?: 'qz',
                'paper_size' => $request->paper_size ?: null,
                'connection_type' => $request->connection_type ?: 'local',
                'ip_address' => $request->ip_address ?: null,
                'port' => $request->port ?: null,
                'is_default' => $request->boolean('is_default', false),
                'is_active' => $request->boolean('is_active', true),
                'notes' => $request->notes ?: null,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Printer updated successfully.',
                'data' => $printer->fresh(['branch', 'till']),
            ], 200);
        } catch (QueryException $e) {
            DB::rollBack();

            if ($e->getCode() === '23000') {
                return response()->json([
                    'message' => 'This printer is already saved for the selected target.',
                    'error' => $e->getMessage(),
                ], 422);
            }

            return response()->json([
                'message' => 'Database error while updating printer.',
                'error' => $e->getMessage(),
            ], 500);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong while updating printer.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        $company = app('company');
        $user = auth()->user();

        $result = hasPermission($user->role_id, 'printer_management', $company->id);
        if ($result !== true) return $result;

        $printer = Printer::where('company_id', $company->id)
            ->where('id', $id)
            ->first();

        if (!$printer) {
            return response()->json([
                'message' => 'Printer not found.',
            ], 404);
        }

        $printer->delete();

        return response()->json([
            'message' => 'Printer deleted successfully.',
        ], 200);
    }

    public function resolveForPrinting(Request $request)
    {
        $company = app('company');
        $till = app()->bound('till') ? app('till') : null;

        if (! $till instanceof PosTill
            || (string) $till->company_id !== (string) $company->id) {
            return response()->json([
                'message' => 'A valid current terminal activation is required.',
                'code' => 'CURRENT_TERMINAL_REQUIRED',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'target_printing' => [
                'required',
                Rule::in(['receipt', 'barcode', 'kitchen', 'bar', 'report', 'label', 'invoice', 'general']),
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Front Office printing is always resolved from the activated terminal.
        // Client-supplied branch/till query values and X-Till-Id are ignored.
        $branchId = $till->branch_id;
        $tillId = $till->id;
        $target = strtolower(trim((string) $request->input('target_printing')));

        $printer = Printer::where('company_id', $company->id)
            ->where('target_printing', $target)
            ->where('is_active', true)
            ->where(function ($query) use ($branchId) {
                $query->whereNull('branch_id');

                if ($branchId) {
                    $query->orWhere('branch_id', $branchId);
                }
            })
            ->where(function ($query) use ($tillId) {
                $query->whereNull('till_id');

                if ($tillId) {
                    $query->orWhere('till_id', $tillId);
                }
            })
            ->orderByRaw('till_id IS NULL ASC')
            ->orderByRaw('branch_id IS NULL ASC')
            ->orderByDesc('is_default')
            ->first();

        if (!$printer) {
            return response()->json([
                'message' => 'No active printer configured for this target.',
            ], 404);
        }

        return response()->json([
            'message' => 'Printer resolved successfully.',
            'data' => $printer,
        ], 200);
    }
}
