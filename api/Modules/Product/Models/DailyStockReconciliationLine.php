<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;

class DailyStockReconciliationLine extends Model
{
    protected $table = 'daily_stock_reconciliation_lines';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id','report_id','company_id','branch_id','trading_day_id','trading_date','section',
        'menu_product_id','menu_product_name','menu_product_code','menu_product_sku',
        'menu_quantity_sold','menu_avg_selling_price','menu_revenue','menu_recipe_cost','menu_gross_profit','menu_cost_percent',
        'ingredient_product_id','ingredient_name','ingredient_code','ingredient_sku','ingredient_type','uom_code',
        'recipe_qty_per_menu','ingredient_consumed_qty','opening_qty','receipts_qty','boh_to_front_qty',
        'other_issues_qty','sales_consumption_qty','adjustments_qty','expected_closing_qty','counted_closing_qty',
        'variance_qty','unit_cost','ingredient_total_cost','variance_value','currency_code','metadata'
    ];

    protected $casts = [
        'trading_date' => 'date',
        'metadata' => 'array',
    ];
}
