<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_location_balances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('branch_id')->index();
            $table->uuid('warehouse_id')->index();
            $table->uuid('location_id')->index();
            $table->uuid('product_id')->index();
            $table->uuid('batch_id')->nullable()->index();
            $table->decimal('quantity_on_hand', 18, 4)->default(0);
            $table->decimal('reserved_quantity', 18, 4)->default(0);
            $table->decimal('available_quantity', 18, 4)->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'location_id', 'product_id', 'batch_id'], 'inventory_location_balances_unique');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('company_branches')->cascadeOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('inventory_warehouses')->cascadeOnDelete();
            $table->foreign('location_id')->references('id')->on('inventory_locations')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('batch_id')->references('id')->on('inventory_batches')->nullOnDelete();
        });

        $now = now();
        foreach (DB::table('company_branches')->select(['id', 'company_id', 'branch_code', 'branch_name'])->get() as $branch) {
            $warehouseId = (string) Str::uuid();
            $warehouseCode = 'MAIN-' . strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', (string) ($branch->branch_code ?: $branch->id)), 0, 24));
            DB::table('inventory_warehouses')->insert([
                'id' => $warehouseId,
                'company_id' => $branch->company_id,
                'branch_id' => $branch->id,
                'code' => $warehouseCode,
                'name' => ($branch->branch_name ?: 'Branch') . ' Main Warehouse',
                'warehouse_type' => 'general',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $locations = [];
            foreach ([['BACK', 'Back Stock', 'storage', false], ['FRONT', 'Front / Selling Stock', 'picking', false], ['QUAR', 'Quarantine', 'quarantine', true]] as [$code, $name, $type, $quarantine]) {
                $locationId = (string) Str::uuid();
                $locations[$code] = $locationId;
                DB::table('inventory_locations')->insert([
                    'id' => $locationId,
                    'company_id' => $branch->company_id,
                    'branch_id' => $branch->id,
                    'warehouse_id' => $warehouseId,
                    'code' => $code,
                    'name' => $name,
                    'location_type' => $type,
                    'pick_sequence' => $code === 'FRONT' ? 10 : 20,
                    'is_quarantine' => $quarantine,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            foreach (DB::table('product_stocks')->where('company_id', $branch->company_id)->where('branch_id', $branch->id)->get() as $stock) {
                $locationId = strtolower((string) $stock->stock_area) === 'front' ? $locations['FRONT'] : $locations['BACK'];
                DB::table('inventory_location_balances')->insert([
                    'id' => (string) Str::uuid(),
                    'company_id' => $branch->company_id,
                    'branch_id' => $branch->id,
                    'warehouse_id' => $warehouseId,
                    'location_id' => $locationId,
                    'product_id' => $stock->product_id,
                    'batch_id' => null,
                    'quantity_on_hand' => $stock->quantity_on_hand,
                    'reserved_quantity' => $stock->reserved_quantity,
                    'available_quantity' => $stock->available_quantity,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_location_balances');
        DB::table('inventory_locations')->whereIn('code', ['BACK', 'FRONT', 'QUAR'])->delete();
        DB::table('inventory_warehouses')->where('code', 'like', 'MAIN-%')->delete();
    }
};
