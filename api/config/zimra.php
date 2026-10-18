<?php

return [
    'default_environment' => env('ZIMRA_ENVIRONMENT', 'testing'),
    'timeout' => (int) env('ZIMRA_TIMEOUT', 30),

    'environments' => [
        'testing' => [
            'base_url' => env('ZIMRA_TEST_BASE_URL', ''),
            'verify_ssl' => filter_var(env('ZIMRA_TEST_VERIFY_SSL', false), FILTER_VALIDATE_BOOLEAN),
        ],
        'production' => [
            'base_url' => env('ZIMRA_PROD_BASE_URL', ''),
            'verify_ssl' => filter_var(env('ZIMRA_PROD_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
        ],
    ],

    // Keep fiscal certificates outside the source tree and public web root.
    // Example: base_path('storage/app/private/zimra/devices/{company_id}/{device_id}/certificate.pem')
    'certificate_base_path' => env('ZIMRA_CERTIFICATE_BASE_PATH', 'storage/app/private/zimra/devices'),

    // Production Android public-certificate registration is fail-closed unless
    // this points to the trusted ZIMRA issuer chain on the Central server.
    'trusted_ca_bundle' => env('ZIMRA_TRUSTED_CA_BUNDLE', ''),

    'allowed_certificate_extensions' => ['pem', 'crt', 'cer', 'txt'],
    'allowed_private_key_extensions' => ['pem', 'key', 'txt'],
];
