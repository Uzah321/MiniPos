<?php

return [
    // A provisioning package is only an installation hand-off. Once the
    // signed entitlement is imported, the licence's own expiry date applies.
    'standalone_package_ttl_hours' => max(
        1,
        (int) env(
            'SERVENEST_STANDALONE_PACKAGE_TTL_HOURS',
            env('SALEXA_STANDALONE_PACKAGE_TTL_HOURS', 168)
        )
    ),
];
