<?php

namespace App\Services\Inventory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Company\Models\CompanyBranch;

class InventoryWarehouseProvisioner
{
    public function ensureBranchDefaults(CompanyBranch $branch): string
    {
        $existing = DB::table('inventory_warehouses')
            ->where('company_id', $branch->company_id)
            ->where('branch_id', $branch->id)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->first();
        if ($existing) return $existing->id;

        $warehouseId = (string) Str::uuid();
        $code = $this->availableCode(
            (string) $branch->company_id,
            'MAIN-' . strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', (string) ($branch->branch_code ?: $branch->id)), 0, 24))
        );
        $now = now();

        DB::table('inventory_warehouses')->insert([
            'id' => $warehouseId,
            'company_id' => $branch->company_id,
            'branch_id' => $branch->id,
            'code' => $code,
            'name' => ($branch->branch_name ?: 'Branch') . ' Main Warehouse',
            'warehouse_type' => 'general',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ([['BACK', 'Back Stock', 'storage', false], ['FRONT', 'Front / Selling Stock', 'picking', false], ['QUAR', 'Quarantine', 'quarantine', true]] as [$locationCode, $name, $type, $quarantine]) {
            DB::table('inventory_locations')->insert([
                'id' => (string) Str::uuid(),
                'company_id' => $branch->company_id,
                'branch_id' => $branch->id,
                'warehouse_id' => $warehouseId,
                'code' => $locationCode,
                'name' => $name,
                'location_type' => $type,
                'pick_sequence' => $locationCode === 'FRONT' ? 10 : 20,
                'is_quarantine' => $quarantine,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $warehouseId;
    }

    /**
     * Warehouses outlive a deleted branch because they hold its stock history,
     * so a branch re-added with the same code needs a fresh warehouse code.
     */
    private function availableCode(string $companyId, string $base): string
    {
        $taken = DB::table('inventory_warehouses')
            ->where('company_id', $companyId)
            ->where('code', 'like', $base . '%')
            ->pluck('code')
            ->map(fn ($code) => strtoupper((string) $code))
            ->all();

        $code = $base;
        for ($suffix = 2; in_array($code, $taken, true); $suffix++) {
            $code = $base . '-' . $suffix;
        }

        return $code;
    }
}
