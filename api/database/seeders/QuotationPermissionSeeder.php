<?php
//
//namespace Database\Seeders;
//
//use Illuminate\Database\Seeder;
//use Illuminate\Support\Facades\DB;
//use Illuminate\Support\Str;
//
//class QuotationPermissionSeeder extends Seeder
//{
//    public function run(): void
//    {
//        $permissions = [
//            ['name' => 'view_quotations', 'module' => 'quotation', 'description' => 'View quotation desk and quotations'],
//            ['name' => 'create_quotations', 'module' => 'quotation', 'description' => 'Create quotations'],
//            ['name' => 'update_quotations', 'module' => 'quotation', 'description' => 'Update quotations'],
//            ['name' => 'issue_quotations', 'module' => 'quotation', 'description' => 'Issue/print quotations'],
//            ['name' => 'cancel_quotations', 'module' => 'quotation', 'description' => 'Cancel quotations'],
//            ['name' => 'redeem_quotations', 'module' => 'quotation', 'description' => 'Load quotation into create sale'],
//            ['name' => 'print_quotations', 'module' => 'quotation', 'description' => 'Print quotations'],
//            ['name' => 'quotation_price_override', 'module' => 'quotation', 'description' => 'Use price override on quotations'],
//            ['name' => 'quotation_discount', 'module' => 'quotation', 'description' => 'Apply discounts on quotations'],
//        ];
//
//        foreach ($permissions as $permission) {
//            DB::table('permissions')->updateOrInsert(
//                ['name' => $permission['name']],
//                [
//                    'id' => DB::table('permissions')->where('name', $permission['name'])->value('id') ?: (string) Str::uuid(),
//                    'module' => $permission['module'],
//                    'description' => $permission['description'],
//                    'updated_at' => now(),
//                    'created_at' => now(),
//                ]
//            );
//        }
//    }
//}
