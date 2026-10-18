<?php

namespace App\Services\Manufacturing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\ProductionOrder;
use Modules\Manufacturing\Models\ProductionOrderInput;
use Modules\Manufacturing\Models\ProductionOrderOutput;
use Modules\Manufacturing\Models\ProductionOrderOverhead;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductCost;
use Modules\Product\Models\ProductStock;
use Modules\Product\Models\StockMovement;

class ProductionOrderService
{
    public function __construct(protected ProductionCostingService $costingService)
    {
    }

    private array $relations = [
        'plan',
        'planItem.finishedProduct',
        'planItem.recipe',
        'finishedProduct.uom',
        'recipe.items.product.uom',
        'inputs.product.uom',
        'outputs.product.uom',
        'overheads',
    ];

    private function freshOrder(ProductionOrder $order): ProductionOrder
    {
        return $order->fresh($this->relations);
    }

    private function recalculateAndSaveActualCosts(ProductionOrder $order): ProductionOrder
    {
        $fresh = $order->fresh(['inputs', 'overheads']);

        $costs = $this->costingService->recalculateActualCosts($fresh);

        $fresh->actual_raw_material_cost = $costs['rawMaterialCost'];
        $fresh->actual_labour_cost = $costs['labourCost'];
        $fresh->actual_overhead_cost = $costs['overheadCost'];
        $fresh->actual_total_cost = $costs['totalCost'];
        $fresh->actual_unit_cost = $costs['unitCost'];

        if ((float) $fresh->planned_total_cost > 0) {
            $fresh->cost_variance_amount =
                (float) $fresh->actual_total_cost - (float) $fresh->planned_total_cost;
        }

        if ((float) $fresh->actual_good_quantity > 0 && (float) $fresh->planned_output_quantity > 0) {
            $fresh->yield_variance_quantity =
                (float) $fresh->actual_good_quantity - (float) $fresh->planned_output_quantity;
        }

        $fresh->save();

        return $fresh;
    }

