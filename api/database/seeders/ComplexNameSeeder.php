<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Brand\Models\ComplexName;
use Modules\RolesAndPermission\Models\Permission;

class ComplexNameSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */

        public function run(): void
    {
        {
            $designations = [
                [
                    'name' => 'Samora Matchell Avenue',
                    'country' => 'ZIMBABWE',
                    'location' => '57 Samora Matchell Avenue',
                ],
                [
                    'name' => 'Msasa Main',
                    'country' => 'ZIMBABWE',
                    'location' => '12 Mutare Road',
                ],
                [
                    'name' => 'Glow Msasa',
                    'country' => 'ZIMBABWE',
                    'location' => '12 Samora Matchell Avenue',
                ],
            ];

            foreach ($designations as $designation) {
                ComplexName::firstOrCreate(
                    [
                        'name' => $designation['name'],
                        'country' => $designation['country'],
                        'location' => $designation['location'],// optional useful extra field
                    ]
                );
            }
        }
    }
    }

