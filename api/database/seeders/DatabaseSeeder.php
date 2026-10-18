<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        //        User::factory()->create([
        //            'name' => 'Test User',
        //            'email' => 'test@example.com',
        //        ]);
        //        $this->call(DisgnationSeeder::class);
        $this->call(RoleSeeder::class);
        $this->call(PermissionSeeder::class);
        $this->call(GeneralSettingsSeeder::class);
        $this->call(DiscountTypeSeeder::class);
        //        $this->call(QuotationPermissionSeeder::class);
        //        $this->call(PriceLevelSeeder::class);

        $this->call(UomSeeder::class);
        //        $this->call(ComplexNameSeeder::class);

        if (app()->environment(['local', 'testing'])
            || (bool) config('servnest.simulation.seed_data', false)) {
            $this->call(SimulationSuperAdminSeeder::class);
        }

        //        $this->call([
        //            CompanySeeder::class,
        //        ]);

    }
}
