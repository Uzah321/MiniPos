<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Product\Support\StandardUomCatalogue;

class UomSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (StandardUomCatalogue::definitions() as $uom) {
                $id = StandardUomCatalogue::idForCode($uom['code']);
                $existingCode = DB::table('uoms')->where('id', $id)->value('code');
                if ($existingCode !== null && strtoupper((string) $existingCode) !== $uom['code']) {
                    throw new \RuntimeException(
                        "The deterministic UOM ID for {$uom['code']} is already used by another unit."
                    );
                }

                $values = [
                    'company_id' => null,
                    'name' => $uom['name'],
                    'code' => $uom['code'],
                    'quantity_type' => $uom['quantity_type'],
                    'decimal_places' => $uom['decimal_places'],
                    'is_active' => true,
                    'deleted_at' => null,
                    'updated_at' => now(),
                ];

                if (DB::table('uoms')->where('id', $id)->exists()) {
                    DB::table('uoms')->where('id', $id)->update($values);

                    continue;
                }

                DB::table('uoms')->insert([
                    'id' => $id,
                    ...$values,
                    'created_at' => now(),
                ]);
            }
        });
    }
}
