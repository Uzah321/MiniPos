<?php

namespace Database\Seeders;

use App\Services\Sales\DiscountTypeProvisioner;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Company\Models\Company;
use Modules\Sales\Models\DiscountType;

class DiscountTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        foreach (Company::all() as $company) {

            foreach (DiscountTypeProvisioner::SYSTEM_TYPES as $type) {
                DiscountType::updateOrCreate(
                    [
                        'company_id' => $company->id,
                        'code' => $type['code'],
                    ],
                    [
                        'id' => DiscountType::where('company_id', $company->id)->where('code', $type['code'])->value('id') ?: (string) Str::uuid(),
                        'name' => $type['name'],
                        'scope' => $type['scope'],
                        'calculation_type' => $type['calculation_type'],
                        'requires_manager_approval' => $type['requires_manager_approval'] ?? false,
                        'requires_reason' => $type['requires_reason'] ?? false,
                        'allow_stacking' => $type['allow_stacking'] ?? false,
                        'is_system' => true,
                        'is_active' => true,
                    ]
                );
            }
        }
    }

}
