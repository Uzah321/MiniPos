<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CompanySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $companies = [
            [
                'trade_name'          => 'SIMBISA BRANDS ZIMBABWE PVT LTD',
                'registration_number' => '53395',
                'vat_number'          => '220006604',
                'buyer_tin'           => '2000231759',
                'email'               => 'helpdesk@simbisa.co.zw',
                'phone'               => '0242780724-8',
                'province'            => 'Harare',
                'city'                => 'Harare',
                'street'              => 'Fife Avenue',
                'house_number'        => '16',
                'district'            => 'CBD',
                'country'             => 'Zimbabwe',
                'logo'                => 'null',
            ],

        ];

        DB::table('companies')->insert($companies);
    }
}
