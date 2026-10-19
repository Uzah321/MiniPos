<?php

$syncEnv = static fn (string $name, mixed $default = null): mixed => env(
    'SERVENEST_SYNC_'.$name,
    env('SALEXA_SYNC_'.$name, $default)
);

$backoff = array_values(array_filter(array_map(
    static fn (string $value): int => max(1, (int) trim($value)),
    explode(',', (string) $syncEnv('RETRY_BACKOFF_SECONDS', '5,15,30,60,300'))
)));

return [
    /*
    |--------------------------------------------------------------------------
    | ServeNest node synchronization
    |--------------------------------------------------------------------------
    |
    | Sync is opt-in. A standalone installation continues to behave exactly as
    | it did before these settings were introduced. Credentials are persisted
    | with Laravel's encrypted cast and are never exposed by Eloquent models.
    |
    */
    'deployment_mode' => env(
        'SERVENEST_DEPLOYMENT_MODE',
        env('SALEXA_DEPLOYMENT_MODE', env('SALEXA_SYNC_DEPLOYMENT_MODE', 'standalone'))
    ),
    // A verify-only local Back Office may act as a one-company sync authority
    // while remaining distinct from the Central licence issuer.
    'local_hub' => filter_var($syncEnv('LOCAL_HUB', false), FILTER_VALIDATE_BOOL),
    'cloud_url' => $syncEnv('CLOUD_URL'),
    'allow_unpinned_cloud_url' => filter_var(
        $syncEnv('ALLOW_UNPINNED_CLOUD_URL', false),
        FILTER_VALIDATE_BOOL
    ),
    'allow_insecure_http' => filter_var(
        $syncEnv('ALLOW_INSECURE_HTTP', false),
        FILTER_VALIDATE_BOOL
    ),
    // Shared-cloud pre-login requests must identify the tenant by company
    // code. Enable only while migrating a legacy single-tenant host.
    'allow_legacy_domain_resolution' => filter_var(
        $syncEnv(
            'ALLOW_LEGACY_DOMAIN_RESOLUTION',
            $syncEnv('ALLOW_IMPLICIT_LEGACY_DOMAIN', false)
        ),
        FILTER_VALIDATE_BOOL
    ),
    'node_code' => $syncEnv('NODE_CODE'),
    'node_name' => $syncEnv('NODE_NAME', env('APP_NAME', 'ServeNest POS')),
    'presence_stale_seconds' => (int) $syncEnv('PRESENCE_STALE_SECONDS', 180),

    'http' => [
        'connect_timeout_seconds' => (int) $syncEnv('CONNECT_TIMEOUT_SECONDS', 5),
        // A configuration snapshot with a full brand catalogue is several MB;
        // on a slow shop link 30 seconds was not enough to download it.
        'timeout_seconds' => (int) $syncEnv('TIMEOUT_SECONDS', 120),
        // Day End is an idempotent, signed command which may need longer than
        // an ordinary push/pull request while the cloud reconciles a branch.
        'day_end_timeout_seconds' => (int) $syncEnv('DAY_END_TIMEOUT_SECONDS', 300),
        'verify_tls' => filter_var($syncEnv('VERIFY_TLS', true), FILTER_VALIDATE_BOOL),
        // Optional installation-specific PEM trust bundle for a local hub.
        // An absent/invalid path must never silently disable TLS checks.
        'ca_bundle' => $syncEnv('CA_BUNDLE'),
    ],

    // Cadence used by both the browser fallback and sync:edge-worker. The
    // worker is installed as an edge-server operating-system service; it is
    // deliberately independent of Laravel's scheduler and of any till UI.
    'polling' => [
        'push_interval_seconds' => (int) $syncEnv('PUSH_INTERVAL_SECONDS', 30),
        'pull_interval_seconds' => (int) $syncEnv('PULL_INTERVAL_SECONDS', 30),
        'heartbeat_interval_seconds' => (int) $syncEnv('HEARTBEAT_INTERVAL_SECONDS', 60),
        // A green synchronization indicator is valid only while a signed
        // cloud comparison is this recent. Unknown or stale comparisons must
        // fail closed in the UI.
        'status_fresh_seconds' => (int) $syncEnv('STATUS_FRESH_SECONDS', 180),
    ],

    // Covers the largest bounded push/pull run. Every browser tab and CLI
    // entry point shares this node lock, so a second run cannot overlap the
    // first merely because one HTTP request took longer than expected.
    'run_lock_seconds' => (int) $syncEnv('RUN_LOCK_SECONDS', 1800),

    'batch' => [
        'push_size' => (int) $syncEnv('PUSH_BATCH_SIZE', 200),
        'pull_size' => (int) $syncEnv('PULL_BATCH_SIZE', 500),
    ],

    'security' => [
        'activation_ttl_minutes' => (int) $syncEnv('ACTIVATION_TTL_MINUTES', 30),
        'terminal_activation_ttl_minutes' => (int) $syncEnv('TERMINAL_ACTIVATION_TTL_MINUTES', 10),
        'credential_version' => (int) $syncEnv('CREDENTIAL_VERSION', 1),
        'clock_skew_seconds' => (int) $syncEnv('CLOCK_SKEW_SECONDS', 300),
        'replay_nonce_ttl_seconds' => (int) $syncEnv('REPLAY_NONCE_TTL_SECONDS', 900),
    ],

    'retry' => [
        'max_attempts' => (int) $syncEnv('MAX_ATTEMPTS', 20),
        'backoff_seconds' => $backoff ?: [5, 15, 30, 60, 300],
    ],

    'streams' => [
        'changes' => $syncEnv('CHANGES_STREAM', 'changes'),
    ],
];
