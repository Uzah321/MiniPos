<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Modules\Authentication\Database\Seeders\AuthenticationDatabaseSeeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $seeders = [
            RolePermissionSeeder::class,
            AuthenticationDatabaseSeeder::class,
        ];

        if (app()->environment(['local', 'testing'])) {
            $seeders[] = DevDataSeeder::class;
        }

        $this->call($seeders);
    }
}
