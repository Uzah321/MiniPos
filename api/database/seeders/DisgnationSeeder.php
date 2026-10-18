<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\RolesAndPermission\Models\Designation;

class DisgnationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $disignations = [
            [
                'designation'        => 'Shop Manager',

            ],
            [
                'designation'        => 'Cashier',

            ],
            [
                'designation'        => 'Shift Manager',

            ],
            [
                'designation'        => 'Accounts',

            ],

            [
                'designation'        => 'Costing',

            ],
            [
                'designation'        => 'Operations',

            ],
            [
                'designation'        => 'IT',

            ],
            [
                'designation'        => 'Head Office',

            ],

        ];


        foreach ($disignations as $disgnation) {
            Designation::firstOrCreate(
                ['designation' => $disgnation['designation']],
                ['slug' => Str::slug($disgnation['designation'])]
            );
        }
    }
}
