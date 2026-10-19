<?php

$loginCodeLookupKeyId = env('AUTH_LOGIN_CODE_LOOKUP_KEY_ID', 'v1');
$loginCodeLookupKeys = [
    $loginCodeLookupKeyId => env('AUTH_LOGIN_CODE_LOOKUP_KEY'),
];
$previousLoginCodeLookupKeyId = env('AUTH_LOGIN_CODE_LOOKUP_PREVIOUS_KEY_ID');
if ($previousLoginCodeLookupKeyId) {
    $loginCodeLookupKeys[$previousLoginCodeLookupKeyId] = env('AUTH_LOGIN_CODE_LOOKUP_PREVIOUS_KEY');
}

return [
    /*
    |--------------------------------------------------------------------------
    | Login-code blind indexes
    |--------------------------------------------------------------------------
    |
    | The lookup key must be stable across the cloud and the edge nodes that
    | receive authentication data. Keep the slow login_code hash as the final
    | verifier: this HMAC only narrows the database lookup to one candidate.
    |
    | During a rotation, keep the previous keys in this map until every user
    | has been re-indexed. Endpoints can query all candidate digests and write
    | the digest made with active_key_id after a successful verification.
    |
    */
    'login_code_blind_index' => [
        'active_key_id' => $loginCodeLookupKeyId,
        // Never fall back to APP_KEY: cloud and edge deployments normally
        // have different application keys, which would make synced blind
        // indexes unresolvable. Provision this dedicated key securely and
        // identically to every edge belonging to the cloud installation.
        'keys' => $loginCodeLookupKeys,
    ],

    'audit_identifier_key' => env('AUTH_AUDIT_IDENTIFIER_KEY', env('APP_KEY')),

    /*
    |--------------------------------------------------------------------------
    | Fail-closed policy defaults
    |--------------------------------------------------------------------------
    |
    | Database policies override these defaults from the broadest scope to
    | the most specific scope. Back-office authentication is deliberately not
    | configurable to a code-only method; it always requires username/password.
    |
    */
    'policy_defaults' => [
        'authentication' => [
            'pos' => [
                'login_mode' => 'username_password',
            ],
            'back_office' => [
                'login_mode' => 'username_password',
            ],
            'lockout' => [
                'maximum_attempts' => 5,
                'decay_seconds' => 900,
            ],
        ],
        'credential' => [
            'password' => [
                'minimum_length' => 6,
                'maximum_length' => 128,
                'require_uppercase' => true,
                'require_lowercase' => true,
                'require_number' => true,
                'require_symbol' => true,
                'history_count' => 5,
            ],
            'login_code' => [
                'minimum_length' => 4,
                'maximum_length' => 8,
                'numeric_only' => true,
                'reject_repeated_digits' => true,
                'reject_sequences' => true,
            ],
        ],
        'session' => [
            'pos' => [
                'lifetime_minutes' => 720,
                'idle_timeout_minutes' => 30,
            ],
            'back_office' => [
                'lifetime_minutes' => 480,
                'idle_timeout_minutes' => 30,
            ],
        ],
    ],
];
