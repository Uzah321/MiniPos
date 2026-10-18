<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Product\Models\Taxe;
use function App\Helpers\hasPermission;

class TaxController extends Controller
{
    public function index(Request $request)
    {
        $company = app('company');

        $query = Taxe::where('company_id', $company->id)
            ->withCount('productTaxes')
            ->orderBy('name');

        if ($request->filled('q')) {
            $q = trim($request->q);

            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")
                    ->orWhere('tax_code', 'like', "%{$q}%")
                    ->orWhere('tax_id', 'like', "%{$q}%");
            });
        }

        if ($request->filled('obliged_to_zimra')) {
            $query->where(
                'obliged_to_zimra',
                filter_var($request->obliged_to_zimra, FILTER_VALIDATE_BOOLEAN)
            );
        }

        if ($request->filled('is_active')) {
            $query->where(
                'is_active',
                filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)
            );
        }

        return response()->json([
            'message' => 'Taxes fetched successfully.',
            'data' => $query->paginate((int) $request->get('per_page', 50)),
        ]);
    }

    public function show(string $id)
    {
        $company = app('company');

        $tax = Taxe::where('company_id', $company->id)
            ->withCount('productTaxes')
            ->find($id);

        if (!$tax) {
            return response()->json([
                'message' => 'Tax not found.',
            ], 404);
        }

        return response()->json([
            'message' => 'Tax fetched successfully.',
            'data' => $tax,
        ]);
    }

    public function store(Request $request)
    {
        $company = app('company');

        $result = hasPermission(auth()->user()->role_id, 'tax_management', $company->id);
        if ($result !== true) return $result;

        $validator = $this->validator($request, $company->id);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $payload = $this->normalisePayload($validator->validated());

        $tax = Taxe::create([
            'company_id' => $company->id,
            ...$payload,
        ]);

        return response()->json([
            'message' => 'Tax created successfully.',
            'data' => $tax,
        ], 201);
    }

    public function update(Request $request, string $id)
    {
        $company = app('company');

        $result = hasPermission(auth()->user()->role_id, 'tax_management', $company->id);
        if ($result !== true) return $result;

        $tax = Taxe::where('company_id', $company->id)->find($id);

        if (!$tax) {
            return response()->json([
                'message' => 'Tax not found.',
            ], 404);
        }

        $validator = $this->validator($request, $company->id, $tax->id);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tax->update($this->normalisePayload($validator->validated()));

        return response()->json([
            'message' => 'Tax updated successfully.',
            'data' => $tax->fresh(),
        ]);
    }

    public function destroy(string $id)
    {
        $company = app('company');

        $result = hasPermission(auth()->user()->role_id, 'tax_management', $company->id);
        if ($result !== true) return $result;

        $tax = Taxe::where('company_id', $company->id)
            ->withCount('productTaxes')
            ->find($id);

        if (!$tax) {
            return response()->json([
                'message' => 'Tax not found.',
            ], 404);
        }

        if ($tax->product_taxes_count > 0) {
            return response()->json([
                'message' => 'This tax is assigned to products. Remove it from products first.',
            ], 422);
        }

        $tax->delete();

        return response()->json([
            'message' => 'Tax deleted successfully.',
        ]);
    }

    public function dropdown(Request $request)
    {
        $company = app('company');

        $rows = Taxe::where('company_id', $company->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'tax_code',
                'tax_id',
                'type',
                'rate',
                'obliged_to_zimra',
                'is_active',
            ]);

        return response()->json([
            'message' => 'Tax dropdown fetched successfully.',
            'data' => $rows,
        ]);
    }

    private function validator(Request $request, string $companyId, ?string $ignoreId = null)
    {
        return Validator::make($request->all(), [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('taxes', 'name')->withoutTrashed()
                    ->where('company_id', $companyId)
                    ->ignore($ignoreId),
            ],
            'tax_code' => [
                'nullable',
                'string',
                'max:3',
            ],
            /*
             * This is the numeric FDMS taxID.
             * It is nullable for non-ZIMRA local taxes.
             * If obliged_to_zimra is true, it becomes required in normalisePayload validation.
             */
            'tax_id' => [
                'nullable',
                'integer',
                'min:0',
                Rule::unique('taxes', 'tax_id')->withoutTrashed()
                    ->where('company_id', $companyId)
                    ->ignore($ignoreId),
            ],
            'type' => 'required|in:percentage,fixed',
            'rate' => 'required|numeric|min:0',
            'obliged_to_zimra' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'valid_from' => 'nullable|date',
            'valid_till' => 'nullable|date|after_or_equal:valid_from',
            'description' => 'nullable|string',
        ]);
    }

    private function normalisePayload(array $payload): array
    {
        $payload['tax_code'] = !empty($payload['tax_code'])
            ? strtoupper(substr(trim($payload['tax_code']), 0, 3))
            : null;

        $payload['tax_id'] = $payload['tax_id'] ?? null;
        $payload['obliged_to_zimra'] = (bool) ($payload['obliged_to_zimra'] ?? false);
        $payload['is_active'] = (bool) ($payload['is_active'] ?? true);

        if ($payload['obliged_to_zimra'] && $payload['tax_id'] === null) {
            abort(response()->json([
                'message' => 'FDMS numeric Tax ID is required when tax is obliged to ZIMRA.',
            ], 422));
        }

        if ($payload['obliged_to_zimra'] && empty($payload['tax_code'])) {
            abort(response()->json([
                'message' => 'Tax code is required when tax is obliged to ZIMRA.',
            ], 422));
        }

        return $payload;
    }
}

