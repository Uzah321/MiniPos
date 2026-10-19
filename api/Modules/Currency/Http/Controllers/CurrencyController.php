<?php

namespace Modules\Currency\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Services\ConfigurationBranchScopeService;
use App\Services\Sync\SyncConfigurationService;
use Modules\Currency\Models\Currency;
use Modules\Currency\Models\CurrencyRate;
use function App\Helpers\hasPermission;



class CurrencyController extends Controller
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
        // their role cannot open currency management.
        $result = hasPermission($user->role_id, 'view_currency', $company->id);
        if ($result !== true && hasPermission($user->role_id, 'create_sale', $company->id) !== true) {
            return $result;
        }

        $branchId = $this->configurationBranchScope->resolve($request, (string) $company->id);

        $query = Currency::where('company_id', $company->id)
            ->where(function ($sub) use ($branchId) {
                $sub->whereNull('branch_id');

                if ($branchId) {
                    $sub->orWhere('branch_id', $branchId);
                }
            })
            ->with(['latestRate', 'branch'])
            ->orderByDesc('is_base_currency')
            ->orderBy('code');

        if ($request->filled('q')) {
            $q = $request->q;

            $query->where(function ($sub) use ($q) {
                $sub->where('code', 'like', "%{$q}%")
                    ->orWhere('name', 'like', "%{$q}%")
                    ->orWhere('country', 'like', "%{$q}%");
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('is_base_currency')) {
            $query->where('is_base_currency', filter_var($request->is_base_currency, FILTER_VALIDATE_BOOLEAN));
        }

        $perPage = (int) $request->get('per_page', 50);

        return response()->json([
            'message' => 'Currencies fetched successfully.',
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

        $result = hasPermission($user->role_id, 'create_currency', $company->id);
        if ($result !== true) return $result;

        $branchId = $this->configurationBranchScope->resolve($request, (string) $company->id);

        $validator = Validator::make($request->all(), [
            'code' => [
                'required',
                'string',
                'max:3',
                Rule::unique('currencies', 'code')->where(function ($q) use ($company, $branchId) {
                    $q->where('company_id', $company->id)
                        ->where('branch_id', $branchId)
                        ->whereNull('deleted_at');
                }),
            ],
            'name' => ['required', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'is_base_currency' => ['nullable', 'boolean'],
            'rate' => ['required', 'numeric', 'gt:0'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation error.',
                'data' => $validator->errors(),
            ], 422);
        }

        try {
            $created = DB::transaction(function () use ($request, $company, $branchId) {
                $code = strtoupper(trim((string) $request->input('code')));
                $name = trim((string) $request->input('name'));
                $country = $request->filled('country')
                    ? trim((string) $request->input('country'))
                    : null;

                $isActive = $request->has('is_active')
                    ? $request->boolean('is_active')
                    : true;

                $makeBase = $request->has('is_base_currency')
                    ? $request->boolean('is_base_currency')
                    : false;

                if (!$makeBase && $isActive && !$this->hasOtherActiveBaseCurrency((string) $company->id, $branchId)) {
                    $makeBase = true;
                }

                if ($makeBase) {
                    Currency::where('company_id', $company->id)
                        ->where('branch_id', $branchId)
                        ->update([
                            'is_base_currency' => false,
                        ]);
                }

                $currency = Currency::create([
                    'company_id' => $company->id,
                    'branch_id' => $branchId,
                    'code' => $code,
                    'name' => $name,
                    'country' => $country,
                    'is_active' => $isActive,
                    'is_base_currency' => $makeBase,
                ]);

                CurrencyRate::create([
                    'company_id' => $company->id,
                    'branch_id' => $branchId,
                    'currency_id' => $currency->id,
                    'rate' => (float) $request->input('rate'),
                ]);

                return $currency->load(['latestRate', 'branch']);
            });

            $this->publishMobileConfiguration((string) $company->id, $branchId);

            return response()->json([
                'message' => 'Currency created successfully.',
                'data' => $created,
            ], 201);
        } catch (QueryException $e) {
            \Log::error('Currency create SQL failed', [
                'message' => $e->getMessage(),
                'sql' => $e->getSql(),
                'bindings' => $e->getBindings(),
            ]);

            return response()->json([
                'message' => 'Database error while creating currency.',
            ], 500);
        } catch (\Throwable $e) {
            \Log::error('Currency create failed', [
                'error_class' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'message' => 'Something went wrong while creating the currency.',
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

        $result = hasPermission($user->role_id, 'view_currency', $company->id);
        if ($result !== true) return $result;

        $branchId = $this->configurationBranchScope->resolve($request, (string) $company->id);

        $currency = Currency::where('company_id', $company->id)
            ->where('id', $id)
            ->where(function ($sub) use ($branchId) {
                $sub->whereNull('branch_id');

                if ($branchId) {
                    $sub->orWhere('branch_id', $branchId);
                }
            })
            ->with(['latestRate', 'rates' => function ($query) {
                $query->orderByDesc('created_at');
            }, 'branch'])
            ->first();

        if (!$currency) {
            return response()->json([
                'message' => 'Currency not found.',
            ], 404);
        }

        return response()->json([
            'message' => 'Currency fetched successfully.',
            'data' => $currency,
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

        $result = hasPermission($user->role_id, 'update_currency', $company->id);
        if ($result !== true) return $result;

        $branchId = $this->configurationBranchScope->resolve($request, (string) $company->id);

        $currency = Currency::where('company_id', $company->id)
            ->where('id', $id)
            ->where('branch_id', $branchId)
            ->first();

        if (!$currency) {
            return response()->json([
                'message' => 'Currency not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'code' => [
                'required',
                'string',
                'max:3',
                Rule::unique('currencies', 'code')
                    ->ignore($currency->id)
                    ->where(function ($q) use ($company, $branchId) {
                        $q->where('company_id', $company->id)
                            ->where('branch_id', $branchId)
                            ->whereNull('deleted_at');
                    }),
            ],
            'name' => ['required', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'is_base_currency' => ['nullable', 'boolean'],
            'rate' => ['nullable', 'numeric', 'gt:0'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation error.',
                'data' => $validator->errors(),
            ], 422);
        }

        try {
            $updated = DB::transaction(function () use ($request, $company, $branchId, $currency) {
                $makeBase = $request->has('is_base_currency')
                    ? $request->boolean('is_base_currency')
                    : $currency->is_base_currency;
                $isActive = $request->has('is_active')
                    ? $request->boolean('is_active')
                    : (bool) $currency->is_active;

                if (!$makeBase && $isActive && !$this->hasOtherActiveBaseCurrency((string) $company->id, $branchId, (string) $currency->id)) {
                    $makeBase = true;
                }

                if ($makeBase) {
                    Currency::where('company_id', $company->id)
                        ->where('branch_id', $branchId)
                        ->where('id', '!=', $currency->id)
                        ->update([
                            'is_base_currency' => false,
                        ]);
                }

                $currency->update([
                    'code' => strtoupper(trim((string) $request->input('code'))),
                    'name' => trim((string) $request->input('name')),
                    'country' => $request->filled('country')
                        ? trim((string) $request->input('country'))
                        : null,
                    'is_active' => $isActive,
                    'is_base_currency' => $makeBase,
                ]);

                if ($request->filled('rate')) {
                    CurrencyRate::create([
                        'company_id' => $company->id,
                        'branch_id' => $branchId,
                        'currency_id' => $currency->id,
                        'rate' => (float) $request->input('rate'),
                    ]);
                }

                return $currency->fresh(['latestRate', 'branch']);
            });

            $this->publishMobileConfiguration((string) $company->id, $branchId);

            return response()->json([
                'message' => 'Currency updated successfully.',
                'data' => $updated,
            ], 200);
        } catch (QueryException $e) {
            \Log::error('Currency update SQL failed', [
                'message' => $e->getMessage(),
                'sql' => $e->getSql(),
                'bindings' => $e->getBindings(),
            ]);

            return response()->json([
                'message' => 'Database error while updating currency.',
            ], 500);
        } catch (\Throwable $e) {
            \Log::error('Currency update failed', [
                'error_class' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'message' => 'Something went wrong while updating the currency.',
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

        $result = hasPermission($user->role_id, 'delete_currency', $company->id);
        if ($result !== true) return $result;

        $branchId = $this->configurationBranchScope->resolve($request, (string) $company->id);

        $currency = Currency::where('company_id', $company->id)
            ->where('id', $id)
            ->where('branch_id', $branchId)
            ->first();

        if (!$currency) {
            return response()->json([
                'message' => 'Currency not found.',
            ], 404);
        }

        if ($currency->is_base_currency) {
            return response()->json([
                'message' => 'Base currency cannot be deleted.',
            ], 422);
        }

        $currency->delete();
        $this->publishMobileConfiguration((string) $company->id, $branchId);

        return response()->json([
            'message' => 'Currency deleted successfully.',
        ], 200);
    }

    public function addRate(Request $request, string $id)
    {
        $company = app('company');
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $result = hasPermission($user->role_id, 'update_currency', $company->id);
        if ($result !== true) return $result;

        $branchId = $this->configurationBranchScope->resolve($request, (string) $company->id);

        $currency = Currency::where('company_id', $company->id)
            ->where('id', $id)
            ->where('branch_id', $branchId)
            ->first();

        if (!$currency) {
            return response()->json([
                'message' => 'Currency not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'rate' => ['required', 'numeric', 'gt:0'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation error.',
                'data' => $validator->errors(),
            ], 422);
        }

        $rate = CurrencyRate::create([
            'company_id' => $company->id,
            'branch_id' => $branchId,
            'currency_id' => $currency->id,
            'rate' => (float) $request->input('rate'),
        ]);

        $this->publishMobileConfiguration((string) $company->id, $branchId);

        return response()->json([
            'message' => 'Currency rate added successfully.',
            'data' => $rate,
        ], 201);
    }

    /**
     * A shop cannot complete a sale without an active base currency (its own
     * or a company-wide one), so saving its only active currency as non-base
     * would leave every sale failing with "Base currency is not configured".
     */
    private function hasOtherActiveBaseCurrency(string $companyId, ?string $branchId, ?string $exceptCurrencyId = null): bool
    {
        return Currency::where('company_id', $companyId)
            ->where('is_base_currency', true)
            ->where('is_active', true)
            ->when($exceptCurrencyId, fn ($query) => $query->where('id', '!=', $exceptCurrencyId))
            ->when(
                $branchId,
                fn ($query) => $query->forBranchOrGlobal($branchId),
                fn ($query) => $query->whereNull('branch_id')
            )
            ->exists();
    }

    private function publishMobileConfiguration(string $companyId, ?string $branchId): void
    {
        try {
            /** @var SyncConfigurationService $configuration */
            $configuration = app(SyncConfigurationService::class);
            if ($branchId) {
                $configuration->publishBranchSnapshot($companyId, $branchId);

                return;
            }

            $configuration->publishCompanySnapshots($companyId);
        } catch (\Throwable $error) {
            // The currency transaction has already committed. Never report a
            // false mutation failure (which could make the operator retry and
            // create another rate); the next pull still regenerates the head.
            \Log::error('Currency saved but configuration publication failed', [
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'message' => $error->getMessage(),
            ]);
        }
    }



}
