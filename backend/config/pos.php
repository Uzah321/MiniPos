<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Manager verification thresholds
    |--------------------------------------------------------------------------
    |
    | Amounts above these thresholds require a manager to verify the
    | operation with their PIN before it is committed.
    |
    */
    'till_open_manager_threshold' => (float) env('POS_TILL_OPEN_MANAGER_THRESHOLD', 500),

    /*
    |--------------------------------------------------------------------------
    | Basket line removal threshold
    |--------------------------------------------------------------------------
    |
    | Removing a basket line worth this much or more (before payment) requires
    | a manager PIN, per the guide's "request override when threshold applies"
    | control on the "Remove item before payment" workflow.
    |
    */
    'line_removal_manager_threshold' => (float) env('POS_LINE_REMOVAL_MANAGER_THRESHOLD', 100),

    /*
    |--------------------------------------------------------------------------
    | Manual discount thresholds
    |--------------------------------------------------------------------------
    |
    | A manual discount at or above either threshold (whichever applies to the
    | discount type given) requires a manager PIN before it is applied.
    |
    */
    'discount_manager_threshold_percent' => (float) env('POS_DISCOUNT_MANAGER_THRESHOLD_PERCENT', 15),
    'discount_manager_threshold_amount' => (float) env('POS_DISCOUNT_MANAGER_THRESHOLD_AMOUNT', 50),

    /*
    |--------------------------------------------------------------------------
    | Loyalty accrual and redemption rates
    |--------------------------------------------------------------------------
    |
    | Points are earned on the final paid total (after all discounts) at the
    | earn rate, and redeemed points reduce the sale total at the redeem
    | rate (currency value per point).
    |
    */
    'loyalty_earn_rate' => (float) env('POS_LOYALTY_EARN_RATE', 0.1),
    'loyalty_redeem_rate' => (float) env('POS_LOYALTY_REDEEM_RATE', 0.1),

    /*
    |--------------------------------------------------------------------------
    | Returns and credit notes
    |--------------------------------------------------------------------------
    |
    | A return is only accepted within the return period, measured from the
    | original sale's completion. A credit note whose total reaches the
    | manager threshold requires a manager PIN; a no-receipt return always
    | requires one regardless of value.
    |
    */
    'return_period_days' => (int) env('POS_RETURN_PERIOD_DAYS', 30),
    'return_manager_threshold' => (float) env('POS_RETURN_MANAGER_THRESHOLD', 100),
];
