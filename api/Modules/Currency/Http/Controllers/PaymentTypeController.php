<?php

namespace Modules\Currency\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\ConfigurationBranchScopeService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Currency\Models\PaymentType;
use function App\Helpers\hasPermission;


class PaymentTypeController extends Controller
{
    public function __construct(
        private readonly ConfigurationBranchScopeService $configurationBranchScope
    ) {}

    public function index(Request $request)
    {
        $company = app('company');
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Till staff who can sell need this list to take a sale, even when
        // their role cannot open payment type management.
        $result = hasPermission($user->role_id, 'view_payment_type', $company->id);
        if ($result !== true && hasPermission($user->role_id, 'create_sale', $company->id) !== true) {
            return $result;
        }

        $branchId = $this->configurationBranchScope->resolve($request, (string) $company->id);

        $query = PaymentType::where('company_id', $company->id)
            ->where(function ($sub) use ($branchId) {
                $sub->whereNull('branch_id');

                if ($branchId) {
                    $sub->orWhere('branch_id', $branchId);
                }
            })
            ->with(['branch'])
            ->orderBy('name');

        if ($request->filled('q')) {
            $q = $request->q;

            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")
                    ->orWhere('money_code', 'like', "%{$q}%")
                    ->orWhere('type', 'like', "%{$q}%")
                    ->orWhere('default_currency_code', 'like', "%{$q}%");
            });
        }

        if ($request->filled('is_active')) {
            $query->where(
                'is_active',
                filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)
            );
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $perPage = (int) $request->get('per_page', 50);

        return response()->json([
            'message' => 'Payment types fetched successfully.',
            'data' => $query->paginate($perPage),
        ], 200);
    }

    public function store(Request $request)
    {
        $company = app('company');
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $result = hasPermission($user->role_id, 'create_payment_type', $company->id);
        if ($result !== true) return $result;

        $branchId = $this->configurationBranchScope->resolve($request, (string) $company->id);

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],

            'money_code' => [
                'required',
                'string',
                'max:255',
                Rule::unique('payment_types', 'money_code')->where(function ($q) use ($company, $branchId) {
                    $q->where('company_id', $company->id)
                        ->where('branch_id', $branchId)
                        ->whereNull('deleted_at');
                }),
            ],

            'type' => ['nullable', 'string', 'max:255'],

            'supportsMultiCurrency' => ['nullable', 'boolean'],
            'requiresAmountTendered' => ['nullable', 'boolean'],
            'default_currency_code' => ['nullable', 'string', 'max:255'],

            'integration_status' => ['nullable', 'boolean'],
            'integration_number' => ['nullable', 'integer', 'min:0'],

            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation error.',
                'data' => $validator->errors(),
            ], 422);
        }

        try {
            $paymentType = PaymentType::create([
                'company_id' => $company->id,
                'branch_id' => $branchId,

                'name' => trim((string) $request->name),
                'money_code' => strtoupper(trim((string) $request->money_code)),
                'type' => $request->filled('type') ? trim((string) $request->type) : null,

                'supportsMultiCurrency' => $request->boolean('supportsMultiCurrency', false),
                'requiresAmountTendered' => $request->boolean('requiresAmountTendered', false),
                'default_currency_code' => $request->filled('default_currency_code')
                    ? strtoupper(trim((string) $request->default_currency_code))
                    : null,

                'integration_status' => $request->has('integration_status')
                    ? $request->boolean('integration_status')
                    : null,

                'integration_number' => $request->input('integration_number'),

                'is_active' => $request->boolean('is_active', true),
            ]);

            return response()->json([
                'message' => 'Payment type created successfully.',
                'data' => $paymentType->load('branch'),
            ], 201);
        } catch (QueryException $e) {
            \Log::error('Payment type create SQL failed', [
                'message' => $e->getMessage(),
                'sql' => $e->getSql(),
                'bindings' => $e->getBindings(),
            ]);

            return response()->json([
                'message' => 'Database error while creating payment type.',
            ], 500);
        } catch (\Throwable $e) {
            \Log::error('Payment type create failed', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Something went wrong while creating payment type.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(Request $request, string $id)
    {
        $company = app('company');
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $result = hasPermission($user->role_id, 'view_payment_type', $company->id);
        if ($result !== true) return $result;

        $branchId = $this->configurationBranchScope->resolve($request, (string) $company->id);

        $paymentType = PaymentType::where('company_id', $company->id)
            ->where('id', $id)
            ->where(function ($sub) use ($branchId) {
                $sub->whereNull('branch_id');

                if ($branchId) {
                    $sub->orWhere('branch_id', $branchId);
                }
            })
            ->with('branch')
            ->first();

        if (!$paymentType) {
            return response()->json([
                'message' => 'Payment type not found.',
            ], 404);
        }

        return response()->json([
            'message' => 'Payment type fetched successfully.',
            'data' => $paymentType,
        ], 200);
    }

    public function update(Request $request, string $id)
    {
        $company = app('company');
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $result = hasPermission($user->role_id, 'update_payment_type', $company->id);
        if ($result !== true) return $result;

        $branchId = $this->configurationBranchScope->resolve($request, (string) $company->id);

        $paymentType = PaymentType::where('company_id', $company->id)
            ->where('id', $id)
            ->where('branch_id', $branchId)
            ->first();

        if (!$paymentType) {
            return response()->json([
                'message' => 'Payment type not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],

            'money_code' => [
                'required',
                'string',
                'max:255',
                Rule::unique('payment_types', 'money_code')
                    ->ignore($paymentType->id)
                    ->where(function ($q) use ($company, $branchId) {
                        $q->where('company_id', $company->id)
                            ->where('branch_id', $branchId)
                            ->whereNull('deleted_at');
                    }),
            ],

            'type' => ['nullable', 'string', 'max:255'],

            'supportsMultiCurrency' => ['nullable', 'boolean'],
            'requiresAmountTendered' => ['nullable', 'boolean'],
            'default_currency_code' => ['nullable', 'string', 'max:255'],

            'integration_status' => ['nullable', 'boolean'],
            'integration_number' => ['nullable', 'integer', 'min:0'],

            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation error.',
                'data' => $validator->errors(),
            ], 422);
        }

        try {
            $paymentType->update([
                'name' => trim((string) $request->name),
                'money_code' => strtoupper(trim((string) $request->money_code)),
                'type' => $request->filled('type') ? trim((string) $request->type) : null,

                'supportsMultiCurrency' => $request->boolean('supportsMultiCurrency', false),
                'requiresAmountTendered' => $request->boolean('requiresAmountTendered', false),
                'default_currency_code' => $request->filled('default_currency_code')
                    ? strtoupper(trim((string) $request->default_currency_code))
                    : null,

                'integration_status' => $request->has('integration_status')
                    ? $request->boolean('integration_status')
                    : null,

                'integration_number' => $request->input('integration_number'),

                'is_active' => $request->boolean('is_active', true),
            ]);

            return response()->json([
                'message' => 'Payment type updated successfully.',
                'data' => $paymentType->fresh('branch'),
            ], 200);
        } catch (QueryException $e) {
            \Log::error('Payment type update SQL failed', [
                'message' => $e->getMessage(),
                'sql' => $e->getSql(),
                'bindings' => $e->getBindings(),
            ]);

            return response()->json([
                'message' => 'Database error while updating payment type.',
            ], 500);
        } catch (\Throwable $e) {
            \Log::error('Payment type update failed', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Something went wrong while updating payment type.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(Request $request, string $id)
    {
        $company = app('company');
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $result = hasPermission($user->role_id, 'delete_payment_type', $company->id);
        if ($result !== true) return $result;

        $branchId = $this->configurationBranchScope->resolve($request, (string) $company->id);

        $paymentType = PaymentType::where('company_id', $company->id)
            ->where('id', $id)
            ->where('branch_id', $branchId)
            ->first();

        if (!$paymentType) {
            return response()->json([
                'message' => 'Payment type not found.',
            ], 404);
        }

        $paymentType->delete();

        return response()->json([
            'message' => 'Payment type deleted successfully.',
        ], 200);
    }
}
