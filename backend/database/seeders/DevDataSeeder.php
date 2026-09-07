<?php

namespace Database\Seeders;

use App\Customers\Customer;
use App\Discounts\Coupon;
use App\Discounts\Promotion;
use App\Items\Item;
use App\Models\Store;
use App\Models\User;
use App\Terminals\Terminal;
use App\Terminals\TerminalStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DevDataSeeder extends Seeder
{
    public function run(): void
    {
        $store = Store::firstOrCreate(
            ['code' => 'STORE-001'],
            ['name' => 'Simbisa Demo Store', 'timezone' => 'UTC']
        );

        $terminal = Terminal::firstOrCreate(
            ['registration_code' => 'TERM-DEV-001', 'store_id' => $store->id],
            ['name' => 'Front Counter 1', 'status' => TerminalStatus::Active, 'app_version' => '0.1.0']
        );

        $manager = User::firstOrCreate(
            ['email' => 'manager@simbisapos.test'],
            [
                'name' => 'Demo Manager',
                'employee_code' => 'MGR001',
                'password' => Hash::make('password'),
                'pin_hash' => Hash::make('1234'),
                'store_id' => $store->id,
                'active' => true,
            ]
        );
        $manager->syncRoles(['manager']);

        $cashier = User::firstOrCreate(
            ['email' => 'cashier@simbisapos.test'],
            [
                'name' => 'Demo Cashier',
                'employee_code' => 'CSH001',
                'password' => Hash::make('password'),
                'pin_hash' => Hash::make('1111'),
                'store_id' => $store->id,
                'active' => true,
            ]
        );
        $cashier->syncRoles(['cashier']);

        $cola = Item::firstOrCreate(['sku' => 'SKU-COLA-500'], [
            'barcode' => '6001001000015', 'name' => 'Cola 500ml', 'price' => 15, 'tax_rate' => 0.15, 'active' => true,
        ]);
        Item::firstOrCreate(['sku' => 'SKU-BURGER-STD'], [
            'barcode' => '6001001000022', 'name' => 'Standard Burger', 'price' => 55, 'tax_rate' => 0.15, 'active' => true,
        ]);
        Item::firstOrCreate(['sku' => 'SKU-CHIPS-REG'], [
            'barcode' => '6001001000039', 'name' => 'Regular Chips', 'price' => 25, 'tax_rate' => 0.15, 'active' => true,
        ]);

        Promotion::firstOrCreate(['item_id' => $cola->id], [
            'min_quantity' => 3, 'discount_type' => 'percent', 'discount_value' => 10, 'active' => true,
        ]);

        Coupon::firstOrCreate(['code' => 'WELCOME10'], [
            'discount_type' => 'percent', 'discount_value' => 10, 'usage_limit' => null, 'active' => true,
        ]);

        Customer::firstOrCreate(['loyalty_id' => 'LOY-DEMO-001'], [
            'name' => 'Demo Loyalty Customer', 'phone' => '0821112222', 'account_status' => 'none', 'loyalty_points_balance' => 100,
        ]);

        $this->command?->info("Seeded store [{$store->code}], terminal [{$terminal->registration_code}], manager [manager@simbisapos.test / password], cashier [cashier@simbisapos.test / password].");
    }
}