    public function reserveMaterials(ProductionOrder $order): ProductionOrder
    {
        return DB::transaction(function () use ($order) {
            if ($order->status !== 'approved') {
                throw new \Exception('Only approved production orders can reserve materials.');
            }

            foreach ($order->inputs()->lockForUpdate()->get() as $input) {
                $stock = ProductStock::where('company_id', $order->company_id)
                    ->where('branch_id', $order->branch_id)
                    ->where('product_id', $input->product_id)
                    ->lockForUpdate()
                    ->first();

                $product = Product::where('company_id', $order->company_id)
                    ->where('id', $input->product_id)
                    ->first();

                if (!$stock || (float) $stock->available_quantity < (float) $input->planned_quantity) {
                    throw new \Exception(
                        'Insufficient stock to reserve material: ' .
                        ($product?->name ?? $input->product_id)
                    );
                }

                $quantity = (float) $input->planned_quantity;

                $stock->available_quantity = (float) $stock->available_quantity - $quantity;
                $stock->reserved_quantity = (float) $stock->reserved_quantity + $quantity;
                $stock->save();

                $input->reserved_quantity = $quantity;
                $input->save();

                StockMovement::create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $order->company_id,
                    'branch_id' => $order->branch_id,
                    'product_id' => $input->product_id,
                    'movement_type' => 'production_material_reserve',
                    'quantity_in' => 0,
                    'quantity_out' => 0,
                    'unit_cost' => 0,
                    'total_cost' => 0,
                    'currency' => $input->currency ?: $order->currency,
                    'reference_type' => 'production_order',
                    'reference_id' => $order->id,
                    'notes' => 'Material reserved for production order ' . $order->production_number,
                    'created_by' => auth()->id(),
                    'metadata' => [
                        'reserved_quantity' => $quantity,
                        'production_number' => $order->production_number,
                    ],
                ]);
            }

            $order->status = 'materials_reserved';
            $order->save();

            return $this->freshOrder($order);
        });
    }

    public function issueMaterials(ProductionOrder $order): ProductionOrder
    {
        return DB::transaction(function () use ($order) {
            if (!in_array($order->status, ['approved', 'materials_reserved'], true)) {
                throw new \Exception('Only approved or reserved production orders can issue materials.');
            }

            foreach ($order->inputs()->lockForUpdate()->get() as $input) {
                $product = Product::where('company_id', $order->company_id)
                    ->where('id', $input->product_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $stock = ProductStock::where('company_id', $order->company_id)
                    ->where('branch_id', $order->branch_id)
                    ->where('product_id', $product->id)
                    ->lockForUpdate()
                    ->first();

                if (!$stock) {
                    throw new \Exception("No stock record found for {$product->name}.");
                }

                $quantity = (float) $input->reserved_quantity > 0
                    ? (float) $input->reserved_quantity
                    : (float) $input->planned_quantity;

                if ($quantity <= 0) {
                    throw new \Exception("Invalid issue quantity for {$product->name}.");
                }

                if ((float) $input->reserved_quantity > 0) {
                    if ((float) $stock->reserved_quantity < $quantity) {
                        throw new \Exception("Insufficient reserved stock for {$product->name}.");
                    }

                    $stock->reserved_quantity = (float) $stock->reserved_quantity - $quantity;
                    $stock->quantity_on_hand = (float) $stock->quantity_on_hand - $quantity;
                } else {
                    if (!(bool) $product->allow_negative_stock && (float) $stock->available_quantity < $quantity) {
                        throw new \Exception("Insufficient stock for {$product->name}.");
                    }

                    $stock->available_quantity = (float) $stock->available_quantity - $quantity;
                    $stock->quantity_on_hand = (float) $stock->quantity_on_hand - $quantity;
                }

                $stock->save();

                $cost = ProductCost::where('company_id', $order->company_id)
                    ->where('branch_id', $order->branch_id)
                    ->where('product_id', $product->id)
                    ->lockForUpdate()
                    ->first();

                $unitCost = (float) ($cost?->average_cost ?? $input->unit_cost ?? 0);
                $totalCost = $quantity * $unitCost;
                $currency = $cost?->currency ?? $input->currency ?? $order->currency;

                $input->issued_quantity = $quantity;
                $input->unit_cost = $unitCost;
                $input->total_cost = $totalCost;
                $input->currency = $currency;
                $input->save();

                StockMovement::create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $order->company_id,
                    'branch_id' => $order->branch_id,
                    'product_id' => $product->id,
                    'movement_type' => 'production_issue',
                    'quantity_in' => 0,
                    'quantity_out' => $quantity,
                    'unit_cost' => $unitCost,
                    'total_cost' => $totalCost,
                    'currency' => $currency,
                    'reference_type' => 'production_order',
                    'reference_id' => $order->id,
                    'notes' => 'Raw material issued to production order ' . $order->production_number,
                    'created_by' => auth()->id(),
                    'metadata' => [
                        'production_number' => $order->production_number,
                        'input_id' => $input->id,
                    ],
                ]);
            }

            $order->status = 'materials_issued';
            $order->save();

            $this->recalculateAndSaveActualCosts($order);

            return $this->freshOrder($order);
        });
    }

    public function start(ProductionOrder $order): ProductionOrder
    {
        if ($order->status !== 'materials_issued') {
            throw new \Exception('Only material-issued production orders can be started.');
        }

        $order->status = 'in_progress';
        $order->started_at = $order->started_at ?: now();
        $order->save();

        return $this->freshOrder($order);
    }

    public function addOverhead(ProductionOrder $order, array $data): ProductionOrderOverhead
    {
        if (in_array($order->status, ['completed', 'cancelled'], true)) {
            throw new \Exception('Cannot add production cost to completed or cancelled production orders.');
        }

        if (!in_array($order->status, ['materials_issued', 'in_progress'], true)) {
            throw new \Exception('Production costs can only be captured after materials are issued.');
        }

        return DB::transaction(function () use ($order, $data) {
            $costType = $data['cost_type'];
            $amount = (float) $data['amount'];

            $costClass = match ($costType) {
                'labour' => 'direct_labour',
                'salary' => 'indirect_labour',
                'electricity', 'fuel', 'water', 'rent', 'machine_maintenance', 'depreciation' => 'factory_overhead',
                default => 'other',
            };

            $overhead = ProductionOrderOverhead::create([
                'id' => (string) Str::uuid(),
                'company_id' => $order->company_id,
                'branch_id' => $order->branch_id,
                'production_order_id' => $order->id,
                'cost_type' => $costType,
                'cost_class' => $data['cost_class'] ?? $costClass,
                'description' => $data['description'] ?? null,
                'quantity' => (float) ($data['quantity'] ?? 1),
                'rate' => (float) ($data['rate'] ?? $amount),
                'staff_name' => $data['staff_name'] ?? null,
                'staff_id' => $data['staff_id'] ?? null,
                'amount' => $amount,
                'currency' => strtoupper($data['currency'] ?? $order->currency),
                'expense_id' => $data['expense_id'] ?? null,
                'payroll_id' => $data['payroll_id'] ?? null,
                'metadata' => $data['metadata'] ?? [],
                'created_by' => auth()->id(),
            ]);

            $this->recalculateAndSaveActualCosts($order);

            return $overhead;
        });
    }

    public function returnMaterial(
        ProductionOrder $order,
        string $inputId,
        float $quantity,
        ?string $notes = null
    ): ProductionOrderInput {
        return DB::transaction(function () use ($order, $inputId, $quantity, $notes) {
            if (!in_array($order->status, ['materials_issued', 'in_progress'], true)) {
                throw new \Exception('Materials can only be returned while production is issued or in progress.');
            }

            $input = ProductionOrderInput::where('production_order_id', $order->id)
                ->where('id', $inputId)
                ->lockForUpdate()
                ->firstOrFail();

            $availableToReturn =
                (float) $input->issued_quantity -
                (float) $input->returned_quantity -
                (float) $input->wasted_quantity;

            if ($quantity <= 0 || $quantity > $availableToReturn) {
                throw new \Exception('Invalid material return quantity.');
            }

            $stock = ProductStock::firstOrCreate(
                [
                    'company_id' => $order->company_id,
                    'branch_id' => $order->branch_id,
                    'product_id' => $input->product_id,
                ],
                [
                    'quantity_on_hand' => 0,
                    'reserved_quantity' => 0,
                    'available_quantity' => 0,
                ]
            );

            $stock = ProductStock::where('id', $stock->id)
                ->lockForUpdate()
                ->first();

            $stock->quantity_on_hand = (float) $stock->quantity_on_hand + $quantity;
            $stock->available_quantity = (float) $stock->available_quantity + $quantity;
            $stock->save();

            $unitCost = (float) $input->unit_cost;
            $totalCost = $quantity * $unitCost;

            $input->returned_quantity = (float) $input->returned_quantity + $quantity;
            $input->total_cost = max(0, (float) $input->total_cost - $totalCost);
            $input->save();

            StockMovement::create([
                'id' => (string) Str::uuid(),
                'company_id' => $order->company_id,
                'branch_id' => $order->branch_id,
                'product_id' => $input->product_id,
                'movement_type' => 'production_return',
                'quantity_in' => $quantity,
                'quantity_out' => 0,
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
                'currency' => $input->currency,
                'reference_type' => 'production_order',
                'reference_id' => $order->id,
                'notes' => $notes ?: 'Unused production material returned to stock.',
                'created_by' => auth()->id(),
                'metadata' => [
                    'production_number' => $order->production_number,
                    'input_id' => $input->id,
                ],
            ]);

            $this->recalculateAndSaveActualCosts($order);

            return $input->fresh('product.uom');
        });
    }

    public function recordWastage(
        ProductionOrder $order,
        string $inputId,
        float $quantity,
        ?string $notes = null
    ): ProductionOrderInput {
        return DB::transaction(function () use ($order, $inputId, $quantity, $notes) {
            if (!in_array($order->status, ['materials_issued', 'in_progress'], true)) {
                throw new \Exception('Material wastage can only be recorded while production is issued or in progress.');
            }

            $input = ProductionOrderInput::where('production_order_id', $order->id)
                ->where('id', $inputId)
                ->lockForUpdate()
                ->firstOrFail();

            $availableToWaste =
                (float) $input->issued_quantity -
                (float) $input->returned_quantity -
                (float) $input->wasted_quantity;

            if ($quantity <= 0 || $quantity > $availableToWaste) {
                throw new \Exception('Invalid material wastage quantity.');
            }

            $unitCost = (float) $input->unit_cost;
            $totalCost = $quantity * $unitCost;

            $input->wasted_quantity = (float) $input->wasted_quantity + $quantity;
            $input->save();

            StockMovement::create([
                'id' => (string) Str::uuid(),
                'company_id' => $order->company_id,
                'branch_id' => $order->branch_id,
                'product_id' => $input->product_id,
                'movement_type' => 'production_wastage',
                'quantity_in' => 0,
                'quantity_out' => 0,
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
                'currency' => $input->currency,
                'reference_type' => 'production_order',
                'reference_id' => $order->id,
                'notes' => $notes ?: 'Production raw material wastage recorded.',
                'created_by' => auth()->id(),
                'metadata' => [
                    'production_number' => $order->production_number,
                    'input_id' => $input->id,
                    'wastage_quantity' => $quantity,
                    'wastage_cost' => $totalCost,
                ],
            ]);

            $this->recalculateAndSaveActualCosts($order);

            return $input->fresh('product.uom');
        });
    }

    public function complete(
        ProductionOrder $order,
        float $goodQuantity,
        float $rejectQuantity = 0,
        float $wasteQuantity = 0
    ): ProductionOrder {
        return DB::transaction(function () use ($order, $goodQuantity, $rejectQuantity, $wasteQuantity) {
            if (!in_array($order->status, ['materials_issued', 'in_progress'], true)) {
                throw new \Exception('Only material-issued or in-progress production orders can be completed.');
            }

            if ($goodQuantity <= 0) {
                throw new \Exception('Actual good quantity must be greater than zero.');
            }

            if ($rejectQuantity < 0 || $wasteQuantity < 0) {
                throw new \Exception('Reject and waste quantities cannot be negative.');
            }

            if ($order->outputs()->exists()) {
                throw new \Exception('This production order already has output recorded.');
            }

            $finishedProduct = Product::where('company_id', $order->company_id)
                ->where('id', $order->finished_product_id)
                ->lockForUpdate()
                ->firstOrFail();

            $order->actual_good_quantity = $goodQuantity;
            $order->actual_reject_quantity = $rejectQuantity;
            $order->actual_waste_quantity = $wasteQuantity;
            $order->save();

            $order = $this->recalculateAndSaveActualCosts($order);

            $costs = [
                'rawMaterialCost' => (float) $order->actual_raw_material_cost,
                'labourCost' => (float) $order->actual_labour_cost,
                'overheadCost' => (float) $order->actual_overhead_cost,
                'totalCost' => (float) $order->actual_total_cost,
                'unitCost' => (float) $order->actual_unit_cost,
            ];

            $stock = ProductStock::firstOrCreate(
                [
                    'company_id' => $order->company_id,
                    'branch_id' => $order->branch_id,
                    'product_id' => $finishedProduct->id,
                ],
                [
                    'quantity_on_hand' => 0,
                    'reserved_quantity' => 0,
                    'available_quantity' => 0,
                ]
            );

            $stock = ProductStock::where('id', $stock->id)
                ->lockForUpdate()
                ->first();

            $stock->quantity_on_hand = (float) $stock->quantity_on_hand + $goodQuantity;
            $stock->available_quantity = (float) $stock->available_quantity + $goodQuantity;
            $stock->save();

            $this->costingService->addAverageCost(
                $finishedProduct,
                $order->branch_id,
                $goodQuantity,
                $costs['unitCost'],
                $order->currency
            );

            ProductionOrderOutput::create([
                'id' => (string) Str::uuid(),
                'company_id' => $order->company_id,
                'branch_id' => $order->branch_id,
                'production_order_id' => $order->id,
                'product_id' => $finishedProduct->id,
                'uom_id' => $finishedProduct->base_uom_id,
                'quantity_produced' => $goodQuantity,
                'quantity_rejected' => $rejectQuantity,
                'quantity_wasted' => $wasteQuantity,
                'unit_cost' => $costs['unitCost'],
                'total_cost' => $costs['totalCost'],
                'currency' => $order->currency,
                'metadata' => [
                    'source' => 'production_completion',
                    'production_number' => $order->production_number,
                    'planned_output_quantity' => $order->planned_output_quantity,
                    'actual_good_quantity' => $goodQuantity,
                    'actual_reject_quantity' => $rejectQuantity,
                    'actual_waste_quantity' => $wasteQuantity,
                    'direct_material_cost' => $costs['rawMaterialCost'],
                    'direct_labour_cost' => $costs['labourCost'],
                    'factory_overhead_cost' => $costs['overheadCost'],
                ],
            ]);

            StockMovement::create([
                'id' => (string) Str::uuid(),
                'company_id' => $order->company_id,
                'branch_id' => $order->branch_id,
                'product_id' => $finishedProduct->id,
                'movement_type' => 'production_output',
                'quantity_in' => $goodQuantity,
                'quantity_out' => 0,
                'unit_cost' => $costs['unitCost'],
                'total_cost' => $costs['totalCost'],
                'currency' => $order->currency,
                'reference_type' => 'production_order',
                'reference_id' => $order->id,
                'notes' => 'Finished goods received from production order ' . $order->production_number,
                'created_by' => auth()->id(),
                'metadata' => [
                    'production_number' => $order->production_number,
                    'good_quantity' => $goodQuantity,
                    'reject_quantity' => $rejectQuantity,
                    'waste_quantity' => $wasteQuantity,
                ],
            ]);

            $order->yield_variance_quantity =
                $goodQuantity - (float) $order->planned_output_quantity;

            $order->cost_variance_amount =
                $costs['totalCost'] - (float) $order->planned_total_cost;

            $order->status = 'completed';
            $order->completed_at = now();
            $order->save();

            return $this->freshOrder($order);
        });
    }

    public function cancel(ProductionOrder $order): ProductionOrder
    {
        return DB::transaction(function () use ($order) {
            if (in_array($order->status, ['completed', 'cancelled'], true)) {
                throw new \Exception('Completed or cancelled production orders cannot be cancelled.');
            }

            if (in_array($order->status, ['materials_issued', 'in_progress'], true)) {
                throw new \Exception(
                    'This order has already issued materials. Return unused materials or complete the order instead.'
                );
            }

            if ($order->status === 'materials_reserved') {
                foreach ($order->inputs()->lockForUpdate()->get() as $input) {
                    if ((float) $input->reserved_quantity <= 0) {
                        continue;
                    }

                    $stock = ProductStock::where('company_id', $order->company_id)
                        ->where('branch_id', $order->branch_id)
                        ->where('product_id', $input->product_id)
                        ->lockForUpdate()
                        ->first();

                    if ($stock) {
                        $stock->reserved_quantity = max(
                            0,
                            (float) $stock->reserved_quantity - (float) $input->reserved_quantity
                        );

                        $stock->available_quantity =
                            (float) $stock->available_quantity +
                            (float) $input->reserved_quantity;

                        $stock->save();
                    }

                    $input->reserved_quantity = 0;
                    $input->save();
                }
            }

            $order->status = 'cancelled';
            $order->save();

            return $this->freshOrder($order);
        });
    }
}
