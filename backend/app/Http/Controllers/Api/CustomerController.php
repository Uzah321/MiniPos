<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Sale;
use App\Services\SaleAdjustmentService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    public function __construct(private readonly SaleAdjustmentService $adjustments) {}

    /**
     * Search by loyalty ID, phone, email or account number.
     */
    public function index(Request $request)
    {
        $data = $request->validate(['query' => ['required', 'string']]);
        $query = $data['query'];

        $customers = Customer::query()
            ->where('loyalty_id', $query)
            ->orWhere('phone', $query)
            ->orWhere('email', $query)
            ->when(is_numeric($query), fn ($q) => $q->orWhere('id', (int) $query))
            ->limit(10)
            ->get();

        return response()->json(['customers' => $customers]);
    }

    /**
     * Attach customer: verify the profile exists and attach it to the sale.
     */
    public function attach(Request $request, Sale $sale)
    {
        $this->assertOwnedByCashier($request, $sale);

        $data = $request->validate(['query' => ['required', 'string']]);
        $query = $data['query'];

        $customer = Customer::query()
            ->where('loyalty_id', $query)
            ->orWhere('phone', $query)
            ->orWhere('email', $query)
            ->when(is_numeric($query), fn ($q) => $q->orWhere('id', (int) $query))
            ->first();

        if (! $customer) {
            throw ValidationException::withMessages(['query' => ['No matching customer profile was found.']]);
        }

        $sale = $this->adjustments->attachCustomer($sale, $customer, $request->user());

        return response()->json(['sale' => $sale, 'customer' => $customer]);
    }

    /**
     * Quick customer registration: minimum details, explicit consent, then
     * attach to the current sale.
     */
    public function register(Request $request, Sale $sale)
    {
        $this->assertOwnedByCashier($request, $sale);

        $data = $request->validate([
            'name' => ['required', 'string'],
            'phone' => ['nullable', 'string'],
            'email' => ['nullable', 'email'],
            'consent' => ['required', 'accepted'],
        ]);

        $sale = $this->adjustments->registerAndAttachCustomer($sale, $data, $request->user());

        return response()->json(['sale' => $sale->load('customer')], 201);
    }

    /**
     * Remove customer: warns via the sale response (customer becomes null,
     * dropping any attached pricing/rewards eligibility) and recalculates.
     */
    public function remove(Request $request, Sale $sale)
    {
        $this->assertOwnedByCashier($request, $sale);

        $sale = $this->adjustments->removeCustomer($sale, $request->user());

        return response()->json(['sale' => $sale]);
    }

    private function assertOwnedByCashier(Request $request, Sale $sale): void
    {
        if ($sale->cashier_id !== $request->user()->id) {
            throw ValidationException::withMessages(['sale' => ['This sale does not belong to you.']]);
        }
    }
}
