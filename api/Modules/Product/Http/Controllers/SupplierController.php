<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Product\Models\Supplier;
use function App\Helpers\hasPermission;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $company = app('company');
        $result = hasPermission(auth()->user()->role_id, 'view_supplier', $company->id);
        if ($result !== true) return $result;

        $query = Supplier::where('company_id', $company->id)->latest();

        if ($request->filled('q')) {
            $q = $request->q;

            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")
                    ->orWhere('code', 'like', "%{$q}%")
                    ->orWhere('contact_person', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('tax_number', 'like', "%{$q}%");
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        return response()->json([
            'message' => 'Suppliers fetched successfully.',
            'data' => $query->paginate($request->per_page ?? 15),
        ]);
    }

    public function store(Request $request)
    {
        $company = app('company');
        $result = hasPermission(auth()->user()->role_id, 'store_supplier', $company->id);
        if ($result !== true) return $result;

        $validator = Validator::make($request->all(), [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('suppliers', 'name')->withoutTrashed()->where('company_id', $company->id),
            ],
            'code' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('suppliers', 'code')->withoutTrashed()->where('company_id', $company->id),
            ],
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'tax_number' => 'nullable|string|max:100',
            'address' => 'nullable|string',
            'notes' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            $supplier = Supplier::create([
                'company_id' => $company->id,
                'name' => $request->name,
                'code' => $request->code,
                'contact_person' => $request->contact_person,
                'phone' => $request->phone,
                'email' => $request->email,
                'tax_number' => $request->tax_number,
                'address' => $request->address,
                'notes' => $request->notes,
                'is_active' => $request->is_active ?? true,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Supplier created successfully.',
                'data' => $supplier,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong while creating supplier.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(string $id)
    {
        $company = app('company');

        $supplier = Supplier::where('company_id', $company->id)
            ->where('id', $id)
            ->first();

        if (!$supplier) {
            return response()->json(['message' => 'Supplier not found.'], 404);
        }

        return response()->json([
            'message' => 'Supplier fetched successfully.',
            'data' => $supplier,
        ]);
    }

    public function update(Request $request, string $id)
    {
        $company = app('company');
        $result = hasPermission(auth()->user()->role_id, 'update_supplier', $company->id);
        if ($result !== true) return $result;

        $supplier = Supplier::where('company_id', $company->id)
            ->where('id', $id)
            ->first();

        if (!$supplier) {
            return response()->json(['message' => 'Supplier not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('suppliers', 'name')->withoutTrashed()
                    ->where('company_id', $company->id)
                    ->ignore($supplier->id),
            ],
            'code' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('suppliers', 'code')->withoutTrashed()
                    ->where('company_id', $company->id)
                    ->ignore($supplier->id),
            ],
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'tax_number' => 'nullable|string|max:100',
            'address' => 'nullable|string',
            'notes' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $supplier->update([
            'name' => $request->name,
            'code' => $request->code,
            'contact_person' => $request->contact_person,
            'phone' => $request->phone,
            'email' => $request->email,
            'tax_number' => $request->tax_number,
            'address' => $request->address,
            'notes' => $request->notes,
            'is_active' => $request->is_active ?? $supplier->is_active,
        ]);

        return response()->json([
            'message' => 'Supplier updated successfully.',
            'data' => $supplier->fresh(),
        ]);
    }

    public function destroy(string $id)
    {

        $company = app('company');
        $result = hasPermission(auth()->user()->role_id, 'delete_supplier', $company->id);
        if ($result !== true) return $result;

        $supplier = Supplier::where('company_id', $company->id)
            ->where('id', $id)
            ->first();

        if (!$supplier) {
            return response()->json(['message' => 'Supplier not found.'], 404);
        }

        $supplier->delete();

        return response()->json([
            'message' => 'Supplier deleted successfully.',
        ]);
    }
}

