<?php

$deploymentMode = strtolower(trim((string) env(
    'SERVENEST_DEPLOYMENT_MODE',
    env('SALEXA_DEPLOYMENT_MODE', env('SALEXA_SYNC_DEPLOYMENT_MODE', 'standalone'))
)));

if (! in_array($deploymentMode, ['cloud', 'edge', 'standalone'], true)) {
    $deploymentMode = 'standalone';
}

return [
    // One release artifact is used in every location. Runtime role is the only
    // deployment switch; no cloud/edge forks are allowed in application code.
    'deployment_mode' => $deploymentMode,
    'hospitality_only' => true,
    'configuration_schema_version' => 2,
    'simulation' => [
        // Enables the intentionally weak Cashier1/password123 fixture outside
        // local/testing environments. Never enable this on a real deployment.
        'seed_data' => filter_var(
            env('SERVENEST_SEED_SIMULATION_DATA', false),
            FILTER_VALIDATE_BOOL
        ),
    ],
    'catalogue' => [
        'default_channel' => env('SERVENEST_DEFAULT_SALES_CHANNEL', 'POS'),
        'import_source_system' => env('SERVENEST_CATALOG_SOURCE_SYSTEM', 'gaap'),
    ],
];
