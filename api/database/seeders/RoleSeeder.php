<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\RolesAndPermission\Models\Designation;
use Modules\RolesAndPermission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $disignations = [
            [
                'name'        => 'Super Admin',
                'description'        => 'Super Admin',

            ],


        ];


        foreach ($disignations as $disgnation) {
            Role::firstOrCreate(
                ['name' => $disgnation['name']],
                ['description' => $disgnation['description']],
            );
        }
    }
}
