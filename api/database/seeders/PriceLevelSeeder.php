<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PriceLevelSeeder extends Seeder
{
    public function run(): void
    {
        $companies = DB::table('companies')->get();

        foreach ($companies as $company) {
            $priceLevels = [
                [
                    'name' => 'Standard',
                    'code' => 'STANDARD',
                    'description' => 'Default standard selling price level.',
                    'is_default' => true,
                    'is_active' => true,
                ],
                [
                    'name' => 'Wholesale',
                    'code' => 'WHOLESALE',
                    'description' => 'Wholesale customer price level.',
                    'is_default' => false,
                    'is_active' => true,
                ],
                [
                    'name' => 'Staff',
                    'code' => 'STAFF',
                    'description' => 'Staff discount price level.',
                    'is_default' => false,
                    'is_active' => true,
                ],
                [
                    'name' => 'Promo',
                    'code' => 'PROMO',
                    'description' => 'Promotional price level.',
                    'is_default' => false,
                    'is_active' => true,
                ],
            ];

            foreach ($priceLevels as $priceLevel) {
                DB::table('price_levels')->updateOrInsert(
                    [
                        'company_id' => $company->id,
                        'code' => $priceLevel['code'],
                    ],
                    [
                        'id' => (string) Str::uuid(),
                        'name' => $priceLevel['name'],
                        'description' => $priceLevel['description'],
                        'is_default' => $priceLevel['is_default'],
                        'is_active' => $priceLevel['is_active'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }
}
